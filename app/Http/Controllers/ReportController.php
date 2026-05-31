<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\CashExpense;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Support\CashRegister;
use App\Support\BranchInventory;
use App\Support\PriceLists;
use App\Support\Reports\BranchReportScope;
use App\Support\Reports\ReportDateRange;
use App\Support\StockAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function dashboard(Request $request): Response|RedirectResponse
    {
        if ($request->user()->is_super_admin && ! currentBusinessId()) {
            return redirect()->route('super-admin.dashboard');
        }

        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $timezone = tenantTimezone($business);
        $today = now($timezone)->toDateString();
        [$start, $end] = $this->dateRange($today, $today, $timezone);

        $todaySales = Sale::query()
            ->where('business_id', $businessId)
            ->when(BranchInventory::branchesEnabled($businessId), fn ($query) => $query->where('branch_id', BranchInventory::activeBranch($businessId)->id))
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'))
            ->whereBetween('created_at', [$start, $end]);

        $topProduct = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sale_items.business_id', $businessId)
            ->where('sales.business_id', $businessId)
            ->when(BranchInventory::branchesEnabled($businessId), fn ($query) => $query->where('sales.branch_id', BranchInventory::activeBranch($businessId)->id))
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->orderByDesc(DB::raw('SUM(sale_items.quantity)'))
            ->selectRaw('sale_items.product_id, sale_items.product_name, SUM(sale_items.quantity) as quantity')
            ->first();

        $salesCount = (clone $todaySales)->count();
        $salesTotal = (float) (clone $todaySales)->sum('total');
        $cancelledSalesCount = Sale::query()
            ->where('business_id', $businessId)
            ->where('status', 'cancelled')
            ->whereBetween('cancelled_at', [$start, $end])
            ->count();
        $lastSale = Sale::query()
            ->where('business_id', $businessId)
            ->when(BranchInventory::branchesEnabled($businessId), fn ($query) => $query->where('branch_id', BranchInventory::activeBranch($businessId)->id))
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'))
            ->whereBetween('created_at', [$start, $end])
            ->latest()
            ->first(['id', 'created_at']);
        $openCashSession = CashRegister::currentOpenSession($businessId);

        return Inertia::render('Dashboard', [
            'stats' => [
                'sales_count' => $salesCount,
                'sales_total' => $salesTotal,
                'average_ticket' => $salesCount > 0 ? round($salesTotal / $salesCount, 2) : 0,
                'low_stock_count' => Product::query()
                    ->where('business_id', $businessId)
                    ->where('stock', '>', 0)
                    ->whereColumn('stock', '<=', 'min_stock')
                    ->count(),
                'out_of_stock_count' => Product::query()
                    ->where('business_id', $businessId)
                    ->where('stock', '<=', 0)
                    ->count(),
                'top_product' => $topProduct?->product_name,
                'estimated_margin' => $this->marginQuery($businessId, $start, $end),
                'cancelled_sales_count' => $cancelledSalesCount,
                'last_sale_time' => $lastSale?->created_at
                    ? $lastSale->created_at->copy()->timezone($timezone)->format('H:i')
                    : null,
                'cash_register_status' => $openCashSession ? 'open' : 'closed',
                'cash_register_expected' => $openCashSession
                    ? CashRegister::summary($openCashSession)['expected_cash']
                    : null,
                'timezone' => $timezone,
            ],
        ]);
    }

    public function sales(Request $request): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $timezone = tenantTimezone($business);
        $today = now($timezone)->toDateString();
        $startDate = $request->query('start_date', $today);
        $endDate = $request->query('end_date', $today);
        $status = $request->query('status', 'completed');
        $status = in_array($status, ['completed', 'cancelled', 'all'], true) ? $status : 'completed';
        $branchId = $this->branchFilter($request, $businessId);
        [$start, $end] = $this->dateRange($startDate, $endDate, $timezone);

        $sales = Sale::query()
            ->where('business_id', $businessId)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('created_at', [$start, $end])
            ->when($status === 'completed', fn ($query) => $query->where('status', 'completed'))
            ->when($status === 'cancelled', fn ($query) => $query->where('status', 'cancelled'))
            ->with('items:id,sale_id,quantity,unit_price,unit_cost,total')
            ->with('cancelledBy:id,name')
            ->latest()
            ->get();

        $rows = $sales->map(fn (Sale $sale) => [
            'id' => $sale->id,
            'business_number' => $sale->business_number,
            'display_number' => format_sale_number($sale),
            'created_at' => $sale->created_at?->copy()->timezone($timezone)->format('Y-m-d H:i'),
            'created_at_local' => $sale->created_at?->copy()->timezone($timezone)->format('Y-m-d H:i'),
            'created_at_local_date' => $sale->created_at?->copy()->timezone($timezone)->format('Y-m-d'),
            'created_at_local_time' => $sale->created_at?->copy()->timezone($timezone)->format('H:i'),
            'status' => $sale->status ?? 'completed',
            'cancelled_at' => $sale->cancelled_at?->copy()->timezone($timezone)->format('Y-m-d H:i'),
            'cancelled_by' => $sale->cancelledBy?->name,
            'cancellation_reason' => $sale->cancellation_reason,
            'payment_method' => $sale->payment_method,
            'items_count' => $sale->items->sum('quantity'),
            'total' => (float) $sale->total,
            'estimated_margin' => round($sale->items->sum(
                fn ($item) => ((float) $item->unit_price - (float) $item->unit_cost) * (int) $item->quantity
            ), 2),
        ]);

        return Inertia::render('Reports/Sales', [
            'filters' => [
                'start_date' => Carbon::parse($startDate, $timezone)->toDateString(),
                'end_date' => Carbon::parse($endDate, $timezone)->toDateString(),
                'status' => $status,
                'branch_id' => $branchId,
            ],
            'summary' => [
                'sales_total' => round($rows->where('status', '!=', 'cancelled')->sum('total'), 2),
                'sales_count' => $rows->where('status', '!=', 'cancelled')->count(),
                'items_count' => $rows->where('status', '!=', 'cancelled')->sum('items_count'),
                'estimated_margin' => round($rows->where('status', '!=', 'cancelled')->sum('estimated_margin'), 2),
                'cancelled_total' => round($rows->where('status', 'cancelled')->sum('total'), 2),
                'cancelled_count' => $rows->where('status', 'cancelled')->count(),
                'cancelled_items_count' => $rows->where('status', 'cancelled')->sum('items_count'),
            ],
            'sales' => $rows,
            'branches_enabled' => BranchInventory::branchesEnabled($businessId),
            'branches' => BranchInventory::branchesEnabled($businessId) ? BranchInventory::branchOptions($businessId) : [],
            'timezone' => $timezone,
        ]);
    }

    public function lowStock(Request $request): Response
    {
        $businessId = currentBusinessId();
        $search = trim((string) $request->query('search', ''));
        $branchId = $this->branchFilter($request, $businessId) ?: BranchInventory::activeBranch($businessId)->id;

        $products = Product::query()
            ->where('business_id', $businessId)
            ->when(! BranchInventory::branchesEnabled($businessId), fn ($query) => $query->whereColumn('stock', '<=', 'min_stock'))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'ilike', "%{$search}%")
                        ->orWhere('code', 'ilike', "%{$search}%")
                        ->orWhere('barcode', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('stock')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'barcode', 'stock', 'min_stock', 'location', 'sale_price']);
        BranchInventory::applyBranchStockAndPrices($products, $businessId, $branchId);
        $products = $products->filter(fn (Product $product) => (float) $product->stock <= (float) $product->min_stock)->values();

        return Inertia::render('Reports/LowStock', [
            'filters' => ['search' => $search, 'branch_id' => $branchId],
            'products' => $products,
            'branches_enabled' => BranchInventory::branchesEnabled($businessId),
            'branches' => BranchInventory::branchesEnabled($businessId) ? BranchInventory::branchOptions($businessId) : [],
        ]);
    }

    public function topProducts(Request $request): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $timezone = tenantTimezone($business);
        $today = now($timezone);
        $startDate = $request->query('start_date', $today->copy()->subDays(6)->toDateString());
        $endDate = $request->query('end_date', $today->toDateString());
        $branchId = $this->branchFilter($request, $businessId);
        [$start, $end] = $this->dateRange($startDate, $endDate, $timezone);

        $products = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('products', function ($join) use ($businessId) {
                $join->on('sale_items.product_id', '=', 'products.id')
                    ->where('products.business_id', '=', $businessId);
            })
            ->where('sale_items.business_id', $businessId)
            ->where('sales.business_id', $businessId)
            ->when($branchId, fn ($query) => $query->where('sales.branch_id', $branchId))
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'products.stock')
            ->orderByDesc(DB::raw('SUM(sale_items.quantity)'))
            ->limit(50)
            ->get([
                'sale_items.product_id',
                'sale_items.product_name',
                'products.stock',
                DB::raw('SUM(sale_items.quantity) as quantity_sold'),
                DB::raw('SUM(sale_items.total) as total_sold'),
                DB::raw('SUM((sale_items.unit_price - sale_items.unit_cost) * sale_items.quantity) as estimated_margin'),
            ]);

        return Inertia::render('Reports/TopProducts', [
            'filters' => [
                'start_date' => Carbon::parse($startDate, $timezone)->toDateString(),
                'end_date' => Carbon::parse($endDate, $timezone)->toDateString(),
                'branch_id' => $branchId,
            ],
            'products' => $products,
            'branches_enabled' => BranchInventory::branchesEnabled($businessId),
            'branches' => BranchInventory::branchesEnabled($businessId) ? BranchInventory::branchOptions($businessId) : [],
            'timezone' => $timezone,
        ]);
    }

    public function inventory(Request $request): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $scope = BranchReportScope::current($businessId);
        $searchName = trim((string) $request->query('product_name', ''));
        $searchCode = trim((string) $request->query('product_code', ''));
        $categoryId = $request->integer('category_id') ?: null;

        $products = Product::query()
            ->where('business_id', $businessId)
            ->with('category:id,name')
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->when($searchName !== '', fn ($query) => $query->where('name', 'ilike', "%{$searchName}%"))
            ->when($searchCode !== '', function ($query) use ($searchCode) {
                $query->where(function ($query) use ($searchCode) {
                    $query->where('code', 'ilike', "%{$searchCode}%")
                        ->orWhere('barcode', 'ilike', "%{$searchCode}%");
                });
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $productIds = $products->getCollection()->pluck('id')->all();
        $stockByProduct = DB::table('product_branch_stocks')
            ->where('business_id', $businessId)
            ->where('branch_id', $scope->branch->id)
            ->whereIn('product_id', $productIds)
            ->pluck('stock', 'product_id');
        $reservedByProduct = DB::table('credit_receipt_lines')
            ->where('business_id', $businessId)
            ->where('branch_id', $scope->branch->id)
            ->whereIn('product_id', $productIds)
            ->whereIn('status', ['pending', 'partially_invoiced'])
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_pending) as reserved')
            ->pluck('reserved', 'product_id');
        $lastInByProduct = DB::table('stock_movements')
            ->where('business_id', $businessId)
            ->where('branch_id', $scope->branch->id)
            ->whereIn('product_id', $productIds)
            ->where('quantity', '>', 0)
            ->groupBy('product_id')
            ->selectRaw('product_id, MAX(created_at) as last_in_at')
            ->pluck('last_in_at', 'product_id');

        $products->getCollection()->transform(function (Product $product) use ($stockByProduct, $reservedByProduct, $lastInByProduct) {
            $stock = (float) ($stockByProduct[$product->id] ?? 0);
            $reserved = (float) ($reservedByProduct[$product->id] ?? 0);
            $lastIn = $lastInByProduct[$product->id] ?? null;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'code' => $product->code ?: $product->barcode,
                'category' => $product->category?->name ?: '-',
                'stock' => $stock,
                'reserved' => $reserved,
                'available' => max(0, $stock - $reserved),
                'last_in_at' => $lastIn ? Carbon::parse($lastIn)->timezone(tenantTimezone())->format('Y-m-d H:i') : '-',
                'cardex_url' => route('products.stock-history', $product->id),
            ];
        });

        return $this->report('Inventario', 'reports.inventory', [
            ['key' => 'name', 'label' => 'Nombre del producto'],
            ['key' => 'code', 'label' => 'Código/SKU'],
            ['key' => 'category', 'label' => 'Categoría'],
            ['key' => 'stock', 'label' => 'Existencia', 'type' => 'number'],
            ['key' => 'reserved', 'label' => 'Reservado', 'type' => 'number'],
            ['key' => 'available', 'label' => 'Disponible', 'type' => 'number'],
            ['key' => 'last_in_at', 'label' => 'Fecha último ingreso'],
            ['key' => 'cardex_url', 'label' => 'Acción', 'type' => 'link', 'link_label' => 'Ver cardex'],
        ], $products, [
            'filters' => ['product_name' => $searchName, 'product_code' => $searchCode, 'category_id' => $categoryId],
            'categories' => Category::query()->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'branch' => $scope->payload(),
        ]);
    }

    public function daily(Request $request): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $scope = BranchReportScope::current($businessId);
        $range = ReportDateRange::daily($request, $business);
        $paymentMethod = (string) $request->query('payment_method', 'all');
        $allowedMethods = ['all', 'cash', 'card', 'bank_transfer', 'transfer', 'check', 'credit', 'other'];
        $paymentMethod = in_array($paymentMethod, $allowedMethods, true) ? $paymentMethod : 'all';
        $salePaymentMethod = $paymentMethod === 'bank_transfer' ? 'transfer' : $paymentMethod;

        $salesQuery = Sale::query()
            ->where('sales.business_id', $businessId)
            ->where('sales.branch_id', $scope->branch->id)
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$range->start, $range->end])
            ->with(['customer:id,name', 'payments:id,sale_id,method,amount']);

        if ($paymentMethod !== 'all') {
            $salesQuery->whereHas('payments', fn ($query) => $query->where('method', $salePaymentMethod));
        }

        $sales = $salesQuery->latest('sales.created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Sale $sale) => [
                'number' => format_sale_number($sale),
                'customer' => $sale->customer_name ?: $sale->customer?->name ?: 'Consumidor Final',
                'payment_method' => $sale->payments->pluck('method')->unique()->join(', '),
                'total' => (float) $sale->total,
            ]);

        $cashOnly = $paymentMethod === 'all' || $paymentMethod === 'cash';
        $cashSales = (float) DB::table('sale_payments')
            ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
            ->where('sale_payments.business_id', $businessId)
            ->where('sales.business_id', $businessId)
            ->where('sales.branch_id', $scope->branch->id)
            ->where('sale_payments.method', $salePaymentMethod === 'all' ? 'cash' : $salePaymentMethod)
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$range->start, $range->end])
            ->sum('sale_payments.amount');
        $selectedSales = $paymentMethod === 'all'
            ? (float) Sale::query()->where('business_id', $businessId)->where('branch_id', $scope->branch->id)->whereBetween('created_at', [$range->start, $range->end])->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'))->sum('total')
            : $cashSales;
        $opening = $cashOnly ? (float) CashRegisterSession::query()->where('business_id', $businessId)->where('branch_id', $scope->branch->id)->whereBetween('opened_at', [$range->start, $range->end])->sum('opening_amount') : 0;
        $cashPurchases = $cashOnly ? (float) Purchase::query()->where('business_id', $businessId)->where('branch_id', $scope->branch->id)->where('paid_from_cash', true)->where('payment_method', 'cash')->whereBetween('created_at', [$range->start, $range->end])->sum('total') : 0;
        $expenses = $cashOnly ? (float) CashExpense::query()->where('business_id', $businessId)->where('branch_id', $scope->branch->id)->whereBetween('created_at', [$range->start, $range->end])->sum('amount') : 0;

        return $this->report('Diario', 'reports.daily', [
            ['key' => 'number', 'label' => 'No. venta'],
            ['key' => 'customer', 'label' => 'Cliente'],
            ['key' => 'payment_method', 'label' => 'Forma de pago'],
            ['key' => 'total', 'label' => 'Total de venta', 'type' => 'money'],
        ], $sales, [
            'filters' => ['date' => $range->dateFrom, 'payment_method' => $paymentMethod],
            'summary' => [
                ['label' => 'Apertura de caja', 'value' => $opening, 'money' => true, 'hidden' => ! $cashOnly],
                ['label' => 'Ventas', 'value' => $selectedSales, 'money' => true],
                ['label' => 'Compras', 'value' => $cashPurchases, 'money' => true, 'hidden' => ! $cashOnly],
                ['label' => 'Gastos', 'value' => $expenses, 'money' => true, 'hidden' => ! $cashOnly],
                ['label' => 'Total', 'value' => $cashOnly ? $opening + $cashSales - $cashPurchases - $expenses : $selectedSales, 'money' => true],
            ],
            'branch' => $scope->payload(),
        ]);
    }

    public function profit(Request $request): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $scope = BranchReportScope::current($businessId);
        $range = ReportDateRange::monthToDate($request, $business);

        $query = Sale::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $scope->branch->id)
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'))
            ->whereBetween('created_at', [$range->start, $range->end])
            ->withSum('items as total_cost_sum', 'total_cost')
            ->withSum('items as profit_amount_sum', 'profit_amount');

        $summaryBase = DB::table('sales')
            ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.business_id', $businessId)
            ->where('sales.branch_id', $scope->branch->id)
            ->whereBetween('sales.created_at', [$range->start, $range->end])
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'));

        $salesTotal = (float) (clone $query)->sum('total');
        $costTotal = (float) (clone $summaryBase)->sum('sale_items.total_cost');
        $profitTotal = (float) (clone $summaryBase)->sum('sale_items.profit_amount');

        $rows = $query->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Sale $sale) => [
                'number' => format_sale_number($sale),
                'date' => $sale->created_at?->timezone(tenantTimezone($business))->format('Y-m-d H:i'),
                'customer' => $sale->customer_name ?: 'Consumidor Final',
                'total' => (float) $sale->total,
                'cost' => (float) $sale->total_cost_sum,
                'profit' => (float) $sale->profit_amount_sum,
            ]);

        return $this->report('Utilidades', 'reports.profit', [
            ['key' => 'number', 'label' => 'No. venta'],
            ['key' => 'date', 'label' => 'Fecha'],
            ['key' => 'customer', 'label' => 'Cliente'],
            ['key' => 'total', 'label' => 'Total venta', 'type' => 'money'],
            ['key' => 'cost', 'label' => 'Costo venta', 'type' => 'money'],
            ['key' => 'profit', 'label' => 'Utilidad', 'type' => 'money'],
        ], $rows, [
            'filters' => ['date_from' => $range->dateFrom, 'date_to' => $range->dateTo],
            'summary' => [
                ['label' => 'Total ventas', 'value' => $salesTotal, 'money' => true],
                ['label' => 'Total costo', 'value' => $costTotal, 'money' => true],
                ['label' => 'Total utilidad', 'value' => $profitTotal, 'money' => true],
                ['label' => 'Margen %', 'value' => $salesTotal > 0 ? round(($profitTotal / $salesTotal) * 100, 2) : 0],
            ],
            'branch' => $scope->payload(),
        ]);
    }

    public function warehouseMoney(Request $request): Response
    {
        $businessId = currentBusinessId();
        $scope = BranchReportScope::current($businessId);
        $search = trim((string) $request->query('search', ''));
        $categoryId = $request->integer('category_id') ?: null;

        $products = Product::query()
            ->where('business_id', $businessId)
            ->with('category:id,name')
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%")->orWhere('barcode', 'ilike', "%{$search}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $defaultPriceType = PriceLists::getDefaultPriceType($businessId);
        $products->getCollection()->transform(function (Product $product) use ($scope, $businessId, $defaultPriceType) {
            $stock = (float) DB::table('product_branch_stocks')->where('business_id', $businessId)->where('branch_id', $scope->branch->id)->where('product_id', $product->id)->value('stock');
            $reserved = StockAvailability::reservedStock($product, null, $scope->branch->id);
            $salePrice = $defaultPriceType
                ? PriceLists::getProductPrice($product->id, $defaultPriceType->id, $businessId, $scope->branch->id)
                : null;
            $salePrice ??= (float) $product->sale_price;
            $cost = (float) $product->cost_price;

            return [
                'product' => $product->name,
                'stock' => $stock,
                'reserved' => $reserved,
                'available' => max(0, $stock - $reserved),
                'cost_price' => $cost,
                'sale_price' => (float) $salePrice,
                'total_cost' => round($stock * $cost, 2),
                'total_sale' => round($stock * (float) $salePrice, 2),
                'possible_profit' => round(($stock * (float) $salePrice) - ($stock * $cost), 2),
            ];
        });

        $summaryQuery = Product::query()
            ->where('products.business_id', $businessId)
            ->when($categoryId, fn ($query) => $query->where('products.category_id', $categoryId))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('products.name', 'ilike', "%{$search}%")->orWhere('products.code', 'ilike', "%{$search}%")->orWhere('products.barcode', 'ilike', "%{$search}%")))
            ->leftJoin('product_branch_stocks as pbs', function ($join) use ($businessId, $scope) {
                $join->on('pbs.product_id', '=', 'products.id')
                    ->where('pbs.business_id', '=', $businessId)
                    ->where('pbs.branch_id', '=', $scope->branch->id);
            });

        if ($defaultPriceType) {
            $summaryQuery->leftJoin('product_prices as pp', function ($join) use ($businessId, $defaultPriceType) {
                $join->on('pp.product_id', '=', 'products.id')
                    ->where('pp.business_id', '=', $businessId)
                    ->where('pp.price_type_id', '=', $defaultPriceType->id)
                    ->where('pp.is_active', '=', true);
            });
        }

        $priceExpression = $defaultPriceType ? 'COALESCE(pp.price, products.sale_price)' : 'products.sale_price';

        if ($defaultPriceType && BranchInventory::pricingScope($businessId) === 'branch') {
            $summaryQuery->leftJoin('branch_product_prices as bpp', function ($join) use ($businessId, $defaultPriceType, $scope) {
                $join->on('bpp.product_id', '=', 'products.id')
                    ->where('bpp.business_id', '=', $businessId)
                    ->where('bpp.branch_id', '=', $scope->branch->id)
                    ->where('bpp.price_type_id', '=', $defaultPriceType->id)
                    ->where('bpp.is_active', '=', true);
            });
            $priceExpression = 'COALESCE(bpp.price, pp.price, products.sale_price)';
        }

        $totals = $summaryQuery
            ->selectRaw('COALESCE(SUM(COALESCE(pbs.stock, 0) * products.cost_price), 0) as total_cost')
            ->selectRaw("COALESCE(SUM(COALESCE(pbs.stock, 0) * {$priceExpression}), 0) as total_sale")
            ->first();
        $totalCost = (float) ($totals?->total_cost ?? 0);
        $totalSale = (float) ($totals?->total_sale ?? 0);

        return $this->report('Dinero en bodega', 'reports.warehouse-money', [
            ['key' => 'product', 'label' => 'Producto'],
            ['key' => 'stock', 'label' => 'Existencia', 'type' => 'number'],
            ['key' => 'reserved', 'label' => 'Reservado', 'type' => 'number'],
            ['key' => 'available', 'label' => 'Disponible', 'type' => 'number'],
            ['key' => 'cost_price', 'label' => 'Precio costo', 'type' => 'money'],
            ['key' => 'sale_price', 'label' => 'Precio venta predeterminado', 'type' => 'money'],
            ['key' => 'total_cost', 'label' => 'Total costo', 'type' => 'money'],
            ['key' => 'total_sale', 'label' => 'Total venta', 'type' => 'money'],
            ['key' => 'possible_profit', 'label' => 'Beneficio posible', 'type' => 'money'],
        ], $products, [
            'filters' => ['search' => $search, 'category_id' => $categoryId],
            'summary' => [
                ['label' => 'Total costo', 'value' => $totalCost, 'money' => true],
                ['label' => 'Total venta', 'value' => $totalSale, 'money' => true],
                ['label' => 'Posible beneficio', 'value' => $totalSale - $totalCost, 'money' => true],
            ],
            'categories' => Category::query()->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'branch' => $scope->payload(),
        ]);
    }

    public function salesBySeller(Request $request): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $scope = BranchReportScope::current($businessId);
        $range = ReportDateRange::monthToDate($request, $business);
        $productSearch = trim((string) $request->query('product_search', ''));

        $query = DB::table('sales')
            ->leftJoin('users', 'sales.created_by', '=', 'users.id')
            ->where('sales.business_id', $businessId)
            ->where('sales.branch_id', $scope->branch->id)
            ->whereBetween('sales.created_at', [$range->start, $range->end])
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'));

        if ($productSearch !== '') {
            $query->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
                ->where(fn ($query) => $query->where('sale_items.product_name', 'ilike', "%{$productSearch}%"));
            $totalGenerated = (float) (clone $query)->sum('sale_items.total');
            $select = [
                DB::raw("COALESCE(users.name, 'Sin vendedor') as seller"),
                DB::raw('SUM(sale_items.quantity) as sales_count'),
                DB::raw('SUM(sale_items.total) as total'),
            ];
        } else {
            $totalGenerated = (float) (clone $query)->sum('sales.total');
            $select = [
                DB::raw("COALESCE(users.name, 'Sin vendedor') as seller"),
                DB::raw('COUNT(sales.id) as sales_count'),
                DB::raw('SUM(sales.total) as total'),
            ];
        }

        $rows = $query
            ->select($select)
            ->groupBy('users.id', 'users.name')
            ->orderByDesc(DB::raw('SUM(sales.total)'))
            ->paginate(25)
            ->withQueryString();

        return $this->report('Ventas por vendedor', 'reports.sales-by-seller', [
            ['key' => 'seller', 'label' => 'Vendedor'],
            ['key' => 'sales_count', 'label' => $productSearch ? 'Cantidad vendida' : 'Cantidad de ventas', 'type' => 'number'],
            ['key' => 'total', 'label' => $productSearch ? 'Total monetario del producto' : 'Total monetario', 'type' => 'money'],
        ], $rows, [
            'filters' => ['date_from' => $range->dateFrom, 'date_to' => $range->dateTo, 'product_search' => $productSearch],
            'summary' => [['label' => 'Total monetario generado', 'value' => $totalGenerated, 'money' => true]],
            'branch' => $scope->payload(),
        ]);
    }

    public function salesByDate(Request $request): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $scope = BranchReportScope::current($businessId);
        $range = ReportDateRange::monthToDate($request, $business);
        $base = DB::table('sales')
            ->where('business_id', $businessId)
            ->where('branch_id', $scope->branch->id)
            ->whereBetween('created_at', [$range->start, $range->end])
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'));
        $salesCount = (int) (clone $base)->count();
        $soldTotal = (float) (clone $base)->sum('total');
        $rows = $base
            ->select([
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as sales_count'),
                DB::raw('SUM(total) as total'),
            ])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at) DESC')
            ->paginate(25)
            ->withQueryString();

        return $this->report('Ventas por fecha', 'reports.sales-by-date', [
            ['key' => 'date', 'label' => 'Fecha'],
            ['key' => 'sales_count', 'label' => 'Cantidad de ventas', 'type' => 'number'],
            ['key' => 'total', 'label' => 'Total vendido', 'type' => 'money'],
        ], $rows, [
            'filters' => ['date_from' => $range->dateFrom, 'date_to' => $range->dateTo],
            'summary' => [
                ['label' => 'Total ventas', 'value' => $salesCount],
                ['label' => 'Total vendido', 'value' => $soldTotal, 'money' => true],
            ],
            'branch' => $scope->payload(),
        ]);
    }

    public function salesByCustomer(Request $request): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $scope = BranchReportScope::current($businessId);
        $range = ReportDateRange::monthToDate($request, $business);
        $customerId = $request->integer('customer_id') ?: null;
        $customerSearch = trim((string) $request->query('customer_search', ''));

        $base = Sale::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $scope->branch->id)
            ->whereBetween('created_at', [$range->start, $range->end])
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'));

        if ($customerId || $customerSearch !== '') {
            $filteredBase = (clone $base)
                ->when($customerId, fn ($query) => $query->where('customer_id', $customerId))
                ->when($customerSearch !== '', fn ($query) => $this->applyCustomerSearch($query, $customerSearch));

            $rows = (clone $filteredBase)
                ->latest()
                ->paginate(25)
                ->withQueryString()
                ->through(fn (Sale $sale) => ['number' => format_sale_number($sale), 'date' => $sale->created_at?->format('Y-m-d'), 'customer' => $sale->customer_name, 'total' => (float) $sale->total]);
            $columns = [['key' => 'number', 'label' => 'No. venta'], ['key' => 'date', 'label' => 'Fecha'], ['key' => 'customer', 'label' => 'Cliente'], ['key' => 'total', 'label' => 'Total', 'type' => 'money']];
            $totalCustomers = (int) (clone $filteredBase)->distinct('customer_id')->count('customer_id');
            $totalSales = (int) (clone $filteredBase)->count();
            $totalSold = (float) (clone $filteredBase)->sum('total');
        } else {
            $rows = DB::table('sales')
                ->where('business_id', $businessId)
                ->where('branch_id', $scope->branch->id)
                ->whereBetween('created_at', [$range->start, $range->end])
                ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'))
                ->select([
                    'customer_name as customer',
                    'customer_doc_number as nit',
                    DB::raw('COUNT(*) as sales_count'),
                    DB::raw('SUM(total) as total'),
                ])
                ->groupBy('customer_id', 'customer_name', 'customer_doc_number')
                ->orderByDesc(DB::raw('SUM(total)'))
                ->paginate(25)
                ->withQueryString();
            $columns = [['key' => 'customer', 'label' => 'Cliente'], ['key' => 'nit', 'label' => 'NIT'], ['key' => 'sales_count', 'label' => 'Cantidad de ventas', 'type' => 'number'], ['key' => 'total', 'label' => 'Total vendido', 'type' => 'money']];
            $totalCustomers = (int) (clone $base)->distinct('customer_id')->count('customer_id');
            $totalSales = (int) (clone $base)->count();
            $totalSold = (float) (clone $base)->sum('total');
        }

        return $this->report('Ventas por cliente', 'reports.sales-by-customer', $columns, $rows, [
            'filters' => ['date_from' => $range->dateFrom, 'date_to' => $range->dateTo, 'customer_id' => $customerId, 'customer_search' => $customerSearch],
            'summary' => [
                ['label' => 'Total clientes', 'value' => $totalCustomers],
                ['label' => 'Total ventas', 'value' => $totalSales],
                ['label' => 'Total vendido', 'value' => $totalSold, 'money' => true],
            ],
            'customers' => Customer::query()->where('business_id', $businessId)->orderBy('name')->limit(100)->get(['id', 'name', 'doc_number']),
            'branch' => $scope->payload(),
        ]);
    }

    public function salesDetailed(Request $request): Response
    {
        return $this->saleLinesReport($request, 'Ventas detalladas', 'reports.sales-detailed', [
            ['key' => 'date', 'label' => 'Fecha venta'],
            ['key' => 'nit', 'label' => 'NIT'],
            ['key' => 'number', 'label' => 'No. recibo / No. venta'],
            ['key' => 'product', 'label' => 'Producto'],
            ['key' => 'quantity', 'label' => 'Cantidad vendida', 'type' => 'number'],
            ['key' => 'total_cost', 'label' => 'Total costo', 'type' => 'money'],
            ['key' => 'total', 'label' => 'Total venta', 'type' => 'money'],
            ['key' => 'profit', 'label' => 'Utilidad', 'type' => 'money'],
        ], includeCost: true);
    }

    public function productsSoldDetailed(Request $request): Response
    {
        return $this->saleLinesReport($request, 'Productos vendidos detallado', 'reports.products-sold-detailed', [
            ['key' => 'date', 'label' => 'Fecha'],
            ['key' => 'seller', 'label' => 'Vendedor'],
            ['key' => 'customer', 'label' => 'Cliente'],
            ['key' => 'quantity', 'label' => 'Cantidad', 'type' => 'number'],
            ['key' => 'product', 'label' => 'Producto'],
            ['key' => 'unit_price', 'label' => 'Precio unitario', 'type' => 'money'],
            ['key' => 'total', 'label' => 'Total', 'type' => 'money'],
        ]);
    }

    public function productsSoldSummary(Request $request): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $scope = BranchReportScope::current($businessId);
        $range = ReportDateRange::monthToDate($request, $business);
        $base = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sale_items.business_id', $businessId)
            ->where('sales.business_id', $businessId)
            ->where('sales.branch_id', $scope->branch->id)
            ->whereBetween('sales.created_at', [$range->start, $range->end])
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'));
        $distinctProducts = (int) (clone $base)->distinct('sale_items.product_id')->count('sale_items.product_id');
        $unitsSold = (float) (clone $base)->sum('sale_items.quantity');
        $totalSold = (float) (clone $base)->sum('sale_items.total');
        $rows = $base
            ->select([
                'sale_items.product_name as product',
                DB::raw('SUM(sale_items.quantity) as quantity'),
                DB::raw('SUM(sale_items.total) as total'),
            ])
            ->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->orderByDesc(DB::raw('SUM(sale_items.quantity)'))
            ->paginate(25)
            ->withQueryString();

        return $this->report('Productos vendidos resumido', 'reports.products-sold-summary', [
            ['key' => 'product', 'label' => 'Producto'],
            ['key' => 'quantity', 'label' => 'Cantidad vendida', 'type' => 'number'],
            ['key' => 'total', 'label' => 'Total monetario vendido', 'type' => 'money'],
        ], $rows, [
            'filters' => ['date_from' => $range->dateFrom, 'date_to' => $range->dateTo],
            'summary' => [
                ['label' => 'Total productos distintos', 'value' => $distinctProducts],
                ['label' => 'Total unidades vendidas', 'value' => $unitsSold],
                ['label' => 'Total monetario vendido', 'value' => $totalSold, 'money' => true],
            ],
            'branch' => $scope->payload(),
        ]);
    }

    private function saleLinesReport(Request $request, string $title, string $routeName, array $columns, bool $includeCost = false): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $scope = BranchReportScope::current($businessId);
        $range = ReportDateRange::monthToDate($request, $business);
        $rows = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('users', 'sales.created_by', '=', 'users.id')
            ->where('sale_items.business_id', $businessId)
            ->where('sales.business_id', $businessId)
            ->where('sales.branch_id', $scope->branch->id)
            ->whereBetween('sales.created_at', [$range->start, $range->end])
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->orderByDesc('sales.created_at')
            ->paginate(25, [
                DB::raw("to_char(sales.created_at, 'YYYY-MM-DD HH24:MI') as date"),
                'users.name as seller',
                'sales.customer_name as customer',
                'sales.customer_doc_number as nit',
                'sales.business_number as business_number',
                'sale_items.product_name as product',
                'sale_items.quantity',
                'sale_items.unit_price',
                'sale_items.total',
                'sale_items.total_cost',
                'sale_items.profit_amount as profit',
            ])
            ->withQueryString()
            ->through(fn ($row) => [
                'date' => $row->date,
                'seller' => $row->seller ?: 'Sin vendedor',
                'customer' => $row->customer ?: 'Consumidor Final',
                'nit' => $row->nit ?: 'CF',
                'number' => 'V-'.$row->business_number,
                'product' => $row->product,
                'quantity' => (float) $row->quantity,
                'unit_price' => (float) $row->unit_price,
                'total' => (float) $row->total,
                'total_cost' => $includeCost ? (float) $row->total_cost : null,
                'profit' => $includeCost ? (float) $row->profit : null,
            ]);

        return $this->report($title, $routeName, $columns, $rows, [
            'filters' => ['date_from' => $range->dateFrom, 'date_to' => $range->dateTo],
            'branch' => $scope->payload(),
        ]);
    }

    private function applyCustomerSearch($query, string $customerSearch)
    {
        return $query->where(function ($query) use ($customerSearch) {
            $query->where('customer_name', 'ilike', "%{$customerSearch}%")
                ->orWhere('customer_doc_number', 'ilike', "%{$customerSearch}%")
                ->orWhereHas('customer', function ($query) use ($customerSearch) {
                    $query->where('name', 'ilike', "%{$customerSearch}%")
                        ->orWhere('doc_number', 'ilike', "%{$customerSearch}%");
                });
        });
    }

    private function report(string $title, string $routeName, array $columns, $rows, array $extra = []): Response
    {
        return Inertia::render('Reports/Generic', [
            'title' => $title,
            'routeName' => $routeName,
            'columns' => $columns,
            'rows' => $rows,
            'summary' => $extra['summary'] ?? [],
            'filters' => $extra['filters'] ?? [],
            'categories' => $extra['categories'] ?? [],
            'customers' => $extra['customers'] ?? [],
            'branch' => $extra['branch'] ?? null,
            'maxRangeLabel' => 'Rango máximo: 3 meses',
        ]);
    }

    private function branchFilter(Request $request, int $businessId): ?int
    {
        if (! BranchInventory::branchesEnabled($businessId)) {
            return null;
        }

        $branchId = $request->integer('branch_id');

        if (! BranchInventory::canSwitchBranches($request->user())) {
            return BranchInventory::activeBranch($businessId)->id;
        }

        if (! $branchId) {
            return BranchInventory::activeBranch($businessId)->id;
        }

        return \App\Models\Branch::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->whereKey($branchId)
            ->value('id');
    }

    public function salesExportExcel(): never
    {
        // TODO: Implementar exportación a Excel.
        abort(501, 'Exportaciones no disponibles en esta versión.');
    }

    public function salesExportPdf(): never
    {
        // TODO: Implementar exportación a PDF.
        abort(501, 'Exportaciones no disponibles en esta versión.');
    }

    public function lowStockExportExcel(): never
    {
        // TODO: Implementar exportación a Excel.
        abort(501, 'Exportaciones no disponibles en esta versión.');
    }

    public function topProductsExportExcel(): never
    {
        // TODO: Implementar exportación a Excel.
        abort(501, 'Exportaciones no disponibles en esta versión.');
    }

    private function dateRange(string $startDate, string $endDate, string $timezone): array
    {
        return [
            Carbon::parse($startDate, $timezone)->startOfDay()->utc(),
            Carbon::parse($endDate, $timezone)->endOfDay()->utc(),
        ];
    }

    private function marginQuery(int $businessId, Carbon $start, Carbon $end): float
    {
        return (float) DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sale_items.business_id', $businessId)
            ->where('sales.business_id', $businessId)
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM((sale_items.unit_price - sale_items.unit_cost) * sale_items.quantity), 0) as margin')
            ->value('margin');
    }
}
