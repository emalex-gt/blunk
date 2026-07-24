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
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\GuatemalaNitCustomerResolver;
use App\Support\GuatemalaLocations;
use App\Support\Inventory\StockReservationService;
use App\Support\Permissions;
use App\Support\PriceLists;
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

    public function preSales(Request $request): Response
    {
        $businessId = currentBusinessId();
        $sellerId = $request->filled('seller_id')
            ? User::query()->where('business_id', $businessId)->whereKey($request->integer('seller_id'))->value('id')
            : null;
        $zoneId = $request->filled('zone_id')
            ? RouteZone::query()->where('business_id', $businessId)->whereKey($request->integer('zone_id'))->value('id')
            : null;

        $query = PreSale::query()
            ->where('business_id', $businessId)
            ->with(['customer:id,name,doc_number', 'seller:id,name', 'zone:id,name', 'branch:id,name'])
            ->withCount('items')
            ->latest();

        $query->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()));
        $query->when($request->filled('seller_id'), fn ($query) => $sellerId ? $query->where('seller_id', $sellerId) : $query->whereRaw('1 = 0'));
        $query->when($request->filled('zone_id'), fn ($query) => $zoneId ? $query->where('route_zone_id', $zoneId) : $query->whereRaw('1 = 0'));
        $query->when($request->filled('date'), fn ($query) => $query->whereDate('created_at', $request->date('date')));
        $query->when($request->filled('customer'), function ($query) use ($businessId, $request) {
            $search = $request->string('customer')->toString();
            $query->whereHas('customer', fn ($query) => $query
                ->where('business_id', $businessId)
                ->where(function ($query) use ($search) {
                    $query->where('name', 'ilike', "%{$search}%")
                        ->orWhere('doc_number', 'ilike', "%{$search}%");
                }));
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
        });

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

        DB::transaction(function () use ($visit, $data, $reservations) {
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

        return back()->with('success', 'Visita marcada sin venta.');
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

        if (! $isFinalConsumer) {
            $existing = Customer::query()
                ->where('business_id', $businessId)
                ->whereRaw("UPPER(REPLACE(REPLACE(doc_number, '-', ''), ' ', '')) = ?", [$docNumber])
                ->where(function ($query) {
                    $query->where('doc_type', 'NIT')->orWhereNull('doc_type');
                })
                ->lockForUpdate()
                ->first();

            if ($existing) {
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
        }

        foreach (['department', 'municipality'] as $field) {
            if ($data[$field] === '' && $branchDefaults[$field] !== '') {
                $data[$field] = $branchDefaults[$field];
            }
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
                    ->whereRaw("UPPER(REPLACE(REPLACE(doc_number, '-', ''), ' ', '')) = ?", [$docNumber])
                    ->where(function ($query) {
                        $query->where('doc_type', 'NIT')->orWhereNull('doc_type');
                    })
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
        $document = strtoupper(preg_replace('/[\s-]+/', '', trim((string) $value)));

        return $document;
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
