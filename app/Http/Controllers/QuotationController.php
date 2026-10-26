<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\TenantSetting;
use App\Support\BranchInventory;
use App\Support\BusinessCounter;
use App\Support\DocumentCompanyHeader;
use App\Support\ManualPricePolicy;
use App\Support\Permissions;
use App\Support\PriceLists;
use App\Services\BranchAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class QuotationController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:open,conversion_pending,converted,cancelled'],
            'branch_id' => ['nullable', 'integer'],
            'issued_from' => ['nullable', 'date'],
            'issued_to' => ['nullable', 'date'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date'],
        ]);
        $business = Business::query()->findOrFail(currentBusinessId());
        $query = Quotation::query()
            ->where('business_id', $business->id)
            ->with(['branch:id,name', 'createdBy:id,name'])
            ->latest('issued_at');

        $this->limitToAccessibleBranches($query, $request, $business->id);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($quotation) use ($search) {
                $quotation->where('business_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'ilike', "%{$search}%")
                    ->orWhere('customer_commercial_name', 'ilike', "%{$search}%")
                    ->orWhere('customer_doc_number', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('issued_from')) {
            $query->whereDate('issued_at', '>=', $request->date('issued_from')->toDateString());
        }
        if ($request->filled('issued_to')) {
            $query->whereDate('issued_at', '<=', $request->date('issued_to')->toDateString());
        }
        if ($request->filled('valid_from')) {
            $query->whereDate('valid_until', '>=', $request->date('valid_from')->toDateString());
        }
        if ($request->filled('valid_to')) {
            $query->whereDate('valid_until', '<=', $request->date('valid_to')->toDateString());
        }

        if ($request->filled('branch_id')) {
            $branchId = $request->integer('branch_id');
            app(BranchAccessService::class)->assertCanAccessBranch($request->user(), $branchId, $business->id);
            $query->where('branch_id', $branchId);
        }

        $timezone = tenantTimezone($business);

        return Inertia::render('Quotations/Index', [
            'quotations' => $query->paginate(25)->withQueryString()
                ->through(fn (Quotation $quotation) => $this->payload($quotation, $timezone)),
            'filters' => $request->only('search', 'status', 'branch_id', 'issued_from', 'issued_to', 'valid_from', 'valid_to'),
            'branches' => app(BranchAccessService::class)->authorizedBranches($request->user(), $business->id)
                ->map->only('id', 'name')->values(),
        ]);
    }

    public function show(Request $request, Quotation $quotation): Response
    {
        $business = $this->authorizeQuotation($request, $quotation);

        return Inertia::render('Quotations/Show', [
            'quotation' => $this->payload(
                $quotation->load(['items', 'branch:id,name', 'createdBy:id,name', 'cancelledBy:id,name', 'convertedSale:id,quotation_id']),
                tenantTimezone($business),
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(module_enabled('pos'), 403);

        $business = Business::query()->findOrFail(currentBusinessId());
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'customer' => ['nullable', 'array'],
            'customer.id' => ['nullable', 'integer'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.commercial_name' => ['nullable', 'string', 'max:255'],
            'customer.doc_type' => ['nullable', 'string', 'max:50'],
            'customer.doc_number' => ['nullable', 'string', 'max:50'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'customer.address_reference' => ['nullable', 'string', 'max:1000'],
            'customer.postal_code' => ['nullable', 'string', 'max:50'],
            'customer.municipality' => ['nullable', 'string', 'max:100'],
            'customer.department' => ['nullable', 'string', 'max:100'],
            'customer.country' => ['nullable', 'string', 'max:10'],
            'customer.phone' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price_type_id' => ['nullable', 'integer'],
            'items.*.unit_price' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0'],
            'items.*.price_source' => ['nullable', 'in:price_list,last_customer_price,manual'],
            'items.*.manual_price' => ['nullable', 'boolean'],
            'discount' => ['nullable', 'array'],
            'discount.type' => ['required_with:discount', 'in:fixed,percent'],
            'discount.value' => ['required_with:discount', 'numeric', 'decimal:0,2', 'gt:0'],
            'discount.reason' => ['required_with:discount', 'string', 'max:1000'],
            'valid_until' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $branch = BranchInventory::activeBranch($business->id);
        if (now(tenantTimezone($business))->startOfDay()->gt($data['valid_until'])) {
            throw ValidationException::withMessages(['valid_until' => 'La vigencia no puede ser anterior a la fecha actual.']);
        }
        if (filled($data['branch_id'] ?? null) && (int) $data['branch_id'] !== $branch->id) {
            throw ValidationException::withMessages([
                'branch_id' => 'La sucursal de la cotización no coincide con la sucursal activa.',
            ]);
        }

        $customerId = data_get($data, 'customer.id');
        $customer = $customerId
            ? Customer::query()->where('business_id', $business->id)->find($customerId)
            : null;
        if ($customerId && ! $customer) {
            throw ValidationException::withMessages(['customer.id' => 'El cliente no pertenece al negocio activo.']);
        }

        $lines = [];
        $subtotal = 0.0;
        foreach ($data['items'] as $item) {
            $product = Product::query()->where('business_id', $business->id)->find($item['product_id']);
            if (! $product) {
                throw ValidationException::withMessages(['items' => 'Un producto no pertenece al negocio activo.']);
            }

            $resolvedPrice = $this->resolveLinePrice($request, $product, $item, $customer, $branch->id);
            $priceType = PriceType::query()
                ->where('business_id', $business->id)
                ->findOrFail($resolvedPrice['price_type_id']);
            $lineTotal = round($resolvedPrice['unit_price'] * (int) $item['quantity'], 2);
            $subtotal += $lineTotal;
            $lines[] = compact('product', 'priceType', 'item', 'resolvedPrice', 'lineTotal');
        }

        $discount = $data['discount'] ?? null;
        $discountValue = (float) data_get($discount, 'value', 0);
        $discountAmount = $discount
            ? ($discount['type'] === 'percent' ? round($subtotal * $discountValue / 100, 2) : $discountValue)
            : 0.0;
        if ($discountAmount >= $subtotal) {
            throw ValidationException::withMessages(['discount.value' => 'El descuento no puede ser mayor o igual al total.']);
        }
        if ($discount) {
            abort_unless(module_enabled('discounts'), 403, 'Este modulo no esta habilitado para esta empresa.');
            if (! Permissions::canApplyDiscounts($request->user())) {
                abort(403);
            }
        }

        $quotation = DB::transaction(function () use ($business, $branch, $request, $data, $customer, $lines, $subtotal, $discount, $discountValue, $discountAmount) {
            $snapshot = $data['customer'] ?? [];
            $quotation = Quotation::query()->create([
                'business_id' => $business->id,
                'business_number' => BusinessCounter::next($business->id, 'quotations'),
                'branch_id' => $branch->id,
                'customer_id' => $customer?->id,
                'created_by' => $request->user()->id,
                'status' => Quotation::OPEN,
                'issued_at' => now(),
                'valid_until' => $data['valid_until'],
                'note' => $data['note'] ?? null,
                'customer_name' => $snapshot['name'] ?? $customer?->name,
                'customer_commercial_name' => $snapshot['commercial_name'] ?? $customer?->commercial_name,
                'customer_doc_type' => $snapshot['doc_type'] ?? $customer?->doc_type,
                'customer_doc_number' => $snapshot['doc_number'] ?? $customer?->doc_number,
                'customer_address' => $snapshot['address'] ?? $customer?->address,
                'customer_address_reference' => $snapshot['address_reference'] ?? $customer?->address_reference,
                'customer_postal_code' => $snapshot['postal_code'] ?? $customer?->postal_code,
                'customer_municipality' => $snapshot['municipality'] ?? $customer?->municipality,
                'customer_department' => $snapshot['department'] ?? $customer?->department,
                'customer_country' => $snapshot['country'] ?? $customer?->country,
                'customer_phone' => $snapshot['phone'] ?? $customer?->phone,
                'subtotal_before_discount' => round($subtotal, 2),
                'discount_type' => $discount['type'] ?? null,
                'discount_value' => round($discountValue, 2),
                'discount_amount' => round($discountAmount, 2),
                'discount_reason' => $discount['reason'] ?? null,
                'total' => round($subtotal - $discountAmount, 2),
            ]);

            $allocatedDiscount = 0.0;
            foreach ($lines as $index => $line) {
                $lineDiscount = $discountAmount === 0.0
                    ? 0.0
                    : ($index === array_key_last($lines)
                        ? round($discountAmount - $allocatedDiscount, 2)
                        : round($line['lineTotal'] / $subtotal * $discountAmount, 2));
                $allocatedDiscount += $lineDiscount;

                $quotation->items()->create([
                    'business_id' => $business->id,
                    'product_id' => $line['product']->id,
                    'price_type_id' => $line['priceType']?->id,
                    'product_name' => $line['product']->name,
                    'product_code' => $line['product']->code,
                    'product_barcode' => $line['product']->barcode,
                    'price_type_name' => $line['priceType']?->name,
                    'quantity' => (int) $line['item']['quantity'],
                    'unit_price' => $line['resolvedPrice']['unit_price'],
                    'original_price' => $line['resolvedPrice']['original_price'],
                    'price_source' => $line['resolvedPrice']['price_source'],
                    'manual_price' => $line['resolvedPrice']['manual_price'],
                    'discount_amount' => $lineDiscount,
                    'total_before_discount' => $line['lineTotal'],
                    'total_after_discount' => round($line['lineTotal'] - $lineDiscount, 2),
                    'total' => round($line['lineTotal'] - $lineDiscount, 2),
                ]);
            }

            return $quotation;
        });

        return redirect()->route('quotations.show', $quotation)->with('success', 'Cotización guardada.');
    }

    public function cancel(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->authorizeQuotation($request, $quotation);
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:2000']]);

        if ($quotation->status !== Quotation::OPEN) {
            throw ValidationException::withMessages(['quotation' => 'Sólo se puede anular una cotización abierta.']);
        }

        $quotation->update([
            'status' => Quotation::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
            'cancellation_reason' => $data['cancellation_reason'],
        ]);

        return back()->with('success', 'Cotización anulada.');
    }

    /**
     * Reuses only the commercial snapshot. A duplicate never inherits the
     * lifecycle or conversion state of its source quotation.
     */
    public function duplicate(Request $request, Quotation $quotation): RedirectResponse
    {
        $business = $this->authorizeQuotation($request, $quotation);
        abort_unless(Permissions::userHas($request->user(), Permissions::QUOTATIONS_CREATE), 403);

        $duplicate = DB::transaction(function () use ($business, $quotation, $request) {
            $source = Quotation::query()
                ->where('business_id', $business->id)
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($quotation->id);

            $timezone = tenantTimezone($business);
            $issuedAt = $source->issued_at?->copy()->timezone($timezone)->startOfDay();
            $validUntil = $source->valid_until;
            $newValidUntil = $issuedAt && $validUntil
                ? now($timezone)->startOfDay()->addDays($issuedAt->diffInDays($validUntil))->toDateString()
                : null;

            $copy = Quotation::query()->create([
                ...collect($source->getAttributes())->only([
                    'branch_id',
                    'customer_id',
                    'note',
                    'customer_name',
                    'customer_commercial_name',
                    'customer_doc_type',
                    'customer_doc_number',
                    'customer_address',
                    'customer_address_reference',
                    'customer_postal_code',
                    'customer_municipality',
                    'customer_department',
                    'customer_country',
                    'customer_phone',
                    'subtotal_before_discount',
                    'discount_type',
                    'discount_value',
                    'discount_amount',
                    'discount_reason',
                    'total',
                ])->all(),
                'business_id' => $business->id,
                'business_number' => BusinessCounter::next($business->id, 'quotations'),
                'created_by' => $request->user()->id,
                'status' => Quotation::OPEN,
                'issued_at' => now(),
                'valid_until' => $newValidUntil,
            ]);

            foreach ($source->items as $item) {
                $copy->items()->create([
                    ...collect($item->getAttributes())->except(['id', 'quotation_id', 'created_at', 'updated_at'])->all(),
                    'business_id' => $business->id,
                ]);
            }

            return $copy;
        });

        return redirect()->route('quotations.show', $duplicate)->with('success', 'Cotización duplicada.');
    }

    /**
     * Opens the normal POS with a server-authorized quotation source.  This is
     * deliberately only a preparation step: it never creates a Sale nor
     * changes the quotation status.
     */
    public function convert(Request $request, Quotation $quotation): RedirectResponse
    {
        $business = $this->authorizeQuotation($request, $quotation);
        abort_unless(Permissions::userHas($request->user(), Permissions::QUOTATIONS_CONVERT), 403);
        $data = $request->validate(['confirm_expired' => ['nullable', 'boolean']]);

        if ($quotation->status !== Quotation::OPEN) {
            throw ValidationException::withMessages(['quotation' => 'Esta cotización ya no puede convertirse en una venta.']);
        }

        if ($quotation->isExpired(tenantTimezone($business)) && ! ($data['confirm_expired'] ?? false)) {
            throw ValidationException::withMessages([
                'quotation' => 'Esta cotización ha vencido. Confirma explícitamente para continuar con sus precios cotizados.',
            ]);
        }

        return redirect()->route('sales.create', [
            'quotation_id' => $quotation->id,
            'confirm_expired' => (bool) ($data['confirm_expired'] ?? false),
        ]);
    }

    public function print(Request $request, Quotation $quotation, string $format)
    {
        $business = $this->authorizeQuotation($request, $quotation);
        abort_unless(in_array($format, ['ticket', 'document'], true), 404);

        $quotation->load(['items', 'branch', 'createdBy', 'cancelledBy']);

        return view("quotations.print-{$format}", [
            'quotation' => $quotation,
            'business' => $business,
            'company' => DocumentCompanyHeader::make($business, $quotation->branch, $business->tenantSetting, [
                'show_logo' => $format === 'document',
            ]),
            'timezone' => tenantTimezone($business),
        ]);
    }

    /**
     * Resolve the commercial price server-side. A quotation intentionally does
     * not check stock, but it follows the same price-list/manual-price policy
     * as the POS at the time it is created.
     */
    private function resolveLinePrice(Request $request, Product $product, array $item, ?Customer $customer, int $branchId): array
    {
        $businessId = (int) $product->business_id;
        $settings = TenantSetting::query()->where('business_id', $businessId)->first();
        $priceSource = $item['price_source'] ?? PriceLists::SOURCE_PRICE_LIST;
        $manualPrice = (bool) ($item['manual_price'] ?? false) || $priceSource === PriceLists::SOURCE_MANUAL;
        $defaultPrice = PriceLists::priceForProduct($product, isset($item['price_type_id']) ? (int) $item['price_type_id'] : null, $branchId);
        $originalPrice = (float) $defaultPrice['price'];

        if ($manualPrice) {
            if (! (bool) ($settings?->allow_manual_price ?? false) || ! Permissions::canUseManualPrice($request->user())) {
                abort(403, 'No tienes permiso para aplicar precio manual.');
            }

            $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);
            ManualPricePolicy::validateUnitPrice($settings, $product, $unitPrice, $originalPrice);

            return [
                'unit_price' => $unitPrice,
                'original_price' => $originalPrice,
                'price_type_id' => $defaultPrice['price_type_id'],
                'price_source' => PriceLists::SOURCE_MANUAL,
                'manual_price' => true,
            ];
        }

        if ($priceSource === PriceLists::SOURCE_LAST_CUSTOMER) {
            if (! (bool) ($settings?->remember_last_customer_product_price ?? false)) {
                throw ValidationException::withMessages(['items' => 'La opcion de recordar ultimo precio por cliente no esta habilitada.']);
            }

            if (! $customer || $customer->is_final_consumer) {
                throw ValidationException::withMessages(['items' => 'El ultimo precio por cliente no aplica para Consumidor Final.']);
            }

            $lastLine = PriceLists::lastCustomerProductPrice($businessId, $customer->id, $product->id);
            if ($lastLine) {
                return [
                    'unit_price' => round((float) $lastLine->unit_price, 2),
                    'original_price' => $originalPrice,
                    'price_type_id' => $lastLine->price_type_id ?: $defaultPrice['price_type_id'],
                    'price_source' => PriceLists::SOURCE_LAST_CUSTOMER,
                    'manual_price' => false,
                ];
            }
        }

        return [
            'unit_price' => $originalPrice,
            'original_price' => $originalPrice,
            'price_type_id' => $defaultPrice['price_type_id'],
            'price_source' => PriceLists::SOURCE_PRICE_LIST,
            'manual_price' => false,
        ];
    }

    private function authorizeQuotation(Request $request, Quotation $quotation): Business
    {
        $business = Business::query()->findOrFail(currentBusinessId());
        abort_unless((int) $quotation->business_id === (int) $business->id, 404);
        if ($quotation->branch_id) {
            app(BranchAccessService::class)->assertCanAccessBranch($request->user(), (int) $quotation->branch_id, $business->id);
        }

        return $business;
    }

    private function limitToAccessibleBranches($query, Request $request, int $businessId): void
    {
        if ($request->user()?->is_super_admin) {
            return;
        }

        $branchIds = app(BranchAccessService::class)->authorizedBranches($request->user(), $businessId)->pluck('id');
        $query->whereIn('branch_id', $branchIds);
    }

    private function payload(Quotation $quotation, string $timezone): array
    {
        return [
            'id' => $quotation->id,
            'number' => $quotation->visible_number,
            'status' => $quotation->status,
            'issued_at' => $quotation->issued_at?->timezone($timezone)->toDateString(),
            'valid_until' => $quotation->valid_until?->toDateString(),
            'expired' => $quotation->isExpired($timezone),
            'total' => (float) $quotation->total,
            'subtotal_before_discount' => (float) ($quotation->subtotal_before_discount ?? $quotation->total),
            'discount_type' => $quotation->discount_type,
            'discount_value' => (float) $quotation->discount_value,
            'discount_amount' => (float) $quotation->discount_amount,
            'discount_reason' => $quotation->discount_reason,
            'note' => $quotation->note,
            'customer' => [
                'name' => $quotation->customer_name,
                'commercial_name' => $quotation->customer_commercial_name,
                'doc_type' => $quotation->customer_doc_type,
                'doc_number' => $quotation->customer_doc_number,
                'address' => $quotation->customer_address,
                'address_reference' => $quotation->customer_address_reference,
                'phone' => $quotation->customer_phone,
                'postal_code' => $quotation->customer_postal_code,
                'department' => $quotation->customer_department,
                'municipality' => $quotation->customer_municipality,
                'country' => $quotation->customer_country,
            ],
            'branch' => $quotation->branch?->only('id', 'name'),
            'created_by' => $quotation->createdBy?->only('id', 'name'),
            'cancelled_by' => $quotation->cancelledBy?->only('id', 'name'),
            'cancellation_reason' => $quotation->cancellation_reason,
            'converted_sale_id' => $quotation->convertedSale?->id,
            'items' => $quotation->relationLoaded('items')
                ? $quotation->items->map(fn ($item) => $item->only('id', 'product_name', 'product_code', 'quantity', 'unit_price', 'discount_amount', 'total'))
                : null,
        ];
    }
}
