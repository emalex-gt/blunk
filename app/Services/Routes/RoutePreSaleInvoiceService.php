<?php

namespace App\Services\Routes;

use App\Models\Business;
use App\Models\ElectronicDocument;
use App\Models\FelReconciliationRequest;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\TenantFelSetting;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Fel\FelException;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Support\AccountsReceivable;
use App\Support\BranchInventory;
use App\Support\BusinessCounter;
use App\Support\CashRegister;
use App\Support\Credits;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use App\Support\Inventory\StockReservationService;
use App\Support\Permissions;
use App\Support\PriceLists;
use App\Support\RouteWorkDayCompletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoutePreSaleInvoiceService
{
    public function convert(PreSale $preSale, array $data, User $user): IdempotencyResult
    {
        $business = Business::query()->findOrFail($preSale->business_id);
        $settings = TenantSetting::query()->where('business_id', $business->id)->first();
        $felSettings = TenantFelSetting::query()->where('business_id', $business->id)->first();
        $stockDeductionTiming = $this->stockDeductionTiming($settings);
        $isCreditSale = ($data['payment_condition'] ?? 'paid') === 'credit';
        $failedFel = null;

        $this->assertDocumentIsAvailable($business, $settings, $felSettings, $data['document_type']);

        if ($isCreditSale) {
            if (! Credits::salesEnabled($business->id)) {
                throw ValidationException::withMessages([
                    'payment_condition' => 'Las ventas al crédito no están habilitadas para este negocio.',
                ]);
            }

            abort_unless(Permissions::userHas($user, Permissions::CREDITS_SALES_CREATE), 403);
        }

        if (! $isCreditSale && empty($data['payment_method'])) {
            throw ValidationException::withMessages([
                'payment_method' => 'Selecciona una forma de pago.',
            ]);
        }

        $snapshotItems = PreSaleItem::query()
            ->where('business_id', $preSale->business_id)
            ->where('pre_sale_id', $preSale->id)
            ->orderBy('id')
            ->get(['id', 'product_id', 'picked_quantity', 'unit_price']);

        try {
            return app(IdempotencyService::class)->run(
                (int) $preSale->business_id,
                (int) $preSale->branch_id,
                $user->id,
                'route_pre_sale_invoice',
                $data['idempotency_key'],
                $this->idempotencyPayload($preSale, $data, $snapshotItems),
                function () use ($preSale, $data, $user, $business, $settings, $felSettings, $isCreditSale, $stockDeductionTiming, &$failedFel) {
                    return DB::transaction(function () use ($preSale, $data, $user, $business, $settings, $felSettings, $isCreditSale, $stockDeductionTiming, &$failedFel) {
                        $lockedPreSale = PreSale::query()
                            ->where('business_id', $preSale->business_id)
                            ->whereKey($preSale->id)
                            ->with(['customer', 'items.product', 'workDay'])
                            ->lockForUpdate()
                            ->firstOrFail();

                        if ($lockedPreSale->status !== PreSale::STATUS_PICKED
                            || $lockedPreSale->converted_sale_id !== null
                            || $lockedPreSale->converted_at !== null) {
                            throw ValidationException::withMessages([
                                'pre_sale' => 'Esta preventa ya fue facturada o no está disponible para facturar.',
                            ]);
                        }

                        $items = $lockedPreSale->items
                            ->filter(fn (PreSaleItem $item) => (float) ($item->picked_quantity ?? 0) > 0)
                            ->values();

                        if ($items->isEmpty()) {
                            throw ValidationException::withMessages([
                                'pre_sale' => 'No se puede facturar una preventa sin productos preparados.',
                            ]);
                        }

                        $reservedByItem = collect();

                        if ($stockDeductionTiming === 'invoice') {
                            $productIds = $items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
                            app(StockReservationService::class)->lockStockRowsForReservation(
                                (int) $lockedPreSale->business_id,
                                (int) $lockedPreSale->branch_id,
                                $productIds,
                            );

                            $reservedByItem = StockReservation::query()
                                ->where('business_id', $lockedPreSale->business_id)
                                ->where('branch_id', $lockedPreSale->branch_id)
                                ->where('source_type', StockReservationService::SOURCE_PRE_SALE)
                                ->where('source_id', $lockedPreSale->id)
                                ->where('status', 'active')
                                ->orderBy('product_id')
                                ->orderBy('id')
                                ->lockForUpdate()
                                ->get()
                                ->groupBy('source_item_id')
                                ->map(fn ($rows) => round((float) $rows->sum('quantity'), 4));
                        }

                        $saleLines = [];
                        $subtotal = 0.0;
                        $discountTotal = 0.0;

                        foreach ($items as $item) {
                            $pickedQuantity = round((float) $item->picked_quantity, 4);
                            $requestedQuantity = round((float) $item->quantity, 4);
                            $reservedQuantity = (float) ($reservedByItem->get($item->id) ?? 0);
                            $stockDeductedQuantity = round((float) ($item->stock_deducted_quantity ?? 0), 4);

                            if ($pickedQuantity <= 0 || $pickedQuantity > $requestedQuantity) {
                                throw ValidationException::withMessages([
                                    'pre_sale' => 'No se puede facturar porque las cantidades preparadas no coinciden con las reservas activas.',
                                ]);
                            }

                            if ($stockDeductionTiming === 'invoice' && abs($pickedQuantity - $reservedQuantity) > 0.0001) {
                                throw ValidationException::withMessages([
                                    'pre_sale' => 'No se puede facturar porque las cantidades preparadas no coinciden con las reservas activas.',
                                ]);
                            }

                            if ($stockDeductionTiming === 'picking' && abs($pickedQuantity - $stockDeductedQuantity) > 0.0001) {
                                throw ValidationException::withMessages([
                                    'pre_sale' => 'No se puede facturar porque el descuento de stock de la preparación no coincide con las cantidades preparadas.',
                                ]);
                            }

                            if (abs($pickedQuantity - round($pickedQuantity)) > 0.0001) {
                                throw ValidationException::withMessages([
                                    'pre_sale' => 'Las cantidades preparadas deben ser enteras para crear la venta.',
                                ]);
                            }

                            $product = Product::query()
                                ->where('business_id', $lockedPreSale->business_id)
                                ->where('is_active', true)
                                ->lockForUpdate()
                                ->find($item->product_id);

                            if (! $product) {
                                throw ValidationException::withMessages([
                                    'pre_sale' => 'Uno de los productos preparados ya no está disponible.',
                                ]);
                            }

                            BranchInventory::ensureProductInBranch($product, (int) $lockedPreSale->branch_id);

                            $lineSubtotal = round((float) $item->unit_price * $pickedQuantity, 2);
                            $lineDiscount = round(((float) $item->discount / max($requestedQuantity, 1)) * $pickedQuantity, 2);
                            $lineDiscount = min($lineDiscount, $lineSubtotal);
                            $lineTotal = round($lineSubtotal - $lineDiscount, 2);

                            $saleLines[] = [
                                'item' => $item,
                                'product' => $product,
                                'quantity' => (int) round($pickedQuantity),
                                'unit_price' => round((float) $item->unit_price, 2),
                                'original_price' => round((float) ($item->original_price ?? $item->unit_price), 2),
                                'price_type_id' => $item->price_type_id,
                                'manual_price' => (bool) $item->manual_price,
                                'line_subtotal' => $lineSubtotal,
                                'line_discount' => $lineDiscount,
                                'line_total' => $lineTotal,
                            ];
                            $subtotal += $lineSubtotal;
                            $discountTotal += $lineDiscount;
                        }

                        $total = round($subtotal - $discountTotal, 2);
                        $customer = $lockedPreSale->customer;

                        if ($data['document_type'] === 'invoice') {
                            $this->assertInvoiceCustomer($customer, $total);
                        }

                        if ($isCreditSale) {
                            $this->assertCreditCustomer($customer);
                            AccountsReceivable::assertCanCharge($customer, $total, (int) $lockedPreSale->branch_id);
                        }

                        $cashSession = $isCreditSale
                            ? null
                            : CashRegister::requireOpenSession(
                                (int) $lockedPreSale->business_id,
                                'Debes abrir caja antes de facturar la preventa.',
                                true,
                                (int) $lockedPreSale->branch_id,
                            );

                        $sale = Sale::query()->create([
                            'business_id' => $lockedPreSale->business_id,
                            'business_number' => BusinessCounter::next((int) $lockedPreSale->business_id, 'sales'),
                            'branch_id' => $lockedPreSale->branch_id,
                            'customer_id' => $customer?->id,
                            ...$this->customerSnapshot($customer),
                            'payment_method' => $isCreditSale ? 'credit' : $data['payment_method'],
                            'payment_status' => $isCreditSale ? 'unpaid' : 'paid',
                            'amount_paid' => $isCreditSale ? 0 : $total,
                            'credit_balance' => $isCreditSale ? $total : 0,
                            'is_credit_sale' => $isCreditSale,
                            'due_date' => $isCreditSale ? ($data['due_date'] ?? null) : null,
                            'document_type' => $data['document_type'],
                            'status' => 'completed',
                            'note' => $data['note'] ?? null,
                            'created_by' => $user->id,
                            'total' => $total,
                            'subtotal_before_discount' => round($subtotal, 2),
                            'discount_type' => $discountTotal > 0 ? 'fixed' : null,
                            'discount_value' => $discountTotal,
                            'discount_amount' => $discountTotal,
                            'discount_reason' => $discountTotal > 0 ? 'Descuento de preventa' : null,
                        ]);

                        foreach ($saleLines as $line) {
                            $product = $line['product'];
                            $unitCost = round((float) $product->cost_price, 4);
                            $totalCost = round($unitCost * $line['quantity'], 2);

                            $sale->items()->create([
                                'business_id' => $sale->business_id,
                                'product_id' => $product->id,
                                'product_name' => $product->name,
                                'quantity' => $line['quantity'],
                                'price_type_id' => $line['price_type_id'],
                                'unit_price' => $line['unit_price'],
                                'original_price' => $line['original_price'],
                                'price_source' => $line['manual_price'] ? PriceLists::SOURCE_MANUAL : PriceLists::SOURCE_PRICE_LIST,
                                'manual_price' => $line['manual_price'],
                                'unit_cost' => $unitCost,
                                'total_cost' => $totalCost,
                                'profit_amount' => round($line['line_total'] - $totalCost, 2),
                                'total' => $line['line_total'],
                                'discount_amount' => $line['line_discount'],
                                'total_before_discount' => $line['line_subtotal'],
                                'total_after_discount' => $line['line_total'],
                            ]);

                            if ($stockDeductionTiming === 'invoice') {
                                [$previousStock, $newStock] = BranchInventory::decrease($product, (int) $lockedPreSale->branch_id, $line['quantity']);

                                StockMovement::query()->create([
                                    'business_id' => $sale->business_id,
                                    'branch_id' => $sale->branch_id,
                                    'product_id' => $product->id,
                                    'type' => 'sale',
                                    'quantity' => -1 * $line['quantity'],
                                    'previous_stock' => $previousStock,
                                    'new_stock' => $newStock,
                                    'note' => stockMovementNote('sale', $sale->business_number ?: $sale->id),
                                    'created_by' => $user->id,
                                ]);
                            }
                        }

                        if (! $isCreditSale) {
                            $sale->payments()->create([
                                'business_id' => $sale->business_id,
                                'method' => $data['payment_method'],
                                'amount' => $total,
                            ]);

                            if ($data['payment_method'] === 'cash') {
                                CashRegister::recordMovement(
                                    $cashSession,
                                    'sale_cash',
                                    $total,
                                    'sale',
                                    $sale->id,
                                    stockMovementNote('sale', $sale->business_number ?: $sale->id),
                                    $user->id,
                                );
                            }
                        }

                        if ($data['document_type'] === 'invoice') {
                            $document = ElectronicDocument::query()->create([
                                'business_id' => $sale->business_id,
                                'sale_id' => $sale->id,
                                'provider' => 'digifact',
                                'environment' => $felSettings?->environment ?? 'test',
                                'document_type' => 'invoice',
                                'status' => 'pending',
                                'created_by' => $user->id,
                            ]);

                            $sale->update([
                                'electronic_document_id' => $document->id,
                                'certification_status' => 'pending',
                            ]);

                            try {
                                app(DigifactInvoiceService::class)->certifySale(
                                    $sale->refresh()->load(['business', 'customer', 'items.product', 'payments', 'electronicDocument']),
                                );
                            } catch (FelException $exception) {
                                $failedFel = [
                                    'business_id' => $sale->business_id,
                                    'branch_id' => $sale->branch_id,
                                    'internal_reference' => $sale->fel_internal_reference ?: 'BLUNK-'.$sale->business_id.'-'.$sale->id,
                                    'issued_date' => $sale->fel_issued_at ?: $sale->created_at,
                                    'environment' => $felSettings?->environment ?? 'test',
                                    'last_error' => $exception->getMessage(),
                                    'created_by' => $user->id,
                                    'payload_snapshot' => [
                                        'pre_sale_id' => $lockedPreSale->id,
                                        'sale_id' => $sale->id,
                                        'business_number' => $sale->business_number,
                                        'total' => $sale->total,
                                    ],
                                ];

                                throw $exception;
                            }
                        }

                        if ($isCreditSale) {
                            AccountsReceivable::createCharge($sale->refresh(), $user->id);
                        }

                        if ($stockDeductionTiming === 'invoice') {
                            StockReservation::query()
                                ->where('business_id', $lockedPreSale->business_id)
                                ->where('branch_id', $lockedPreSale->branch_id)
                                ->where('source_type', StockReservationService::SOURCE_PRE_SALE)
                                ->where('source_id', $lockedPreSale->id)
                                ->where('status', 'active')
                                ->update([
                                    'status' => 'consumed',
                                    'consumed_at' => now(),
                                ]);
                        }

                        $lockedPreSale->update([
                            'status' => PreSale::STATUS_CONVERTED,
                            'converted_at' => now(),
                            'converted_by' => $user->id,
                            'converted_sale_id' => $sale->id,
                        ]);

                        if ($lockedPreSale->workDay) {
                            app(RouteWorkDayCompletion::class)->refresh($lockedPreSale->workDay, $user);
                        }

                        return [
                            'result_id' => $sale->id,
                            'response_payload' => [
                                'sale_id' => $sale->id,
                                'pre_sale_id' => $lockedPreSale->id,
                                'document_type' => $sale->document_type,
                            ],
                        ];
                    });
                },
                'sale',
            );
        } catch (FelException $exception) {
            if ($failedFel !== null) {
                FelReconciliationRequest::query()->updateOrCreate(
                    [
                        'business_id' => $failedFel['business_id'],
                        'provider' => 'digifact',
                        'environment' => $failedFel['environment'],
                        'internal_reference' => $failedFel['internal_reference'],
                    ],
                    [
                        'branch_id' => $failedFel['branch_id'],
                        'sale_id' => null,
                        'issued_date' => $failedFel['issued_date'],
                        'status' => 'pending',
                        'last_error' => $failedFel['last_error'] ?: 'No se pudo certificar la factura.',
                        'payload_snapshot' => $failedFel['payload_snapshot'],
                        'created_by' => $failedFel['created_by'],
                    ],
                );
            }

            throw ValidationException::withMessages([
                'document_type' => 'No se pudo emitir la factura FEL. La preventa no fue convertida y su preparación se mantiene para reintentar.',
            ]);
        }
    }

    private function stockDeductionTiming(?TenantSetting $settings): string
    {
        return $settings?->route_pre_sale_stock_deduction_timing === 'picking'
            ? 'picking'
            : 'invoice';
    }

    private function assertDocumentIsAvailable(Business $business, ?TenantSetting $settings, ?TenantFelSetting $felSettings, string $documentType): void
    {
        if ($documentType === 'receipt' && (bool) ($settings?->allow_receipts ?? true)) {
            return;
        }

        if ($documentType === 'invoice'
            && $business->country === 'GT'
            && (bool) ($settings?->allow_invoices ?? false)
            && module_enabled('fel_gt', $business->id)
            && (bool) $felSettings?->enabled
            && (bool) $felSettings?->isConfigured()) {
            return;
        }

        throw ValidationException::withMessages([
            'document_type' => 'El tipo de documento seleccionado no está habilitado.',
        ]);
    }

    private function assertCreditCustomer($customer): void
    {
        $docType = strtoupper(trim((string) $customer?->doc_type));
        $docNumber = $this->normalizedDocument($customer?->doc_number);

        if (! $customer || $docType !== 'NIT' || $docNumber === '' || $docNumber === 'CF') {
            throw ValidationException::withMessages([
                'payment_condition' => 'Para una venta al crédito debes seleccionar un cliente con NIT válido.',
            ]);
        }
    }

    private function assertInvoiceCustomer($customer, float $total): void
    {
        if (! $customer) {
            throw ValidationException::withMessages([
                'customer' => 'Selecciona un cliente antes de emitir factura FEL.',
            ]);
        }

        $docType = strtoupper(trim((string) $customer->doc_type));
        $docNumber = $this->normalizedDocument($customer->doc_number);
        $isFinalConsumer = $docType === 'CF' || $docNumber === 'CF' || (bool) $customer->is_final_consumer;

        if ($isFinalConsumer) {
            if ($total >= 2500) {
                throw ValidationException::withMessages([
                    'customer' => 'No se puede emitir factura FEL a Consumidor Final por Q2,500.00 o más.',
                ]);
            }

            return;
        }

        if ($docType === 'CUI' || $docNumber === '' || ! preg_match('/^[A-Z0-9]+$/', $docNumber)) {
            throw ValidationException::withMessages([
                'customer' => 'El NIT debe validarse antes de emitir factura FEL.',
            ]);
        }

        if (! $customer->tax_lookup_verified_at || ! $customer->name_locked) {
            throw ValidationException::withMessages([
                'customer' => 'Este cliente existe, pero su NIT no ha sido validado fiscalmente. Valídalo nuevamente antes de emitir factura FEL.',
            ]);
        }
    }

    private function customerSnapshot($customer): array
    {
        return [
            'customer_name' => $customer?->name,
            'customer_doc_type' => $customer?->doc_type,
            'customer_doc_number' => $this->normalizedDocument($customer?->doc_number),
            'customer_address' => $customer?->address,
            'customer_postal_code' => $customer?->postal_code,
            'customer_municipality' => $customer?->municipality,
            'customer_department' => $customer?->department,
            'customer_country' => $customer?->country ?: 'GT',
            'customer_phone' => $customer?->phone,
        ];
    }

    private function idempotencyPayload(PreSale $preSale, array $data, $items): array
    {
        return [
            'business_id' => (int) $preSale->business_id,
            'branch_id' => (int) $preSale->branch_id,
            'user_id' => auth()->id(),
            'operation_type' => 'route_pre_sale_invoice',
            'pre_sale_id' => $preSale->id,
            'document_type' => $data['document_type'],
            'payment_condition' => $data['payment_condition'] ?? 'paid',
            'payment_method' => $data['payment_method'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'note' => $data['note'] ?? null,
            'items' => $items->map(fn (PreSaleItem $item) => [
                'pre_sale_item_id' => $item->id,
                'product_id' => $item->product_id,
                'picked_quantity' => round((float) ($item->picked_quantity ?? 0), 4),
                'unit_price' => round((float) $item->unit_price, 2),
            ])->values()->all(),
        ];
    }

    private function normalizedDocument(?string $value): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', trim((string) $value)));
    }
}
