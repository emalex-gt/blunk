<?php

namespace App\Http\Controllers;

use App\Exports\ArrayTableExport;
use App\Models\Business;
use App\Models\CashExpense;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use App\Support\CashRegister;
use App\Support\BranchInventory;
use App\Support\Exports\TableExporter;
use App\Support\Permissions;
use App\Support\PriceLists;
use App\Support\Reports\BranchReportScope;
use App\Support\Reports\ReportDateRange;
use App\Support\StockAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ReportController extends Controller
{
    private const EXPORT_LIMIT = 5000;

    private const REPORT_EXPORTS = [
        'sales' => ['method' => 'sales', 'permission' => Permissions::REPORTS_SALES_VIEW, 'title' => 'Ventas'],
        'inventory' => ['method' => 'inventory', 'permission' => Permissions::REPORTS_INVENTORY_VIEW, 'title' => 'Inventario'],
        'daily' => ['method' => 'daily', 'permission' => Permissions::REPORTS_DAILY_VIEW, 'title' => 'Diario'],
        'profit' => ['method' => 'profit', 'permission' => Permissions::REPORTS_PROFIT_VIEW, 'title' => 'Utilidades'],
        'warehouse-money' => ['method' => 'warehouseMoney', 'permission' => Permissions::REPORTS_WAREHOUSE_MONEY_VIEW, 'title' => 'Dinero en bodega'],
        'sales-by-seller' => ['method' => 'salesBySeller', 'permission' => Permissions::REPORTS_SALES_BY_SELLER_VIEW, 'title' => 'Ventas por vendedor'],
        'sales-by-date' => ['method' => 'salesByDate', 'permission' => Permissions::REPORTS_SALES_BY_DATE_VIEW, 'title' => 'Ventas por fecha'],
        'sales-by-customer' => ['method' => 'salesByCustomer', 'permission' => Permissions::REPORTS_SALES_BY_CUSTOMER_VIEW, 'title' => 'Ventas por cliente'],
        'sales-detailed' => ['method' => 'salesDetailed', 'permission' => Permissions::REPORTS_SALES_DETAILED_VIEW, 'title' => 'Ventas detalladas'],
        'products-sold-detailed' => ['method' => 'productsSoldDetailed', 'permission' => Permissions::REPORTS_PRODUCTS_SOLD_DETAILED_VIEW, 'title' => 'Productos vendidos detallado'],
        'products-sold-summary' => ['method' => 'productsSoldSummary', 'permission' => Permissions::REPORTS_PRODUCTS_SOLD_SUMMARY_VIEW, 'title' => 'Productos vendidos resumido'],
        'low-stock' => ['method' => 'lowStock', 'permission' => Permissions::REPORTS_LOW_STOCK_VIEW, 'title' => 'Stock bajo'],
        'top-products' => ['method' => 'topProducts', 'permission' => Permissions::REPORTS_TOP_PRODUCTS_VIEW, 'title' => 'Productos más vendidos'],
    ];

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
        $scope = BranchReportScope::current($businessId);
        $today = now(tenantTimezone($business))->toDateString();
        $range = ReportDateRange::fromRequest($request, $business, $today, $today);
        $customerSearch = trim((string) $request->query('customer_search', ''));
        $sellerId = $request->integer('seller_id') ?: null;
        $paymentMethod = (string) $request->query('payment_method', 'all');
        $documentType = (string) $request->query('document_type', 'all');
        $status = (string) $request->query('status', 'completed');
        $saleNumber = trim((string) $request->query('sale_number', ''));
        $allowedPaymentMethods = ['all', 'cash', 'card', 'bank_transfer', 'transfer', 'check', 'credit', 'other'];
        $allowedDocumentTypes = ['all', 'receipt', 'invoice', 'credit'];
        $allowedStatuses = ['completed', 'cancelled', 'all'];
        $paymentMethod = in_array($paymentMethod, $allowedPaymentMethods, true) ? $paymentMethod : 'all';
        $documentType = in_array($documentType, $allowedDocumentTypes, true) ? $documentType : 'all';
        $status = in_array($status, $allowedStatuses, true) ? $status : 'completed';
        $salePaymentMethod = $paymentMethod === 'bank_transfer' ? 'transfer' : $paymentMethod;

        $query = Sale::query()
            ->where('sales.business_id', $businessId)
            ->where('sales.branch_id', $scope->branch->id)
            ->whereBetween('sales.created_at', [$range->start, $range->end])
            ->with(['createdBy:id,name', 'payments:id,sale_id,method,amount']);

        if ($status === 'completed') {
            $query->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'));
        } elseif ($status === 'cancelled') {
            $query->where('sales.status', 'cancelled');
        }

        if ($customerSearch !== '') {
            $this->applyCustomerSearch($query, $customerSearch);
        }

        if ($sellerId) {
            $query->where('sales.created_by', $sellerId);
        }

        if ($paymentMethod !== 'all') {
            $query->whereHas('payments', fn ($query) => $query->where('method', $salePaymentMethod));
        }

        if ($documentType === 'credit') {
            $query->whereRaw('1 = 0');
        } elseif ($documentType !== 'all') {
            $query->where('sales.document_type', $documentType);
        }

        if ($saleNumber !== '') {
            $number = preg_replace('/\D+/', '', $saleNumber);
            $query->when($number !== '', fn ($query) => $query->where('sales.business_number', (int) $number));
        }

        $summaryBase = clone $query;
        $totalSales = (int) (clone $summaryBase)->count();
        $totalSold = (float) (clone $summaryBase)->sum('sales.total');
        $invoiceCount = (int) (clone $summaryBase)->where('sales.document_type', 'invoice')->count();
        $receiptCount = (int) (clone $summaryBase)->where('sales.document_type', 'receipt')->count();
        $paymentTotals = DB::table('sale_payments')
            ->where('sale_payments.business_id', $businessId)
            ->whereIn('sale_payments.sale_id', (clone $summaryBase)->select('sales.id'))
            ->when($paymentMethod !== 'all', fn ($query) => $query->where('sale_payments.method', $salePaymentMethod))
            ->groupBy('sale_payments.method')
            ->selectRaw('sale_payments.method, SUM(sale_payments.amount) as total')
            ->pluck('total', 'method');

        $rows = $query
            ->latest('sales.created_at')
            ->paginate($this->reportPerPage($request))
            ->withQueryString()
            ->through(fn (Sale $sale) => [
                'created_at' => $sale->created_at?->timezone(tenantTimezone($business))->format('Y-m-d H:i'),
                'number' => format_sale_number($sale),
                'customer' => $sale->customer_name ?: 'Consumidor Final',
                'nit' => $sale->customer_doc_number ?: 'CF',
                'seller' => $sale->createdBy?->name ?: 'Sin vendedor',
                'document_type' => match ($sale->document_type) {
                    'invoice' => 'Factura',
                    'receipt' => 'Comprobante',
                    default => $sale->document_type ?: '-',
                },
                'payment_method' => $sale->payments->pluck('method')->unique()->join(', ') ?: $sale->payment_method,
                'total' => (float) $sale->total,
                'status' => $sale->status === 'cancelled' ? 'Anulada' : 'Completada',
                'detail_url' => route('sales.show', $sale),
                'print_url' => route('sales.receipt', $sale),
            ]);

        return $this->report('Ventas', 'reports.sales', [
            ['key' => 'created_at', 'label' => 'Fecha / hora'],
            ['key' => 'number', 'label' => 'No. venta'],
            ['key' => 'customer', 'label' => 'Cliente'],
            ['key' => 'nit', 'label' => 'NIT'],
            ['key' => 'seller', 'label' => 'Vendedor'],
            ['key' => 'document_type', 'label' => 'Tipo documento'],
            ['key' => 'payment_method', 'label' => 'Forma de pago'],
            ['key' => 'total', 'label' => 'Total', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Estado'],
            ['key' => 'detail_url', 'label' => 'Ver detalle', 'type' => 'link', 'link_label' => 'Ver'],
            ['key' => 'print_url', 'label' => 'Imprimir', 'type' => 'link', 'link_label' => 'Imprimir'],
        ], $rows, [
            'filters' => [
                'date_from' => $range->dateFrom,
                'date_to' => $range->dateTo,
                'customer_search' => $customerSearch,
                'seller_id' => $sellerId,
                'payment_method' => $paymentMethod,
                'document_type' => $documentType,
                'status' => $status,
                'sale_number' => $saleNumber,
            ],
            'summary' => [
                ['label' => 'Total ventas', 'value' => $totalSales],
                ['label' => 'Total vendido', 'value' => $totalSold, 'money' => true],
                ['label' => 'Total efectivo', 'value' => (float) ($paymentTotals['cash'] ?? 0), 'money' => true],
                ['label' => 'Total tarjeta', 'value' => (float) ($paymentTotals['card'] ?? 0), 'money' => true],
                ['label' => 'Total transferencia', 'value' => (float) (($paymentTotals['transfer'] ?? 0) + ($paymentTotals['bank_transfer'] ?? 0)), 'money' => true],
                ['label' => 'Total otros', 'value' => (float) (($paymentTotals['check'] ?? 0) + ($paymentTotals['credit'] ?? 0) + ($paymentTotals['other'] ?? 0)), 'money' => true],
                ['label' => 'Cantidad de facturas', 'value' => $invoiceCount],
                ['label' => 'Cantidad de comprobantes', 'value' => $receiptCount],
            ],
            'sellers' => User::query()->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'branch' => $scope->payload(),
        ]);
    }

    public function lowStock(Request $request): Response
    {
        $businessId = currentBusinessId();
        $scope = BranchReportScope::current($businessId);
        $categoryId = $request->integer('category_id') ?: null;
        $productSearch = trim((string) $request->query('product_search', ''));
        $onlyBelowMinimum = filter_var($request->query('only_below_minimum', true), FILTER_VALIDATE_BOOLEAN);

        $reservedSubquery = DB::table('credit_receipt_lines')
            ->where('business_id', $businessId)
            ->where('branch_id', $scope->branch->id)
            ->whereIn('status', ['pending', 'partially_invoiced'])
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_pending) as reserved');

        $lastInSubquery = DB::table('stock_movements')
            ->where('business_id', $businessId)
            ->where('branch_id', $scope->branch->id)
            ->where('quantity', '>', 0)
            ->groupBy('product_id')
            ->selectRaw('product_id, MAX(created_at) as last_in_at');

        $products = Product::query()
            ->where('products.business_id', $businessId)
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('product_branch_stocks as pbs', function ($join) use ($businessId, $scope) {
                $join->on('pbs.product_id', '=', 'products.id')
                    ->where('pbs.business_id', '=', $businessId)
                    ->where('pbs.branch_id', '=', $scope->branch->id);
            })
            ->leftJoinSub($reservedSubquery, 'reserved_lines', fn ($join) => $join->on('reserved_lines.product_id', '=', 'products.id'))
            ->leftJoinSub($lastInSubquery, 'last_in', fn ($join) => $join->on('last_in.product_id', '=', 'products.id'))
            ->when($categoryId, fn ($query) => $query->where('products.category_id', $categoryId))
            ->when($productSearch !== '', function ($query) use ($productSearch) {
                $query->where(function ($query) use ($productSearch) {
                    $query->where('products.name', 'ilike', "%{$productSearch}%")
                        ->orWhere('products.code', 'ilike', "%{$productSearch}%")
                        ->orWhere('products.barcode', 'ilike', "%{$productSearch}%");
                });
            })
            ->when($onlyBelowMinimum, fn ($query) => $query->whereRaw('(COALESCE(pbs.stock, 0) - COALESCE(reserved_lines.reserved, 0)) <= products.min_stock'))
            ->orderByRaw('(COALESCE(pbs.stock, 0) - COALESCE(reserved_lines.reserved, 0)) ASC')
            ->orderBy('products.name')
            ->paginate($this->reportPerPage($request), [
                'products.id',
                'products.name as product',
                DB::raw('COALESCE(products.code, products.barcode) as code'),
                DB::raw("COALESCE(categories.name, '-') as category"),
                'products.min_stock as minimum',
                DB::raw('COALESCE(pbs.stock, 0) as stock'),
                DB::raw('COALESCE(reserved_lines.reserved, 0) as reserved'),
                DB::raw('(COALESCE(pbs.stock, 0) - COALESCE(reserved_lines.reserved, 0)) as available'),
                DB::raw('GREATEST(products.min_stock - (COALESCE(pbs.stock, 0) - COALESCE(reserved_lines.reserved, 0)), 0) as suggested_missing'),
                'last_in.last_in_at',
            ])
            ->withQueryString()
            ->through(fn ($row) => [
                'product' => $row->product,
                'code' => $row->code,
                'category' => $row->category,
                'minimum' => (float) $row->minimum,
                'stock' => (float) $row->stock,
                'reserved' => (float) $row->reserved,
                'available' => (float) $row->available,
                'suggested_missing' => (float) $row->suggested_missing,
                'last_in_at' => $row->last_in_at ? Carbon::parse($row->last_in_at)->timezone(tenantTimezone())->format('Y-m-d H:i') : '-',
                'cardex_url' => route('products.stock-history', $row->id),
            ]);

        return $this->report('Stock bajo', 'reports.low-stock', [
            ['key' => 'product', 'label' => 'Producto'],
            ['key' => 'code', 'label' => 'Código/SKU'],
            ['key' => 'category', 'label' => 'Categoría'],
            ['key' => 'minimum', 'label' => 'Mínimo', 'type' => 'number'],
            ['key' => 'stock', 'label' => 'Existencia', 'type' => 'number'],
            ['key' => 'reserved', 'label' => 'Reservado', 'type' => 'number'],
            ['key' => 'available', 'label' => 'Disponible', 'type' => 'number'],
            ['key' => 'suggested_missing', 'label' => 'Faltante sugerido', 'type' => 'number'],
            ['key' => 'last_in_at', 'label' => 'Último ingreso'],
            ['key' => 'cardex_url', 'label' => 'Acción', 'type' => 'link', 'link_label' => 'Ver cardex'],
        ], $products, [
            'filters' => ['category_id' => $categoryId, 'product_search' => $productSearch, 'only_below_minimum' => $onlyBelowMinimum ? '1' : '0'],
            'categories' => Category::query()->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'branch' => $scope->payload(),
        ]);
    }

    public function topProducts(Request $request): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->find($businessId);
        $scope = BranchReportScope::current($businessId);
        $range = ReportDateRange::monthToDate($request, $business);
        $categoryId = $request->integer('category_id') ?: null;
        $productSearch = trim((string) $request->query('product_search', ''));
        $sellerId = $request->integer('seller_id') ?: null;
        $canViewProfit = $request->user()?->hasPermission('reports.profit.view') ?? false;

        $base = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('products', function ($join) use ($businessId) {
                $join->on('sale_items.product_id', '=', 'products.id')
                    ->where('products.business_id', '=', $businessId);
            })
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('sale_items.business_id', $businessId)
            ->where('sales.business_id', $businessId)
            ->where('sales.branch_id', $scope->branch->id)
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$range->start, $range->end])
            ->when($categoryId, fn ($query) => $query->where('products.category_id', $categoryId))
            ->when($sellerId, fn ($query) => $query->where('sales.created_by', $sellerId))
            ->when($productSearch !== '', function ($query) use ($productSearch) {
                $query->where(function ($query) use ($productSearch) {
                    $query->where('sale_items.product_name', 'ilike', "%{$productSearch}%")
                        ->orWhere('products.code', 'ilike', "%{$productSearch}%")
                        ->orWhere('products.barcode', 'ilike', "%{$productSearch}%");
                });
            });

        $unitsSold = (float) (clone $base)->sum('sale_items.quantity');
        $totalSold = (float) (clone $base)->sum('sale_items.total');
        $topProduct = (clone $base)
            ->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->orderByDesc(DB::raw('SUM(sale_items.quantity)'))
            ->value('sale_items.product_name');

        $columns = [
            ['key' => 'product', 'label' => 'Producto'],
            ['key' => 'code', 'label' => 'Código/SKU'],
            ['key' => 'category', 'label' => 'Categoría'],
            ['key' => 'quantity', 'label' => 'Cantidad vendida', 'type' => 'number'],
            ['key' => 'total', 'label' => 'Total vendido', 'type' => 'money'],
        ];

        if ($canViewProfit) {
            $columns[] = ['key' => 'profit', 'label' => 'Utilidad', 'type' => 'money'];
        }

        $products = $base
            ->select([
                'sale_items.product_name as product',
                DB::raw('COALESCE(products.code, products.barcode) as code'),
                DB::raw("COALESCE(categories.name, '-') as category"),
                DB::raw('SUM(sale_items.quantity) as quantity_sold'),
                DB::raw('SUM(sale_items.total) as total_sold'),
                DB::raw('SUM(COALESCE(sale_items.profit_amount, 0)) as profit'),
            ])
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'products.code', 'products.barcode', 'categories.name')
            ->orderByDesc(DB::raw('SUM(sale_items.quantity)'))
            ->paginate($this->reportPerPage($request))
            ->withQueryString()
            ->through(fn ($row) => [
                'product' => $row->product,
                'code' => $row->code,
                'category' => $row->category,
                'quantity' => (float) $row->quantity_sold,
                'total' => (float) $row->total_sold,
                'profit' => $canViewProfit ? (float) $row->profit : null,
            ]);

        return $this->report('Productos más vendidos', 'reports.top-products', $columns, $products, [
            'filters' => [
                'date_from' => $range->dateFrom,
                'date_to' => $range->dateTo,
                'category_id' => $categoryId,
                'product_search' => $productSearch,
                'seller_id' => $sellerId,
            ],
            'summary' => [
                ['label' => 'Total unidades vendidas', 'value' => $unitsSold],
                ['label' => 'Total monetario vendido', 'value' => $totalSold, 'money' => true],
                ['label' => 'Producto más vendido', 'value' => $topProduct ?: '-'],
            ],
            'categories' => Category::query()->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'sellers' => User::query()->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'branch' => $scope->payload(),
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
            ->paginate($this->reportPerPage($request))
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
                'available' => $stock - $reserved,
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
            ->paginate($this->reportPerPage($request))
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
            ->paginate($this->reportPerPage($request))
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
            ->paginate($this->reportPerPage($request))
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
                'available' => $stock - $reserved,
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
            ->paginate($this->reportPerPage($request))
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
            ->paginate($this->reportPerPage($request))
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
                ->paginate($this->reportPerPage($request))
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
                ->paginate($this->reportPerPage($request))
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
            ->paginate($this->reportPerPage($request))
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
            ->paginate($this->reportPerPage($request), [
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
            'sellers' => $extra['sellers'] ?? [],
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

    public function export(Request $request, string $report, string $format): SymfonyResponse
    {
        abort_unless(isset(self::REPORT_EXPORTS[$report]), 404);
        abort_unless(in_array($format, ['excel', 'pdf'], true), 404);
        abort_unless(Permissions::userHas($request->user(), Permissions::REPORTS_EXPORT), 403);

        $config = self::REPORT_EXPORTS[$report];
        abort_unless(Permissions::userHas($request->user(), $config['permission']), 403);

        return $this->downloadTableExport(
            $this->reportExportPayload($request, $config['method']),
            $format,
            $report,
        );
    }

    public function salesExportExcel(Request $request): SymfonyResponse
    {
        return $this->export($request, 'sales', 'excel');
    }

    public function salesExportPdf(Request $request): SymfonyResponse
    {
        return $this->export($request, 'sales', 'pdf');
    }

    public function lowStockExportExcel(Request $request): SymfonyResponse
    {
        return $this->export($request, 'low-stock', 'excel');
    }

    public function lowStockExportPdf(Request $request): SymfonyResponse
    {
        return $this->export($request, 'low-stock', 'pdf');
    }

    public function topProductsExportExcel(Request $request): SymfonyResponse
    {
        return $this->export($request, 'top-products', 'excel');
    }

    public function topProductsExportPdf(Request $request): SymfonyResponse
    {
        return $this->export($request, 'top-products', 'pdf');
    }

    private function reportPerPage(Request $request): int
    {
        return $request->boolean('__export') ? self::EXPORT_LIMIT : 25;
    }

    private function reportExportPayload(Request $request, string $method): array
    {
        $exportRequest = clone $request;
        $exportRequest->query->set('__export', '1');
        $exportRequest->headers->set('X-Inertia', 'true');

        /** @var Response $inertiaResponse */
        $inertiaResponse = $this->{$method}($exportRequest);
        $jsonResponse = $inertiaResponse->toResponse($exportRequest);
        $page = json_decode($jsonResponse->getContent(), true);
        $props = $page['props'] ?? [];
        $rows = $props['rows'] ?? [];
        $totalRows = (int) ($rows['total'] ?? count($rows['data'] ?? []));

        if ($totalRows > self::EXPORT_LIMIT) {
            throw ValidationException::withMessages([
                'export' => 'La exportación es demasiado grande. Reduce los filtros e inténtalo nuevamente.',
            ]);
        }

        return $this->normalizeTableExportPayload($props, $rows['data'] ?? []);
    }

    private function normalizeTableExportPayload(array $props, array $data): array
    {
        $columns = collect($props['columns'] ?? [])
            ->reject(fn (array $column) => ($column['type'] ?? null) === 'link')
            ->values();

        $rows = collect($data)
            ->map(fn (array $row) => $columns
                ->map(fn (array $column) => TableExporter::value($row[$column['key']] ?? null))
                ->all())
            ->all();

        $branchName = $props['branch']['name'] ?? BranchReportScope::current(currentBusinessId())->branch->name;

        return [
            'title' => $props['title'] ?? 'Reporte',
            'businessName' => Business::query()->whereKey(currentBusinessId())->value('name') ?? 'Empresa',
            'branchName' => $branchName,
            'generatedAt' => now(tenantTimezone())->format('Y-m-d H:i'),
            'filters' => TableExporter::filters($props['filters'] ?? []),
            'columns' => $columns->pluck('label')->all(),
            'rows' => $rows,
            'summary' => collect($props['summary'] ?? [])
                ->reject(fn (array $item) => (bool) ($item['hidden'] ?? false))
                ->map(fn (array $item) => [
                    'label' => $item['label'] ?? '',
                    'value' => TableExporter::value($item['value'] ?? null),
                ])
                ->values()
                ->all(),
        ];
    }

    private function downloadTableExport(array $payload, string $format, string $filename): SymfonyResponse
    {
        $filename = str($filename)->slug()->toString() ?: 'reporte';

        if ($format === 'excel') {
            $sheet = [
                [$payload['title']],
                ['Empresa', $payload['businessName']],
                ['Sucursal', $payload['branchName']],
                ['Generado', $payload['generatedAt']],
                ['Filtros', $payload['filters']],
                [],
                $payload['columns'],
                ...$payload['rows'],
            ];

            if (! empty($payload['summary'])) {
                $sheet[] = [];
                $sheet[] = ['Resumen'];
                foreach ($payload['summary'] as $item) {
                    $sheet[] = [$item['label'], $item['value']];
                }
            }

            return Excel::download(new ArrayTableExport($sheet, $payload['title']), "{$filename}.xlsx");
        }

        return Pdf::loadView('exports.table', $payload)
            ->setPaper('a4', 'landscape')
            ->download("{$filename}.pdf");
    }

    private function exportValue(mixed $value): string|int|float
    {
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return is_numeric($value) ? (float) $value : (string) $value;
    }

    private function formatFilters(array $filters): string
    {
        return collect($filters)
            ->reject(fn ($value) => $value === null || $value === '' || $value === 'all')
            ->map(fn ($value, $key) => "{$key}: {$value}")
            ->implode(' | ');
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
