<?php

namespace App\Support;

use App\Models\Business;
use App\Models\InventoryTransfer;
use App\Models\Sale;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Read-only operational integrity checks. This class deliberately never calls
 * domain services because several of them create missing stock rows as a side effect.
 */
class SystemIntegrityAuditor
{
    private const SECTIONS = [
        'stock',
        'sales',
        'cash',
        'ar',
        'purchases',
        'transfers',
        'credit-reservations',
    ];

    private const REPORT_FILES = [
        'stock' => 'stock_discrepancies.csv',
        'negative_stock' => 'negative_stock.csv',
        'sales' => 'sales_integrity_issues.csv',
        'cash' => 'cash_integrity_issues.csv',
        'ar' => 'accounts_receivable_issues.csv',
        'purchases' => 'purchase_integrity_issues.csv',
        'transfers' => 'transfer_integrity_issues.csv',
        'stock_adjustments' => 'stock_adjustment_issues.csv',
        'credit-reservations' => 'credit_reservation_issues.csv',
    ];

    public function __construct(private readonly SalesDuplicateAuditor $salesDuplicateAuditor)
    {
    }

    public function audit(array $options): array
    {
        $businessId = (int) ($options['business'] ?? 0);

        if ($businessId <= 0 || ! Business::query()->whereKey($businessId)->exists()) {
            throw new InvalidArgumentException('Debes indicar un --business existente.');
        }

        $branchId = filled($options['branch'] ?? null) ? (int) $options['branch'] : null;

        if ($branchId && ! DB::table('branches')->where('business_id', $businessId)->where('id', $branchId)->exists()) {
            throw new InvalidArgumentException('La sucursal indicada no pertenece al negocio.');
        }

        $section = $options['section'] ?? null;

        if ($section !== null && ! in_array($section, self::SECTIONS, true)) {
            throw new InvalidArgumentException('La opción --section no es válida.');
        }

        $context = [
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'from' => filled($options['from'] ?? null) ? Carbon::parse($options['from'])->startOfDay() : null,
            'to' => filled($options['to'] ?? null) ? Carbon::parse($options['to'])->endOfDay() : null,
            'strict' => (bool) ($options['strict'] ?? false),
        ];
        $results = [
            'stock' => [],
            'negative_stock' => [],
            'sales' => [],
            'cash' => [],
            'ar' => [],
            'purchases' => [],
            'transfers' => [],
            'stock_adjustments' => [],
            'credit-reservations' => [],
        ];

        $shouldRun = fn (string $name): bool => $section === null || $section === $name || ($name === 'stock_adjustments' && $section === 'stock');

        if ($shouldRun('stock')) {
            [$results['stock'], $results['negative_stock'], $results['credit-reservations']] = $this->auditStock($context);
            $results['stock_adjustments'] = $this->auditStockAdjustments($context);
        }

        if ($shouldRun('sales')) {
            $results['sales'] = $this->auditSales($context);
        }

        if ($shouldRun('cash')) {
            $results['cash'] = $this->auditCash($context);
        }

        if ($shouldRun('ar')) {
            $results['ar'] = $this->auditAccountsReceivable($context);
        }

        if ($shouldRun('purchases')) {
            $results['purchases'] = $this->auditPurchases($context);
        }

        if ($shouldRun('transfers')) {
            $results['transfers'] = $this->auditTransfers($context);
        }

        if ($shouldRun('credit-reservations') && $section === 'credit-reservations') {
            $results['credit-reservations'] = $this->auditCreditReservations($context);
        }

        $summary = $this->summarize($results, $section);
        $reportPath = ! empty($options['report']) ? $this->writeReport($businessId, $results, $summary) : null;

        return [
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'section' => $section,
            'summary' => $summary,
            'results' => $results,
            'report_path' => $reportPath,
            'has_critical' => collect($summary)->sum('critical_count') > 0,
        ];
    }

