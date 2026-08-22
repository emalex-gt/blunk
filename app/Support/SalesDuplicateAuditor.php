<?php

namespace App\Support;

use App\Models\CashMovement;
use App\Models\ElectronicDocument;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SalesDuplicateAuditor
{
    public const REPAIR_REASON = 'Anulacion por venta duplicada detectada por auditoria';

    public function audit(array $options): array
    {
        $businessId = (int) ($options['business'] ?? 0);
        $windowSeconds = max(1, (int) ($options['window_seconds'] ?? 60));

        if ($businessId <= 0) {
            throw new RuntimeException('Debes indicar --business=ID.');
        }

        $sales = $this->baseSaleQuery($businessId, $options)
            ->with([
                'items:id,business_id,sale_id,product_id,product_name,quantity,unit_price,total',
                'payments:id,business_id,sale_id,method,amount,reference',
                'customer:id,name,doc_number',
                'createdBy:id,name',
                'electronicDocument:id,sale_id,status,uuid,series,number',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $groups = $this->duplicateGroups($sales, $windowSeconds);
        $reportPath = null;

        if (! empty($options['report'])) {
            $reportPath = $this->writeReport($businessId, $groups);
        }

        return [
            'business_id' => $businessId,
            'window_seconds' => $windowSeconds,
            'sales_reviewed' => $sales->count(),
            'duplicate_groups' => count($groups),
            'groups' => $groups,
            'report_path' => $reportPath,
        ];
    }

    public function repair(array $options): array
    {
        $businessId = (int) ($options['business'] ?? 0);
        $keepSaleId = (int) ($options['keep_sale_id'] ?? 0);
        $duplicateSaleId = (int) ($options['duplicate_sale_id'] ?? 0);
        $confirm = (bool) ($options['confirm'] ?? false);

        if ($businessId <= 0 || $keepSaleId <= 0 || $duplicateSaleId <= 0) {
            throw new RuntimeException('Indica --business, --keep-sale-id y --duplicate-sale-id.');
        }

        if ($keepSaleId === $duplicateSaleId) {
            throw new RuntimeException('La venta a conservar y la duplicada no pueden ser la misma.');
        }

        $keepSale = Sale::query()
            ->where('business_id', $businessId)
            ->with('items', 'payments', 'electronicDocument')
            ->findOrFail($keepSaleId);
        $duplicateSale = Sale::query()
            ->where('business_id', $businessId)
            ->with('items', 'payments', 'electronicDocument')
            ->findOrFail($duplicateSaleId);

        $this->assertRepairable($keepSale, $duplicateSale);

        $preview = [
            'mode' => $confirm ? 'confirm' : 'dry-run',
            'keep_sale_id' => $keepSale->id,
            'duplicate_sale_id' => $duplicateSale->id,
            'duplicate_business_number' => $duplicateSale->business_number,
            'stock_reversals' => $duplicateSale->items->count(),
            'cash_reversal_amount' => CashRegister::cashAmountFromPayments($duplicateSale->payments),
            'recommendation' => $confirm ? 'Venta duplicada anulada con reversas operativas.' : 'Dry-run: conserva la primera venta y anula/reversa la posterior solo con --confirm.',
        ];

        if (! $confirm) {
            return $preview;
        }

        DB::transaction(function () use ($businessId, $duplicateSale, $keepSale) {
            $sale = Sale::query()
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->with(['items', 'payments'])
                ->findOrFail($duplicateSale->id);
            $repairReason = self::REPAIR_REASON.'; conserva venta '.$keepSale->id.'; duplicada '.$sale->id;

            if (($sale->status ?? 'completed') === 'cancelled') {
                throw ValidationException::withMessages([
                    'sale' => 'La venta duplicada ya esta anulada.',
                ]);
            }

            AccountsReceivable::cancelSaleCharge($sale, null);

            foreach ($sale->items as $item) {
                $product = Product::query()
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->find($item->product_id);

                if (! $product) {
                    continue;
                }

                [$previousStock, $newStock] = BranchInventory::increase($product, (int) $sale->branch_id, (float) $item->quantity);

                StockMovement::query()->create([
                    'business_id' => $businessId,
                    'branch_id' => $sale->branch_id,
                    'product_id' => $product->id,
                    'type' => 'sale_cancel',
                    'quantity' => (float) $item->quantity,
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                    'note' => $repairReason,
                    'created_by' => null,
                ]);
            }

            $cashAmount = CashRegister::cashAmountFromPayments($sale->payments);

            if ($cashAmount > 0) {
                $cashMovement = CashMovement::query()
                    ->where('business_id', $businessId)
                    ->where('reference_type', 'sale')
                    ->where('reference_id', $sale->id)
                    ->with('session')
                    ->latest('id')
                    ->first();

                if (! $cashMovement || ! $cashMovement->session || $cashMovement->session->status !== 'open') {
                    throw ValidationException::withMessages([
                        'cash_register' => 'La venta duplicada tiene movimiento de caja en una sesion cerrada o no encontrada. Requiere revision manual.',
                    ]);
                }

                CashRegister::recordMovement(
                    $cashMovement->session,
                    'sale_cash_cancel',
                    -1 * $cashAmount,
                    'sale',
                    $sale->id,
                    $repairReason,
                    null,
                );
            }

            $sale->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => null,
                'cancellation_reason' => $repairReason,
            ]);
        });

        return $preview;
    }

    private function baseSaleQuery(int $businessId, array $options)
    {
        return Sale::query()
            ->where('business_id', $businessId)
            ->when($options['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($options['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to));
    }

    private function duplicateGroups(Collection $sales, int $windowSeconds): array
    {
        $groups = [];

        foreach ($sales->groupBy(fn (Sale $sale) => $this->fingerprint($sale)) as $fingerprint => $sameSales) {
            $bucket = [];
            $bucketStart = null;

            foreach ($sameSales->values() as $sale) {
                $createdAt = Carbon::parse($sale->created_at);

                if ($bucketStart && $createdAt->diffInSeconds($bucketStart) > $windowSeconds) {
                    $this->appendGroup($groups, $fingerprint, $bucket);
                    $bucket = [];
                    $bucketStart = null;
                }

                $bucket[] = $sale;
                $bucketStart ??= $createdAt;
            }

            $this->appendGroup($groups, $fingerprint, $bucket);
        }

        return array_values($groups);
    }

    private function appendGroup(array &$groups, string $fingerprint, array $bucket): void
    {
        if (count($bucket) < 2) {
            return;
        }

        $sales = collect($bucket);
        $first = $sales->first();

        $groups[] = [
            'fingerprint' => $fingerprint,
            'sale_ids' => $sales->pluck('id')->values()->all(),
            'business_numbers' => $sales->pluck('business_number')->values()->all(),
            'created_at' => $sales->pluck('created_at')->map(fn ($value) => (string) $value)->values()->all(),
            'user' => $first->createdBy?->name,
            'customer' => $first->customer?->name ?? $first->customer_name,
            'total' => (float) $first->total,
            'items' => $first->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ])->values()->all(),
            'stock_movements' => StockMovement::query()
                ->where('business_id', $first->business_id)
                ->where('type', 'sale')
                ->whereIn('product_id', $first->items->pluck('product_id')->filter()->values())
                ->whereBetween('created_at', [
                    Carbon::parse($sales->min('created_at'))->subSeconds(5),
                    Carbon::parse($sales->max('created_at'))->addSeconds(5),
                ])
                ->count(),
            'cash_movements' => CashMovement::query()
                ->where('reference_type', 'sale')
                ->whereIn('reference_id', $sales->pluck('id'))
                ->count(),
            'fel_documents' => $sales->filter(fn (Sale $sale) => $this->hasFelDocument($sale))->count(),
            'recommendation' => 'Conservar primera venta y revisar/anular posteriores.',
        ];
    }

    private function fingerprint(Sale $sale): string
    {
        $items = $sale->items
            ->map(fn ($item) => [
                'product_id' => (int) $item->product_id,
                'quantity' => (float) $item->quantity,
                'unit_price_cents' => $this->cents($item->unit_price),
                'total_cents' => $this->cents($item->total),
            ])
            ->sortBy(fn (array $item) => implode('|', $item))
            ->values()
            ->all();

        $payload = [
            'business_id' => (int) $sale->business_id,
            'branch_id' => (int) $sale->branch_id,
            'user_id' => (int) $sale->created_by,
            'customer_id' => $sale->customer_id ? (int) $sale->customer_id : null,
            'document_type' => $sale->document_type,
            'payment_method' => $sale->payment_method,
            'payment_status' => $sale->payment_status,
            'is_credit_sale' => (bool) $sale->is_credit_sale,
            'total_cents' => $this->cents($sale->total),
            'items' => $items,
        ];

        return hash('sha256', json_encode($payload));
    }

    private function writeReport(int $businessId, array $groups): string
    {
        $directory = 'sales-duplicate-audits/'.now()->format('Ymd-His')."-business-{$businessId}";
        Storage::disk('local')->makeDirectory($directory);
        $path = "{$directory}/duplicate_sales.csv";
        $stream = fopen('php://temp', 'w+');

        fputcsv($stream, [
            'group',
            'sale_ids',
            'business_numbers',
            'created_at',
            'user',
            'customer',
            'total',
            'items',
            'cash_movements',
            'fel_documents',
            'recommendation',
        ]);

        foreach ($groups as $index => $group) {
            fputcsv($stream, [
                $index + 1,
                implode('|', $group['sale_ids']),
                implode('|', $group['business_numbers']),
                implode('|', $group['created_at']),
                $group['user'],
                $group['customer'],
                number_format((float) $group['total'], 2, '.', ''),
                json_encode($group['items'], JSON_UNESCAPED_UNICODE),
                $group['cash_movements'],
                $group['fel_documents'],
                $group['recommendation'],
            ]);
        }

        rewind($stream);
        Storage::disk('local')->put($path, stream_get_contents($stream));
        fclose($stream);

        return storage_path('app/'.$path);
    }

    private function assertRepairable(Sale $keepSale, Sale $duplicateSale): void
    {
        if ($this->hasFelDocument($duplicateSale) || filled($duplicateSale->fel_uuid)) {
            throw new RuntimeException('Venta duplicada tiene FEL/electronic_document. Requiere anulacion fiscal.');
        }

        if ($this->fingerprint($keepSale) !== $this->fingerprint($duplicateSale)) {
            throw new RuntimeException('Las ventas indicadas no tienen la misma huella de duplicado. Revisa manualmente.');
        }

        if ($duplicateSale->is_credit_sale && (float) $duplicateSale->amount_paid > 0) {
            throw new RuntimeException('La venta al credito duplicada tiene abonos registrados. Requiere revision manual.');
        }
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function hasFelDocument(Sale $sale): bool
    {
        return $sale->electronicDocument !== null
            || ElectronicDocument::query()
                ->where('business_id', $sale->business_id)
                ->where('sale_id', $sale->id)
                ->exists();
    }
}
