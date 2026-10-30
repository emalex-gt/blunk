<?php

namespace App\Services\Fiscal;

use App\Models\ElectronicDocument;
use App\Models\FiscalDocumentIntent;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Sale;
use App\Support\AccountsReceivable;
use App\Support\BranchInventory;
use App\Support\BusinessCounter;
use App\Support\CashRegister;
use App\Support\Inventory\StockReservationService;
use App\Support\RouteWorkDayCompletion;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Finalizes a certified route pre-sale invoice from its immutable snapshot. */
class RoutePreSaleInvoiceFiscalFinalizer
{
    public function finalize(FiscalDocumentIntent $intent): FiscalDocumentIntent
    {
        $payload = $intent->operation_payload ?? [];
        $route = $payload['route_pre_sale'] ?? [];
        $preSaleId = (int) ($route['pre_sale_id'] ?? 0);
        if (! $preSaleId) {
            throw new \LogicException('La intención fiscal no contiene la preventa de ruta a finalizar.');
        }

        $preSale = PreSale::query()
            ->where('business_id', $intent->business_id)
            ->with('workDay')
            ->lockForUpdate()
            ->findOrFail($preSaleId);

        if ($preSale->converted_sale_id) {
            $intent->update([
                'finalized_sale_id' => $preSale->converted_sale_id,
                'status' => FiscalDocumentIntent::FINALIZED,
                'finalized_at' => now(),
                'last_finalization_error' => null,
            ]);

            return $intent->refresh();
        }

        if ($preSale->status !== PreSale::STATUS_PICKED) {
            throw ValidationException::withMessages(['pre_sale' => 'La preventa ya no está disponible para finalizar la factura certificada.']);
        }

        $header = $payload['sale'] ?? [];
        $items = $payload['items'] ?? [];
        if (! is_array($header) || $header === [] || ! is_array($items) || $items === []) {
            throw new \LogicException('La intención fiscal no contiene una venta de ruta finalizable.');
        }

        $timing = $route['stock_deduction_timing'] ?? 'invoice';
        $routeItems = collect($route['items'] ?? [])->keyBy('pre_sale_item_id');
        $currentItems = PreSaleItem::query()
            ->where('business_id', $intent->business_id)
            ->where('pre_sale_id', $preSale->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        foreach ($routeItems as $preSaleItemId => $routeItem) {
            $current = $currentItems->get($preSaleItemId);
            if (! $current || (int) $current->product_id !== (int) $routeItem['product_id']
                || abs((float) $current->picked_quantity - (float) $routeItem['quantity']) > 0.0001) {
                throw ValidationException::withMessages(['pre_sale' => 'Los productos preparados ya no coinciden con la factura certificada.']);
            }
            if ($timing === 'picking' && abs((float) $current->stock_deducted_quantity - (float) $routeItem['quantity']) > 0.0001) {
                throw ValidationException::withMessages(['pre_sale' => 'El descuento de preparación ya no coincide con la factura certificada.']);
            }
        }
        if ($timing === 'invoice') {
            $productIds = collect($items)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            app(StockReservationService::class)->lockStockRowsForReservation($intent->business_id, $intent->branch_id, $productIds);

            $reservations = StockReservation::query()
                ->where('business_id', $intent->business_id)
                ->where('branch_id', $intent->branch_id)
                ->where('source_type', StockReservationService::SOURCE_PRE_SALE)
                ->where('source_id', $preSale->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get()
                ->groupBy('source_item_id')
                ->map(fn ($rows) => round((float) $rows->sum('quantity'), 4));

            foreach ($routeItems as $preSaleItemId => $routeItem) {
                if (abs((float) $reservations->get($preSaleItemId, 0) - (float) $routeItem['quantity']) > 0.0001) {
                    throw ValidationException::withMessages(['pre_sale' => 'Las reservas de la preventa ya no coinciden con la factura certificada.']);
                }
            }
        }

        $sale = Sale::query()->create([
            ...$header,
            'business_id' => $intent->business_id,
            'branch_id' => $intent->branch_id,
            'business_number' => BusinessCounter::next($intent->business_id, 'sales'),
            'status' => 'fiscal_pending',
            'payment_status' => 'pending',
            'amount_paid' => 0,
            'credit_balance' => 0,
            'fel_internal_reference' => $intent->internal_reference,
            'certification_status' => 'certified',
            'fel_status' => 'CERTIFIED',
        ]);

        foreach ($items as $item) {
            $sale->items()->create([...$item, 'business_id' => $intent->business_id]);
        }
        $sale->load('items');

        foreach ($sale->items as $saleItem) {
            $product = Product::query()->where('business_id', $intent->business_id)->lockForUpdate()->findOrFail($saleItem->product_id);
            if ($timing === 'invoice') {
                [$previous, $new] = BranchInventory::decrease($product, $intent->branch_id, $saleItem->quantity);
                StockMovement::query()->create([
                    'business_id' => $intent->business_id,
                    'branch_id' => $intent->branch_id,
                    'product_id' => $product->id,
                    'type' => 'sale',
                    'quantity' => -1 * $saleItem->quantity,
                    'previous_stock' => $previous,
                    'new_stock' => $new,
                    'note' => stockMovementNote('sale', $sale->business_number ?: $sale->id),
                    'created_by' => $sale->created_by,
                ]);
            }
        }

        foreach (($payload['payments'] ?? []) as $payment) {
            $sale->payments()->create([
                'business_id' => $intent->business_id,
                'method' => $payment['method'],
                'amount' => round((float) $payment['amount'], 2),
                'reference' => $payment['reference'] ?? null,
                'details' => $payment['details'] ?? null,
            ]);
        }
        $cashAmount = CashRegister::cashAmountFromPayments($payload['payments'] ?? []);
        if ($cashAmount > 0) {
            $session = $intent->cashRegisterSession()->lockForUpdate()->first();
            if (! $session || $session->status !== 'open') {
                throw new \RuntimeException('La caja reservada ya no está disponible para finalizar la venta certificada.');
            }
            CashRegister::recordMovement($session, 'sale_cash', $cashAmount, 'sale', $sale->id, stockMovementNote('sale', $sale->business_number ?: $sale->id), $sale->created_by);
        }

        if ((bool) ($header['is_credit_sale'] ?? false)) {
            AccountsReceivable::createCharge($sale, $sale->created_by);
        }

        $ids = app(DigifactInvoiceService::class)->extractCertificationIdentifiers($intent->provider_response ?? []);
        $document = ElectronicDocument::query()->create([
            'business_id' => $intent->business_id,
            'sale_id' => $sale->id,
            'provider' => $intent->provider,
            'environment' => $intent->environment,
            'document_type' => 'invoice',
            'internal_reference' => $intent->internal_reference,
            'status' => 'certified',
            'uuid' => $ids['uuid'] ?? null,
            'series' => $ids['series'] ?? null,
            'number' => $ids['number'] ?? null,
            'certification_date' => $ids['certification_date'] ?? $intent->certified_at,
            'issued_at' => $this->frozenIssuedAt($intent),
            'request_payload' => $intent->fiscal_payload,
            'response_payload' => $intent->provider_response,
            'created_by' => $intent->created_by,
        ]);

        $sale->update([
            'electronic_document_id' => $document->id,
            'status' => 'completed',
            'payment_status' => (bool) ($header['is_credit_sale'] ?? false) || (($header['payment_status'] ?? null) === 'unpaid') ? 'unpaid' : 'paid',
            'amount_paid' => (bool) ($header['is_credit_sale'] ?? false) || (($header['payment_status'] ?? null) === 'unpaid') ? 0 : $sale->total,
            'credit_balance' => (bool) ($header['is_credit_sale'] ?? false) ? $sale->total : 0,
            'fel_uuid' => $document->uuid,
            'fel_series' => $document->series,
            'fel_number' => $document->number,
            'fel_certified_at' => $document->certification_date,
            'fel_issued_at' => $document->issued_at,
            'fel_raw_response' => $intent->provider_response,
        ]);

        if ($timing === 'invoice') {
            StockReservation::query()
                ->where('business_id', $intent->business_id)
                ->where('branch_id', $intent->branch_id)
                ->where('source_type', StockReservationService::SOURCE_PRE_SALE)
                ->where('source_id', $preSale->id)
                ->where('status', 'active')
                ->update(['status' => 'consumed', 'consumed_at' => now()]);
        }

        $preSale->update([
            'status' => PreSale::STATUS_CONVERTED,
            'converted_at' => now(),
            'converted_by' => $intent->created_by,
            'converted_sale_id' => $sale->id,
        ]);
        if ($preSale->workDay) {
            app(RouteWorkDayCompletion::class)->refresh($preSale->workDay);
        }

        $intent->update([
            'finalized_sale_id' => $sale->id,
            'status' => FiscalDocumentIntent::FINALIZED,
            'finalized_at' => now(),
            'last_finalization_error' => null,
        ]);

        return $intent->refresh();
    }

    private function frozenIssuedAt(FiscalDocumentIntent $intent): Carbon
    {
        $issuedDate = data_get($intent->fiscal_payload, 'Header.IssuedDateTime');

        return is_string($issuedDate) && $issuedDate !== ''
            ? Carbon::parse($issuedDate)->utc()
            : $intent->created_at->copy()->utc();
    }
}
