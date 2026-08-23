<?php

namespace App\Support;

use App\Models\Business;
use App\Models\InventoryTransfer;
use App\Models\Sale;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
                $stockIssues[] = $this->issue($base, 'warning', 'zero_quantity_stock_movement', "Movimiento #{$movement->id} tiene cantidad cero.", 'Revisar si el movimiento debe conservarse como evidencia o corregirse manualmente.');
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
        $cashPayments = DB::table('sale_payments')
            ->select('sale_id', DB::raw("COALESCE(SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END), 0) as cash_paid"))
            ->where('business_id', $businessId)
            ->groupBy('sale_id');

        $sales = DB::table('sales as s')
            ->leftJoinSub($itemTotals, 'items', fn ($join) => $join->on('items.sale_id', '=', 's.id'))
            ->leftJoinSub($cashMovements, 'cash', fn ($join) => $join->on('cash.reference_id', '=', 's.id'))
            ->leftJoinSub($cashPayments, 'payments', fn ($join) => $join->on('payments.sale_id', '=', 's.id'))
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.business_id', $businessId)
            ->when($context['branch_id'], fn (Builder $q, $branch) => $q->where('s.branch_id', $branch))
            ->tap(fn (Builder $q) => $this->applyDates($q, 's.created_at', $context))
            ->select('s.*', DB::raw('COALESCE(items.item_count, 0) as item_count'), DB::raw('COALESCE(items.expected_total, 0) as expected_total'), DB::raw('COALESCE(cash.cash_in, 0) as cash_in'), DB::raw('COALESCE(cash.cash_cancel, 0) as cash_cancel'), DB::raw('COALESCE(payments.cash_paid, 0) as cash_paid'), 'c.business_id as customer_business_id', 'b.business_id as branch_business_id')
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

            $cashExpected = max((float) $sale->cash_paid, $sale->payment_method === 'cash' && ! $sale->is_credit_sale ? (float) $sale->total : 0);
            $cashNet = round((float) $sale->cash_in + (float) $sale->cash_cancel, 2);

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
                $issues[] = $this->issue($base, 'warning', 'zero_amount_cash_movement', 'Movimiento de caja con monto cero.', 'Revisar si debe conservarse como evidencia.');
            }

            if (! $movement->reference_type && ! in_array($movement->type, ['opening', 'closing_adjustment', 'expense'], true)) {
                $issues[] = $this->issue($base, 'warning', 'cash_movement_without_reference', 'Movimiento de caja sin referencia operativa.', 'Documentar o vincular la operación en una fase de reparación.');
            }
        }

        foreach (DB::table('cash_movements')
            ->where('business_id', $businessId)
            ->whereIn('type', ['sale_cash', 'purchase_cash', 'credit_payment_cash'])
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
                $issues[] = $this->issue($base, 'warning', 'zero_quantity_adjustment', 'Ajuste manual con cantidad cero.', 'Revisar si debe conservarse como evidencia.');
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
