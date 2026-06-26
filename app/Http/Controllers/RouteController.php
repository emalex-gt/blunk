<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\Product;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\RouteZoneCustomer;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Inventory\StockReservationService;
use App\Support\Permissions;
use App\Support\PriceLists;
use App\Support\StockAvailability;
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
            'zone' => $zone->load(['branch:id,name', 'assignedUser:id,name']),
            'assignments' => RouteZoneCustomer::query()
                ->where('business_id', $businessId)
                ->where('route_zone_id', $zone->id)
                ->with('customer:id,name,doc_number,address,phone')
                ->orderByRaw('visit_order IS NULL, visit_order')
                ->orderBy('id')
                ->get(),
            'availableCustomers' => Customer::query()
                ->where('business_id', $businessId)
                ->when($search, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('name', 'ilike', "%{$search}%")
                            ->orWhere('doc_number', 'ilike', "%{$search}%")
                            ->orWhere('phone', 'ilike', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->limit(25)
                ->get(['id', 'name', 'doc_number', 'address', 'phone']),
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

    public function destroyZoneCustomer(RouteZone $zone, RouteZoneCustomer $assignment): RedirectResponse
    {
        $this->authorizeBusiness($zone);
        abort_unless((int) $assignment->route_zone_id === (int) $zone->id && (int) $assignment->business_id === currentBusinessId(), 403);
        $assignment->delete();

        return back()->with('success', 'Cliente removido de la zona.');
    }

    public function preSales(Request $request): Response
    {
        $businessId = currentBusinessId();

        $query = PreSale::query()
            ->where('business_id', $businessId)
            ->with(['customer:id,name,doc_number', 'seller:id,name', 'zone:id,name', 'branch:id,name'])
            ->withCount('items')
            ->latest();

        $query->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()));
        $query->when($request->filled('seller_id'), fn ($query) => $query->where('seller_id', $request->integer('seller_id')));
        $query->when($request->filled('zone_id'), fn ($query) => $query->where('route_zone_id', $request->integer('zone_id')));
        $query->when($request->filled('date'), fn ($query) => $query->whereDate('created_at', $request->date('date')));
        $query->when($request->filled('customer'), function ($query) use ($request) {
            $search = $request->string('customer')->toString();
            $query->whereHas('customer', fn ($query) => $query
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('doc_number', 'ilike', "%{$search}%"));
        });

        return Inertia::render('Routes/PreSales/Index', [
            'preSales' => $query->paginate(25)->withQueryString(),
            'filters' => $request->only(['status', 'seller_id', 'zone_id', 'date', 'customer']),
            'sellers' => User::query()->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'zones' => RouteZone::query()->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
        ]);
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
        $existing = RouteWorkDay::query()
            ->where('business_id', currentBusinessId())
            ->where('route_zone_id', $zone->id)
            ->where('seller_id', $request->user()->id)
            ->whereDate('work_date', $today)
            ->first();

        if ($existing?->status === 'closed') {
            return back()->withErrors(['work_day' => 'La jornada de esta zona ya fue cerrada.']);
        }

        $workDay = DB::transaction(function () use ($request, $zone, $today, $existing) {
            $workDay = $existing ?: RouteWorkDay::query()->create([
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

        return redirect()->route('routes.mobile.work-days.show', $workDay)->with('success', 'Jornada iniciada.');
    }

    public function workDay(Request $request, RouteWorkDay $workDay): Response
    {
        $this->authorizeSellerWorkDay($request, $workDay);

        return Inertia::render('Routes/Mobile/WorkDay', [
            'workDay' => $workDay->load(['zone:id,name', 'branch:id,name']),
            'visits' => RouteVisit::query()
                ->where('route_work_day_id', $workDay->id)
                ->with(['customer:id,name,doc_number,address,phone', 'preSale:id,route_visit_id,status,total'])
                ->orderByRaw('visit_order IS NULL, visit_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function visit(Request $request, RouteVisit $visit): Response
    {
        $this->authorizeSellerVisit($request, $visit);
        $search = $request->string('search')->toString();

        if ($visit->status === 'pending') {
            $visit->update(['status' => 'in_progress', 'started_at' => now()]);
        }

        $products = Product::query()
            ->where('business_id', currentBusinessId())
            ->where('is_active', true)
            ->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'ilike', "%{$search}%")
                        ->orWhere('code', 'ilike', "%{$search}%")
                        ->orWhere('barcode', 'ilike', "%{$search}%");
                });
            })
            ->with('category:id,name')
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'business_id', 'category_id', 'name', 'code', 'barcode', 'sale_price', 'image_url']);

        BranchInventory::applyBranchStockAndPrices($products, currentBusinessId(), $visit->branch_id);
        PriceLists::applyBranchPricesToProducts($products, currentBusinessId(), $visit->branch_id);

        $products->each(function (Product $product) use ($visit) {
            $reserved = StockAvailability::reservedStock($product, null, $visit->branch_id);
            $stock = StockAvailability::totalStock($product, null, $visit->branch_id);
            $product->setAttribute('stock', $stock);
            $product->setAttribute('reserved_stock', $reserved);
            $product->setAttribute('available_stock', $stock - $reserved);
        });

        return Inertia::render('Routes/Mobile/Visit', [
            'visit' => $visit->load(['customer:id,name,doc_number,address,phone', 'workDay:id,status,work_date', 'zone:id,name']),
            'preSale' => PreSale::query()
                ->where('business_id', currentBusinessId())
                ->where('route_visit_id', $visit->id)
                ->where('status', '!=', 'cancelled')
                ->with('items.product:id,name,code,barcode,image_url')
                ->first(),
            'products' => $products,
            'filters' => ['search' => $search],
            'allowNegativeStock' => \App\Support\Inventory\StockPolicy::allowsNegativeStockForBusinessId(currentBusinessId()),
        ]);
    }

    public function savePreSale(Request $request, RouteVisit $visit, StockReservationService $reservations): RedirectResponse
    {
        $this->authorizeSellerVisit($request, $visit);
        $this->assertVisitEditable($visit);

        $data = $request->validate([
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.price_type_id' => ['nullable', 'integer'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($request, $visit, $data, $reservations) {
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

            foreach ($data['items'] as $row) {
                $product = $products->get((int) $row['product_id']);
                $price = PriceLists::priceForProduct($product, $row['price_type_id'] ?? null, $visit->branch_id);
                $quantity = (float) $row['quantity'];
                $discount = round((float) ($row['discount'] ?? 0), 2);
                $lineSubtotal = round($quantity * (float) $price['price'], 2);
                $lineTotal = max(0, round($lineSubtotal - $discount, 2));

                $item = $preSale->items()->create([
                    'business_id' => currentBusinessId(),
                    'product_id' => $product->id,
                    'price_type_id' => $price['price_type_id'],
                    'quantity' => $quantity,
                    'unit_price' => $price['price'],
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
                'finished_at' => now(),
            ]);
        });

        return back()->with('success', 'Preventa guardada y stock reservado.');
    }

    public function cancelPreSale(Request $request, PreSale $preSale, StockReservationService $reservations): RedirectResponse
    {
        abort_unless((int) $preSale->business_id === currentBusinessId(), 403);
        abort_unless((int) $preSale->seller_id === (int) $request->user()->id || Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_ADMIN_VIEW), 403);

        if ($preSale->status !== 'draft' || $preSale->workDay?->status !== 'open') {
            throw ValidationException::withMessages([
                'pre_sale' => 'Solo se pueden cancelar preventas en borrador con jornada abierta.',
            ]);
        }

        DB::transaction(function () use ($preSale, $reservations) {
            $reservations->releasePreSaleReservations($preSale);
            $preSale->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
        });

        return back()->with('success', 'Preventa cancelada y reserva liberada.');
    }

    public function withoutSale(Request $request, RouteVisit $visit): RedirectResponse
    {
        $this->authorizeSellerVisit($request, $visit);
        $this->assertVisitEditable($visit);

        $visit->update([
            'status' => 'without_sale',
            'finished_at' => now(),
        ]);

        return back()->with('success', 'Visita marcada sin compra.');
    }

    public function closeWorkDay(Request $request, RouteWorkDay $workDay): RedirectResponse
    {
        $this->authorizeSellerWorkDay($request, $workDay);

        if ($workDay->status !== 'open') {
            throw ValidationException::withMessages(['work_day' => 'La jornada no está abierta.']);
        }

        DB::transaction(function () use ($workDay) {
            PreSale::query()
                ->where('business_id', currentBusinessId())
                ->where('route_work_day_id', $workDay->id)
                ->where('status', 'draft')
                ->update([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]);

            RouteVisit::query()
                ->where('business_id', currentBusinessId())
                ->where('route_work_day_id', $workDay->id)
                ->whereHas('preSale', fn ($query) => $query->whereIn('status', ['draft', 'submitted']))
                ->update(['status' => 'with_pre_sale', 'finished_at' => now()]);

            $workDay->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);
        });

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
            ->where('status', 'submitted')
            ->exists();

        if ($submittedExists) {
            throw ValidationException::withMessages([
                'pre_sale' => 'La preventa ya fue enviada y no se puede editar.',
            ]);
        }
    }
}
