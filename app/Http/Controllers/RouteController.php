<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\Product;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\RouteZoneCustomer;
use App\Models\TenantSetting;
use App\Models\TenantFelSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\CustomerIdentity;
use App\Support\GuatemalaNitCustomerResolver;
use App\Support\GuatemalaLocations;
use App\Support\IdempotencyService;
use App\Support\Inventory\StockReservationService;
use App\Support\ManualPricePolicy;
use App\Support\Permissions;
use App\Support\PriceLists;
use App\Support\RouteWorkDayCompletion;
use App\Support\StockAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RouteController extends Controller
{
    public function zones(Request $request): Response
    {
        $businessId = currentBusinessId();

        $zones = RouteZone::query()
            ->where('business_id', $businessId)
            ->with(['branch:id,name', 'assignedUser:id,name'])
            ->withCount(['zoneCustomers as active_customers_count' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get();

        return Inertia::render('Routes/Zones/Index', [
            'zones' => $zones,
            'branches' => BranchInventory::branchOptions($businessId),
            'sellers' => User::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'current_branch_id']),
        ]);
    }

    public function storeZone(Request $request): RedirectResponse
    {
        $data = $this->validateZone($request);
        RouteZone::query()->create(['business_id' => currentBusinessId(), ...$data]);

        return back()->with('success', 'Zona creada.');
    }

    public function updateZone(Request $request, RouteZone $zone): RedirectResponse
    {
        $this->authorizeBusiness($zone);
        $zone->update($this->validateZone($request));

        return back()->with('success', 'Zona actualizada.');
    }

    public function zoneCustomers(Request $request, RouteZone $zone): Response
    {
        $this->authorizeBusiness($zone);
        $businessId = currentBusinessId();
        $search = $request->string('search')->toString();

        return Inertia::render('Routes/Zones/Customers', [
            'zone' => $zone->load(['branch:id,name,department,municipality', 'assignedUser:id,name']),
            'assignments' => RouteZoneCustomer::query()
                ->where('business_id', $businessId)
                ->where('route_zone_id', $zone->id)
                ->with('customer:id,name,commercial_name,contact_name,doc_number,address,department,municipality,phone')
                ->orderByRaw('visit_order IS NULL, visit_order')
                ->orderBy('id')
                ->get(),
            'availableCustomers' => Customer::query()
                ->where('business_id', $businessId)
                ->when($search, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('name', 'ilike', "%{$search}%")
                            ->orWhere('commercial_name', 'ilike', "%{$search}%")
                            ->orWhere('doc_number', 'ilike', "%{$search}%")
                            ->orWhere('phone', 'ilike', "%{$search}%")
                            ->orWhere('address', 'ilike', "%{$search}%")
                            ->orWhere('department', 'ilike', "%{$search}%")
                            ->orWhere('municipality', 'ilike', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->limit(25)
                ->get(['id', 'name', 'commercial_name', 'doc_number', 'address', 'department', 'municipality', 'phone']),
            'filters' => ['search' => $search],
        ]);
    }

    public function storeZoneCustomer(Request $request, RouteZone $zone): RedirectResponse
    {
        $this->authorizeBusiness($zone);
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'visit_order' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        $customer = Customer::query()
            ->where('business_id', currentBusinessId())
            ->findOrFail($data['customer_id']);

        RouteZoneCustomer::query()->updateOrCreate(
            [
                'route_zone_id' => $zone->id,
                'customer_id' => $customer->id,
            ],
            [
                'business_id' => currentBusinessId(),
                'visit_order' => $data['visit_order'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_active' => true,
            ],
        );

        return back()->with('success', 'Cliente asignado a la zona.');
    }

    public function createZoneCustomer(Request $request, RouteZone $zone): RedirectResponse
    {
        $this->authorizeBusiness($zone);
        $zone->loadMissing('branch');

        $data = $this->validateRouteCustomer($request);
        $result = DB::transaction(function () use ($data, $zone) {
            $customerResult = $this->findOrCreateRouteCustomer($data, $zone->branch);
            /** @var Customer $customer */
            $customer = $customerResult['customer'];
            $assignment = $this->assignCustomerToZone($zone, $customer, $data['notes'] ?? null);

            $this->createMissingVisitsForOpenZoneWorkDays($zone, $customer, $assignment);

            return $customerResult;
        });

        $message = $result['created']
            ? 'Cliente creado y asignado a la zona.'
            : 'El cliente ya existía y fue asignado a la zona.';

        return back()->with('success', $message);
    }

    public function updateZoneCustomer(Request $request, RouteZone $zone, RouteZoneCustomer $assignment): RedirectResponse
    {
        $this->authorizeBusiness($zone);
        abort_unless((int) $assignment->route_zone_id === (int) $zone->id && (int) $assignment->business_id === currentBusinessId(), 403);

        $data = $request->validate([
            'visit_order' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $assignment->update($data);

        return back()->with('success', 'Orden de visita actualizado.');
    }

    public function updateZoneCustomerDetails(Request $request, RouteZone $zone, RouteZoneCustomer $assignment): RedirectResponse
    {
        $this->authorizeBusiness($zone);
        abort_unless((int) $assignment->route_zone_id === (int) $zone->id && (int) $assignment->business_id === currentBusinessId(), 403);
        $assignment->loadMissing('customer');
        abort_unless((int) $assignment->customer->business_id === currentBusinessId(), 403);

        $data = $this->validateRouteCustomer($request, requireAnyName: true);
        $this->updateCustomerFromRouteData($assignment->customer, $data, allowFiscalFields: true);

        if (array_key_exists('notes', $data)) {
            $assignment->update(['notes' => $data['notes'] !== '' ? $data['notes'] : null]);
        }

        return back()->with('success', 'Cliente actualizado correctamente.');
    }

    public function destroyZoneCustomer(RouteZone $zone, RouteZoneCustomer $assignment): RedirectResponse
    {
        $this->authorizeBusiness($zone);
        abort_unless((int) $assignment->route_zone_id === (int) $zone->id && (int) $assignment->business_id === currentBusinessId(), 403);
        $assignment->delete();

        return back()->with('success', 'Cliente removido de la zona.');
    }

    public function closedWorkDays(Request $request): Response
    {
        $businessId = currentBusinessId();
        $dateFrom = $request->date('date_from')?->startOfDay();
        $dateTo = $request->date('date_to')?->endOfDay();
        $status = $request->string('status')->toString();
        $status = in_array($status, ['closed', 'completed'], true) ? $status : '';
        $search = trim((string) $request->query('search', ''));
        $branchId = $request->filled('branch_id')
            ? Branch::query()->where('business_id', $businessId)->whereKey($request->integer('branch_id'))->value('id')
            : null;
        $sellerId = $request->filled('seller_id')
            ? User::query()->where('business_id', $businessId)->whereKey($request->integer('seller_id'))->value('id')
            : null;
        $zoneId = $request->filled('zone_id')
            ? RouteZone::query()->where('business_id', $businessId)->whereKey($request->integer('zone_id'))->value('id')
            : null;

        $query = RouteWorkDay::query()
            ->where('business_id', $businessId)
            ->where('status', 'closed')
            ->with(['branch:id,name', 'zone:id,name', 'seller:id,name'])
            ->select('route_work_days.*')
            ->selectSub(function ($query) use ($businessId) {
                $query->from('pre_sales')
                    ->selectRaw('COALESCE(SUM(total), 0)')
                    ->whereColumn('pre_sales.route_work_day_id', 'route_work_days.id')
                    ->where('pre_sales.business_id', $businessId)
                    ->whereNotIn('pre_sales.status', [PreSale::STATUS_DRAFT, PreSale::STATUS_CANCELLED]);
            }, 'pre_sales_total')
            ->withCount([
                'visits as total_clients_count',
                'visits as visited_clients_count' => fn ($query) => $query->where('status', '!=', 'pending'),
                'visits as with_pre_sale_count' => fn ($query) => $query->where('status', 'with_pre_sale'),
                'visits as without_sale_count' => fn ($query) => $query->where('status', 'without_sale'),
                'visits as pending_clients_count' => fn ($query) => $query->where('status', 'pending'),
                'preSales as pre_sales_count' => fn ($query) => $query->where('status', '!=', PreSale::STATUS_DRAFT),
            ])
            ->latest('work_date')
            ->latest('closed_at')
            ->latest('id');

        $query->when($request->filled('branch_id'), fn ($query) => $branchId ? $query->where('branch_id', $branchId) : $query->whereRaw('1 = 0'));
        $query->when($request->filled('seller_id'), fn ($query) => $sellerId ? $query->where('seller_id', $sellerId) : $query->whereRaw('1 = 0'));
        $query->when($request->filled('zone_id'), fn ($query) => $zoneId ? $query->where('route_zone_id', $zoneId) : $query->whereRaw('1 = 0'));
        $query->when($dateFrom, fn ($query) => $query->where('work_date', '>=', $dateFrom->toDateString()));
        $query->when($dateTo, fn ($query) => $query->where('work_date', '<=', $dateTo->toDateString()));
        $query->when($status === 'completed', fn ($query) => $query->whereNotNull('completed_at'));
        $query->when($status === 'closed', fn ($query) => $query->whereNull('completed_at'));
        $query->when($search !== '', function ($query) use ($search) {
            $query->where(function ($query) use ($search) {
                $query->whereHas('seller', fn ($query) => $query->where('name', 'ilike', "%{$search}%"))
                    ->orWhereHas('zone', fn ($query) => $query->where('name', 'ilike', "%{$search}%"));
            });
        });

        $workDays = $query->paginate(25)
            ->withQueryString()
            ->through(fn (RouteWorkDay $workDay) => [
                'id' => $workDay->id,
                'work_date' => $workDay->work_date?->toDateString(),
                'status' => $workDay->status,
                'closed_at' => $workDay->closed_at?->toIso8601String(),
                'completed_at' => $workDay->completed_at?->toIso8601String(),
                'total_clients_count' => (int) $workDay->total_clients_count,
                'visited_clients_count' => (int) $workDay->visited_clients_count,
                'pre_sales_count' => (int) $workDay->pre_sales_count,
                'without_sale_count' => (int) $workDay->without_sale_count,
                'pre_sales_total' => (float) $workDay->pre_sales_total,
                'branch' => $workDay->branch,
                'zone' => $workDay->zone,
                'seller' => $workDay->seller,
            ]);

        return Inertia::render('Routes/WorkDays/ClosedIndex', [
            'workDays' => $workDays,
            'filters' => [
                'date_from' => $request->query('date_from', ''),
                'date_to' => $request->query('date_to', ''),
                'branch_id' => $request->query('branch_id', ''),
                'zone_id' => $request->query('zone_id', ''),
                'seller_id' => $request->query('seller_id', ''),
                'status' => $status,
                'search' => $search,
            ],
            'branches' => Branch::query()->where('business_id', $businessId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'sellers' => User::query()->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'zones' => RouteZone::query()->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function showWorkDay(Request $request, RouteWorkDay $workDay): Response
    {
        abort_unless((int) $workDay->business_id === currentBusinessId(), 403);

        $businessId = currentBusinessId();
        $workDay->load(['branch:id,name', 'zone:id,name', 'seller:id,name', 'completedBy:id,name']);

        $summary = [
            'total_clients' => RouteVisit::query()->where('business_id', $businessId)->where('route_work_day_id', $workDay->id)->count(),
            'visited' => RouteVisit::query()->where('business_id', $businessId)->where('route_work_day_id', $workDay->id)->where('status', '!=', 'pending')->count(),
            'with_pre_sale' => RouteVisit::query()->where('business_id', $businessId)->where('route_work_day_id', $workDay->id)->where('status', 'with_pre_sale')->count(),
            'without_sale' => RouteVisit::query()->where('business_id', $businessId)->where('route_work_day_id', $workDay->id)->where('status', 'without_sale')->count(),
            'pending' => RouteVisit::query()->where('business_id', $businessId)->where('route_work_day_id', $workDay->id)->where('status', 'pending')->count(),
            'pre_sales_total' => (float) PreSale::query()
                ->where('business_id', $businessId)
                ->where('route_work_day_id', $workDay->id)
                ->whereNotIn('status', [PreSale::STATUS_DRAFT, PreSale::STATUS_CANCELLED])
                ->sum('total'),
            'prepared_total' => (float) PreSale::query()
                ->where('business_id', $businessId)
                ->where('route_work_day_id', $workDay->id)
                ->whereIn('status', [PreSale::STATUS_PICKED, PreSale::STATUS_CONVERTED])
                ->sum('total'),
            'converted_total' => (float) PreSale::query()
                ->where('business_id', $businessId)
                ->where('route_work_day_id', $workDay->id)
                ->where('status', PreSale::STATUS_CONVERTED)
                ->sum('total'),
        ];

        $preSales = PreSale::query()
            ->where('business_id', $businessId)
            ->where('route_work_day_id', $workDay->id)
            ->with(['customer:id,name,commercial_name,contact_name,doc_number', 'seller:id,name', 'zone:id,name', 'branch:id,name', 'workDay:id,work_date,status,completed_at'])
            ->select('pre_sales.*')
            ->selectSub(function ($query) use ($businessId) {
                $query->from('stock_reservations')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('stock_reservations.source_id', 'pre_sales.id')
                    ->where('stock_reservations.business_id', $businessId)
                    ->where('stock_reservations.source_type', 'pre_sale')
                    ->where('stock_reservations.status', 'active');
            }, 'reserved_quantity_total')
            ->selectSub(function ($query) {
                $query->from('pre_sale_items')
                    ->selectRaw('COALESCE(SUM(picked_quantity), 0)')
                    ->whereColumn('pre_sale_items.pre_sale_id', 'pre_sales.id');
            }, 'picked_quantity_total')
            ->withCount('items')
            ->latest('submitted_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Routes/WorkDays/Show', [
            'workDay' => [
                'id' => $workDay->id,
                'work_date' => $workDay->work_date?->toDateString(),
                'status' => $workDay->status,
                'started_at' => $workDay->started_at?->toIso8601String(),
                'closed_at' => $workDay->closed_at?->toIso8601String(),
                'completed_at' => $workDay->completed_at?->toIso8601String(),
                'completed_by' => $workDay->completedBy,
                'branch' => $workDay->branch,
                'zone' => $workDay->zone,
                'seller' => $workDay->seller,
                'summary' => $summary,
            ],
            'preSales' => $preSales,
            'canInvoice' => Permissions::userHas(request()->user(), Permissions::ROUTES_PRE_SALES_INVOICE),
            'activeBranchId' => BranchInventory::activeBranch($businessId)->id,
        ]);
    }

    public function preSales(Request $request): Response
    {
        $businessId = currentBusinessId();
        $status = $request->string('status')->toString();
        $status = in_array($status, ['draft', 'submitted', 'processing', 'picked', 'converted', 'cancelled'], true) ? $status : null;
        $dateFrom = $request->date('date_from')?->startOfDay();
        $dateTo = $request->date('date_to')?->endOfDay();
        $branchId = $request->filled('branch_id')
            ? Branch::query()->where('business_id', $businessId)->whereKey($request->integer('branch_id'))->value('id')
            : null;
        $sellerId = $request->filled('seller_id')
            ? User::query()->where('business_id', $businessId)->whereKey($request->integer('seller_id'))->value('id')
            : null;
        $zoneId = $request->filled('zone_id')
            ? RouteZone::query()->where('business_id', $businessId)->whereKey($request->integer('zone_id'))->value('id')
            : null;
        $customerSearch = trim((string) $request->query('customer', ''));
        $productSearch = trim((string) $request->query('product_search', ''));

        $query = PreSale::query()
            ->where('business_id', $businessId)
            ->with(['customer:id,name,commercial_name,contact_name,doc_number', 'seller:id,name', 'zone:id,name', 'branch:id,name', 'workDay:id,work_date,status'])
            ->select('pre_sales.*')
            ->selectSub(function ($query) use ($businessId) {
                $query->from('stock_reservations')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('stock_reservations.source_id', 'pre_sales.id')
                    ->where('stock_reservations.business_id', $businessId)
                    ->where('stock_reservations.source_type', 'pre_sale')
                    ->where('stock_reservations.status', 'active');
            }, 'reserved_quantity_total')
            ->selectSub(function ($query) {
                $query->from('pre_sale_items')
                    ->selectRaw('COALESCE(SUM(picked_quantity), 0)')
                    ->whereColumn('pre_sale_items.pre_sale_id', 'pre_sales.id');
            }, 'picked_quantity_total')
            ->withCount('items')
            ->latest('submitted_at')
            ->latest('id');

        $query->when($status, fn ($query) => $query->where('status', $status));
        $query->when(! $status, fn ($query) => $query->whereIn('status', ['submitted', 'processing', 'picked']));
        $query->when($request->filled('branch_id'), fn ($query) => $branchId ? $query->where('branch_id', $branchId) : $query->whereRaw('1 = 0'));
        $query->when($request->filled('seller_id'), fn ($query) => $sellerId ? $query->where('seller_id', $sellerId) : $query->whereRaw('1 = 0'));
        $query->when($request->filled('zone_id'), fn ($query) => $zoneId ? $query->where('route_zone_id', $zoneId) : $query->whereRaw('1 = 0'));
        $query->when($dateFrom, fn ($query) => $query->where('created_at', '>=', $dateFrom));
        $query->when($dateTo, fn ($query) => $query->where('created_at', '<=', $dateTo));
        $query->when($customerSearch !== '', function ($query) use ($businessId, $customerSearch) {
            $query->whereHas('customer', fn ($query) => $query
                ->where('business_id', $businessId)
                ->where(function ($query) use ($customerSearch) {
                    $query->where('name', 'ilike', "%{$customerSearch}%")
                        ->orWhere('commercial_name', 'ilike', "%{$customerSearch}%")
                        ->orWhere('contact_name', 'ilike', "%{$customerSearch}%")
                        ->orWhere('doc_number', 'ilike', "%{$customerSearch}%");
                }));
        });
        $query->when($productSearch !== '', function ($query) use ($businessId, $productSearch) {
            $query->whereHas('items.product', fn ($query) => $query
                ->where('business_id', $businessId)
                ->where(function ($query) use ($productSearch) {
                    $query->where('name', 'ilike', "%{$productSearch}%")
                        ->orWhere('code', 'ilike', "%{$productSearch}%")
                        ->orWhere('barcode', 'ilike', "%{$productSearch}%");
                }));
        });

        return Inertia::render('Routes/PreSales/Index', [
            'preSales' => $query->paginate(25)->withQueryString(),
            'filters' => [
                'status' => $status ?? '',
                'branch_id' => $request->query('branch_id', ''),
                'seller_id' => $request->query('seller_id', ''),
                'zone_id' => $request->query('zone_id', ''),
                'date_from' => $request->query('date_from', ''),
                'date_to' => $request->query('date_to', ''),
                'customer' => $customerSearch,
                'product_search' => $productSearch,
            ],
            'branches' => Branch::query()->where('business_id', $businessId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'sellers' => User::query()->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'zones' => RouteZone::query()->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'canInvoice' => Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_INVOICE),
            'activeBranchId' => BranchInventory::activeBranch($businessId)->id,
        ]);
    }

    public function showPreSale(PreSale $preSale): Response
    {
        abort_unless((int) $preSale->business_id === currentBusinessId(), 403);
        abort_unless(Permissions::userHas(request()->user(), Permissions::ROUTES_PRE_SALES_ADMIN_VIEW), 403);

        $preSale->load([
            'branch:id,name',
            'zone:id,name',
            'seller:id,name',
            'processingUser:id,name',
            'pickedBy:id,name',
            'convertedBy:id,name',
            'convertedSale:id,business_id,business_number,document_type,total',
            'customer:id,name,commercial_name,contact_name,doc_number,address,phone',
            'workDay:id,work_date,status,started_at,closed_at',
            'visit:id,status,visit_order,no_sale_reason,no_sale_note,started_at,finished_at',
            'items.product:id,business_id,name,code,barcode,image_url',
        ]);

        $productIds = $preSale->items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $stockBreakdown = StockAvailability::getBreakdownForProducts((int) $preSale->business_id, (int) $preSale->branch_id, $productIds);
        $reservedByItem = DB::table('stock_reservations')
            ->where('business_id', $preSale->business_id)
            ->where('branch_id', $preSale->branch_id)
            ->where('source_type', 'pre_sale')
            ->where('source_id', $preSale->id)
            ->where('status', 'active')
            ->groupBy('source_item_id')
            ->selectRaw('source_item_id, COALESCE(SUM(quantity), 0) as quantity')
            ->pluck('quantity', 'source_item_id');
        $tenantSettings = TenantSetting::query()->where('business_id', $preSale->business_id)->first();
        $felSettings = TenantFelSetting::query()->where('business_id', $preSale->business_id)->first();
        $business = Business::query()->findOrFail($preSale->business_id);
        $invoiceAvailable = $business->country === 'GT'
            && (bool) ($tenantSettings?->allow_invoices ?? false)
            && module_enabled('fel_gt', $preSale->business_id)
            && (bool) $felSettings?->enabled
            && (bool) $felSettings?->isConfigured();

        return Inertia::render('Routes/PreSales/Show', [
            'preSale' => [
                'id' => $preSale->id,
                'status' => $preSale->status,
                'created_at' => $preSale->created_at?->toIso8601String(),
                'submitted_at' => $preSale->submitted_at?->toIso8601String(),
                'processing_started_at' => $preSale->processing_started_at?->toIso8601String(),
                'processing_user' => $preSale->processingUser,
                'picked_at' => $preSale->picked_at?->toIso8601String(),
                'picked_by' => $preSale->pickedBy,
                'converted_at' => $preSale->converted_at?->toIso8601String(),
                'converted_by' => $preSale->convertedBy,
                'converted_sale' => $preSale->convertedSale,
                'cancelled_at' => $preSale->cancelled_at?->toIso8601String(),
                'cancellation_reason' => $preSale->cancellation_reason,
                'cancellation_note' => $preSale->cancellation_note,
                'notes' => $preSale->notes,
                'subtotal' => (float) $preSale->subtotal,
                'discount_total' => (float) $preSale->discount_total,
                'total' => (float) $preSale->total,
                'prepared_total' => round((float) $preSale->items->sum(function ($item) {
                    $picked = (float) ($item->picked_quantity ?? 0);
                    $quantity = max((float) $item->quantity, 1);

                    return max(0, round(($picked * (float) $item->unit_price) - (((float) $item->discount / $quantity) * $picked), 2));
                }), 2),
                'branch' => $preSale->branch,
                'zone' => $preSale->zone,
                'seller' => $preSale->seller,
                'customer' => $preSale->customer,
                'work_day' => $preSale->workDay,
                'visit' => $preSale->visit,
                'items' => $preSale->items->map(function ($item) use ($stockBreakdown, $reservedByItem) {
                    $breakdown = $stockBreakdown->get((int) $item->product_id, []);

                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_code' => $item->product?->code ?: $item->product?->barcode,
                        'product_barcode' => $item->product?->barcode,
                        'product_name' => $item->product?->name,
                        'quantity' => (float) $item->quantity,
                        'picked_quantity' => $item->picked_quantity !== null ? (float) $item->picked_quantity : null,
                        'picking_note' => $item->picking_note,
                        'reserved_quantity' => (float) ($reservedByItem[$item->id] ?? 0),
                        'unit_price' => (float) $item->unit_price,
                        'discount' => (float) $item->discount,
                        'total' => (float) $item->total,
                        'physical_stock' => (float) ($breakdown['physical_stock'] ?? 0),
                        'reserved_total' => (float) ($breakdown['reserved_total'] ?? 0),
                        'available_stock' => (float) ($breakdown['available_stock'] ?? 0),
                    ];
                })->values(),
            ],
            'canInvoice' => Permissions::userHas(request()->user(), Permissions::ROUTES_PRE_SALES_INVOICE)
                && (int) BranchInventory::activeBranch((int) $preSale->business_id)->id === (int) $preSale->branch_id,
            'invoiceOptions' => [
                'mode' => $tenantSettings?->route_pre_sale_invoicing_mode ?: 'manual',
                'document_types' => array_values(array_filter([
                    (bool) ($tenantSettings?->allow_receipts ?? true) ? 'receipt' : null,
                    $invoiceAvailable ? 'invoice' : null,
                ])),
                'credit_enabled' => (bool) ($tenantSettings?->enable_credit_sales ?? false),
                'payment_methods' => ['cash', 'card', 'transfer', 'check'],
            ],
        ]);
    }

    public function pickPreSale(PreSale $preSale): Response
    {
        abort_unless((int) $preSale->business_id === currentBusinessId(), 403);
        abort_unless(Permissions::userHas(request()->user(), Permissions::ROUTES_PRE_SALES_PICK), 403);

        if (! in_array($preSale->status, [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING], true)) {
            throw ValidationException::withMessages([
                'pre_sale' => 'La preventa cambió de estado. Actualiza la página e intenta de nuevo.',
            ]);
        }

        $preSale->load([
            'branch:id,name',
            'zone:id,name',
            'seller:id,name',
            'customer:id,name,commercial_name,contact_name,doc_number,address,phone',
            'items.product:id,business_id,name,code,barcode,image_url',
        ]);

        $productIds = $preSale->items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $stockBreakdown = StockAvailability::getBreakdownForProducts((int) $preSale->business_id, (int) $preSale->branch_id, $productIds);
        $reservedByItem = DB::table('stock_reservations')
            ->where('business_id', $preSale->business_id)
            ->where('branch_id', $preSale->branch_id)
            ->where('source_type', 'pre_sale')
            ->where('source_id', $preSale->id)
            ->where('status', 'active')
            ->groupBy('source_item_id')
            ->selectRaw('source_item_id, COALESCE(SUM(quantity), 0) as quantity')
            ->pluck('quantity', 'source_item_id');

        return Inertia::render('Routes/PreSales/Pick', [
            'preSale' => [
                'id' => $preSale->id,
                'status' => $preSale->status,
                'submitted_at' => $preSale->submitted_at?->toIso8601String(),
                'processing_started_at' => $preSale->processing_started_at?->toIso8601String(),
                'branch' => $preSale->branch,
                'zone' => $preSale->zone,
                'seller' => $preSale->seller,
                'customer' => $preSale->customer,
                'items' => $preSale->items->map(function ($item) use ($stockBreakdown, $reservedByItem) {
                    $breakdown = $stockBreakdown->get((int) $item->product_id, []);
                    $requestedQuantity = (float) $item->quantity;
                    $reservedQuantity = (float) ($reservedByItem[$item->id] ?? 0);

                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_code' => $item->product?->code ?: $item->product?->barcode,
                        'product_barcode' => $item->product?->barcode,
                        'product_name' => $item->product?->name,
                        'quantity' => $requestedQuantity,
                        'reserved_quantity' => $reservedQuantity,
                        'picked_quantity' => $item->picked_quantity !== null
                            ? (float) $item->picked_quantity
                            : min($requestedQuantity, $reservedQuantity),
                        'picking_note' => $item->picking_note,
                        'unit_price' => (float) $item->unit_price,
                        'physical_stock' => (float) ($breakdown['physical_stock'] ?? 0),
                        'reserved_total' => (float) ($breakdown['reserved_total'] ?? 0),
                        'available_stock' => (float) ($breakdown['available_stock'] ?? 0),
                    ];
                })->values(),
            ],
        ]);
    }

    public function storePreSalePicking(Request $request, PreSale $preSale, StockReservationService $reservations): RedirectResponse
    {
        abort_unless((int) $preSale->business_id === currentBusinessId(), 403);
        abort_unless(Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_PICK), 403);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.picked_quantity' => ['required', 'numeric', 'min:0'],
            'items.*.picking_note' => ['nullable', 'string', 'max:1000'],
        ]);

        app(IdempotencyService::class)->run(
            (int) $preSale->business_id,
            (int) $preSale->branch_id,
            $request->user()->id,
            'pre_sale_pick',
            $data['idempotency_key'],
            [
                'business_id' => (int) $preSale->business_id,
                'branch_id' => (int) $preSale->branch_id,
                'user_id' => $request->user()->id,
                'operation_type' => 'pre_sale_pick',
                'pre_sale_id' => $preSale->id,
                'items' => $data['items'],
            ],
            function () use ($request, $preSale, $data, $reservations) {
                DB::transaction(function () use ($request, $preSale, $data, $reservations) {
            $lockedPreSale = PreSale::query()
                ->where('business_id', currentBusinessId())
                ->whereKey($preSale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedPreSale->status, [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING], true)) {
                if ($lockedPreSale->status === PreSale::STATUS_PICKED) {
                    throw ValidationException::withMessages([
                        'pre_sale' => 'Esta preventa ya está lista para facturar y no se puede cancelar desde esta fase.',
                    ]);
                }

                throw ValidationException::withMessages([
                    'pre_sale' => 'La preventa cambió de estado. Actualiza la página e intenta de nuevo.',
                ]);
            }

            $rows = collect($data['items'])->keyBy(fn ($row) => (int) $row['id']);
            $items = $lockedPreSale->items()
                ->whereIn('id', $rows->keys()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($items->count() !== $lockedPreSale->items()->count() || $items->count() !== $rows->count()) {
                throw ValidationException::withMessages([
                    'pre_sale' => 'La preventa cambió de estado. Actualiza la página e intenta de nuevo.',
                ]);
            }

            $reservations->lockStockRowsForReservation(
                (int) $lockedPreSale->business_id,
                (int) $lockedPreSale->branch_id,
                $items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            );

            $reservationRows = DB::table('stock_reservations')
                ->where('business_id', $lockedPreSale->business_id)
                ->where('branch_id', $lockedPreSale->branch_id)
                ->where('source_type', 'pre_sale')
                ->where('source_id', $lockedPreSale->id)
                ->where('status', 'active')
                ->whereIn('source_item_id', $items->keys()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['source_item_id', 'quantity']);
            $reservedByItem = $reservationRows
                ->groupBy('source_item_id')
                ->map(fn ($rows) => $rows->sum(fn ($row) => (float) $row->quantity));

            $preparedQuantity = 0.0;

            foreach ($rows as $itemId => $row) {
                $item = $items->get($itemId);
                $pickedQuantity = round((float) $row['picked_quantity'], 4);
                $requestedQuantity = round((float) $item->quantity, 4);
                $reservedQuantity = round((float) ($reservedByItem[$itemId] ?? 0), 4);

                if ($pickedQuantity > $requestedQuantity || $pickedQuantity > $reservedQuantity) {
                    throw ValidationException::withMessages([
                        'items' => 'La cantidad preparada no puede exceder lo solicitado ni lo reservado.',
                    ]);
                }

                $preparedQuantity += $pickedQuantity;

                $item->update([
                    'picked_quantity' => $pickedQuantity,
                    'picking_note' => $row['picking_note'] ?? null,
                ]);

                $reservations->syncPreSaleItemPickedReservation($item, $pickedQuantity);
            }

            if ($preparedQuantity <= 0) {
                throw ValidationException::withMessages([
                    'items' => 'No puedes marcar como listo un pedido sin productos preparados. Cancela la preventa si no se preparará.',
                ]);
            }

            $lockedPreSale->update([
                'status' => PreSale::STATUS_PICKED,
                'picked_at' => now(),
                'picked_by' => $request->user()->id,
            ]);
                });

                return [
                    'result_id' => $preSale->id,
                    'response_payload' => ['pre_sale_id' => $preSale->id],
                ];
            },
            'pre_sale',
        );

        return redirect()
            ->route('routes.pre-sales.show', $preSale)
            ->with('success', 'Preventa lista para facturar.');
    }

    public function mobileZones(Request $request): Response|RedirectResponse
    {
        $branch = $this->sellerBranch($request);

        if (! $branch) {
            return back()->withErrors(['branch_id' => 'Tu usuario no tiene una sucursal asignada.']);
        }

        return Inertia::render('Routes/Mobile/Zones', [
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'zones' => RouteZone::query()
                ->where('business_id', currentBusinessId())
                ->where('branch_id', $branch->id)
                ->where('assigned_user_id', $request->user()->id)
                ->where('is_active', true)
                ->withCount(['zoneCustomers as active_customers_count' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('name')
                ->get(['id', 'business_id', 'branch_id', 'assigned_user_id', 'name', 'description']),
        ]);
    }

    public function startWorkDay(Request $request, RouteZone $zone): RedirectResponse
    {
        $this->authorizeSellerZone($request, $zone);

        $today = now()->toDateString();
        $openWorkDay = RouteWorkDay::query()
            ->where('business_id', currentBusinessId())
            ->where('branch_id', $zone->branch_id)
            ->where('route_zone_id', $zone->id)
            ->where('seller_id', $request->user()->id)
            ->where('status', 'open')
            ->oldest('id')
            ->first();

        $workDay = DB::transaction(function () use ($request, $zone, $today, $openWorkDay) {
            $workDay = $openWorkDay ?: RouteWorkDay::query()->create([
                'business_id' => currentBusinessId(),
                'branch_id' => $zone->branch_id,
                'route_zone_id' => $zone->id,
                'seller_id' => $request->user()->id,
                'work_date' => $today,
                'status' => 'open',
                'started_at' => now(),
            ]);

            $customers = RouteZoneCustomer::query()
                ->where('business_id', currentBusinessId())
                ->where('route_zone_id', $zone->id)
                ->where('is_active', true)
                ->orderByRaw('visit_order IS NULL, visit_order')
                ->orderBy('id')
                ->get();

            foreach ($customers as $zoneCustomer) {
                RouteVisit::query()->firstOrCreate(
                    [
                        'route_work_day_id' => $workDay->id,
                        'customer_id' => $zoneCustomer->customer_id,
                    ],
                    [
                        'business_id' => currentBusinessId(),
                        'branch_id' => $zone->branch_id,
                        'route_zone_id' => $zone->id,
                        'seller_id' => $request->user()->id,
                        'visit_order' => $zoneCustomer->visit_order,
                        'status' => 'pending',
                    ],
                );
            }

            return $workDay;
        });

        return redirect()
            ->route('routes.mobile.work-days.show', $workDay)
            ->setStatusCode(303)
            ->with('success', 'Jornada iniciada.');
    }

    public function workDay(Request $request, RouteWorkDay $workDay): Response
    {
        $this->authorizeSellerWorkDay($request, $workDay);

        return Inertia::render('Routes/Mobile/WorkDay', [
            'workDay' => $workDay->load(['zone:id,name', 'branch:id,name,department,municipality']),
            'visits' => RouteVisit::query()
                ->where('route_work_day_id', $workDay->id)
                ->with([
                    'customer:id,name,commercial_name,contact_name,doc_number,address,department,municipality,phone',
                    'preSale' => fn ($query) => $query
                        ->select('id', 'route_visit_id', 'status', 'total')
                        ->where('status', '!=', 'cancelled')
                        ->withCount('items'),
                ])
                ->orderByRaw('visit_order IS NULL, visit_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function createMobileCustomer(Request $request, RouteWorkDay $workDay): RedirectResponse
    {
        $this->authorizeSellerWorkDay($request, $workDay);
        $workDay->loadMissing('zone', 'branch');

        if ($workDay->status !== 'open') {
            throw ValidationException::withMessages([
                'work_day' => 'La jornada ya fue cerrada. No puedes agregar clientes.',
            ]);
        }

        abort_unless((int) $workDay->zone?->assigned_user_id === (int) $request->user()->id, 403);

        $data = $this->validateRouteCustomer($request);

        $result = DB::transaction(function () use ($data, $workDay) {
            $customerResult = $this->findOrCreateRouteCustomer($data, $workDay->branch);
            /** @var Customer $customer */
            $customer = $customerResult['customer'];
            $assignment = $this->assignCustomerToZone($workDay->zone, $customer, $data['notes'] ?? null);
            $visit = $this->createVisitForWorkDay($workDay, $customer, $assignment);

            return ['customer_result' => $customerResult, 'visit' => $visit];
        });

        $message = $result['customer_result']['created']
            ? 'Cliente creado y asignado a la zona.'
            : 'El cliente ya existía y fue asignado a la zona.';

        return redirect()
            ->route('routes.mobile.visits.show', $result['visit'])
            ->with('success', $message);
    }

    public function visit(Request $request, RouteVisit $visit): Response
    {
        $this->authorizeSellerVisit($request, $visit);
        $search = $request->string('search')->toString();

        if ($visit->status === 'pending') {
            $visit->update(['status' => 'in_progress', 'started_at' => now()]);
        }

        return Inertia::render('Routes/Mobile/Visit', [
            'visit' => $visit->load(['customer:id,name,commercial_name,contact_name,doc_number,address,department,municipality,phone', 'workDay:id,status,work_date', 'zone:id,name']),
            'preSale' => PreSale::query()
                ->where('business_id', currentBusinessId())
                ->where('route_visit_id', $visit->id)
                ->where('status', '!=', 'cancelled')
                ->with('items.product:id,name,code,barcode,image_url')
                ->first(),
            'products' => $search !== '' ? $this->preSaleProductResults($visit, $search) : collect(),
            'filters' => ['search' => $search],
            'allowNegativeStock' => \App\Support\Inventory\StockPolicy::allowsNegativeStockForBusinessId(currentBusinessId()),
            'allowManualPrice' => $this->preSaleManualPriceEnabled(currentBusinessId()),
        ]);
    }

    public function visitProductSearch(Request $request, RouteVisit $visit): JsonResponse
    {
        $this->authorizeSellerVisit($request, $visit);

        $search = $request->string('q')->trim()->toString();

        if ($search === '') {
            return response()->json(['products' => []]);
        }

        return response()->json([
            'products' => $this->preSaleProductResults($visit, $search)->values(),
        ]);
    }

    public function resolveNit(Request $request): JsonResponse
    {
        $request->validate([
            'nit' => ['required', 'string', 'max:50'],
        ]);

        $business = Business::query()->findOrFail(currentBusinessId());

        try {
            $resolved = GuatemalaNitCustomerResolver::resolve($business, (string) $request->query('nit'), allowCache: true);
            /** @var Customer $customer */
            $customer = $resolved['customer'];

            return response()->json([
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'commercial_name' => $customer->commercial_name,
                    'contact_name' => $customer->contact_name,
                    'doc_number' => $customer->doc_number,
                    'phone' => $customer->phone,
                    'address' => $customer->address,
                    'department' => $customer->department,
                    'municipality' => $customer->municipality,
                    'tax_lookup_verified_at' => $customer->tax_lookup_verified_at?->toIso8601String(),
                ],
                'source' => $resolved['source'],
            ]);
        } catch (ValidationException $exception) {
            $message = $exception->errors()['nit'][0]
                ?? $exception->errors()['to_customer_doc_number'][0]
                ?? 'No se pudo validar el NIT. Verifica el número e inténtalo nuevamente.';

            return response()->json([
                'message' => $message,
                'errors' => ['nit' => [$message]],
            ], 422);
        }
    }

    public function savePreSale(Request $request, RouteVisit $visit, StockReservationService $reservations): RedirectResponse
    {
        $this->authorizeSellerVisit($request, $visit);
        $this->assertVisitEditable($visit);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.price_type_id' => ['nullable', 'integer'],
            'items.*.unit_price' => ['nullable', 'numeric', 'gt:0'],
            'items.*.manual_price' => ['nullable', 'boolean'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        app(IdempotencyService::class)->run(
            currentBusinessId(),
            (int) $visit->branch_id,
            $request->user()->id,
            'route_pre_sale_save',
            $data['idempotency_key'],
            [
                'business_id' => currentBusinessId(),
                'branch_id' => (int) $visit->branch_id,
                'user_id' => $request->user()->id,
                'operation_type' => 'route_pre_sale_save',
                'visit_id' => $visit->id,
                'data' => $data,
            ],
            function () use ($request, $visit, $data, $reservations) {
                $preSaleId = DB::transaction(function () use ($request, $visit, $data, $reservations) {
            $workDay = RouteWorkDay::query()
                ->where('business_id', currentBusinessId())
                ->whereKey($visit->route_work_day_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($workDay->status !== 'open') {
                throw ValidationException::withMessages([
                    'pre_sale' => 'La jornada está cerrada. La preventa ya no se puede editar.',
                ]);
            }

            $visit = RouteVisit::query()
                ->where('business_id', currentBusinessId())
                ->whereKey($visit->id)
                ->lockForUpdate()
                ->firstOrFail();

            $preSale = PreSale::query()
                ->where('business_id', currentBusinessId())
                ->where('route_visit_id', $visit->id)
                ->where('status', 'draft')
                ->lockForUpdate()
                ->first();

            if (! $preSale) {
                abort_unless(Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_CREATE), 403);
                $preSale = PreSale::query()->create([
                    'business_id' => currentBusinessId(),
                    'branch_id' => $visit->branch_id,
                    'route_work_day_id' => $visit->route_work_day_id,
                    'route_visit_id' => $visit->id,
                    'route_zone_id' => $visit->route_zone_id,
                    'customer_id' => $visit->customer_id,
                    'seller_id' => $request->user()->id,
                    'status' => 'draft',
                ]);
            } else {
                abort_unless(Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_EDIT), 403);
            }

            $reservations->releasePreSaleReservations($preSale);
            $preSale->items()->delete();

            $products = Product::query()
                ->where('business_id', currentBusinessId())
                ->whereIn('id', collect($data['items'])->pluck('product_id')->all())
                ->where('is_active', true)
                ->get()
                ->keyBy('id');

            $quantitiesByProduct = collect($data['items'])
                ->groupBy(fn ($item) => (int) $item['product_id'])
                ->map(fn ($items) => $items->sum(fn ($item) => (float) $item['quantity']));

            if ($products->count() !== $quantitiesByProduct->count()) {
                throw ValidationException::withMessages(['items' => 'Uno de los productos no existe o está inactivo.']);
            }

            $reservations->lockStockRowsForReservation(
                currentBusinessId(),
                $visit->branch_id,
                $quantitiesByProduct->keys()->all(),
            );

            foreach ($quantitiesByProduct as $productId => $quantity) {
                $product = $products->get($productId);
                if (! $product) {
                    throw ValidationException::withMessages(['items' => 'Uno de los productos no existe o está inactivo.']);
                }

                BranchInventory::ensureProductInBranch($product, $visit->branch_id);
                $reservations->assertAvailableForReservation(currentBusinessId(), $visit->branch_id, $product, $quantity);
            }

            $subtotal = 0.0;
            $discountTotal = 0.0;
            $configuredPriceTypeId = $this->preSalePriceTypeId(currentBusinessId());
            $manualPriceEnabled = $this->preSaleManualPriceEnabled(currentBusinessId());

            foreach ($data['items'] as $row) {
                $product = $products->get((int) $row['product_id']);
                $price = PriceLists::priceForProduct($product, $configuredPriceTypeId, $visit->branch_id);
                $quantity = (float) $row['quantity'];
                $discount = round((float) ($row['discount'] ?? 0), 2);
                $manualPrice = (bool) ($row['manual_price'] ?? false);
                $unitPrice = round((float) ($price['price'] ?? $product->sale_price), 2);

                if ($manualPrice) {
                    if (! $manualPriceEnabled) {
                        throw ValidationException::withMessages([
                            'items' => 'El precio manual no está permitido para preventas.',
                        ]);
                    }

                    $unitPrice = round((float) ($row['unit_price'] ?? 0), 2);
                    $this->validatePreSaleManualPrice($product, $unitPrice);
                }

                $lineSubtotal = round($quantity * $unitPrice, 2);
                $lineTotal = max(0, round($lineSubtotal - $discount, 2));

                $item = $preSale->items()->create([
                    'business_id' => currentBusinessId(),
                    'product_id' => $product->id,
                    'price_type_id' => $price['price_type_id'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'original_price' => $price['price'],
                    'manual_price' => $manualPrice,
                    'discount' => $discount,
                    'total' => $lineTotal,
                    'notes' => $row['notes'] ?? null,
                ]);
                $reservations->reservePreSaleItem($item);

                $subtotal += $lineSubtotal;
                $discountTotal += $discount;
            }

            $preSale->update([
                'notes' => $data['notes'] ?? null,
                'subtotal' => round($subtotal, 2),
                'discount_total' => round($discountTotal, 2),
                'total' => round(max(0, $subtotal - $discountTotal), 2),
            ]);

            $visit->update([
                'status' => 'with_pre_sale',
                'no_sale_reason' => null,
                'no_sale_note' => null,
                'finished_at' => now(),
            ]);

                    return $preSale->id;
                });

                return [
                    'result_id' => $preSaleId,
                    'response_payload' => ['pre_sale_id' => $preSaleId],
                ];
            },
            'pre_sale',
        );

        return back()->with('success', 'Preventa guardada y stock reservado.');
    }

    public function updateVisitCustomer(Request $request, RouteVisit $visit): RedirectResponse
    {
        $this->authorizeSellerVisit($request, $visit);
        $visit->loadMissing('customer');

        $data = $this->validateRouteCustomer($request, requireAnyName: false);
        $this->updateCustomerFromRouteData($visit->customer, $data, allowFiscalFields: false);

        if (array_key_exists('notes', $data) && $data['notes'] !== '') {
            $visit->update(['notes' => $data['notes']]);
        }

        return back()->with('success', 'Cliente actualizado correctamente.');
    }

    public function cancelPreSale(Request $request, PreSale $preSale, StockReservationService $reservations): RedirectResponse
    {
        abort_unless((int) $preSale->business_id === currentBusinessId(), 403);
        abort_unless((int) $preSale->seller_id === (int) $request->user()->id || Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_ADMIN_VIEW), 403);
        $idempotency = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);

        if ($preSale->status === PreSale::STATUS_DRAFT) {
        app(IdempotencyService::class)->run(
            (int) $preSale->business_id,
            (int) $preSale->branch_id,
            $request->user()->id,
            'pre_sale_cancel',
            $idempotency['idempotency_key'],
            [
                'business_id' => (int) $preSale->business_id,
                'branch_id' => (int) $preSale->branch_id,
                'user_id' => $request->user()->id,
                'operation_type' => 'pre_sale_cancel',
                'pre_sale_id' => $preSale->id,
                'status' => PreSale::STATUS_DRAFT,
            ],
            function () use ($preSale, $reservations, $request) {
                DB::transaction(function () use ($preSale, $reservations, $request) {
            $workDay = RouteWorkDay::query()
                ->where('business_id', currentBusinessId())
                ->whereKey($preSale->route_work_day_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($workDay->status !== 'open') {
                throw ValidationException::withMessages([
                    'pre_sale' => 'Solo se pueden cancelar preventas en borrador con jornada abierta.',
                ]);
            }

            $lockedPreSale = PreSale::query()
                ->where('business_id', currentBusinessId())
                ->whereKey($preSale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPreSale->status !== PreSale::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'pre_sale' => 'Esta preventa ya no está disponible para cancelar.',
                ]);
            }

            $reservations->releasePreSaleReservations($lockedPreSale);
            $lockedPreSale->update([
                'status' => PreSale::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
            ]);

            app(RouteWorkDayCompletion::class)->refresh($workDay, $request->user());
                });

                return [
                    'result_id' => $preSale->id,
                    'response_payload' => ['pre_sale_id' => $preSale->id],
                ];
            },
            'pre_sale',
        );

            return back()->with('success', 'Preventa cancelada y reserva liberada.');
        }

        abort_unless(Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_ADMIN_VIEW), 403);

        if ($preSale->status === PreSale::STATUS_PICKED) {
            throw ValidationException::withMessages([
                'pre_sale' => 'Esta preventa ya está lista para facturar y no se puede cancelar desde esta fase.',
            ]);
        }

        if (! in_array($preSale->status, [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING], true)) {
            throw ValidationException::withMessages([
                'pre_sale' => 'Solo se pueden cancelar preventas enviadas o en preparación.',
            ]);
        }

        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'in:Cliente canceló,Producto no disponible,Duplicada,Error de captura,Otro'],
            'cancellation_note' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'cancellation_reason.required' => 'Selecciona un motivo de cancelación.',
            'cancellation_reason.in' => 'Selecciona un motivo de cancelación válido.',
            'cancellation_note.required' => 'Ingresa una observación de cancelación.',
            'cancellation_note.min' => 'La observación debe tener al menos 3 caracteres.',
        ]);

        app(IdempotencyService::class)->run(
            (int) $preSale->business_id,
            (int) $preSale->branch_id,
            $request->user()->id,
            'pre_sale_cancel',
            $idempotency['idempotency_key'],
            [
                'business_id' => (int) $preSale->business_id,
                'branch_id' => (int) $preSale->branch_id,
                'user_id' => $request->user()->id,
                'operation_type' => 'pre_sale_cancel',
                'pre_sale_id' => $preSale->id,
                'cancellation_reason' => $data['cancellation_reason'],
                'cancellation_note' => $data['cancellation_note'],
            ],
            function () use ($preSale, $reservations, $request, $data) {
                DB::transaction(function () use ($preSale, $reservations, $request, $data) {
            $lockedPreSale = PreSale::query()
                ->where('business_id', currentBusinessId())
                ->whereKey($preSale->id)
                ->with('workDay')
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedPreSale->status, [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING], true)) {
                throw ValidationException::withMessages([
                    'pre_sale' => 'Solo se pueden cancelar preventas enviadas o en preparación.',
                ]);
            }

            $reservations->releasePreSaleReservations($lockedPreSale);
            $lockedPreSale->update([
                'status' => PreSale::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $data['cancellation_reason'],
                'cancellation_note' => $data['cancellation_note'],
            ]);

            if ($lockedPreSale->workDay) {
                app(RouteWorkDayCompletion::class)->refresh($lockedPreSale->workDay, $request->user());
            }
                });

                return [
                    'result_id' => $preSale->id,
                    'response_payload' => ['pre_sale_id' => $preSale->id],
                ];
            },
            'pre_sale',
        );

        return back()->with('success', 'Preventa cancelada y reserva liberada.');
    }

    public function markPreSaleProcessing(Request $request, PreSale $preSale): RedirectResponse
    {
        abort_unless((int) $preSale->business_id === currentBusinessId(), 403);
        abort_unless(Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_ADMIN_VIEW), 403);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);

        app(IdempotencyService::class)->run(
            (int) $preSale->business_id,
            (int) $preSale->branch_id,
            $request->user()->id,
            'pre_sale_processing',
            $data['idempotency_key'],
            [
                'business_id' => (int) $preSale->business_id,
                'branch_id' => (int) $preSale->branch_id,
                'user_id' => $request->user()->id,
                'operation_type' => 'pre_sale_processing',
                'pre_sale_id' => $preSale->id,
            ],
            function () use ($preSale, $request) {
                DB::transaction(function () use ($preSale, $request) {
            $lockedPreSale = PreSale::query()
                ->where('business_id', currentBusinessId())
                ->whereKey($preSale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPreSale->status !== PreSale::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'pre_sale' => 'Solo se pueden marcar en preparación las preventas enviadas.',
                ]);
            }

            $lockedPreSale->update([
                'status' => PreSale::STATUS_PROCESSING,
                'processing_started_at' => now(),
                'processing_user_id' => $request->user()->id,
            ]);
                });

                return [
                    'result_id' => $preSale->id,
                    'response_payload' => ['pre_sale_id' => $preSale->id],
                ];
            },
            'pre_sale',
        );

        return back()->with('success', 'Preventa marcada en preparación.');
    }

    public function withoutSale(Request $request, RouteVisit $visit, StockReservationService $reservations): RedirectResponse
    {
        $this->authorizeSellerVisit($request, $visit);
        $visit->loadMissing('workDay');

        if ($visit->workDay->status !== 'open') {
            throw ValidationException::withMessages([
                'pre_sale' => 'La jornada está cerrada. La visita ya no se puede editar.',
            ]);
        }

        $data = $request->validate([
            'no_sale_reason' => ['required', 'string', 'in:Tienda cerrada,Cliente surtido,No quiso comprar,Sin presupuesto,Encargado ausente,Pedido para otro día,No encontrado,Otro'],
            'no_sale_note' => ['required', 'string', 'min:3', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ], [
            'no_sale_reason.required' => 'Selecciona un motivo.',
            'no_sale_reason.in' => 'Selecciona un motivo válido.',
            'no_sale_note.required' => 'Ingresa una observación.',
            'no_sale_note.min' => 'La observación debe tener al menos 3 caracteres.',
        ]);

        $submittedExists = PreSale::query()
            ->where('business_id', currentBusinessId())
            ->where('route_visit_id', $visit->id)
            ->where('status', '!=', 'draft')
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($submittedExists) {
            throw ValidationException::withMessages([
                'pre_sale' => 'La preventa ya fue enviada y no se puede cambiar a sin venta.',
            ]);
        }

        app(IdempotencyService::class)->run(
            currentBusinessId(),
            (int) $visit->branch_id,
            $request->user()->id,
            'route_visit_without_sale',
            $data['idempotency_key'],
            [
                'business_id' => currentBusinessId(),
                'branch_id' => (int) $visit->branch_id,
                'user_id' => $request->user()->id,
                'operation_type' => 'route_visit_without_sale',
                'visit_id' => $visit->id,
                'no_sale_reason' => $data['no_sale_reason'],
                'no_sale_note' => $data['no_sale_note'],
            ],
            function () use ($visit, $data, $reservations) {
                DB::transaction(function () use ($visit, $data, $reservations) {
            $workDay = RouteWorkDay::query()
                ->where('business_id', currentBusinessId())
                ->whereKey($visit->route_work_day_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($workDay->status !== 'open') {
                throw ValidationException::withMessages([
                    'pre_sale' => 'La jornada está cerrada. La visita ya no se puede editar.',
                ]);
            }

            $visit = RouteVisit::query()
                ->where('business_id', currentBusinessId())
                ->whereKey($visit->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($visit->status === 'without_sale') {
                throw ValidationException::withMessages([
                    'pre_sale' => 'Esta visita ya fue marcada sin venta.',
                ]);
            }

            $submittedPreSale = PreSale::query()
                ->where('business_id', currentBusinessId())
                ->where('route_visit_id', $visit->id)
                ->whereNotIn('status', [PreSale::STATUS_DRAFT, PreSale::STATUS_CANCELLED])
                ->lockForUpdate()
                ->first();

            if ($submittedPreSale) {
                throw ValidationException::withMessages([
                    'pre_sale' => 'La preventa ya fue enviada y no se puede cambiar a sin venta.',
                ]);
            }

            $preSale = PreSale::query()
                ->where('business_id', currentBusinessId())
                ->where('route_visit_id', $visit->id)
                ->where('status', 'draft')
                ->lockForUpdate()
                ->first();

            if ($preSale) {
                $reservations->releasePreSaleReservations($preSale);
                $preSale->items()->delete();
                $preSale->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);
            }

            $visit->update([
                'status' => 'without_sale',
                'no_sale_reason' => $data['no_sale_reason'],
                'no_sale_note' => $data['no_sale_note'],
                'finished_at' => now(),
            ]);
                });

                return [
                    'result_id' => $visit->id,
                    'response_payload' => ['visit_id' => $visit->id],
                ];
            },
            'route_visit',
        );

        return back()->with('success', 'Visita marcada sin venta.');
    }

    public function closeWorkDay(Request $request, RouteWorkDay $workDay): RedirectResponse
    {
        $this->authorizeSellerWorkDay($request, $workDay);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);

        app(IdempotencyService::class)->run(
            (int) $workDay->business_id,
            (int) $workDay->branch_id,
            $request->user()->id,
            'route_work_day_close',
            $data['idempotency_key'],
            [
                'business_id' => (int) $workDay->business_id,
                'branch_id' => (int) $workDay->branch_id,
                'user_id' => $request->user()->id,
                'operation_type' => 'route_work_day_close',
                'work_day_id' => $workDay->id,
            ],
            function () use ($request, $workDay) {
                DB::transaction(function () use ($request, $workDay) {
                    $lockedWorkDay = RouteWorkDay::query()
                        ->where('business_id', currentBusinessId())
                        ->whereKey($workDay->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($lockedWorkDay->status !== 'open') {
                        throw ValidationException::withMessages(['work_day' => 'La jornada no está abierta.']);
                    }

                    PreSale::query()
                        ->where('business_id', currentBusinessId())
                        ->where('route_work_day_id', $lockedWorkDay->id)
                        ->where('status', 'draft')
                        ->update([
                            'status' => 'submitted',
                            'submitted_at' => now(),
                        ]);

                    RouteVisit::query()
                        ->where('business_id', currentBusinessId())
                        ->where('route_work_day_id', $lockedWorkDay->id)
                        ->whereHas('preSale', fn ($query) => $query->whereIn('status', ['draft', 'submitted']))
                        ->update(['status' => 'with_pre_sale', 'finished_at' => now()]);

                    $lockedWorkDay->update([
                        'status' => 'closed',
                        'closed_at' => now(),
                    ]);

                    app(RouteWorkDayCompletion::class)->refresh($lockedWorkDay, $request->user());
                });

                return [
                    'result_id' => $workDay->id,
                    'response_payload' => ['work_day_id' => $workDay->id],
                ];
            },
            'route_work_day',
        );

        return redirect()->route('routes.mobile.zones')->with('success', 'Jornada cerrada. Las preventas quedaron congeladas.');
    }

    private function validateZone(Request $request): array
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'assigned_user_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        Branch::query()
            ->where('business_id', currentBusinessId())
            ->findOrFail($data['branch_id']);

        if (! empty($data['assigned_user_id'])) {
            User::query()
                ->where('business_id', currentBusinessId())
                ->findOrFail($data['assigned_user_id']);
        }

        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }

    private function preSaleProductResults(RouteVisit $visit, string $search)
    {
        $businessId = currentBusinessId();
        $query = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->when($search !== '', function ($query) use ($businessId, $search) {
                $query->where(function ($query) use ($businessId, $search) {
                    $query->where('name', 'ilike', "%{$search}%")
                        ->orWhere('code', 'ilike', "%{$search}%")
                        ->orWhere('barcode', 'ilike', "%{$search}%")
                        ->orWhereHas('category', fn ($category) => $category
                            ->where('business_id', $businessId)
                            ->where('name', 'ilike', "%{$search}%"))
                        ->orWhereHas('brand', fn ($brand) => $brand
                            ->where('business_id', $businessId)
                            ->where('name', 'ilike', "%{$search}%"));
                });
            })
            ->with([
                'category' => fn ($query) => $query
                    ->where('business_id', $businessId)
                    ->select('id', 'business_id', 'name'),
                'brand' => fn ($query) => $query
                    ->where('business_id', $businessId)
                    ->select('id', 'business_id', 'name'),
            ]);

        BranchInventory::restrictProductsToBranch($query, $businessId, $visit->branch_id);

        $products = $query
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'business_id', 'category_id', 'brand_id', 'name', 'code', 'barcode', 'sale_price', 'image_url']);

        $priceTypeId = $this->preSalePriceTypeId($businessId);
        $stockBreakdown = StockAvailability::getBreakdownForProducts(
            $businessId,
            $visit->branch_id,
            $products->pluck('id')->all(),
        );

        return $products->map(function (Product $product) use ($businessId, $visit, $priceTypeId, $stockBreakdown) {
            BranchInventory::ensureProductInBranch($product, $visit->branch_id);
            $breakdown = $stockBreakdown->get((int) $product->id, []);
            $stock = (float) ($breakdown['physical_stock'] ?? 0);
            $reserved = (float) ($breakdown['reserved_total'] ?? 0);
            $price = PriceLists::priceForProduct($product, $priceTypeId, $visit->branch_id);

            return [
                'id' => $product->id,
                'business_id' => $businessId,
                'category_id' => $product->category_id,
                'brand_id' => $product->brand_id,
                'category_name' => $product->category?->name,
                'brand_name' => $product->brand?->name,
                'name' => $product->name,
                'code' => $product->code,
                'barcode' => $product->barcode,
                'sale_price' => $price['price'],
                'price_type_id' => $price['price_type_id'],
                'image_url' => $product->image_url,
                'stock' => $stock,
                'reserved_stock' => $reserved,
                'reserved_total' => $reserved,
                'reserved_pre_sales' => (float) ($breakdown['reserved_pre_sales'] ?? 0),
                'reserved_credit_reservations' => (float) ($breakdown['reserved_credit_reservations'] ?? 0),
                'reserved_other' => (float) ($breakdown['reserved_other'] ?? 0),
                'available_stock' => (float) ($breakdown['available_stock'] ?? ($stock - $reserved)),
            ];
        });
    }

    private function preSalePriceTypeId(int $businessId): ?int
    {
        $priceTypeId = TenantSetting::query()
            ->where('business_id', $businessId)
            ->value('pre_sale_price_type_id');

        if (! $priceTypeId) {
            return null;
        }

        return (int) $priceTypeId;
    }

    private function preSaleManualPriceEnabled(int $businessId): bool
    {
        $settings = TenantSetting::query()
            ->where('business_id', $businessId)
            ->first(['allow_manual_price', 'pre_sale_allow_manual_price']);

        return (bool) ($settings?->allow_manual_price) && (bool) ($settings?->pre_sale_allow_manual_price);
    }

    private function validatePreSaleManualPrice(Product $product, float $unitPrice): void
    {
        $settings = TenantSetting::query()
            ->where('business_id', $product->business_id)
            ->first();
        $mainPrice = PriceLists::priceForProduct($product, $this->preSalePriceTypeId((int) $product->business_id))['price'];

        ManualPricePolicy::validateUnitPrice($settings, $product, $unitPrice, (float) $mainPrice);

        return;

        $minMargin = (float) TenantSetting::query()
            ->where('business_id', $product->business_id)
            ->value('manual_price_min_margin_percent');
        $cost = (float) ($product->cost_price ?? 0);
        $minimum = $cost > 0 ? round($cost * (1 + ($minMargin / 100)), 2) : 0;

        if ($unitPrice <= 0 || ($minimum > 0 && $unitPrice < $minimum)) {
            throw ValidationException::withMessages([
                'items' => 'Este precio no está permitido.',
            ]);
        }
    }

    private function validateRouteCustomer(Request $request, bool $requireAnyName = true): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'commercial_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'doc_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:100'],
            'municipality' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ], [
            'name.required' => 'El nombre del cliente es obligatorio.',
        ]);

        $data['name'] = trim((string) ($data['name'] ?? ''));
        $data['commercial_name'] = trim((string) ($data['commercial_name'] ?? ''));
        $data['contact_name'] = trim((string) ($data['contact_name'] ?? ''));
        if ($requireAnyName && $data['name'] === '' && $data['commercial_name'] === '') {
            throw ValidationException::withMessages([
                'name' => 'Debes ingresar nombre o nombre del negocio.',
            ]);
        }

        if ($data['name'] === '' && $data['commercial_name'] !== '') {
            $data['name'] = $data['commercial_name'];
        }

        $data['doc_number'] = $this->normalizeRouteCustomerDocument($data['doc_number'] ?? null);
        $data['phone'] = trim((string) ($data['phone'] ?? ''));
        $data['address'] = trim((string) ($data['address'] ?? ''));
        $data['department'] = trim((string) ($data['department'] ?? ''));
        $data['municipality'] = trim((string) ($data['municipality'] ?? ''));
        $data['notes'] = trim((string) ($data['notes'] ?? ''));
        $this->validateRouteCustomerLocation($data['department'], $data['municipality']);

        return $data;
    }

    private function routeCustomerBranchLocationDefaults(?Branch $branch): array
    {
        $department = trim((string) ($branch?->department ?? ''));
        $municipality = trim((string) ($branch?->municipality ?? ''));

        if (
            $this->currentBusinessCountry() === 'GT'
            && (
                ! GuatemalaLocations::isValidDepartment($department)
                || ! GuatemalaLocations::isValidMunicipality($department, $municipality)
            )
        ) {
            return ['department' => '', 'municipality' => ''];
        }

        return [
            'department' => $department,
            'municipality' => $municipality,
        ];
    }

    private function findOrCreateRouteCustomer(array $data, ?Branch $defaultBranch = null): array
    {
        $businessId = currentBusinessId();
        $docNumber = $data['doc_number'];
        $isFinalConsumer = $docNumber === '' || $docNumber === 'CF';
        $branchDefaults = $this->routeCustomerBranchLocationDefaults($defaultBranch);

        foreach (['department', 'municipality'] as $field) {
            if ($data[$field] === '' && $branchDefaults[$field] !== '') {
                $data[$field] = $branchDefaults[$field];
            }
        }

        if ($isFinalConsumer && CustomerIdentity::isGenericFinalConsumer('CF', $data['name'], $data)) {
            return [
                'customer' => Customer::getOrCreateGenericFinalConsumer($businessId),
                'created' => false,
            ];
        }

        if (! $isFinalConsumer) {
            $existing = Customer::findOrCreateByNormalizedTaxId($businessId, [
                'name' => $data['name'],
                'commercial_name' => $data['commercial_name'] !== '' ? $data['commercial_name'] : null,
                'contact_name' => $data['contact_name'] !== '' ? $data['contact_name'] : null,
                'doc_type' => 'NIT',
                'doc_number' => $docNumber,
                'address' => $data['address'] !== '' ? $data['address'] : null,
                'department' => $data['department'] !== '' ? $data['department'] : null,
                'municipality' => $data['municipality'] !== '' ? $data['municipality'] : null,
                'phone' => $data['phone'] !== '' ? $data['phone'] : null,
                'country' => $this->currentBusinessCountry(),
                'is_final_consumer' => false,
            ]);

            if (! $existing->wasRecentlyCreated) {
                $payload = [];

                if (blank($existing->name) && $data['name'] !== '') {
                    $payload['name'] = $data['name'];
                }

                if (blank($existing->commercial_name) && $data['commercial_name'] !== '') {
                    $payload['commercial_name'] = $data['commercial_name'];
                }

                if (blank($existing->contact_name) && $data['contact_name'] !== '') {
                    $payload['contact_name'] = $data['contact_name'];
                }

                foreach (['address', 'department', 'municipality', 'phone'] as $field) {
                    if (
                        in_array($field, ['department', 'municipality'], true)
                        && $data[$field] !== ''
                        && $data[$field] === $branchDefaults[$field]
                    ) {
                        continue;
                    }

                    if (blank($existing->{$field}) && $data[$field] !== '') {
                        $payload[$field] = $data[$field];
                    }
                }

                if ($payload !== []) {
                    $existing->forceFill($payload)->save();
                }

                return ['customer' => $existing, 'created' => false];
            }

            return ['customer' => $existing, 'created' => true];
        }

        $customer = Customer::query()->create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'commercial_name' => $data['commercial_name'] !== '' ? $data['commercial_name'] : null,
            'contact_name' => $data['contact_name'] !== '' ? $data['contact_name'] : null,
            'doc_type' => $isFinalConsumer ? 'CF' : 'NIT',
            'doc_number' => $isFinalConsumer ? 'CF' : $docNumber,
            'address' => $data['address'] !== '' ? $data['address'] : null,
            'department' => $data['department'] !== '' ? $data['department'] : null,
            'municipality' => $data['municipality'] !== '' ? $data['municipality'] : null,
            'phone' => $data['phone'] !== '' ? $data['phone'] : null,
            'country' => $this->currentBusinessCountry(),
            'is_final_consumer' => $isFinalConsumer,
        ]);

        return ['customer' => $customer, 'created' => true];
    }

    private function updateCustomerFromRouteData(Customer $customer, array $data, bool $allowFiscalFields): void
    {
        $payload = [
            'commercial_name' => $data['commercial_name'] !== '' ? $data['commercial_name'] : null,
            'contact_name' => $data['contact_name'] !== '' ? $data['contact_name'] : null,
            'address' => $data['address'] !== '' ? $data['address'] : null,
            'department' => $data['department'] !== '' ? $data['department'] : null,
            'municipality' => $data['municipality'] !== '' ? $data['municipality'] : null,
            'phone' => $data['phone'] !== '' ? $data['phone'] : null,
        ];

        if ($allowFiscalFields) {
            $docNumber = $data['doc_number'];
            if ($docNumber !== '' && $docNumber !== 'CF') {
                $duplicate = Customer::query()
                    ->where('business_id', currentBusinessId())
                    ->whereKeyNot($customer->id)
                    ->where('normalized_tax_id', $docNumber)
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'doc_number' => 'Ya existe un cliente con este NIT.',
                    ]);
                }
            }

            $payload['name'] = $data['name'] !== '' ? $data['name'] : ($data['commercial_name'] ?: $customer->name);
            $payload['doc_type'] = ($docNumber === '' || $docNumber === 'CF') ? 'CF' : 'NIT';
            $payload['doc_number'] = ($docNumber === '' || $docNumber === 'CF') ? 'CF' : $docNumber;
            $payload['is_final_consumer'] = $payload['doc_number'] === 'CF';
        }

        $customer->update($payload);
    }

    private function validateRouteCustomerLocation(?string $department, ?string $municipality): void
    {
        if ($this->currentBusinessCountry() !== 'GT') {
            return;
        }

        if (! GuatemalaLocations::isValidDepartment($department)) {
            throw ValidationException::withMessages([
                'department' => 'Selecciona un departamento válido.',
            ]);
        }

        if (! GuatemalaLocations::isValidMunicipality($department, $municipality)) {
            throw ValidationException::withMessages([
                'municipality' => 'Selecciona un municipio válido para el departamento.',
            ]);
        }
    }

    private function currentBusinessCountry(): string
    {
        return Business::query()->whereKey(currentBusinessId())->value('country') ?: 'GT';
    }

    private function assignCustomerToZone(RouteZone $zone, Customer $customer, ?string $notes = null): RouteZoneCustomer
    {
        abort_unless((int) $customer->business_id === currentBusinessId(), 403);

        $assignment = RouteZoneCustomer::query()
            ->where('business_id', currentBusinessId())
            ->where('route_zone_id', $zone->id)
            ->where('customer_id', $customer->id)
            ->lockForUpdate()
            ->first();

        if ($assignment) {
            $payload = ['is_active' => true];

            if ($assignment->visit_order === null) {
                $payload['visit_order'] = $this->nextZoneVisitOrder($zone);
            }

            if (filled($notes)) {
                $payload['notes'] = $notes;
            }

            $assignment->update($payload);

            return $assignment->refresh();
        }

        return RouteZoneCustomer::query()->create([
            'business_id' => currentBusinessId(),
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'visit_order' => $this->nextZoneVisitOrder($zone),
            'notes' => filled($notes) ? $notes : null,
            'is_active' => true,
        ]);
    }

    private function createMissingVisitsForOpenZoneWorkDays(RouteZone $zone, Customer $customer, RouteZoneCustomer $assignment): void
    {
        RouteWorkDay::query()
            ->where('business_id', currentBusinessId())
            ->where('route_zone_id', $zone->id)
            ->where('status', 'open')
            ->get()
            ->each(fn (RouteWorkDay $workDay) => $this->createVisitForWorkDay($workDay, $customer, $assignment));
    }

    private function createVisitForWorkDay(RouteWorkDay $workDay, Customer $customer, RouteZoneCustomer $assignment): RouteVisit
    {
        return RouteVisit::query()->firstOrCreate(
            [
                'route_work_day_id' => $workDay->id,
                'customer_id' => $customer->id,
            ],
            [
                'business_id' => currentBusinessId(),
                'branch_id' => $workDay->branch_id,
                'route_zone_id' => $workDay->route_zone_id,
                'seller_id' => $workDay->seller_id,
                'visit_order' => $assignment->visit_order ?? $this->nextWorkDayVisitOrder($workDay),
                'status' => 'pending',
                'notes' => $assignment->notes,
            ],
        );
    }

    private function nextZoneVisitOrder(RouteZone $zone): int
    {
        return ((int) RouteZoneCustomer::query()
            ->where('business_id', currentBusinessId())
            ->where('route_zone_id', $zone->id)
            ->max('visit_order')) + 1;
    }

    private function nextWorkDayVisitOrder(RouteWorkDay $workDay): int
    {
        return ((int) RouteVisit::query()
            ->where('business_id', currentBusinessId())
            ->where('route_work_day_id', $workDay->id)
            ->max('visit_order')) + 1;
    }

    private function normalizeRouteCustomerDocument(?string $value): string
    {
        return CustomerIdentity::normalizeTaxId($value) ?? '';
    }

    private function authorizeBusiness(RouteZone $zone): void
    {
        abort_unless((int) $zone->business_id === currentBusinessId(), 403);
    }

    private function sellerBranch(Request $request): ?Branch
    {
        $branchId = $request->user()?->current_branch_id;

        if (! $branchId) {
            return null;
        }

        return Branch::query()
            ->where('business_id', currentBusinessId())
            ->where('is_active', true)
            ->find($branchId);
    }

    private function authorizeSellerZone(Request $request, RouteZone $zone): void
    {
        $this->authorizeBusiness($zone);
        $branch = $this->sellerBranch($request);

        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Tu usuario no tiene una sucursal asignada.']);
        }

        abort_unless((int) $zone->assigned_user_id === (int) $request->user()->id, 403);

        if ((int) $zone->branch_id !== (int) $branch->id) {
            throw ValidationException::withMessages(['branch_id' => 'Esta zona no pertenece a tu sucursal.']);
        }
    }

    private function authorizeSellerWorkDay(Request $request, RouteWorkDay $workDay): void
    {
        abort_unless((int) $workDay->business_id === currentBusinessId(), 403);
        abort_unless((int) $workDay->seller_id === (int) $request->user()->id, 403);
        $branch = $this->sellerBranch($request);
        abort_unless($branch && (int) $workDay->branch_id === (int) $branch->id, 403);
    }

    private function authorizeSellerVisit(Request $request, RouteVisit $visit): void
    {
        abort_unless((int) $visit->business_id === currentBusinessId(), 403);
        abort_unless((int) $visit->seller_id === (int) $request->user()->id, 403);
        $branch = $this->sellerBranch($request);
        abort_unless($branch && (int) $visit->branch_id === (int) $branch->id, 403);
    }

    private function assertVisitEditable(RouteVisit $visit): void
    {
        $visit->loadMissing('workDay');

        if ($visit->workDay->status !== 'open') {
            throw ValidationException::withMessages([
                'pre_sale' => 'La jornada está cerrada. La preventa ya no se puede editar.',
            ]);
        }

        $submittedExists = PreSale::query()
            ->where('business_id', currentBusinessId())
            ->where('route_visit_id', $visit->id)
            ->whereIn('status', [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING, PreSale::STATUS_PICKED])
            ->exists();

        if ($submittedExists) {
            throw ValidationException::withMessages([
                'pre_sale' => 'La preventa ya fue enviada y no se puede editar.',
            ]);
        }
    }
}