    private function auditStock(array $context): array
    {
        $stockIssues = [];
        $negativeStock = [];
        $businessId = $context['business_id'];
        $branchId = $context['branch_id'];
        $allowsNegativeStock = (bool) DB::table('tenant_settings')->where('business_id', $businessId)->value('allow_negative_stock');
        $movementTotals = DB::table('stock_movements')
            ->select('business_id', 'branch_id', 'product_id', DB::raw('COALESCE(SUM(quantity), 0) as calculated_stock'))
            ->where('business_id', $businessId)
            ->whereNotNull('branch_id')
            ->groupBy('business_id', 'branch_id', 'product_id');

        $stockRows = DB::table('product_branch_stocks as pbs')
            ->join('products as p', 'p.id', '=', 'pbs.product_id')
            ->leftJoinSub($movementTotals, 'moves', function ($join) {
                $join->on('moves.business_id', '=', 'pbs.business_id')
                    ->on('moves.branch_id', '=', 'pbs.branch_id')
                    ->on('moves.product_id', '=', 'pbs.product_id');
            })
            ->where('pbs.business_id', $businessId)
            ->when($branchId, fn (Builder $query) => $query->where('pbs.branch_id', $branchId))
            ->select([
                'pbs.branch_id', 'pbs.product_id', 'pbs.stock as current_stock', 'p.name as product_name', 'p.barcode', 'p.is_active',
                DB::raw('COALESCE(moves.calculated_stock, 0) as calculated_stock'),
            ])
            ->orderBy('pbs.id')
            ->cursor();

        foreach ($stockRows as $row) {
            $current = (float) $row->current_stock;
            $calculated = (float) $row->calculated_stock;
            $difference = round($current - $calculated, 2);

            if (abs($difference) >= 0.01) {
                $stockIssues[] = $this->issue([
                    'business_id' => $businessId,
                    'branch_id' => $row->branch_id,
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'barcode' => $row->barcode,
                    'current_stock' => $current,
                    'calculated_stock' => $calculated,
                    'difference' => $difference,
                ], 'critical', 'stock_mismatch', 'El stock físico no coincide con la suma de movimientos históricos.', 'Revisar movimientos y saldo inicial antes de cualquier corrección.');
            }

            if ($current < 0) {
                $negativeStock[] = $this->issue([
                    'branch_id' => $row->branch_id,
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'current_stock' => $current,
                    'allow_negative_stock' => $allowsNegativeStock,
                ], $allowsNegativeStock ? 'warning' : 'critical', 'negative_stock', 'El producto tiene existencia física negativa.', 'Revisar la operación que dejó la existencia en negativo.');
            }

            if (! $row->is_active && $current > 0) {
                $stockIssues[] = $this->issue([
                    'business_id' => $businessId,
                    'branch_id' => $row->branch_id,
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'barcode' => $row->barcode,
                    'current_stock' => $current,
                    'calculated_stock' => $calculated,
                    'difference' => $difference,
                ], 'warning', 'inactive_product_with_stock', 'Producto inactivo con stock positivo.', 'Revisar si debe reactivarse o agotarse mediante un ajuste autorizado.');
            }
        }

        $validTypes = ['sale', 'sale_cancel', 'purchase', 'transfer_out', 'transfer_in', 'entry', 'exit', 'add', 'remove', 'adjustment', 'manual', 'initial', 'product_import_initial', 'product_import_increment'];
        $movements = DB::table('stock_movements as sm')
            ->leftJoin('products as p', 'p.id', '=', 'sm.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'sm.branch_id')
            ->where('sm.business_id', $businessId)
            ->when($branchId, fn (Builder $query) => $query->where('sm.branch_id', $branchId))
            ->select('sm.*', 'p.business_id as product_business_id', 'b.business_id as branch_business_id')
            ->orderBy('sm.id')
            ->cursor();

        foreach ($movements as $movement) {
            $base = [
                'business_id' => $businessId,
                'branch_id' => $movement->branch_id,
                'product_id' => $movement->product_id,
                'product_name' => null,
                'barcode' => null,
                'current_stock' => null,
                'calculated_stock' => null,
                'difference' => null,
            ];

            if ($movement->product_business_id === null) {
                $stockIssues[] = $this->issue($base, 'critical', 'orphan_stock_movement', "Movimiento #{$movement->id} sin producto válido.", 'Revisar la referencia histórica; no borrar el movimiento.');
            } elseif ((int) $movement->product_business_id !== $businessId || ($movement->branch_business_id !== null && (int) $movement->branch_business_id !== $businessId)) {
                $stockIssues[] = $this->issue($base, 'critical', 'cross_tenant_stock_movement', "Movimiento #{$movement->id} referencia producto o sucursal de otro negocio.", 'Requiere revisión manual de aislamiento tenant.');
            } elseif ($movement->branch_id === null) {
                $stockIssues[] = $this->issue($base, 'critical', 'stock_movement_without_branch', "Movimiento #{$movement->id} no tiene sucursal.", 'Asignar sucursal solo tras revisar el origen del movimiento.');
            } elseif ((float) $movement->quantity === 0) {
                if ($this->isManualStockAdjustment($movement->type)) {
                    if ($context['strict']) {
                        $stockIssues[] = $this->issue($base, 'info', 'zero_quantity_manual_adjustment', "Movimiento manual #{$movement->id} no tuvo impacto en stock.", 'Hallazgo informativo; no requiere corrección operativa.');
                    }
                } else {
                    $stockIssues[] = $this->issue($base, 'warning', 'zero_quantity_stock_movement', "Movimiento #{$movement->id} tiene cantidad cero.", 'Revisar si debe conservarse como evidencia o corregirse manualmente.');
                }
            } elseif (! in_array($movement->type, $validTypes, true)) {
                $stockIssues[] = $this->issue($base, 'warning', 'invalid_stock_movement_type', "Movimiento #{$movement->id} usa tipo no reconocido: {$movement->type}.", 'Verificar la procedencia y documentar o normalizar en una reparación posterior.');
            }
        }

        foreach (DB::table('stock_movements')
            ->where('business_id', $businessId)
            ->where('type', 'purchase')
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->select('branch_id', 'product_id', 'note', DB::raw('COUNT(*) as movement_count'), DB::raw('SUM(quantity) as movement_quantity'))
            ->groupBy('branch_id', 'product_id', 'note')
            ->havingRaw('COUNT(*) > 1')
            ->get() as $duplicate) {
            $stockIssues[] = $this->issue([
                'business_id' => $businessId,
                'branch_id' => $duplicate->branch_id,
                'product_id' => $duplicate->product_id,
                'product_name' => null,
                'barcode' => null,
                'current_stock' => null,
                'calculated_stock' => null,
                'difference' => null,
            ], 'critical', 'purchase_stock_increased_more_than_once', "{$duplicate->movement_count} movimientos de compra con la misma referencia para el mismo producto.", 'Comparar compra e idempotencia antes de revertir stock.');
        }

        foreach (Sale::query()->where('business_id', $businessId)->where('status', 'cancelled')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->with('items')->cursor() as $sale) {
            foreach ($sale->items as $item) {
                $reversed = (float) DB::table('stock_movements')
                    ->where('business_id', $businessId)
                    ->where('branch_id', $sale->branch_id)
                    ->where('product_id', $item->product_id)
                    ->where('type', 'sale_cancel')
                    ->where('note', stockMovementNote('sale_cancel', $sale->business_number ?: $sale->id))
                    ->sum('quantity');

                if ($reversed + 0.001 < (float) $item->quantity) {
                    $stockIssues[] = $this->issue([
                        'business_id' => $businessId,
                        'branch_id' => $sale->branch_id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'barcode' => null,
                        'current_stock' => null,
                        'calculated_stock' => null,
                        'difference' => round((float) $item->quantity - $reversed, 2),
                    ], 'critical', 'cancelled_sale_stock_not_reversed', "Venta anulada #{$sale->id} no tiene reversa de stock completa.", 'Revisar la anulación antes de aplicar cualquier reversa manual.');
                }
            }
        }

        foreach (DB::table('route_operation_returns as operation_return')
            ->join('sales as sale', 'sale.id', '=', 'operation_return.sale_id')
            ->where('operation_return.business_id', $businessId)
            ->where('operation_return.status', 'completed')
            ->when($branchId, fn (Builder $query) => $query->where('operation_return.branch_id', $branchId))
            ->select('operation_return.id', 'operation_return.business_id', 'operation_return.branch_id', 'operation_return.sale_id', 'sale.business_number', 'sale.status as sale_status', 'sale.business_id as sale_business_id', 'sale.branch_id as sale_branch_id')
            ->cursor() as $operationReturn) {
            $expected = DB::table('sale_items')->where('sale_id', $operationReturn->sale_id)
                ->select('product_id', DB::raw('COUNT(*) as line_count'), DB::raw('SUM(quantity) as quantity'))
                ->groupBy('product_id')->get()->keyBy('product_id');
            $actual = DB::table('stock_movements')->where('route_operation_return_id', $operationReturn->id)
                ->select('product_id', 'business_id', 'branch_id', 'type', 'note', DB::raw('COUNT(*) as movement_count'), DB::raw('SUM(quantity) as quantity'))
                ->groupBy('product_id', 'business_id', 'branch_id', 'type', 'note')->get();
            $validNote = stockMovementNote('sale_cancel', $operationReturn->business_number ?: $operationReturn->sale_id);
            $valid = $operationReturn->sale_status === 'cancelled'
                && (int) $operationReturn->sale_business_id === (int) $operationReturn->business_id
                && (int) $operationReturn->sale_branch_id === (int) $operationReturn->branch_id
                && $expected->isNotEmpty();
            foreach ($actual as $movement) {
                if ((int) $movement->business_id !== (int) $operationReturn->business_id
                    || (int) $movement->branch_id !== (int) $operationReturn->branch_id
                    || $movement->type !== 'sale_cancel' || $movement->note !== $validNote) {
                    $valid = false;
                }
            }
            foreach ($expected as $productId => $item) {
                $matches = $actual->where('product_id', $productId);
                if ((int) $matches->sum('movement_count') !== (int) $item->line_count
                    || abs((float) $matches->sum('quantity') - (float) $item->quantity) > 0.0001) {
                    $valid = false;
                }
            }
            if ($actual->pluck('product_id')->diff($expected->keys())->isNotEmpty()) {
                $valid = false;
            }
            if (! $valid) {
                $stockIssues[] = $this->issue([
                    'business_id' => $businessId, 'branch_id' => $operationReturn->branch_id,
                    'product_id' => null, 'product_name' => null, 'barcode' => null,
                    'current_stock' => null, 'calculated_stock' => null, 'difference' => null,
                ], 'critical', 'route_operation_return_stock_mismatch', "Devolución de ruta #{$operationReturn->id} no conserva exactamente la restauración causal de la venta #{$operationReturn->sale_id}.", 'Revisar la venta, la devolución y los movimientos causales sin alterar historia automáticamente.');
            }
        }

        return [$stockIssues, $negativeStock, $this->auditCreditReservations($context)];
    }

    private function auditSales(array $context): array
    {
        $issues = [];
        $businessId = $context['business_id'];
        $itemTotals = DB::table('sale_items')
            ->select('sale_id', DB::raw('COUNT(*) as item_count'), DB::raw('COALESCE(SUM(total), 0) as expected_total'))
            ->where('business_id', $businessId)
            ->groupBy('sale_id');
        $cashMovements = DB::table('cash_movements')
            ->select('reference_id', DB::raw("COALESCE(SUM(CASE WHEN type = 'sale_cash' THEN amount ELSE 0 END), 0) as cash_in"), DB::raw("COALESCE(SUM(CASE WHEN type = 'sale_cash_cancel' THEN amount ELSE 0 END), 0) as cash_cancel"))
            ->where('business_id', $businessId)
            ->where('reference_type', 'sale')
            ->groupBy('reference_id');
        $cashRefunds = DB::table('sale_refunds')
            ->select('sale_id', DB::raw('COALESCE(SUM(amount), 0) as refunded_cash'))
            ->where('business_id', $businessId)
            ->where('status', 'confirmed')
            ->groupBy('sale_id');
        $cashPayments = DB::table('sale_payments as sp')
            ->leftJoin('route_pre_sale_collections as pre_collection', 'pre_collection.id', '=', 'sp.route_pre_sale_collection_id')
            ->leftJoin('route_delivery_collections as delivery_collection', 'delivery_collection.id', '=', 'sp.route_delivery_collection_id')
            ->leftJoin('route_post_conversion_collections as post_collection', 'post_collection.id', '=', 'sp.route_post_conversion_collection_id')
            ->select('sp.sale_id', DB::raw("COALESCE(SUM(CASE WHEN sp.method = 'cash' THEN sp.amount ELSE 0 END), 0) as cash_paid"), DB::raw("COALESCE(SUM(CASE WHEN sp.method = 'cash' AND (pre_collection.custody_status = 'held_by_collector' OR delivery_collection.custody_status = 'held_by_collector' OR post_collection.custody_status = 'held_by_collector') THEN sp.amount ELSE 0 END), 0) as held_route_cash"))
            ->where('sp.business_id', $businessId)
            ->where('sp.status', 'captured')
            ->groupBy('sp.sale_id');
        $preSaleRouteCash = DB::table('cash_movements as cm')
            ->join('route_pre_sale_collections as collection', 'collection.id', '=', 'cm.reference_id')
            ->join('sale_payments as payment', 'payment.route_pre_sale_collection_id', '=', 'collection.id')
            ->where('cm.business_id', $businessId)->where('cm.reference_type', 'route_pre_sale_collection')->where('cm.type', 'sale_cash')
            ->select('payment.sale_id', 'cm.amount');
        $deliveryRouteCash = DB::table('cash_movements as cm')
            ->join('route_delivery_collections as collection', 'collection.id', '=', 'cm.reference_id')
            ->join('sale_payments as payment', 'payment.route_delivery_collection_id', '=', 'collection.id')
            ->where('cm.business_id', $businessId)->where('cm.reference_type', 'route_delivery_collection')->where('cm.type', 'sale_cash')
            ->where('payment.status', 'captured')->where('collection.status', 'captured')
            ->select('payment.sale_id', 'cm.amount');
        $settledPreSaleRouteCash = DB::table('route_cash_settlement_items as item')
            ->join('route_cash_settlements as settlement', 'settlement.id', '=', 'item.route_cash_settlement_id')
            ->join('cash_movements as cm', 'cm.id', '=', 'settlement.cash_movement_id')
            ->join('sale_payments as payment', 'payment.route_pre_sale_collection_id', '=', 'item.route_pre_sale_collection_id')
            ->where('settlement.business_id', $businessId)->where('settlement.status', 'confirmed')->where('item.is_active', true)
            ->where('cm.type', 'route_cash_settlement')->where('cm.reference_type', 'route_cash_settlement')
            ->whereColumn('cm.reference_id', 'settlement.id')
            ->select('payment.sale_id', 'item.amount_snapshot as amount');
        $settledDeliveryRouteCash = DB::table('route_cash_settlement_items as item')
            ->join('route_cash_settlements as settlement', 'settlement.id', '=', 'item.route_cash_settlement_id')
            ->join('cash_movements as cm', 'cm.id', '=', 'settlement.cash_movement_id')
            ->join('route_delivery_collections as collection', 'collection.id', '=', 'item.route_delivery_collection_id')
            ->join('sale_payments as payment', 'payment.route_delivery_collection_id', '=', 'item.route_delivery_collection_id')
            ->where('settlement.business_id', $businessId)->where('settlement.status', 'confirmed')->where('item.is_active', true)
            ->where('cm.type', 'route_cash_settlement')->where('cm.reference_type', 'route_cash_settlement')
            ->whereColumn('cm.reference_id', 'settlement.id')
            ->where('collection.status', 'captured')->where('payment.status', 'captured')
            ->select('payment.sale_id', 'item.amount_snapshot as amount');
        $postConversionRouteCash = DB::table('cash_movements as cm')
            ->join('route_post_conversion_collections as collection', 'collection.id', '=', 'cm.reference_id')
            ->join('sale_payments as payment', 'payment.route_post_conversion_collection_id', '=', 'collection.id')
            ->where('cm.business_id', $businessId)->where('cm.reference_type', 'route_post_conversion_collection')->where('cm.type', 'sale_cash')
            ->where('payment.status', 'captured')->where('collection.status', 'captured')
            ->select('payment.sale_id', 'cm.amount');
        $settledPostConversionRouteCash = DB::table('route_cash_settlement_items as item')
            ->join('route_cash_settlements as settlement', 'settlement.id', '=', 'item.route_cash_settlement_id')
            ->join('cash_movements as cm', 'cm.id', '=', 'settlement.cash_movement_id')
            ->join('route_post_conversion_collections as collection', 'collection.id', '=', 'item.route_post_conversion_collection_id')
            ->join('sale_payments as payment', 'payment.route_post_conversion_collection_id', '=', 'item.route_post_conversion_collection_id')
            ->where('settlement.business_id', $businessId)->where('settlement.status', 'confirmed')->where('item.is_active', true)
            ->where('cm.type', 'route_cash_settlement')->where('cm.reference_type', 'route_cash_settlement')
            ->whereColumn('cm.reference_id', 'settlement.id')->where('collection.status', 'captured')->where('payment.status', 'captured')
            ->select('payment.sale_id', 'item.amount_snapshot as amount');
        $routeCashMovements = DB::query()->fromSub($preSaleRouteCash->unionAll($deliveryRouteCash)->unionAll($settledPreSaleRouteCash)->unionAll($settledDeliveryRouteCash)->unionAll($postConversionRouteCash)->unionAll($settledPostConversionRouteCash), 'route_cash')
            ->select('sale_id', DB::raw('COALESCE(SUM(amount), 0) as cash_in'))
            ->groupBy('sale_id');

        $sales = DB::table('sales as s')
            ->leftJoinSub($itemTotals, 'items', fn ($join) => $join->on('items.sale_id', '=', 's.id'))
            ->leftJoinSub($cashMovements, 'cash', fn ($join) => $join->on('cash.reference_id', '=', 's.id'))
            ->leftJoinSub($cashRefunds, 'refunds', fn ($join) => $join->on('refunds.sale_id', '=', 's.id'))
            ->leftJoinSub($cashPayments, 'payments', fn ($join) => $join->on('payments.sale_id', '=', 's.id'))
            ->leftJoinSub($routeCashMovements, 'route_cash', fn ($join) => $join->on('route_cash.sale_id', '=', 's.id'))
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.business_id', $businessId)
            ->when($context['branch_id'], fn (Builder $q, $branch) => $q->where('s.branch_id', $branch))
            ->tap(fn (Builder $q) => $this->applyDates($q, 's.created_at', $context))
            ->select('s.*', DB::raw('COALESCE(items.item_count, 0) as item_count'), DB::raw('COALESCE(items.expected_total, 0) as expected_total'), DB::raw('COALESCE(cash.cash_in, 0) as cash_in'), DB::raw('COALESCE(cash.cash_cancel, 0) as cash_cancel'), DB::raw('COALESCE(refunds.refunded_cash, 0) as refunded_cash'), DB::raw('COALESCE(payments.cash_paid, 0) as cash_paid'), DB::raw('COALESCE(payments.held_route_cash, 0) as held_route_cash'), DB::raw('COALESCE(route_cash.cash_in, 0) as route_cash_in'), 'c.business_id as customer_business_id', 'b.business_id as branch_business_id')
            ->orderBy('s.id')
            ->cursor();

        foreach ($sales as $sale) {
            $base = [
                'sale_id' => $sale->id,
                'correlative' => $sale->business_number,
                'branch_id' => $sale->branch_id,
                'customer_id' => $sale->customer_id,
                'status' => $sale->status,
                'total' => (float) $sale->total,
                'expected_total' => (float) $sale->expected_total,
                'difference' => round((float) $sale->total - (float) $sale->expected_total, 2),
            ];

            if ((int) $sale->item_count === 0) {
                $issues[] = $this->issue($base, 'critical', 'sale_without_items', 'Venta sin líneas de venta.', 'Revisar la operación antes de anular o reconstruir líneas.');
            } elseif (abs((float) $base['difference']) >= 0.01) {
                $issues[] = $this->issue($base, abs((float) $base['difference']) <= 0.02 ? 'warning' : 'critical', 'sale_total_mismatch', 'El total de venta no coincide con la suma de sus líneas.', 'Revisar descuentos y totales de líneas antes de una corrección.');
            }

            if ($sale->customer_id && ((int) $sale->customer_business_id !== $businessId)) {
                $issues[] = $this->issue($base, 'critical', 'sale_customer_cross_tenant', 'La venta apunta a un cliente inexistente o de otro negocio.', 'Requiere revisión inmediata de aislamiento tenant.');
            }

            if ($sale->branch_id && ((int) $sale->branch_business_id !== $businessId)) {
                $issues[] = $this->issue($base, 'critical', 'sale_branch_cross_tenant', 'La venta apunta a una sucursal inexistente o de otro negocio.', 'Requiere revisión inmediata de aislamiento tenant.');
            }

            $cashExpected = (float) $sale->cash_paid > 0
                ? max(0, (float) $sale->cash_paid - (float) $sale->held_route_cash)
                : ($sale->payment_method === 'cash' && ! $sale->is_credit_sale ? (float) $sale->total : 0);
            $cashNet = round((float) $sale->cash_in + (float) $sale->route_cash_in + (float) $sale->cash_cancel - (float) $sale->refunded_cash, 2);

            if (($sale->status ?? 'completed') === 'cancelled' && $cashExpected > 0 && $cashNet > 0.001) {
                $issues[] = $this->issue($base, 'critical', 'cancelled_sale_cash_not_reversed', 'Venta anulada conserva efectivo activo en caja.', 'Revisar la reversa de caja asociada a la anulación.');
            } elseif (($sale->status ?? 'completed') !== 'cancelled' && $cashExpected > 0 && $cashNet + 0.001 < $cashExpected) {
                $issues[] = $this->issue($base, 'critical', 'cash_sale_without_cash_movement', 'Venta de contado sin movimiento de caja suficiente.', 'Revisar el cobro y su sesión de caja.');
            }

            if ($sale->is_credit_sale && ($sale->status ?? 'completed') !== 'cancelled' && ! DB::table('customer_account_movements')->where('business_id', $businessId)->where('sale_id', $sale->id)->where('type', 'charge')->exists()) {
                $issues[] = $this->issue($base, 'critical', 'credit_sale_without_ar_charge', 'Venta a crédito sin cargo inicial en cuentas por cobrar.', 'Revisar la transacción de venta y el ledger antes de generar un cargo manual.');
            }
        }

        foreach (DB::table('sale_items')->where('business_id', $businessId)->where(function (Builder $q) {
            $q->where('quantity', '<=', 0)->orWhere('total', '<', 0);
        })->orderBy('id')->cursor() as $item) {
            $issues[] = $this->issue([
                'sale_id' => $item->sale_id,
                'correlative' => null,
                'branch_id' => null,
                'customer_id' => null,
                'status' => null,
                'total' => (float) $item->total,
                'expected_total' => round((float) $item->quantity * (float) $item->unit_price - (float) $item->discount_amount, 2),
                'difference' => null,
            ], 'critical', 'invalid_sale_item', "Línea de venta #{$item->id} tiene cantidad o total inválido.", 'Revisar la línea y su venta antes de cualquier corrección.');
        }

        foreach (DB::table('sale_items')->where('business_id', $businessId)->orderBy('id')->cursor() as $item) {
            $expected = round((float) $item->quantity * (float) $item->unit_price - (float) $item->discount_amount, 2);
            if (abs((float) $item->total - $expected) < 0.01) {
                continue;
            }
            $issues[] = $this->issue([
                'sale_id' => $item->sale_id,
                'correlative' => null,
                'branch_id' => null,
                'customer_id' => null,
                'status' => null,
                'total' => (float) $item->total,
                'expected_total' => $expected,
                'difference' => round((float) $item->total - $expected, 2),
            ], 'critical', 'sale_item_total_mismatch', "Línea de venta #{$item->id} no coincide con cantidad, precio y descuento.", 'Revisar descuentos por línea antes de corregir totales.');
        }

        $duplicates = $this->salesDuplicateAuditor->audit([
            'business' => $businessId,
            'branch' => $context['branch_id'],
            'from' => $context['from']?->toDateString(),
            'to' => $context['to']?->toDateString(),
        ]);

        foreach ($duplicates['groups'] as $group) {
            $issues[] = $this->issue([
                'sale_id' => implode('|', $group['sale_ids']),
                'correlative' => implode('|', $group['business_numbers']),
                'branch_id' => null,
                'customer_id' => null,
                'status' => null,
                'total' => (float) $group['total'],
                'expected_total' => (float) $group['total'],
                'difference' => 0,
            ], 'critical', 'suspected_duplicate_sale', 'Grupo detectado por sales:audit-duplicates.', $group['recommendation']);
        }

        $issues = [...$issues, ...$this->auditDeliveryRuns($context)];

        return $issues;
    }

    private function auditDeliveryRuns(array $context): array
    {
        if (! Schema::hasTable('route_delivery_runs') || ! Schema::hasTable('route_delivery_stops')) {
            return [];
        }

        $issues = []; $businessId = $context['business_id'];
        foreach (DB::table('route_delivery_runs as r')->leftJoin('route_delivery_stops as st', 'st.route_delivery_run_id', '=', 'r.id')->where('r.business_id', $businessId)->when($context['branch_id'], fn ($q, $branch) => $q->where('r.branch_id', $branch))->groupBy('r.id', 'r.business_id', 'r.branch_id', 'r.status')->select('r.id', 'r.business_id', 'r.branch_id', 'r.status', DB::raw("COUNT(*) FILTER (WHERE st.status = 'pending') AS pending"))->cursor() as $run) {
            $base = ['sale_id' => null, 'correlative' => null, 'branch_id' => $run->branch_id, 'customer_id' => null, 'status' => $run->status, 'total' => null, 'expected_total' => null, 'difference' => null, 'run_id' => $run->id];
            if ($run->status === 'closed' && (int) $run->pending > 0) $issues[] = $this->issue($base, 'critical', 'delivery_run_closed_with_pending_stops', 'Jornada cerrada con paradas pendientes.', 'Reabrir mediante el flujo administrativo futuro o revisar la integridad.');
        }
        foreach (DB::table('route_delivery_stops as st')->join('route_delivery_runs as r', 'r.id', '=', 'st.route_delivery_run_id')->join('route_delivery_batch_pre_sales as e', 'e.id', '=', 'st.route_delivery_batch_pre_sale_id')->join('route_delivery_batches as b', 'b.id', '=', 'e.route_delivery_batch_id')->where('st.business_id', $businessId)->where(function ($q) { $q->whereColumn('st.business_id', '<>', 'r.business_id')->orWhereColumn('st.branch_id', '<>', 'r.branch_id')->orWhereColumn('st.business_id', '<>', 'b.business_id')->orWhereColumn('st.branch_id', '<>', 'b.branch_id')->orWhere('b.delivery_tracking_snapshot', '<>', 'in_app')->orWhere('st.delivery_tracking_snapshot', '<>', 'in_app')->orWhere('r.delivery_tracking_snapshot', '<>', 'in_app')->orWhereColumn('st.collection_responsibility_snapshot', '<>', 'b.collection_responsibility_snapshot')->orWhereColumn('st.collection_responsibility_snapshot', '<>', 'r.collection_responsibility_snapshot'); })->select('st.id', 'st.branch_id')->cursor() as $stop) {
            $issues[] = $this->issue(['sale_id' => null, 'correlative' => null, 'branch_id' => $stop->branch_id, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null, 'stop_id' => $stop->id], 'critical', 'delivery_stop_snapshot_or_scope_mismatch', 'Parada de entrega incompatible con su jornada o lote documental.', 'Revisar aislamiento, snapshots y asignación.');
        }

        foreach (DB::table('route_delivery_stops')->where('business_id', $businessId)->select('route_delivery_batch_pre_sale_id', DB::raw('COUNT(*) AS duplicated'))->groupBy('route_delivery_batch_pre_sale_id')->havingRaw('COUNT(*) > 1')->cursor() as $duplicate) {
            $issues[] = $this->issue(['sale_id' => null, 'correlative' => null, 'branch_id' => null, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null, 'route_delivery_batch_pre_sale_id' => $duplicate->route_delivery_batch_pre_sale_id], 'critical', 'duplicate_delivery_stop', 'Un comprobante tiene más de una parada de entrega dentro de Blunk.', 'Conservar una única asignación operativa y revisar concurrencia.');
        }

        foreach (DB::table('route_delivery_stops as st')->join('route_external_delivery_reconciliation_items as er', 'er.route_delivery_batch_pre_sale_id', '=', 'st.route_delivery_batch_pre_sale_id')->where('st.business_id', $businessId)->select('st.id', 'st.branch_id')->cursor() as $duplicate) {
            $issues[] = $this->issue(['sale_id' => null, 'correlative' => null, 'branch_id' => $duplicate->branch_id, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null, 'stop_id' => $duplicate->id], 'critical', 'external_and_in_app_delivery', 'Un comprobante aparece en conciliación externa y entrega dentro de Blunk.', 'Mantener una sola fuente de resultado físico.');
        }

        if (! Schema::hasTable('route_delivery_collections')) {
            return $issues;
        }

        foreach (DB::table('route_delivery_collections')->where('business_id', $businessId)->where(function ($q) { $q->where(function ($q) { $q->where('delivery_origin', 'external_reconciliation')->where(function ($q) { $q->whereNull('route_external_delivery_reconciliation_item_id')->orWhereNotNull('route_delivery_stop_id'); }); })->orWhere(function ($q) { $q->where('delivery_origin', 'in_app_stop')->where(function ($q) { $q->whereNull('route_delivery_stop_id')->orWhereNotNull('route_external_delivery_reconciliation_item_id'); }); })->orWhereNotIn('delivery_origin', ['external_reconciliation', 'in_app_stop']); })->select('id', 'branch_id')->cursor() as $collection) {
            $issues[] = $this->issue(['sale_id' => null, 'correlative' => null, 'branch_id' => $collection->branch_id, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null, 'route_delivery_collection_id' => $collection->id], 'critical', 'delivery_collection_invalid_origin', 'Cobro de entrega sin un único origen válido.', 'Restaurar exactamente un origen con integridad referencial.');
        }

        foreach (DB::table('route_delivery_collections')->where('business_id', $businessId)->where('status', 'captured')->select('sale_id', 'branch_id', DB::raw('COUNT(*) AS duplicated'))->groupBy('sale_id', 'branch_id')->havingRaw('COUNT(*) > 1')->cursor() as $duplicate) {
            $issues[] = $this->issue(['sale_id' => $duplicate->sale_id, 'correlative' => null, 'branch_id' => $duplicate->branch_id, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null], 'critical', 'duplicate_delivery_collection_for_sale', 'Una venta tiene más de un cobro de entrega.', 'Mantener un único cobro postventa por venta.');
        }

        foreach (DB::table('sale_payments')->whereNotNull('route_delivery_collection_id')->select('route_delivery_collection_id', DB::raw('COUNT(*) AS duplicated'))->groupBy('route_delivery_collection_id')->havingRaw('COUNT(*) > 1')->cursor() as $duplicate) {
            $issues[] = $this->issue(['sale_id' => null, 'correlative' => null, 'branch_id' => null, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null, 'route_delivery_collection_id' => $duplicate->route_delivery_collection_id], 'critical', 'duplicate_sale_payment_for_delivery_collection', 'Un cobro de entrega tiene más de un pago financiero.', 'Mantener un único sale_payment por cobro de entrega.');
        }

        foreach (DB::table('route_delivery_collections as collection')
            ->where('collection.business_id', $businessId)
            ->where('collection.payment_method', 'cash')
            ->where('collection.cash_posting_state', 'posted_to_current_session')
            ->whereNull('collection.cash_movement_id')
            ->whereNotExists(function (Builder $query) {
                $query->selectRaw('1')
                    ->from('route_cash_settlement_items as item')
                    ->join('route_cash_settlements as settlement', 'settlement.id', '=', 'item.route_cash_settlement_id')
                    ->whereColumn('item.route_delivery_collection_id', 'collection.id')
                    ->where('item.is_active', true)
                    ->where('settlement.status', 'confirmed');
            })
            ->select('collection.id', 'collection.branch_id')->cursor() as $collection) {
            $issues[] = $this->issue(['sale_id' => null, 'correlative' => null, 'branch_id' => $collection->branch_id, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null, 'route_delivery_collection_id' => $collection->id], 'critical', 'posted_route_cash_without_movement', 'Efectivo de ruta marcado como recibido en caja sin movimiento de caja.', 'Revisar la recepción física actual y el movimiento vinculado.');
        }

        foreach (DB::table('route_delivery_runs as r')->leftJoin('users as u', 'u.id', '=', 'r.delivery_user_id')->where('r.business_id', $businessId)->where(function ($q) { $q->whereNull('u.id')->orWhereColumn('u.business_id', '<>', 'r.business_id')->orWhereColumn('u.current_branch_id', '<>', 'r.branch_id'); })->select('r.id', 'r.branch_id')->cursor() as $run) {
            $issues[] = $this->issue(['sale_id' => null, 'correlative' => null, 'branch_id' => $run->branch_id, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null, 'run_id' => $run->id], 'critical', 'delivery_run_delivery_user_scope_mismatch', 'El entregador asignado no pertenece al negocio o sucursal de la jornada.', 'Reasignar mediante el flujo administrativo autorizado.');
        }
        if (Schema::hasTable('route_pending_collection_cases')) {
            foreach (DB::table('route_pending_collection_cases as pc')
                ->join('sales as s', 's.id', '=', 'pc.sale_id')
                ->where('pc.business_id', $businessId)
                ->where(function ($q) {
                    $q->where(function ($q) { $q->where('pc.status', 'open')->where(function ($q) { $q->where('s.payment_status', '<>', 'unpaid')->orWhere('s.amount_paid', '<>', 0); }); })
                        ->orWhere(function ($q) { $q->where('pc.status', 'resolved')->where('s.payment_status', '<>', 'paid'); });
                })
                ->select('pc.id', 'pc.sale_id', 'pc.branch_id', 'pc.status')->cursor() as $case) {
                $issues[] = $this->issue(['sale_id' => $case->sale_id, 'correlative' => null, 'branch_id' => $case->branch_id, 'customer_id' => null, 'status' => $case->status, 'total' => null, 'expected_total' => null, 'difference' => null, 'route_pending_collection_case_id' => $case->id], 'critical', 'pending_collection_case_financial_state_mismatch', 'El case operativo no coincide con el estado financiero de la venta.', 'Revisar collection, sale_payment y la transición del case.');
            }
            foreach (DB::table('route_pending_collection_cases as pc')->join('route_delivery_collections as dc', 'dc.sale_id', '=', 'pc.sale_id')->where('dc.status', 'captured')->where('pc.business_id', $businessId)->where('pc.status', 'open')->select('pc.id', 'pc.sale_id', 'pc.branch_id')->cursor() as $case) {
                $issues[] = $this->issue(['sale_id' => $case->sale_id, 'correlative' => null, 'branch_id' => $case->branch_id, 'customer_id' => null, 'status' => 'open', 'total' => null, 'expected_total' => null, 'difference' => null, 'route_pending_collection_case_id' => $case->id], 'critical', 'pending_collection_open_with_delivery_collection', 'Case abierto aunque la venta ya tiene un cobro postventa.', 'Resolver el case usando el cobro real; no crear un segundo pago.');
            }
            foreach (DB::table('route_pending_collection_cases as pc')->leftJoin('route_delivery_collections as dc', 'dc.id', '=', 'pc.resolution_route_delivery_collection_id')->where('pc.business_id', $businessId)->where('pc.status', 'resolved')->where(function ($q) { $q->whereNull('dc.id')->orWhereColumn('dc.sale_id', '<>', 'pc.sale_id')->orWhereColumn('dc.business_id', '<>', 'pc.business_id')->orWhereColumn('dc.branch_id', '<>', 'pc.branch_id'); })->select('pc.id', 'pc.sale_id', 'pc.branch_id')->cursor() as $case) {
                $issues[] = $this->issue(['sale_id' => $case->sale_id, 'correlative' => null, 'branch_id' => $case->branch_id, 'customer_id' => null, 'status' => 'resolved', 'total' => null, 'expected_total' => null, 'difference' => null, 'route_pending_collection_case_id' => $case->id], 'critical', 'pending_collection_invalid_resolution_collection', 'El cobro que resuelve el case no corresponde a su venta o alcance.', 'Restaurar el vínculo al único route_delivery_collection financiero correcto.');
            }
            foreach (DB::table('route_pending_collection_cases as pc')->leftJoin('route_external_delivery_reconciliation_items as er', 'er.id', '=', 'pc.route_external_delivery_reconciliation_item_id')->where('pc.business_id', $businessId)->where('pc.delivery_origin', 'external_reconciliation')->where(function ($q) { $q->whereNull('er.id')->orWhereColumn('er.business_id', '<>', 'pc.business_id')->orWhereColumn('er.branch_id', '<>', 'pc.branch_id')->orWhere('er.delivery_tracking_snapshot', '<>', 'external')->orWhere('er.collection_responsibility_snapshot', '<>', 'delivery_agent')->orWhere('er.delivery_status', '<>', 'delivered'); })->select('pc.id', 'pc.sale_id', 'pc.branch_id')->cursor() as $case) {
                $issues[] = $this->issue(['sale_id' => $case->sale_id, 'correlative' => null, 'branch_id' => $case->branch_id, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null, 'route_pending_collection_case_id' => $case->id], 'critical', 'pending_collection_external_origin_mismatch', 'Case external con origen, custodia o resultado físico incompatible.', 'Revisar el origen físico sin reescribir pagos.');
            }
            foreach (DB::table('route_pending_collection_cases as pc')->leftJoin('route_delivery_stops as st', 'st.id', '=', 'pc.route_delivery_stop_id')->where('pc.business_id', $businessId)->where('pc.delivery_origin', 'in_app_stop')->where(function ($q) { $q->whereNull('st.id')->orWhereColumn('st.business_id', '<>', 'pc.business_id')->orWhereColumn('st.branch_id', '<>', 'pc.branch_id')->orWhere('st.delivery_tracking_snapshot', '<>', 'in_app')->orWhere('st.collection_responsibility_snapshot', '<>', 'delivery_agent')->orWhere('st.status', '<>', 'delivered'); })->select('pc.id', 'pc.sale_id', 'pc.branch_id')->cursor() as $case) {
                $issues[] = $this->issue(['sale_id' => $case->sale_id, 'correlative' => null, 'branch_id' => $case->branch_id, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null, 'route_pending_collection_case_id' => $case->id], 'critical', 'pending_collection_in_app_origin_mismatch', 'Case in-app con origen, custodia o resultado físico incompatible.', 'Revisar el origen físico sin reescribir pagos.');
            }
            foreach (DB::table('route_pending_collection_cases as pc')->join('sales as s', 's.id', '=', 'pc.sale_id')->leftJoin('route_external_delivery_reconciliation_items as er', 'er.id', '=', 'pc.route_external_delivery_reconciliation_item_id')->leftJoin('route_delivery_stops as st', 'st.id', '=', 'pc.route_delivery_stop_id')->where('pc.business_id', $businessId)->where('pc.status', 'not_applicable')->where('s.payment_status', 'unpaid')->where('s.amount_paid', 0)->where(function ($q) { $q->where(function ($q) { $q->where('pc.delivery_origin', 'external_reconciliation')->where('er.delivery_status', 'delivered')->where('er.delivery_tracking_snapshot', 'external')->where('er.collection_responsibility_snapshot', 'delivery_agent'); })->orWhere(function ($q) { $q->where('pc.delivery_origin', 'in_app_stop')->where('st.status', 'delivered')->where('st.delivery_tracking_snapshot', 'in_app')->where('st.collection_responsibility_snapshot', 'delivery_agent'); }); })->select('pc.id', 'pc.sale_id', 'pc.branch_id')->cursor() as $case) {
                $issues[] = $this->issue(['sale_id' => $case->sale_id, 'correlative' => null, 'branch_id' => $case->branch_id, 'customer_id' => null, 'status' => 'not_applicable', 'total' => null, 'expected_total' => null, 'difference' => null, 'route_pending_collection_case_id' => $case->id], 'critical', 'pending_collection_stale_not_applicable', 'La entrega volvió a ser elegible pero el case sigue no aplicable.', 'Reabrir sólo mediante la corrección física auditada.');
            }
            if (Schema::hasTable('route_pending_collection_events')) {
                foreach (DB::table('route_pending_collection_events as ev')->join('route_pending_collection_cases as pc', 'pc.id', '=', 'ev.route_pending_collection_case_id')->where('pc.business_id', $businessId)->where(function ($q) { $q->whereColumn('ev.business_id', '<>', 'pc.business_id')->orWhereColumn('ev.branch_id', '<>', 'pc.branch_id'); })->select('ev.id', 'pc.sale_id', 'pc.branch_id')->cursor() as $event) {
                    $issues[] = $this->issue(['sale_id' => $event->sale_id, 'correlative' => null, 'branch_id' => $event->branch_id, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null, 'route_pending_collection_event_id' => $event->id], 'critical', 'pending_collection_event_scope_mismatch', 'Evento de seguimiento fuera del negocio o sucursal de su case.', 'Restaurar el alcance del evento; no alterar el historial silenciosamente.');
                }
            }
            foreach (DB::table('route_pending_collection_cases as pc')->join('route_delivery_collections as dc', 'dc.id', '=', 'pc.resolution_route_delivery_collection_id')->leftJoin('route_external_delivery_reconciliation_items as er', 'er.id', '=', 'dc.route_external_delivery_reconciliation_item_id')->leftJoin('route_delivery_stops as st', 'st.id', '=', 'dc.route_delivery_stop_id')->where('pc.business_id', $businessId)->where('pc.status', 'resolved')->where(function ($q) { $q->where(function ($q) { $q->where('dc.delivery_origin', 'external_reconciliation')->where('er.delivery_status', '<>', 'delivered'); })->orWhere(function ($q) { $q->where('dc.delivery_origin', 'in_app_stop')->where('st.status', '<>', 'delivered'); }); })->select('pc.id', 'pc.sale_id', 'pc.branch_id')->cursor() as $case) {
                $issues[] = $this->issue(['sale_id' => $case->sale_id, 'correlative' => null, 'branch_id' => $case->branch_id, 'customer_id' => null, 'status' => 'resolved', 'total' => null, 'expected_total' => null, 'difference' => null, 'route_pending_collection_case_id' => $case->id], 'critical', 'pending_collection_resolved_from_not_delivered_origin', 'Cobro posterior resuelve un origen físico no entregado.', 'Requiere futura reversión administrativa; no crear un nuevo pago.');
            }
            foreach (DB::table('route_external_delivery_reconciliation_items as er')->join('sales as s', 's.id', '=', 'er.sale_id')->where('er.business_id', $businessId)->where('er.delivery_status', 'delivered')->where('er.collection_responsibility_snapshot', 'pre_seller')->where('s.payment_status', 'unpaid')->select('er.sale_id', 'er.branch_id')->cursor() as $sale) {
                $issues[] = $this->issue(['sale_id' => $sale->sale_id, 'correlative' => null, 'branch_id' => $sale->branch_id, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null], 'critical', 'pre_seller_delivered_unpaid_anomaly', 'Entrega física con responsabilidad pre_seller continúa sin pago.', 'Revisión administrativa: no convertirla automáticamente en CxC.');
            }
            foreach (DB::table('route_delivery_stops as st')->join('sales as s', 's.id', '=', 'st.sale_id')->where('st.business_id', $businessId)->where('st.status', 'delivered')->where('st.collection_responsibility_snapshot', 'pre_seller')->where('s.payment_status', 'unpaid')->select('st.sale_id', 'st.branch_id')->cursor() as $sale) {
                $issues[] = $this->issue(['sale_id' => $sale->sale_id, 'correlative' => null, 'branch_id' => $sale->branch_id, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null], 'critical', 'pre_seller_delivered_unpaid_anomaly', 'Entrega física con responsabilidad pre_seller continúa sin pago.', 'Revisión administrativa: no convertirla automáticamente en CxC.');
            }
            if (Schema::hasTable('customer_account_movements')) {
                foreach (DB::table('customer_account_movements as cam')->join('sales as s', 's.id', '=', 'cam.sale_id')->leftJoin('route_external_delivery_reconciliation_items as er', 'er.sale_id', '=', 's.id')->leftJoin('route_delivery_stops as st', 'st.sale_id', '=', 's.id')->where('cam.business_id', $businessId)->where(function ($q) { $q->where(function ($q) { $q->where('er.delivery_status', 'delivered')->where('er.collection_responsibility_snapshot', 'delivery_agent'); })->orWhere(function ($q) { $q->where('st.status', 'delivered')->where('st.collection_responsibility_snapshot', 'delivery_agent'); }); })->select('cam.id', 's.id as sale_id', 'cam.branch_id')->cursor() as $movement) {
                    $issues[] = $this->issue(['sale_id' => $movement->sale_id, 'correlative' => null, 'branch_id' => $movement->branch_id, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null, 'customer_account_movement_id' => $movement->id], 'critical', 'route_pending_collection_artificial_credit', 'Entrega de ruta pendiente fue vinculada a CxC contractual.', 'Eliminar sólo mediante futura reversión administrativa; no crear otro cobro.');
                }
            }
        }
        return [...$issues, ...$this->auditRouteDeliveryCollectionReversals($context)];
    }

    private function auditRouteDeliveryCollectionReversals(array $context): array
    {
        if (! Schema::hasTable('route_delivery_collection_reversals')) {
            return [];
        }

        $issues = []; $businessId = $context['business_id'];
        $base = fn ($row) => ['sale_id' => $row->sale_id ?? null, 'correlative' => null, 'branch_id' => $row->branch_id ?? null, 'customer_id' => null, 'status' => null, 'total' => null, 'expected_total' => null, 'difference' => null, 'route_delivery_collection_id' => $row->collection_id ?? null, 'route_delivery_collection_reversal_id' => $row->reversal_id ?? null];
        $add = function ($row, string $type, string $message, string $recommendation) use (&$issues, $base): void {
            $issues[] = $this->issue($base($row), 'critical', $type, $message, $recommendation);
        };

        foreach (DB::table('route_delivery_collections as c')->leftJoin('route_delivery_collection_reversals as r', 'r.route_delivery_collection_id', '=', 'c.id')->where('c.business_id', $businessId)->where('c.status', 'reversed')->whereNull('r.id')->select('c.id as collection_id', 'c.sale_id', 'c.branch_id')->cursor() as $row) {
            $add($row, 'reversed_delivery_collection_without_ledger', 'Cobro de entrega reversado sin ledger append-only.', 'Restaurar el ledger de reversa; no borrar la collection.');
        }
        foreach (DB::table('route_delivery_collection_reversals as r')->join('route_delivery_collections as c', 'c.id', '=', 'r.route_delivery_collection_id')->join('sale_payments as p', 'p.id', '=', 'r.sale_payment_id')->join('sales as s', 's.id', '=', 'c.sale_id')->leftJoin('route_delivery_collections as replacement', function ($join) {
            $join->on('replacement.sale_id', '=', 'c.sale_id')->where('replacement.status', '=', 'captured');
        })->leftJoin('sale_payments as replacement_payment', function ($join) {
            $join->on('replacement_payment.route_delivery_collection_id', '=', 'replacement.id')->where('replacement_payment.status', '=', 'captured');
        })->where('r.business_id', $businessId)->where(function ($q) {
            $q->where('c.status', '<>', 'reversed')->orWhere('p.status', '<>', 'reversed')->orWhereColumn('p.route_delivery_collection_id', '<>', 'c.id')->orWhereColumn('c.business_id', '<>', 'r.business_id')->orWhereColumn('c.branch_id', '<>', 'r.branch_id')->orWhereColumn('p.business_id', '<>', 'r.business_id')->orWhereColumn('s.business_id', '<>', 'r.business_id')->orWhereColumn('s.branch_id', '<>', 'r.branch_id')
                ->orWhere(function ($q) { $q->whereNull('replacement.id')->where(function ($q) { $q->where('s.payment_status', '<>', 'unpaid')->orWhere('s.amount_paid', '<>', 0)->orWhereNotNull('s.payment_method'); }); })
                ->orWhere(function ($q) { $q->whereNotNull('replacement.id')->where(function ($q) { $q->where('s.payment_status', '<>', 'paid')->orWhereColumn('s.amount_paid', '<>', 's.total')->orWhereNull('replacement_payment.id'); }); });
        })->select('r.id as reversal_id', 'c.id as collection_id', 'c.sale_id', 'c.branch_id')->cursor() as $row) {
            $add($row, 'delivery_collection_reversal_financial_mismatch', 'El ledger de reversa no coincide con collection, pago y proyección financiera.', 'Revisar la transacción de reversa sin generar pagos nuevos.');
        }
        foreach (DB::table('route_delivery_collections as c')->join('route_delivery_collection_reversals as r', 'r.route_delivery_collection_id', '=', 'c.id')->leftJoin('route_external_delivery_reconciliation_items as er', 'er.id', '=', 'c.route_external_delivery_reconciliation_item_id')->leftJoin('route_delivery_stops as st', 'st.id', '=', 'c.route_delivery_stop_id')->where('c.business_id', $businessId)->where(function ($q) {
            $q->where(function ($q) { $q->where('c.delivery_origin', 'external_reconciliation')->where(function ($q) { $q->whereNull('er.id')->orWhere('er.delivery_status', '<>', 'delivered')->orWhere('er.collection_responsibility_snapshot', '<>', 'delivery_agent'); }); })
                ->orWhere(function ($q) { $q->where('c.delivery_origin', 'in_app_stop')->where(function ($q) { $q->whereNull('st.id')->orWhere('st.status', '<>', 'delivered')->orWhere('st.collection_responsibility_snapshot', '<>', 'delivery_agent'); }); });
        })->select('r.id as reversal_id', 'c.id as collection_id', 'c.sale_id', 'c.branch_id')->cursor() as $row) {
            $add($row, 'delivery_collection_reversal_origin_mismatch', 'La reversa apunta a un origen físico que no es entrega realizada por delivery_agent.', 'Bloquear nuevas mutaciones y revisar el origen físico.');
        }
        foreach (DB::table('route_delivery_collection_reversals as r')->leftJoin('route_delivery_collections as original_collection', 'original_collection.id', '=', 'r.route_delivery_collection_id')->leftJoin('route_delivery_collections as replacement', function ($join) {
            $join->on('replacement.sale_id', '=', 'original_collection.sale_id')->where('replacement.status', '=', 'captured');
        })->leftJoin('route_pending_collection_cases as pc', 'pc.id', '=', 'r.route_pending_collection_case_id')->leftJoin('route_pending_collection_events as ev', function ($join) {
            $join->on('ev.route_pending_collection_case_id', '=', 'pc.id')->where('ev.type', '=', 'collection_reversed')->whereColumn('ev.business_id', '=', 'r.business_id')->whereColumn('ev.branch_id', '=', 'r.branch_id')->whereColumn('ev.recorded_by', '=', 'r.reversed_by')->whereColumn('ev.occurred_at', '=', 'r.reversed_at');
        })->where('r.business_id', $businessId)->groupBy('r.id', 'r.route_delivery_collection_id', 'r.business_id', 'r.branch_id', 'r.route_pending_collection_case_id', 'r.previous_case_resolved_by', 'r.previous_case_resolved_at', 'original_collection.sale_id', 'replacement.id', 'pc.id', 'pc.sale_id', 'pc.business_id', 'pc.branch_id', 'pc.status', 'pc.resolved_by', 'pc.resolved_at', 'pc.resolution_route_delivery_collection_id')->havingRaw("(r.route_pending_collection_case_id IS NULL AND (r.previous_case_resolved_by IS NOT NULL OR r.previous_case_resolved_at IS NOT NULL)) OR (r.route_pending_collection_case_id IS NOT NULL AND (pc.id IS NULL OR pc.business_id <> r.business_id OR pc.branch_id <> r.branch_id OR r.previous_case_resolved_by IS NULL OR r.previous_case_resolved_at IS NULL OR COUNT(ev.id) <> 1 OR (replacement.id IS NULL AND (pc.status <> 'open' OR pc.resolved_by IS NOT NULL OR pc.resolved_at IS NOT NULL OR pc.resolution_route_delivery_collection_id IS NOT NULL)) OR (replacement.id IS NOT NULL AND (pc.status <> 'resolved' OR pc.resolved_by IS NULL OR pc.resolved_at IS NULL OR pc.resolution_route_delivery_collection_id <> replacement.id))))")->select('r.id as reversal_id', 'r.route_delivery_collection_id as collection_id', 'pc.sale_id', 'r.branch_id')->cursor() as $row) {
            $add($row, 'delivery_collection_reversal_case_mismatch', 'La reapertura excepcional del case no conserva su post-state y evento interno requeridos.', 'Restaurar únicamente mediante una reparación auditada.');
        }
        foreach (DB::table('route_delivery_collection_reversals as r')->join('route_delivery_collections as c', 'c.id', '=', 'r.route_delivery_collection_id')->leftJoin('cash_movements as original', 'original.id', '=', 'c.cash_movement_id')->leftJoin('cash_register_sessions as original_session', 'original_session.id', '=', 'original.cash_register_session_id')->leftJoin('cash_movements as compensating', 'compensating.id', '=', 'r.compensating_cash_movement_id')->where('r.business_id', $businessId)->where(function ($q) {
            $q->where(function ($q) { $q->where('r.cash_correction_type', 'none')->where(function ($q) { $q->whereNotNull('r.compensating_cash_movement_id')->orWhere(function ($q) { $q->where('c.payment_method', 'cash')->where('c.custody_status', 'posted_to_branch_cash'); }); }); })
                ->orWhere(function ($q) { $q->where('r.cash_correction_type', 'historical_closed_session_ledger')->where(function ($q) { $q->whereNotNull('r.compensating_cash_movement_id')->orWhereNull('original.id')->orWhere('original_session.status', '<>', 'closed'); }); })
                ->orWhere(function ($q) { $q->where('r.cash_correction_type', 'current_open_session_adjustment')->where(function ($q) { $q->whereNull('compensating.id')->orWhere('compensating.amount', '>=', 0)->orWhere('compensating.type', '<>', 'route_delivery_collection_reversal_current_session')->orWhere('compensating.reference_type', '<>', 'route_delivery_collection_reversal')->orWhereColumn('compensating.reference_id', '<>', 'r.id')->orWhereColumn('compensating.cash_register_session_id', '<>', 'c.cash_register_session_id'); }); });
        })->select('r.id as reversal_id', 'c.id as collection_id', 'c.sale_id', 'c.branch_id')->cursor() as $row) {
            $add($row, 'delivery_collection_reversal_cash_mismatch', 'La corrección de caja de la reversa no corresponde a su política y sesión original.', 'No crear movimientos históricos; revisar el ledger y la sesión vigente.');
        }

        return $issues;
    }

    private function auditCash(array $context): array
    {
        $issues = [];
        $businessId = $context['business_id'];
        $movements = DB::table('cash_movements as cm')
            ->leftJoin('cash_register_sessions as crs', 'crs.id', '=', 'cm.cash_register_session_id')
            ->where('cm.business_id', $businessId)
            ->when($context['branch_id'], fn (Builder $q, $branch) => $q->where('cm.branch_id', $branch))
            ->tap(fn (Builder $q) => $this->applyDates($q, 'cm.created_at', $context))
            ->select('cm.*', 'crs.business_id as session_business_id', 'crs.branch_id as session_branch_id', 'crs.status as session_status', 'crs.closed_at')
            ->orderBy('cm.id')
            ->cursor();

        foreach ($movements as $movement) {
            $base = [
                'cash_register_id' => $movement->cash_register_session_id,
                'cash_movement_id' => $movement->id,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'amount' => (float) $movement->amount,
                'movement_type' => $movement->type,
            ];

            if ($movement->session_business_id === null || (int) $movement->session_business_id !== $businessId || ($movement->branch_id && (int) $movement->session_branch_id !== (int) $movement->branch_id)) {
                $issues[] = $this->issue($base, 'critical', 'cash_movement_session_mismatch', 'Movimiento de caja sin sesión válida o con negocio/sucursal distinta.', 'Revisar el vínculo de la sesión de caja.');
            } elseif ($movement->closed_at && Carbon::parse($movement->created_at)->gt(Carbon::parse($movement->closed_at))) {
                $issues[] = $this->issue($base, 'critical', 'cash_movement_after_close', 'Movimiento creado después del cierre de la caja.', 'Requiere revisión manual de la sesión y del movimiento.');
            }

            if ((float) $movement->amount === 0.0) {
                if ($this->isCashOpeningMovement($movement->type)) {
                    if ($context['strict']) {
                        $issues[] = $this->issue($base, 'info', 'zero_amount_cash_opening', 'Apertura de caja válida con monto inicial cero.', 'Hallazgo informativo; no requiere corrección operativa.');
                    }
                } else {
                    $issues[] = $this->issue($base, 'warning', 'zero_amount_cash_movement', 'Movimiento de caja con monto cero.', 'Revisar si debe conservarse como evidencia.');
                }
            }

            if ((float) $movement->amount < 0 && ! in_array($movement->type, ['purchase_cash', 'expense', 'sale_cash_cancel', 'sale_refund_cash', 'credit_payment_cash_cancel', 'closing_adjustment', 'route_cash_variance_overage_returned', 'route_delivery_collection_reversal_current_session'], true)) {
                $issues[] = $this->issue($base, 'warning', 'invalid_negative_cash_movement', 'Movimiento de caja negativo con tipo que no representa una salida o reversa válida.', 'Revisar tipo, referencia y evidencia del movimiento.');
            }

            if (! $movement->reference_type && ! in_array($movement->type, ['opening', 'open', 'apertura', 'closing_adjustment', 'expense'], true)) {
                $issues[] = $this->issue($base, 'warning', 'cash_movement_without_reference', 'Movimiento de caja sin referencia operativa.', 'Documentar o vincular la operación en una fase de reparación.');
            }
        }

        if (Schema::hasTable('route_delivery_collection_reversals')) {
            foreach (DB::table('cash_movements as cm')
                ->leftJoin('route_delivery_collection_reversals as reversal', function ($join) {
                    $join->on('reversal.compensating_cash_movement_id', '=', 'cm.id')
                        ->where('reversal.cash_correction_type', '=', 'current_open_session_adjustment');
                })
                ->leftJoin('route_delivery_collections as collection', 'collection.id', '=', 'reversal.route_delivery_collection_id')
                ->where('cm.business_id', $businessId)
                ->where('cm.type', 'route_delivery_collection_reversal_current_session')
                ->where(function ($q) {
                    $q->whereNull('reversal.id')->orWhere('cm.amount', '>=', 0)->orWhere('cm.reference_type', '<>', 'route_delivery_collection_reversal')->orWhereColumn('cm.reference_id', '<>', 'reversal.id')->orWhereColumn('cm.cash_register_session_id', '<>', 'collection.cash_register_session_id');
                })
                ->select('cm.id', 'cm.cash_register_session_id', 'cm.reference_type', 'cm.reference_id', 'cm.amount', 'cm.type')
                ->cursor() as $movement) {
                $issues[] = $this->issue([
                    'cash_register_id' => $movement->cash_register_session_id,
                    'cash_movement_id' => $movement->id,
                    'reference_type' => $movement->reference_type,
                    'reference_id' => $movement->reference_id,
                    'amount' => (float) $movement->amount,
                    'movement_type' => $movement->type,
                ], 'critical', 'route_delivery_collection_reversal_cash_movement_mismatch', 'Ajuste de caja de reversa sin ledger, signo, referencia o sesión original válidos.', 'No mover efectivo entre sesiones; restaurar el vínculo auditado.');
            }
        }

        foreach (DB::table('cash_movements')
            ->where('business_id', $businessId)
            ->whereIn('type', ['sale_cash', 'sale_refund_cash', 'purchase_cash', 'credit_payment_cash', 'route_cash_settlement', 'route_delivery_collection_reversal_current_session'])
            ->whereNotNull('reference_type')
            ->whereNotNull('reference_id')
            ->when($context['branch_id'], fn (Builder $q, $branch) => $q->where('branch_id', $branch))
            ->tap(fn (Builder $q) => $this->applyDates($q, 'created_at', $context))
            ->select('cash_register_session_id', 'reference_type', 'reference_id', 'type', DB::raw('COUNT(*) as duplicate_count'), DB::raw('SUM(amount) as amount'))
            ->groupBy('cash_register_session_id', 'reference_type', 'reference_id', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->get() as $duplicate) {
            $issues[] = $this->issue([
                'cash_register_id' => $duplicate->cash_register_session_id,
                'cash_movement_id' => null,
                'reference_type' => $duplicate->reference_type,
                'reference_id' => $duplicate->reference_id,
                'amount' => (float) $duplicate->amount,
                'movement_type' => $duplicate->type,
            ], 'critical', 'duplicate_cash_movement', "Se detectaron {$duplicate->duplicate_count} movimientos de caja para la misma operación.", 'Revisar idempotencia y no eliminar registros sin una reparación trazable.');
        }

        foreach (DB::table('customer_credit_payments')
            ->where('business_id', $businessId)
            ->where('payment_method', 'cash')
            ->when($context['branch_id'], fn (Builder $q, $branch) => $q->where('branch_id', $branch))
            ->tap(fn (Builder $q) => $this->applyDates($q, 'created_at', $context))
            ->orderBy('id')
            ->cursor() as $payment) {
            $cashNet = (float) DB::table('cash_movements')
                ->where('business_id', $businessId)
                ->where('reference_type', 'customer_credit_payment')
                ->where('reference_id', $payment->id)
                ->sum('amount');
            $base = [
                'cash_register_id' => $payment->cash_register_session_id,
                'cash_movement_id' => null,
                'reference_type' => 'customer_credit_payment',
                'reference_id' => $payment->id,
                'amount' => (float) $payment->amount,
                'movement_type' => 'credit_payment_cash',
            ];
            if ($payment->status === 'completed' && (! $payment->cash_register_session_id || $cashNet + 0.001 < (float) $payment->amount)) {
                $issues[] = $this->issue($base, 'critical', 'cash_credit_payment_without_cash_movement', 'Abono en efectivo sin caja abierta o sin ingreso de caja suficiente.', 'Revisar el abono y su sesión de caja.');
            }
            if ($payment->status === 'cancelled' && $cashNet > 0.001) {
                $issues[] = $this->issue($base, 'critical', 'cancelled_credit_payment_cash_not_reversed', 'Abono en efectivo anulado conserva ingreso activo en caja.', 'Revisar la reversa de caja del abono.');
            }
        }

        return [...$issues, ...$this->auditRouteCashSettlements($context)];
    }

    private function auditRouteCashSettlements(array $context): array
    {
        if (! Schema::hasTable('route_cash_settlements') || ! Schema::hasTable('route_cash_settlement_items')) {
            return [];
        }

        $issues = [];
        $businessId = $context['business_id'];
        $itemTotals = DB::table('route_cash_settlement_items')
            ->select('route_cash_settlement_id', DB::raw("COALESCE(SUM(CASE WHEN is_active THEN amount_snapshot ELSE 0 END), 0) AS expected_items"))
            ->groupBy('route_cash_settlement_id');
        $settlements = DB::table('route_cash_settlements as settlement')
            ->leftJoinSub($itemTotals, 'items', fn ($join) => $join->on('items.route_cash_settlement_id', '=', 'settlement.id'))
            ->leftJoin('cash_movements as movement', 'movement.id', '=', 'settlement.cash_movement_id')
            ->leftJoin('cash_register_sessions as session', 'session.id', '=', 'settlement.cash_register_session_id')
            ->where('settlement.business_id', $businessId)
            ->when($context['branch_id'], fn (Builder $query, $branch) => $query->where('settlement.branch_id', $branch))
            ->select('settlement.*', DB::raw('COALESCE(items.expected_items, 0) AS expected_items'), 'movement.business_id as movement_business_id', 'movement.branch_id as movement_branch_id', 'movement.cash_register_session_id as movement_session_id', 'movement.type as movement_type', 'movement.reference_type as movement_reference_type', 'movement.reference_id as movement_reference_id', 'movement.amount as movement_amount', 'session.business_id as session_business_id', 'session.branch_id as session_branch_id')
            ->orderBy('settlement.id')->cursor();

        foreach ($settlements as $settlement) {
            $base = ['cash_register_id' => $settlement->cash_register_session_id, 'cash_movement_id' => $settlement->cash_movement_id, 'reference_type' => 'route_cash_settlement', 'reference_id' => $settlement->id, 'amount' => (float) $settlement->expected_amount, 'movement_type' => 'route_cash_settlement'];
            if (round((float) $settlement->expected_amount, 2) !== round((float) $settlement->expected_items, 2)) {
                $issues[] = $this->issue($base, 'critical', 'route_cash_settlement_expected_mismatch', 'El importe esperado no coincide con la suma de sus items activos.', 'Revisar los items reservados sin modificar cobros históricos.');
            }
            if ($settlement->status === 'confirmed') {
                $validMovement = $settlement->cash_movement_id
                    && (int) $settlement->movement_business_id === $businessId
                    && (int) $settlement->movement_branch_id === (int) $settlement->branch_id
                    && (int) $settlement->movement_session_id === (int) $settlement->cash_register_session_id
                    && $settlement->movement_type === 'route_cash_settlement'
                    && $settlement->movement_reference_type === 'route_cash_settlement'
                    && (int) $settlement->movement_reference_id === (int) $settlement->id
                    && round((float) $settlement->movement_amount, 2) === round((float) $settlement->received_amount, 2)
                    && (int) $settlement->session_business_id === $businessId
                    && (int) $settlement->session_branch_id === (int) $settlement->branch_id;
                if (! $validMovement) {
                    $issues[] = $this->issue($base, 'critical', 'confirmed_route_cash_settlement_invalid_movement', 'Liquidación confirmada sin su único movimiento consolidado válido en la caja actual.', 'Revisar la cadena liquidación → movimiento de caja sin crear movimientos por cobro.');
                }
            } elseif ($settlement->cash_movement_id !== null) {
                $issues[] = $this->issue($base, 'critical', 'unconfirmed_route_cash_settlement_with_movement', 'Un borrador o una liquidación cancelada tiene movimiento de caja.', 'La liquidación debe confirmar antes de afectar caja.');
            }
        }

        foreach ([['route_pre_sale_collection_id', 'route_pre_sale_collections'], ['route_delivery_collection_id', 'route_delivery_collections'], ['route_post_conversion_collection_id', 'route_post_conversion_collections']] as [$column, $table]) {
            foreach (DB::table('route_cash_settlement_items as item')
                ->join('route_cash_settlements as settlement', 'settlement.id', '=', 'item.route_cash_settlement_id')
                ->where('settlement.business_id', $businessId)->where('item.is_active', true)->whereNotNull("item.{$column}")
                ->select("item.{$column}", DB::raw('COUNT(*) AS duplicated'))
                ->groupBy("item.{$column}")->havingRaw('COUNT(*) > 1')->cursor() as $duplicate) {
                $issues[] = $this->issue(['cash_register_id' => null, 'cash_movement_id' => null, 'reference_type' => 'route_cash_settlement_item', 'reference_id' => $duplicate->{$column}, 'amount' => null, 'movement_type' => 'route_cash_settlement'], 'critical', 'duplicate_active_route_cash_settlement_item', 'Un mismo cobro está reservado en más de una liquidación activa.', 'Mantener una única reserva activa por cobro físico.');
            }
            foreach (DB::table('route_cash_settlement_items as item')
                ->join('route_cash_settlements as settlement', 'settlement.id', '=', 'item.route_cash_settlement_id')
                ->join("{$table} as collection", 'collection.id', '=', "item.{$column}")
                ->where('settlement.business_id', $businessId)->where('item.is_active', true)
                ->where(function (Builder $query) {
                    $query->whereColumn('collection.business_id', '<>', 'settlement.business_id')
                        ->orWhereColumn('collection.branch_id', '<>', 'settlement.branch_id')
                        ->orWhereColumn('collection.collected_by', '<>', 'settlement.collector_user_id')
                        ->orWhere('collection.payment_method', '<>', 'cash');
                })->select('item.id')->cursor() as $item) {
                $issues[] = $this->issue(['cash_register_id' => null, 'cash_movement_id' => null, 'reference_type' => 'route_cash_settlement_item', 'reference_id' => $item->id, 'amount' => null, 'movement_type' => 'route_cash_settlement'], 'critical', 'route_cash_settlement_collection_scope_mismatch', 'Item de liquidación incompatible con negocio, sucursal, cobrador o efectivo.', 'Revisar la reserva y conservar la custodia física trazable.');
            }
        }

        foreach (DB::table('route_cash_settlement_items as item')
            ->join('route_cash_settlements as settlement', 'settlement.id', '=', 'item.route_cash_settlement_id')
            ->leftJoin('route_pre_sale_collections as pre', 'pre.id', '=', 'item.route_pre_sale_collection_id')
            ->leftJoin('route_delivery_collections as delivery', 'delivery.id', '=', 'item.route_delivery_collection_id')
            ->leftJoin('route_post_conversion_collections as post', 'post.id', '=', 'item.route_post_conversion_collection_id')
            ->where('settlement.business_id', $businessId)->where('settlement.status', 'confirmed')->where('item.is_active', true)
            ->where(function (Builder $query) {
                $query->where('pre.custody_status', '!=', 'posted_to_branch_cash')->orWhere('delivery.custody_status', '!=', 'posted_to_branch_cash')->orWhere('post.custody_status', '!=', 'posted_to_branch_cash');
            })->select('item.id')->cursor() as $item) {
            $issues[] = $this->issue(['cash_register_id' => null, 'cash_movement_id' => null, 'reference_type' => 'route_cash_settlement_item', 'reference_id' => $item->id, 'amount' => null, 'movement_type' => 'route_cash_settlement'], 'critical', 'confirmed_route_cash_settlement_collection_not_posted', 'Liquidación confirmada conserva un cobro bajo custodia del cobrador.', 'Revisar la transición de custodia en la misma transacción de confirmación.');
        }

        foreach ([['route_pre_sale_collections', 'route_pre_sale_collection_id'], ['route_delivery_collections', 'route_delivery_collection_id'], ['route_post_conversion_collections', 'route_post_conversion_collection_id']] as [$table, $column]) {
            foreach (DB::table("{$table} as collection")
                ->where('collection.business_id', $businessId)
                ->where('collection.payment_method', 'cash')->where('collection.custody_status', 'posted_to_branch_cash')->whereNull('collection.cash_movement_id')
                ->whereNotExists(function (Builder $query) use ($column) {
                    $query->selectRaw('1')->from('route_cash_settlement_items as item')
                        ->join('route_cash_settlements as settlement', 'settlement.id', '=', 'item.route_cash_settlement_id')
                        ->whereColumn("item.{$column}", 'collection.id')->where('item.is_active', true)->where('settlement.status', 'confirmed');
                })->select('collection.id', 'collection.branch_id')->cursor() as $collection) {
                $issues[] = $this->issue(['cash_register_id' => null, 'cash_movement_id' => null, 'reference_type' => 'route_cash_collection', 'reference_id' => $collection->id, 'amount' => null, 'movement_type' => 'route_cash_settlement'], 'critical', 'route_cash_collection_posted_without_confirmed_settlement', 'Efectivo de ruta marcado como ingresado sin movimiento propio ni liquidación confirmada.', 'Revisar la recepción física y mantener una sola cadena de custodia.');
            }
        }

        $issues = [...$issues, ...$this->auditRouteCashSettlementVariances($context)];

        return $issues;
    }

    private function auditRouteCashSettlementVariances(array $context): array
    {
        if (! Schema::hasTable('route_cash_settlement_variances')) {
            return [];
        }

        $issues = [];
        $businessId = $context['business_id'];
        $base = static fn ($variance, ?int $movementId = null): array => [
            'cash_register_id' => null,
            'cash_movement_id' => $movementId,
            'reference_type' => 'route_cash_settlement_variance',
            'reference_id' => $variance->id,
            'amount' => (float) $variance->difference_amount,
            'movement_type' => 'route_cash_settlement_variance',
        ];

        foreach (DB::table('route_cash_settlements as s')
            ->leftJoin('route_cash_settlement_variances as v', 'v.route_cash_settlement_id', '=', 's.id')
            ->where('s.business_id', $businessId)
            ->where('s.status', 'confirmed')
            ->where(function (Builder $query) {
                $query->where(function (Builder $query) { $query->where('s.difference_amount', '!=', 0)->whereNull('v.id'); })
                    ->orWhere(function (Builder $query) { $query->where('s.difference_amount', 0)->whereNotNull('v.id'); })
                    ->orWhereColumn('v.difference_amount', '!=', 's.difference_amount')
                    ->orWhereColumn('v.business_id', '!=', 's.business_id')
                    ->orWhereColumn('v.branch_id', '!=', 's.branch_id')
                    ->orWhereColumn('v.collector_user_id', '!=', 's.collector_user_id');
            })->select('s.id as settlement_id', 's.branch_id as settlement_branch_id', 's.difference_amount as settlement_difference', 'v.*')->cursor() as $row) {
            $issues[] = $this->issue([
                'cash_register_id' => null, 'cash_movement_id' => null, 'reference_type' => 'route_cash_settlement', 'reference_id' => $row->settlement_id,
                'amount' => (float) $row->settlement_difference, 'movement_type' => 'route_cash_settlement',
            ], 'critical', 'route_cash_settlement_variance_mismatch', 'La liquidación confirmada y su variance no son coherentes en diferencia, scope o cobrador.', 'Revisar la cadena settlement → variance sin alterar cobros de cliente.');
        }

        $varianceRows = DB::table('route_cash_settlement_variances as v')
            ->join('route_cash_settlements as s', 's.id', '=', 'v.route_cash_settlement_id')
            ->where('v.business_id', $businessId)
            ->select('v.*', 's.status as settlement_status', 's.business_id as settlement_business_id', 's.branch_id as settlement_branch_id', 's.collector_user_id as settlement_collector_user_id', 's.difference_amount as settlement_difference')
            ->cursor();
        foreach ($varianceRows as $variance) {
            $rowBase = $base($variance);
            if ($variance->settlement_status !== 'confirmed' || (int) $variance->branch_id !== (int) $variance->settlement_branch_id || (int) $variance->business_id !== (int) $variance->settlement_business_id || (int) $variance->collector_user_id !== (int) $variance->settlement_collector_user_id || round((float) $variance->difference_amount, 2) !== round((float) $variance->settlement_difference, 2)) {
                $issues[] = $this->issue($rowBase, 'critical', 'route_cash_settlement_variance_scope_mismatch', 'La variance no coincide con su liquidación confirmada.', 'Restaurar exclusivamente la relación y snapshots de custodia válidos.');
            }
            $resolved = (float) DB::table('route_cash_settlement_variance_resolutions')->where('variance_id', $variance->id)->sum('amount');
            $remaining = round(abs((float) $variance->difference_amount) - $resolved, 2);
            if (($variance->status === 'resolved' && $remaining !== 0.0) || ($variance->status === 'open' && $remaining === 0.0)) {
                $issues[] = $this->issue($rowBase, 'critical', 'route_cash_settlement_variance_status_balance_mismatch', 'El estado de la variance no coincide con su saldo físico derivado.', 'Revisar únicamente el ledger de resoluciones y su evidencia de caja.');
            }
            if ($remaining < 0) {
                $issues[] = $this->issue($rowBase, 'critical', 'route_cash_settlement_variance_over_resolved', 'Las resoluciones físicas superan la diferencia original.', 'No crear más movimientos; revisar la evidencia y futura reversión administrativa.');
            }
        }

        foreach (DB::table('route_cash_settlement_variance_resolutions as r')
            ->join('route_cash_settlement_variances as v', 'v.id', '=', 'r.variance_id')
            ->leftJoin('cash_movements as m', 'm.id', '=', 'r.cash_movement_id')
            ->leftJoin('cash_register_sessions as cs', 'cs.id', '=', 'r.cash_register_session_id')
            ->where('r.business_id', $businessId)
            ->select('r.*', 'v.difference_amount', 'v.collector_user_id', 'v.business_id as variance_business_id', 'v.branch_id as variance_branch_id', 'm.business_id as movement_business_id', 'm.branch_id as movement_branch_id', 'm.cash_register_session_id as movement_session_id', 'm.type as movement_type', 'm.amount as movement_amount', 'm.reference_type as movement_reference_type', 'm.reference_id as movement_reference_id', 'cs.business_id as session_business_id', 'cs.branch_id as session_branch_id')->cursor() as $resolution) {
            $signValid = (float) $resolution->difference_amount < 0
                ? $resolution->type === 'shortage_cash_received' && round((float) $resolution->movement_amount, 2) === round((float) $resolution->amount, 2) && $resolution->movement_type === 'route_cash_variance_shortage_received'
                : $resolution->type === 'overage_cash_returned' && round((float) $resolution->movement_amount, 2) === -round((float) $resolution->amount, 2) && $resolution->movement_type === 'route_cash_variance_overage_returned';
            $valid = $signValid
                && (int) $resolution->business_id === (int) $resolution->variance_business_id
                && (int) $resolution->branch_id === (int) $resolution->variance_branch_id
                && (int) $resolution->counterparty_user_id === (int) $resolution->collector_user_id
                && (int) $resolution->movement_business_id === (int) $resolution->variance_business_id
                && (int) $resolution->movement_branch_id === (int) $resolution->variance_branch_id
                && (int) $resolution->movement_session_id === (int) $resolution->cash_register_session_id
                && (int) $resolution->session_business_id === (int) $resolution->variance_business_id
                && (int) $resolution->session_branch_id === (int) $resolution->variance_branch_id
                && $resolution->movement_reference_type === 'route_cash_settlement_variance_resolution'
                && (int) $resolution->movement_reference_id === (int) $resolution->id;
            if (! $valid) {
                $issues[] = $this->issue(['cash_register_id' => $resolution->cash_register_session_id, 'cash_movement_id' => $resolution->cash_movement_id, 'reference_type' => 'route_cash_settlement_variance_resolution', 'reference_id' => $resolution->id, 'amount' => (float) $resolution->amount, 'movement_type' => $resolution->type], 'critical', 'route_cash_settlement_variance_invalid_resolution', 'La resolution no conserva un único movimiento, scope, signo, sesión, referencia o cobrador válidos.', 'Revisar la evidencia física sin modificar pagos de cliente.');
            }
        }

        return $issues;
    }

    private function auditAccountsReceivable(array $context): array
    {
        $issues = [];
        $businessId = $context['business_id'];
        $allocations = DB::table('customer_credit_payment_allocations as a')
            ->join('customer_credit_payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.business_id', $businessId)
            ->where('p.business_id', $businessId)
            ->where('p.status', 'completed')
            ->select('a.sale_id', DB::raw('COALESCE(SUM(a.amount), 0) as allocated'))
            ->groupBy('a.sale_id');
        $creditSales = DB::table('sales as s')
            ->leftJoinSub($allocations, 'allocations', fn ($join) => $join->on('allocations.sale_id', '=', 's.id'))
            ->where('s.business_id', $businessId)
            ->where('s.is_credit_sale', true)
            ->where('s.status', '!=', 'cancelled')
            ->when($context['branch_id'], fn (Builder $q, $branch) => $q->where('s.branch_id', $branch))
            ->tap(fn (Builder $q) => $this->applyDates($q, 's.created_at', $context))
            ->select('s.*', DB::raw('COALESCE(allocations.allocated, 0) as allocated'))
            ->orderBy('s.id')
            ->cursor();

        foreach ($creditSales as $sale) {
            $expected = round((float) $sale->total - (float) $sale->allocated, 2);
            $base = [
                'customer_id' => $sale->customer_id,
                'sale_id' => $sale->id,
                'payment_id' => null,
                'expected_balance' => $expected,
                'current_balance' => (float) $sale->credit_balance,
                'difference' => round((float) $sale->credit_balance - $expected, 2),
            ];

            if (abs((float) $base['difference']) >= 0.01) {
                $issues[] = $this->issue($base, 'critical', 'credit_sale_balance_mismatch', 'El saldo de la venta a crédito no coincide con sus abonos válidos.', 'Revisar allocations y movimientos de pago antes de corregir.');
            }

            if ((float) $sale->credit_balance < -0.001 || (float) $sale->amount_paid - (float) $sale->total > 0.001) {
                $issues[] = $this->issue($base, 'critical', 'credit_sale_overpaid', 'La venta a crédito tiene saldo negativo o abonos superiores al total.', 'Revisar allocations duplicadas o pagos anulados.');
            }

            if (! DB::table('customer_account_movements')->where('business_id', $businessId)->where('sale_id', $sale->id)->where('type', 'charge')->exists()) {
                $issues[] = $this->issue($base, 'critical', 'credit_sale_without_initial_charge', 'Venta a crédito sin movimiento AR inicial.', 'Revisar la transacción original antes de crear un cargo manual.');
            }
        }

        $ledger = DB::table('customer_account_movements')
            ->where('business_id', $businessId)
            ->whereNull('cancelled_at')
            ->select('customer_credit_account_id', DB::raw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount WHEN direction = 'credit' THEN -amount ELSE 0 END), 0) as expected_balance"))
            ->groupBy('customer_credit_account_id');
        foreach (DB::table('customer_credit_accounts as a')
            ->leftJoinSub($ledger, 'ledger', fn ($join) => $join->on('ledger.customer_credit_account_id', '=', 'a.id'))
            ->where('a.business_id', $businessId)
            ->select('a.*', DB::raw('COALESCE(ledger.expected_balance, 0) as expected_balance'))
            ->orderBy('a.id')
            ->cursor() as $account) {
            $difference = round((float) $account->current_balance - (float) $account->expected_balance, 2);

            if (abs($difference) >= 0.01) {
                $issues[] = $this->issue([
                    'customer_id' => $account->customer_id,
                    'sale_id' => null,
                    'payment_id' => null,
                    'expected_balance' => (float) $account->expected_balance,
                    'current_balance' => (float) $account->current_balance,
                    'difference' => $difference,
                ], 'critical', 'credit_account_balance_mismatch', 'El saldo de la cuenta no coincide con su ledger válido.', 'Revisar movimientos AR en orden cronológico antes de modificar el saldo.');
            }
        }

        foreach (DB::table('customer_credit_payment_allocations as a')
            ->leftJoin('customer_credit_payments as p', 'p.id', '=', 'a.payment_id')
            ->leftJoin('sales as s', 's.id', '=', 'a.sale_id')
            ->where('a.business_id', $businessId)
            ->where(function (Builder $q) use ($businessId) {
                $q->whereNull('p.id')->orWhereNull('s.id')->orWhere('p.business_id', '!=', $businessId)->orWhere('s.business_id', '!=', $businessId);
            })
            ->orderBy('a.id')
            ->cursor() as $allocation) {
            $issues[] = $this->issue([
                'customer_id' => null,
                'sale_id' => $allocation->sale_id,
                'payment_id' => $allocation->payment_id,
                'expected_balance' => null,
                'current_balance' => null,
                'difference' => null,
            ], 'critical', 'orphan_credit_payment_allocation', "Allocation #{$allocation->id} no tiene pago o venta válida del negocio.", 'Requiere revisión manual de integridad referencial.');
        }

        foreach (DB::table('customer_account_movements')
            ->where('business_id', $businessId)
            ->where(function (Builder $q) {
                $q->where(fn (Builder $charge) => $charge->where('type', 'charge')->whereNull('sale_id'))
                    ->orWhere(fn (Builder $payment) => $payment->where('type', 'payment')->whereNull('payment_id'));
            })
            ->orderBy('id')
            ->cursor() as $movement) {
            $issues[] = $this->issue([
                'customer_id' => $movement->customer_id,
                'sale_id' => $movement->sale_id,
                'payment_id' => $movement->payment_id,
                'expected_balance' => null,
                'current_balance' => null,
                'difference' => null,
            ], 'critical', 'ar_movement_without_reference', "Movimiento AR #{$movement->id} no tiene referencia requerida.", 'Revisar el origen del ledger antes de cualquier reparación.');
        }

        foreach (DB::table('customer_credit_payments')
            ->where('business_id', $businessId)
            ->where('status', 'completed')
            ->when($context['branch_id'], fn (Builder $q, $branch) => $q->where('branch_id', $branch))
            ->tap(fn (Builder $q) => $this->applyDates($q, 'created_at', $context))
            ->select('customer_id', 'amount', 'payment_method', DB::raw('DATE(created_at) as payment_date'), DB::raw('COUNT(*) as duplicate_count'), DB::raw("STRING_AGG(id::text, '|') as payment_ids"))
            ->groupBy('customer_id', 'amount', 'payment_method', DB::raw('DATE(created_at)'))
            ->havingRaw('COUNT(*) > 1')
            ->get() as $duplicate) {
            $issues[] = $this->issue([
                'customer_id' => $duplicate->customer_id,
                'sale_id' => null,
                'payment_id' => $duplicate->payment_ids,
                'expected_balance' => null,
                'current_balance' => null,
                'difference' => null,
            ], 'warning', 'suspected_duplicate_credit_payment', "Se detectaron {$duplicate->duplicate_count} abonos iguales del mismo cliente en la misma fecha.", 'Comparar referencias y comprobantes antes de anular alguno.');
        }

        return $issues;
    }

    private function auditPurchases(array $context): array
    {
        $issues = [];
        $businessId = $context['business_id'];
        $itemTotals = DB::table('purchase_items')
            ->where('business_id', $businessId)
            ->select('purchase_id', DB::raw('COUNT(*) as item_count'), DB::raw('COALESCE(SUM(total), 0) as expected_total'))
            ->groupBy('purchase_id');
        $purchases = DB::table('purchases as p')
            ->leftJoinSub($itemTotals, 'items', fn ($join) => $join->on('items.purchase_id', '=', 'p.id'))
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('p.business_id', $businessId)
            ->when($context['branch_id'], fn (Builder $q, $branch) => $q->where('p.branch_id', $branch))
            ->tap(fn (Builder $q) => $this->applyDates($q, 'p.created_at', $context))
            ->select('p.*', DB::raw('COALESCE(items.item_count, 0) as item_count'), DB::raw('COALESCE(items.expected_total, 0) as expected_total'), 's.business_id as supplier_business_id')
            ->orderBy('p.id')
            ->cursor();

        foreach ($purchases as $purchase) {
            $base = [
                'purchase_id' => $purchase->id,
                'branch_id' => $purchase->branch_id,
                'supplier_id' => $purchase->supplier_id,
                'supplier_invoice_number' => $purchase->supplier_invoice_number,
                'total' => (float) $purchase->total,
                'expected_total' => (float) $purchase->expected_total,
            ];
            $difference = round((float) $purchase->total - (float) $purchase->expected_total, 2);

            if ((int) $purchase->item_count === 0) {
                $issues[] = $this->issue($base, 'critical', 'purchase_without_items', 'Compra sin líneas.', 'Revisar la operación antes de anular o reconstruir líneas.');
            } elseif (abs($difference) >= 0.01) {
                $issues[] = $this->issue($base, abs($difference) <= 0.02 ? 'warning' : 'critical', 'purchase_total_mismatch', 'El total de compra no coincide con sus líneas.', 'Revisar costos y totales antes de una corrección.');
            }

            if ($purchase->supplier_id && (int) $purchase->supplier_business_id !== $businessId) {
                $issues[] = $this->issue($base, 'critical', 'purchase_supplier_cross_tenant', 'La compra referencia proveedor inexistente o de otro negocio.', 'Requiere revisión inmediata de aislamiento tenant.');
            }
        }

        foreach (DB::table('purchase_items')->where('business_id', $businessId)->where(fn (Builder $q) => $q->where('quantity', '<=', 0)->orWhere('unit_cost', '<=', 0))->orderBy('id')->cursor() as $item) {
            $issues[] = $this->issue([
                'purchase_id' => $item->purchase_id,
                'branch_id' => null,
                'supplier_id' => null,
                'supplier_invoice_number' => null,
                'total' => (float) $item->total,
                'expected_total' => round((float) $item->quantity * (float) $item->unit_cost, 2),
            ], 'critical', 'invalid_purchase_item', "Línea de compra #{$item->id} tiene cantidad o costo no válido.", 'Revisar el detalle de compra antes de cualquier corrección.');
        }

        foreach (DB::table('purchases')
            ->where('business_id', $businessId)
            ->whereNotNull('supplier_invoice_number')
            ->where('supplier_invoice_number', '!=', '')
            ->when($context['branch_id'], fn (Builder $q, $branch) => $q->where('branch_id', $branch))
            ->tap(fn (Builder $q) => $this->applyDates($q, 'created_at', $context))
            ->select('branch_id', 'supplier_id', 'supplier_invoice_number', 'total', DB::raw('COUNT(*) as duplicate_count'), DB::raw("STRING_AGG(id::text, '|') as purchase_ids"))
            ->groupBy('branch_id', 'supplier_id', 'supplier_invoice_number', 'total')
            ->havingRaw('COUNT(*) > 1')
            ->get() as $duplicate) {
            $issues[] = $this->issue([
                'purchase_id' => $duplicate->purchase_ids,
                'branch_id' => $duplicate->branch_id,
                'supplier_id' => $duplicate->supplier_id,
                'supplier_invoice_number' => $duplicate->supplier_invoice_number,
                'total' => (float) $duplicate->total,
                'expected_total' => (float) $duplicate->total,
            ], 'critical', 'suspected_duplicate_purchase', "Se detectaron {$duplicate->duplicate_count} compras con misma sucursal, proveedor, factura y total.", 'Comparar líneas y movimientos de stock antes de anular una compra.');
        }

        return $issues;
    }

    private function auditTransfers(array $context): array
    {
        $issues = [];
        $businessId = $context['business_id'];
        $lineCounts = DB::table('inventory_transfer_lines')->where('business_id', $businessId)->select('inventory_transfer_id', DB::raw('COUNT(*) as line_count'))->groupBy('inventory_transfer_id');
        $transfers = DB::table('inventory_transfers as t')
            ->leftJoinSub($lineCounts, 'lines', fn ($join) => $join->on('lines.inventory_transfer_id', '=', 't.id'))
            ->leftJoin('branches as from', 'from.id', '=', 't.from_branch_id')
            ->leftJoin('branches as destination', 'destination.id', '=', 't.to_branch_id')
            ->where('t.business_id', $businessId)
            ->when($context['branch_id'], fn (Builder $q, $branch) => $q->where(fn (Builder $scope) => $scope->where('t.from_branch_id', $branch)->orWhere('t.to_branch_id', $branch)))
            ->tap(fn (Builder $q) => $this->applyDates($q, 't.created_at', $context))
            ->select('t.*', DB::raw('COALESCE(lines.line_count, 0) as line_count'), 'from.business_id as from_business_id', 'destination.business_id as destination_business_id')
            ->orderBy('t.id')
            ->get();

        foreach ($transfers as $transfer) {
            $base = [
                'transfer_id' => $transfer->id,
                'source_branch_id' => $transfer->from_branch_id,
                'destination_branch_id' => $transfer->to_branch_id,
            ];
            if ((int) $transfer->line_count === 0) {
                $issues[] = $this->issue($base, 'critical', 'transfer_without_items', 'Traslado sin líneas.', 'Revisar antes de anular o reconstruir el traslado.');
            }
            if ((int) $transfer->from_branch_id === (int) $transfer->to_branch_id) {
                $issues[] = $this->issue($base, 'critical', 'transfer_same_branch', 'El origen y destino del traslado son la misma sucursal.', 'Revisar la operación y sus movimientos de stock.');
            }
            if ((int) $transfer->from_business_id !== $businessId || (int) $transfer->destination_business_id !== $businessId) {
                $issues[] = $this->issue($base, 'critical', 'transfer_cross_tenant_branch', 'El traslado referencia sucursal inexistente o de otro negocio.', 'Requiere revisión inmediata de aislamiento tenant.');
            }

            if ($transfer->status === 'cancelled' && DB::table('stock_movements')->where('business_id', $businessId)->where('note', 'like', "Traslado #{$transfer->id} %")->exists()) {
                $issues[] = $this->issue($base, 'critical', 'cancelled_transfer_stock_not_reversed', 'Traslado anulado conserva movimientos de stock.', 'Revisar si existe una reversa completa en ambas sucursales.');
            }

            foreach (DB::table('inventory_transfer_lines')->where('business_id', $businessId)->where('inventory_transfer_id', $transfer->id)->cursor() as $line) {
                $out = (float) DB::table('stock_movements')->where('business_id', $businessId)->where('branch_id', $transfer->from_branch_id)->where('product_id', $line->product_id)->where('type', 'transfer_out')->where('note', "Traslado #{$transfer->id} hacia ".DB::table('branches')->where('id', $transfer->to_branch_id)->value('name'))->sum('quantity');
                $in = (float) DB::table('stock_movements')->where('business_id', $businessId)->where('branch_id', $transfer->to_branch_id)->where('product_id', $line->product_id)->where('type', 'transfer_in')->where('note', "Traslado #{$transfer->id} desde ".DB::table('branches')->where('id', $transfer->from_branch_id)->value('name'))->sum('quantity');
                if (abs($out) + 0.001 < (float) $line->quantity || $in + 0.001 < (float) $line->quantity || abs(abs($out) - $in) >= 0.01) {
                    $issues[] = $this->issue($base, 'critical', 'transfer_stock_mismatch', "Traslado #{$transfer->id} tiene salida/entrada de stock incompleta o con cantidades distintas para producto #{$line->product_id}.", 'Revisar ambos movimientos antes de ajustar existencias.');
                }
            }
        }

        $recent = [];
        foreach (InventoryTransfer::query()->where('business_id', $businessId)->when($context['branch_id'], fn ($q, $branch) => $q->where(fn ($scope) => $scope->where('from_branch_id', $branch)->orWhere('to_branch_id', $branch)))->tap(fn ($q) => $this->applyDates($q, 'created_at', $context))->with('lines')->orderBy('created_at')->orderBy('id')->cursor() as $transfer) {
            $signature = implode('|', [$transfer->from_branch_id, $transfer->to_branch_id, $transfer->created_by, json_encode($transfer->lines->map(fn ($line) => [$line->product_id, $line->quantity])->sort()->values())]);
            $previous = $recent[$signature] ?? null;
            if ($previous && Carbon::parse($transfer->created_at)->diffInSeconds(Carbon::parse($previous->created_at)) <= 60) {
                $issues[] = $this->issue([
                    'transfer_id' => $previous->id.'|'.$transfer->id,
                    'source_branch_id' => $transfer->from_branch_id,
                    'destination_branch_id' => $transfer->to_branch_id,
                ], 'critical', 'suspected_duplicate_transfer', 'Traslados consecutivos con mismo origen, destino, usuario y líneas.', 'Comparar movimientos de stock antes de anular uno.');
            }
            $recent[$signature] = $transfer;
        }

        return $issues;
    }

    private function auditStockAdjustments(array $context): array
    {
        $issues = [];
        $seen = [];
        foreach (DB::table('stock_movements')
            ->where('business_id', $context['business_id'])
            ->whereIn('type', ['entry', 'exit', 'add', 'remove', 'adjustment', 'manual'])
            ->when($context['branch_id'], fn (Builder $q, $branch) => $q->where('branch_id', $branch))
            ->tap(fn (Builder $q) => $this->applyDates($q, 'created_at', $context))
            ->orderBy('created_at')->orderBy('id')->cursor() as $movement) {
            $base = [
                'stock_movement_id' => $movement->id,
                'branch_id' => $movement->branch_id,
                'product_id' => $movement->product_id,
                'quantity' => (float) $movement->quantity,
                'movement_type' => $movement->type,
                'note' => $movement->note,
            ];
            if ((float) $movement->quantity === 0.0) {
                if ($context['strict']) {
                    $issues[] = $this->issue($base, 'info', 'zero_quantity_adjustment', 'Ajuste manual sin impacto de stock.', 'Hallazgo informativo; no requiere corrección operativa.');
                }

                continue;
            }
            if (in_array($movement->type, ['exit', 'adjustment'], true) && blank($movement->note)) {
                $issues[] = $this->issue($base, 'warning', 'adjustment_without_note', 'Salida o ajuste manual sin nota.', 'Documentar el motivo antes de una reparación.');
            }
            $fingerprint = implode('|', [$movement->created_by, $movement->branch_id, $movement->product_id, $movement->type, $movement->quantity, trim((string) $movement->note)]);
            $previous = $seen[$fingerprint] ?? null;
            if ($previous && Carbon::parse($movement->created_at)->diffInSeconds(Carbon::parse($previous->created_at)) <= 60) {
                $issues[] = $this->issue($base, 'warning', 'suspected_duplicate_stock_adjustment', "Ajuste #{$movement->id} coincide con el movimiento #{$previous->id} en una ventana de 60 segundos.", 'Revisar idempotencia y evidencia del ajuste antes de revertir.');
            }
            $seen[$fingerprint] = $movement;
        }

        return $issues;
    }

    private function auditCreditReservations(array $context): array
    {
        $issues = [];
        $businessId = $context['business_id'];
        $reserved = DB::table('credit_receipt_lines as l')
            ->join('credit_receipts as r', 'r.id', '=', 'l.credit_receipt_id')
            ->leftJoin('product_branch_stocks as pbs', function ($join) {
                $join->on('pbs.business_id', '=', 'l.business_id')->on('pbs.branch_id', '=', 'l.branch_id')->on('pbs.product_id', '=', 'l.product_id');
            })
            ->where('l.business_id', $businessId)
            ->where('r.business_id', $businessId)
            ->where('r.status', '!=', 'cancelled')
            ->where('l.status', '!=', 'cancelled')
            ->where('l.qty_reserved', '>', 0)
            ->when($context['branch_id'], fn (Builder $q, $branch) => $q->where('l.branch_id', $branch))
            ->select('l.*', 'r.status as receipt_status', DB::raw('COALESCE(pbs.stock, 0) as physical_stock'))
            ->orderBy('l.id')
            ->cursor();

        foreach ($reserved as $line) {
            $base = [
                'credit_receipt_id' => $line->credit_receipt_id,
                'credit_receipt_line_id' => $line->id,
                'branch_id' => $line->branch_id,
                'product_id' => $line->product_id,
                'qty_reserved' => (float) $line->qty_reserved,
                'qty_pending' => (float) $line->qty_pending,
                'physical_stock' => (float) $line->physical_stock,
            ];
            if ((float) $line->qty_reserved > (float) $line->qty_pending) {
                $issues[] = $this->issue($base, 'critical', 'reserved_exceeds_pending', 'La reserva de la línea supera la cantidad pendiente.', 'Revisar facturación/cancelación parcial antes de corregir.');
            }
            if ((float) $line->qty_reserved > (float) $line->physical_stock) {
                $issues[] = $this->issue($base, 'critical', 'reservation_exceeds_physical_stock', 'La reserva activa supera el stock físico de la sucursal.', 'Revisar reservas activas y operaciones de salida.');
            }
        }

        foreach (DB::table('credit_receipt_lines as l')
            ->join('credit_receipts as r', 'r.id', '=', 'l.credit_receipt_id')
            ->where('l.business_id', $businessId)
            ->where('r.business_id', $businessId)
            ->where('r.status', 'cancelled')
            ->where('l.qty_reserved', '>', 0)
            ->orderBy('l.id')
            ->cursor() as $line) {
            $issues[] = $this->issue([
                'credit_receipt_id' => $line->credit_receipt_id,
                'credit_receipt_line_id' => $line->id,
                'branch_id' => $line->branch_id,
                'product_id' => $line->product_id,
                'qty_reserved' => (float) $line->qty_reserved,
                'qty_pending' => (float) $line->qty_pending,
                'physical_stock' => null,
            ], 'critical', 'cancelled_credit_reservation_still_reserved', 'Recibo de crédito anulado conserva cantidad reservada.', 'Revisar la liberación de la reserva antes de modificar existencias.');
        }

        foreach (DB::table('credit_receipt_lines as l')
            ->leftJoin('credit_receipts as r', 'r.id', '=', 'l.credit_receipt_id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.business_id', $businessId)
            ->where(function (Builder $q) use ($businessId) {
                $q->whereNull('r.id')->orWhereNull('p.id')->orWhere('r.business_id', '!=', $businessId)->orWhere('p.business_id', '!=', $businessId)->orWhere('l.qty_reserved', '<', 0);
            })
            ->orderBy('l.id')->cursor() as $line) {
            $issues[] = $this->issue([
                'credit_receipt_id' => $line->credit_receipt_id,
                'credit_receipt_line_id' => $line->id,
                'branch_id' => $line->branch_id,
                'product_id' => $line->product_id,
                'qty_reserved' => (float) $line->qty_reserved,
                'qty_pending' => (float) $line->qty_pending,
                'physical_stock' => null,
            ], 'critical', 'orphan_or_invalid_credit_reservation', 'Reserva sin recibo/producto válido, de otro negocio o con cantidad negativa.', 'Requiere revisión manual de integridad referencial.');
        }

        return $issues;
    }

    private function applyDates($query, string $column, array $context): void
    {
        if ($context['from']) {
            $query->where($column, '>=', $context['from']);
        }
        if ($context['to']) {
            $query->where($column, '<=', $context['to']);
        }
    }

    private function issue(array $row, string $severity, string $issueType, string $notes, string $recommendedAction): array
    {
        return [...$row, 'issue_type' => $issueType, 'severity' => $severity, 'notes' => $notes, 'recommended_action' => $recommendedAction];
    }

    private function isCashOpeningMovement(?string $type): bool
    {
        return in_array(mb_strtolower(trim((string) $type)), ['opening', 'open', 'apertura'], true);
    }

    private function isManualStockAdjustment(?string $type): bool
    {
        return in_array($type, ['entry', 'exit', 'add', 'remove', 'adjustment', 'manual'], true);
    }

    private function summarize(array $results, ?string $requestedSection): array
    {
        $summary = [];
        foreach ($results as $section => $issues) {
            if ($requestedSection !== null) {
                $allowedSections = $requestedSection === 'stock'
                    ? ['stock', 'negative_stock', 'stock_adjustments', 'credit-reservations']
                    : [$requestedSection];

                if (! in_array($section, $allowedSections, true)) {
                    continue;
                }
            }
            $summary[$section] = [
                'section' => $section,
                'critical_count' => count(array_filter($issues, fn (array $issue) => $issue['severity'] === 'critical')),
                'warning_count' => count(array_filter($issues, fn (array $issue) => $issue['severity'] === 'warning')),
                'info_count' => count(array_filter($issues, fn (array $issue) => $issue['severity'] === 'info')),
                'total_count' => count($issues),
            ];
        }
        return $summary;
    }

    private function writeReport(int $businessId, array $results, array $summary): string
    {
        $directory = 'system-integrity-audits/'.now()->format('Ymd-His')."-business-{$businessId}";
        Storage::disk('local')->makeDirectory($directory);
        $this->writeCsv("{$directory}/summary.csv", array_values($summary), ['section', 'critical_count', 'warning_count', 'info_count', 'total_count']);
        foreach (self::REPORT_FILES as $section => $file) {
            $this->writeCsv("{$directory}/{$file}", $results[$section] ?? [], $this->headersFor($section));
        }
        return storage_path('app/'.$directory);
    }

    private function writeCsv(string $path, array $rows, array $defaultHeaders): void
    {
        $headers = array_values(array_unique(array_merge($defaultHeaders, ...array_map(fn (array $row) => array_keys($row), $rows))));
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(function (string $header) use ($row) {
                $value = $row[$header] ?? null;
                return is_bool($value) ? ($value ? 'true' : 'false') : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value);
            }, $headers));
        }
        rewind($stream);
        Storage::disk('local')->put($path, stream_get_contents($stream));
        fclose($stream);
    }

    private function headersFor(string $section): array
    {
        return match ($section) {
            'stock' => ['business_id', 'branch_id', 'product_id', 'product_name', 'barcode', 'current_stock', 'calculated_stock', 'difference', 'severity', 'notes', 'recommended_action'],
            'negative_stock' => ['branch_id', 'product_id', 'product_name', 'current_stock', 'allow_negative_stock', 'severity', 'notes', 'recommended_action'],
            'sales' => ['sale_id', 'correlative', 'branch_id', 'customer_id', 'status', 'total', 'expected_total', 'difference', 'issue_type', 'severity', 'notes', 'recommended_action'],
            'cash' => ['cash_register_id', 'cash_movement_id', 'reference_type', 'reference_id', 'amount', 'movement_type', 'issue_type', 'severity', 'notes', 'recommended_action'],
            'ar' => ['customer_id', 'sale_id', 'payment_id', 'expected_balance', 'current_balance', 'difference', 'issue_type', 'severity', 'notes', 'recommended_action'],
            'purchases' => ['purchase_id', 'branch_id', 'supplier_id', 'supplier_invoice_number', 'total', 'expected_total', 'issue_type', 'severity', 'notes', 'recommended_action'],
            'transfers' => ['transfer_id', 'source_branch_id', 'destination_branch_id', 'issue_type', 'severity', 'notes', 'recommended_action'],
            'stock_adjustments' => ['stock_movement_id', 'branch_id', 'product_id', 'quantity', 'movement_type', 'note', 'issue_type', 'severity', 'notes', 'recommended_action'],
            'credit-reservations' => ['credit_receipt_id', 'credit_receipt_line_id', 'branch_id', 'product_id', 'qty_reserved', 'qty_pending', 'physical_stock', 'issue_type', 'severity', 'notes', 'recommended_action'],
        };
    }
}
