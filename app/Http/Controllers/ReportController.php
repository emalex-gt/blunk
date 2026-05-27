<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Support\CashRegister;
use App\Support\BranchInventory;
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
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'))
            ->whereBetween('created_at', [$start, $end]);

        $topProduct = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sale_items.business_id', $businessId)
            ->where('sales.business_id', $businessId)
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

    private function branchFilter(Request $request, int $businessId): ?int
    {
        if (! BranchInventory::branchesEnabled($businessId)) {
            return null;
        }

        $branchId = $request->integer('branch_id');

        if (! $branchId) {
            return null;
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
        abort(501, 'Exportación aún no implementada');
    }

    public function salesExportPdf(): never
    {
        // TODO: Implementar exportación a PDF.
        abort(501, 'Exportación aún no implementada');
    }

    public function lowStockExportExcel(): never
    {
        // TODO: Implementar exportación a Excel.
        abort(501, 'Exportación aún no implementada');
    }

    public function topProductsExportExcel(): never
    {
        // TODO: Implementar exportación a Excel.
        abort(501, 'Exportación aún no implementada');
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
