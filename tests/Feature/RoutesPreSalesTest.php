<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductPrice;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\RouteZoneCustomer;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use App\Support\StockAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutesPreSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_admin_can_create_route_zone_and_assign_customer(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $customer = $this->customer($business);

        $this->actingAs($admin)->post(route('routes.zones.store'), [
            'branch_id' => $branch->id,
            'assigned_user_id' => $seller->id,
            'name' => 'Ruta Centro',
            'description' => 'Centro',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $zone = RouteZone::query()->where('name', 'Ruta Centro')->firstOrFail();

        $this->actingAs($admin)->post(route('routes.zones.customers.store', $zone), [
            'customer_id' => $customer->id,
            'visit_order' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('route_zone_customers', [
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'visit_order' => 1,
        ]);
    }

    public function test_admin_can_create_customer_from_route_zone_and_it_is_appended(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        $existing = $this->customer($business, 'Cliente existente');
        RouteZoneCustomer::query()->create([
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $existing->id,
            'visit_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('routes.zones.customers.create', $zone), [
                'name' => 'Tienda Nueva',
                'doc_number' => ' 123-456 ',
                'phone' => '5555-1111',
                'address' => 'Zona 1',
                'notes' => 'Frente al parque',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Cliente creado y asignado a la zona.');

        $customer = Customer::query()
            ->where('business_id', $business->id)
            ->where('doc_number', '123456')
            ->firstOrFail();

        $this->assertDatabaseHas('route_zone_customers', [
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'visit_order' => 2,
            'notes' => 'Frente al parque',
            'is_active' => true,
        ]);
    }

    public function test_customer_display_name_prefers_commercial_name(): void
    {
        [$business] = $this->tenant(role: 'owner');
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Juan Carlos Perez',
            'commercial_name' => 'Ferreteria El Tornillo',
            'doc_type' => 'NIT',
            'doc_number' => '1234567',
            'country' => 'GT',
        ]);

        $this->assertSame('Ferreteria El Tornillo', $customer->display_name);
    }

    public function test_customer_display_name_falls_back_to_legal_name(): void
    {
        [$business] = $this->tenant(role: 'owner');
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Juan Carlos Perez',
            'doc_type' => 'NIT',
            'doc_number' => '1234567',
            'country' => 'GT',
        ]);

        $this->assertSame('Juan Carlos Perez', $customer->display_name);
    }

    public function test_gt_route_customer_rejects_invalid_municipality_for_department(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        $this->actingAs($admin)
            ->post(route('routes.zones.customers.create', $zone), [
                'commercial_name' => 'Tienda GT',
                'department' => 'Guatemala',
                'municipality' => 'Huehuetenango',
            ])
            ->assertSessionHasErrors('municipality');
    }

    public function test_non_gt_route_customer_allows_free_text_location_and_uses_business_country(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner', country: 'AR');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        $this->actingAs($admin)
            ->post(route('routes.zones.customers.create', $zone), [
                'commercial_name' => 'Kiosco Centro',
                'department' => 'Buenos Aires',
                'municipality' => 'CABA',
            ])
            ->assertSessionHasNoErrors();

        $customer = Customer::query()->where('business_id', $business->id)->where('commercial_name', 'Kiosco Centro')->firstOrFail();

        $this->assertSame('AR', $customer->country);
        $this->assertSame('Buenos Aires', $customer->department);
        $this->assertSame('CABA', $customer->municipality);
    }

    public function test_admin_duplicate_nit_assigns_existing_customer_without_duplicate_customer_or_assignment(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        $customer = $this->customer($business, 'Cliente NIT');
        $customer->forceFill(['doc_number' => '789123'])->save();

        $payload = [
            'name' => 'Nombre nuevo no usado',
            'doc_number' => '789-123',
            'address' => 'Otra direccion',
        ];

        $this->actingAs($admin)
            ->post(route('routes.zones.customers.create', $zone), $payload)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'El cliente ya existía y fue asignado a la zona.');

        $this->actingAs($admin)
            ->post(route('routes.zones.customers.create', $zone), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Customer::query()->where('business_id', $business->id)->where('doc_number', '789123')->count());
        $this->assertSame(1, RouteZoneCustomer::query()->where('route_zone_id', $zone->id)->where('customer_id', $customer->id)->count());
    }

    public function test_route_customer_without_nit_does_not_merge_by_name_or_commercial_name(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($admin)
                ->post(route('routes.zones.customers.create', $zone), [
                    'name' => 'Cliente sin NIT',
                    'commercial_name' => 'Tienda Repetida',
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Customer::query()
            ->where('business_id', $business->id)
            ->where('commercial_name', 'Tienda Repetida')
            ->count());
    }

    public function test_admin_cannot_edit_route_customer_to_duplicate_nit(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        $existing = $this->customer($business, 'Cliente existente');
        $existing->forceFill(['doc_number' => '123456'])->save();
        $target = $this->customer($business, 'Cliente objetivo');
        $assignment = RouteZoneCustomer::query()->create([
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $target->id,
            'visit_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('routes.zones.customers.details.update', [$zone, $assignment]), [
                'name' => 'Cliente objetivo',
                'doc_number' => '123-456',
            ])
            ->assertSessionHasErrors('doc_number');

        $this->assertNotSame('123456', $target->refresh()->doc_number);
    }

    public function test_unauthorized_user_cannot_create_customer_from_zone(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $cashier = $this->user($business, $branch, 'cashier');
        $zone = $this->zone($business, $branch, $seller);

        $this->actingAs($cashier)
            ->post(route('routes.zones.customers.create', $zone), [
                'name' => 'Cliente no autorizado',
                'doc_number' => '555',
            ])
            ->assertForbidden();
    }

    public function test_seller_only_sees_and_starts_assigned_zone_in_own_branch(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $otherSeller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        $this->zone($business, $branch, $otherSeller, 'Otra zona');

        $this->actingAs($seller)
            ->get(route('routes.mobile.zones'))
            ->assertOk()
            ->assertSee($zone->name)
            ->assertDontSee('Otra zona');

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone))
            ->assertRedirect();

        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Otra sucursal',
            'code' => 'B2',
            'is_active' => true,
        ]);
        $otherBranchZone = $this->zone($business, $otherBranch, $seller, 'Sucursal ajena');

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $otherBranchZone))
            ->assertSessionHasErrors('branch_id');
    }

    public function test_seller_without_branch_cannot_start_work_day(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, null, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone))
            ->assertSessionHasErrors('branch_id');
    }

    public function test_seller_can_create_customer_from_open_work_day_and_visit_is_created(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone))
            ->assertRedirect();

        $workDay = RouteWorkDay::query()->where('route_zone_id', $zone->id)->firstOrFail();

        $this->actingAs($seller)
            ->post(route('routes.mobile.work-days.customers.store', $workDay), [
                'name' => 'Cliente movil',
                'doc_number' => '101010',
                'phone' => '4444-0000',
                'address' => 'Mercado',
                'notes' => 'Local 4',
            ])
            ->assertSessionHasNoErrors();

        $customer = Customer::query()->where('business_id', $business->id)->where('doc_number', '101010')->firstOrFail();
        $visit = RouteVisit::query()->where('route_work_day_id', $workDay->id)->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame('pending', $visit->status);
        $this->assertSame(1, $visit->visit_order);
        $this->assertDatabaseHas('route_zone_customers', [
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'visit_order' => 1,
            'notes' => 'Local 4',
        ]);
    }

    public function test_seller_can_create_customer_with_only_commercial_name_from_open_work_day(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.zones.work-day.start', $zone))->assertRedirect();
        $workDay = RouteWorkDay::query()->where('route_zone_id', $zone->id)->firstOrFail();

        $this->actingAs($seller)
            ->post(route('routes.mobile.work-days.customers.store', $workDay), [
                'commercial_name' => 'Ferreteria El Tornillo',
                'department' => 'Huehuetenango',
                'municipality' => 'Huehuetenango',
            ])
            ->assertSessionHasNoErrors();

        $customer = Customer::query()->where('business_id', $business->id)->where('commercial_name', 'Ferreteria El Tornillo')->firstOrFail();

        $this->assertSame('Ferreteria El Tornillo', $customer->name);
        $this->assertSame('Huehuetenango', $customer->department);
        $this->assertSame('Huehuetenango', $customer->municipality);
        $this->assertDatabaseHas('route_visits', [
            'business_id' => $business->id,
            'route_work_day_id' => $workDay->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_seller_can_edit_route_customer_contact_location_without_changing_pre_sale(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $visit = $this->startedVisit($business, $branch, $seller);
        $preSale = PreSale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_work_day_id' => $visit->route_work_day_id,
            'route_visit_id' => $visit->id,
            'route_zone_id' => $visit->route_zone_id,
            'customer_id' => $visit->customer_id,
            'seller_id' => $seller->id,
            'status' => 'draft',
            'total' => 0,
        ]);

        $this->actingAs($seller)
            ->put(route('routes.mobile.visits.customer.update', $visit), [
                'commercial_name' => 'Tienda Actualizada',
                'phone' => '5555-1212',
                'address' => '5a avenida',
                'department' => 'Guatemala',
                'municipality' => 'Guatemala',
            ])
            ->assertSessionHasNoErrors();

        $customer = $visit->customer->refresh();
        $this->assertSame('Tienda Actualizada', $customer->commercial_name);
        $this->assertSame('Guatemala', $customer->department);
        $this->assertSame('Guatemala', $customer->municipality);
        $this->assertSame('draft', $preSale->refresh()->status);
    }

    public function test_seller_cannot_create_customer_from_closed_work_day(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.zones.work-day.start', $zone))->assertRedirect();
        $workDay = RouteWorkDay::query()->where('route_zone_id', $zone->id)->firstOrFail();
        $workDay->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->actingAs($seller)
            ->post(route('routes.mobile.work-days.customers.store', $workDay), [
                'name' => 'Cliente cerrado',
                'doc_number' => '202020',
            ])
            ->assertSessionHasErrors('work_day');
    }

    public function test_seller_cannot_create_customer_in_another_sellers_work_day(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $otherSeller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.zones.work-day.start', $zone))->assertRedirect();
        $workDay = RouteWorkDay::query()->where('route_zone_id', $zone->id)->firstOrFail();

        $this->actingAs($otherSeller)
            ->post(route('routes.mobile.work-days.customers.store', $workDay), [
                'name' => 'Cliente ajeno',
                'doc_number' => '303030',
            ])
            ->assertForbidden();
    }

    public function test_seller_cannot_create_customer_for_work_day_from_another_branch(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal ajena',
            'code' => 'B9',
            'is_active' => true,
        ]);
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $otherBranch, $seller, 'Zona otra sucursal');
        $workDay = RouteWorkDay::query()->create([
            'business_id' => $business->id,
            'branch_id' => $otherBranch->id,
            'route_zone_id' => $zone->id,
            'seller_id' => $seller->id,
            'work_date' => now()->toDateString(),
            'status' => 'open',
            'started_at' => now(),
        ]);

        $this->actingAs($seller)
            ->post(route('routes.mobile.work-days.customers.store', $workDay), [
                'name' => 'Cliente otra sucursal',
                'doc_number' => '404040',
            ])
            ->assertForbidden();
    }

    public function test_route_customer_duplicate_lookup_is_business_scoped(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        [$otherBusiness] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        Customer::query()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Cliente otro negocio',
            'doc_type' => 'NIT',
            'doc_number' => '909090',
            'country' => 'GT',
        ]);

        $this->actingAs($admin)
            ->post(route('routes.zones.customers.create', $zone), [
                'name' => 'Cliente negocio actual',
                'doc_number' => '909090',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Customer::query()->where('business_id', $business->id)->where('doc_number', '909090')->count());
        $this->assertSame(1, Customer::query()->where('business_id', $otherBusiness->id)->where('doc_number', '909090')->count());
    }

    public function test_starting_work_day_creates_visits_and_second_start_resumes_without_duplicates(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        $customerA = $this->customer($business, 'Cliente A');
        $customerB = $this->customer($business, 'Cliente B');
        RouteZoneCustomer::query()->create(['business_id' => $business->id, 'route_zone_id' => $zone->id, 'customer_id' => $customerA->id, 'visit_order' => 2, 'is_active' => true]);
        RouteZoneCustomer::query()->create(['business_id' => $business->id, 'route_zone_id' => $zone->id, 'customer_id' => $customerB->id, 'visit_order' => 1, 'is_active' => true]);

        $this->actingAs($seller)->post(route('routes.mobile.zones.work-day.start', $zone))->assertRedirect();
        $this->actingAs($seller)->post(route('routes.mobile.zones.work-day.start', $zone))->assertRedirect();

        $workDay = RouteWorkDay::query()->firstOrFail();
        $this->assertSame(2, RouteVisit::query()->where('route_work_day_id', $workDay->id)->count());
        $this->assertSame($customerB->id, RouteVisit::query()->where('route_work_day_id', $workDay->id)->orderBy('visit_order')->first()->customer_id);
    }

    public function test_creating_and_editing_pre_sale_updates_stock_reservation_and_available_stock(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner', allowNegativeStock: false);
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10, salePrice: 100);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [['product_id' => $product->id, 'quantity' => 5, 'discount' => 0]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(5.0, (float) StockReservation::query()->where('product_id', $product->id)->where('status', 'active')->value('quantity'));
        $this->assertSame(5.0, StockAvailability::availableStock($product, null, $branch->id));

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [['product_id' => $product->id, 'quantity' => 6, 'discount' => 0]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(6.0, (float) StockReservation::query()->where('product_id', $product->id)->where('status', 'active')->value('quantity'));
        $this->assertSame(4.0, StockAvailability::availableStock($product, null, $branch->id));
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->value('stock'));
    }

    public function test_cancelling_draft_pre_sale_releases_reservation(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        Permissions::assignDirectPermissions($seller, [Permissions::ROUTES_PRE_SALES_CANCEL]);
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $preSale = PreSale::query()->firstOrFail();

        $this->actingAs($seller)
            ->post(route('routes.pre-sales.cancel', $preSale))
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $preSale->refresh()->status);
        $this->assertSame(0, StockReservation::query()->where('status', 'active')->count());
        $this->assertSame(10.0, StockAvailability::availableStock($product, null, $branch->id));
    }

    public function test_closing_work_day_submits_pre_sales_keeps_reservations_and_does_not_deduct_stock(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $workDay = RouteWorkDay::query()->firstOrFail();
        $this->actingAs($seller)->post(route('routes.mobile.work-days.close', $workDay))->assertRedirect(route('routes.mobile.zones'));

        $this->assertSame('closed', $workDay->refresh()->status);
        $this->assertSame('submitted', PreSale::query()->firstOrFail()->status);
        $this->assertSame(1, StockReservation::query()->where('status', 'active')->count());
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->value('stock'));

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('pre_sale');
    }

    public function test_pre_sale_reservation_reduces_available_stock_and_negative_stock_policy_controls_over_reservation(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner', allowNegativeStock: false);
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();

        $otherVisit = $this->startedVisit($business, $branch, $seller, 'Cliente extra');
        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $otherVisit), [
            'items' => [['product_id' => $product->id, 'quantity' => 6]],
        ])->assertSessionHasErrors('items');

        [$allowedBusiness, , $allowedBranch] = $this->tenant(role: 'owner', allowNegativeStock: true);
        $allowedSeller = $this->user($allowedBusiness, $allowedBranch, 'pre_seller');
        $allowedProduct = $this->product($allowedBusiness, $allowedBranch, stock: 1);
        $allowedVisit = $this->startedVisit($allowedBusiness, $allowedBranch, $allowedSeller);

        $this->actingAs($allowedSeller)->post(route('routes.mobile.visits.pre-sale.store', $allowedVisit), [
            'items' => [['product_id' => $allowedProduct->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(-2.0, StockAvailability::availableStock($allowedProduct, null, $allowedBranch->id));
    }

    public function test_seller_cannot_access_another_sellers_work_day(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $otherSeller = $this->user($business, $branch, 'pre_seller');
        $visit = $this->startedVisit($business, $branch, $seller);
        $workDay = $visit->workDay;

        $this->actingAs($otherSeller)->get(route('routes.mobile.work-days.show', $workDay))->assertForbidden();
        $this->actingAs($otherSeller)->get(route('routes.mobile.visits.show', $visit))->assertForbidden();
    }

    public function test_admin_can_view_pre_sales(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch);
        $visit = $this->startedVisit($business, $branch, $seller);
        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.index'))
            ->assertOk()
            ->assertSee($visit->customer->name);
    }

    public function test_customer_search_finds_commercial_name_and_is_business_scoped(): void
    {
        [$business, $admin] = $this->tenant(role: 'owner');
        [$otherBusiness] = $this->tenant(role: 'owner');

        TenantModule::query()->create([
            'business_id' => $business->id,
            'module' => 'customers',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $match = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Juan Perez',
            'commercial_name' => 'Ferreteria El Tornillo',
            'doc_type' => 'NIT',
            'doc_number' => '1234567',
            'address' => '5a avenida',
            'department' => 'Huehuetenango',
            'municipality' => 'Huehuetenango',
            'country' => 'GT',
        ]);

        Customer::query()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Otro cliente',
            'commercial_name' => 'Ferreteria El Tornillo',
            'doc_type' => 'NIT',
            'doc_number' => '7654321',
            'country' => 'GT',
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('customers.search', ['search' => 'Tornillo']));

        $response->assertOk()
            ->assertJsonPath('customers.0.id', $match->id)
            ->assertJsonCount(1, 'customers');
    }

    private function tenant(string $role = 'owner', bool $allowNegativeStock = false, string $country = 'GT'): array
    {
        $business = Business::query()->create([
            'name' => 'Routes Test',
            'slug' => 'routes-test-'.uniqid(),
            'currency' => 'GTQ',
            'country' => $country,
            'is_active' => true,
        ]);

        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_product_images' => true,
            'max_users' => 10,
            'use_branches' => true,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_manual_price' => false,
            'remember_last_customer_product_price' => false,
            'enable_credit_sales' => false,
            'allow_negative_stock' => $allowNegativeStock,
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);

        foreach (['routes', 'inventory', 'branches'] as $module) {
            TenantModule::query()->create([
                'business_id' => $business->id,
                'module' => $module,
                'is_enabled' => true,
                'enabled_at' => now(),
            ]);
        }

        $branch = BranchInventory::defaultBranchForBusiness($business);
        $user = $this->user($business, $branch, $role);

        return [$business, $user, $branch];
    }

    private function user(Business $business, ?Branch $branch, string $role): User
    {
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => $role,
            'is_active' => true,
            'is_super_admin' => false,
            'current_branch_id' => $branch?->id,
        ]);
        Permissions::assignRole($user, $role);

        return $user;
    }

    private function zone(Business $business, Branch $branch, User $seller, string $name = 'Ruta Norte'): RouteZone
    {
        return RouteZone::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'assigned_user_id' => $seller->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function customer(Business $business, string $name = 'Cliente ruta'): Customer
    {
        return Customer::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'doc_type' => 'NIT',
            'doc_number' => (string) random_int(1000000, 9999999),
            'address' => 'Ciudad',
            'phone' => '5555-0000',
            'country' => 'GT',
        ]);
    }

    private function product(Business $business, Branch $branch, float $stock = 10, float $salePrice = 100): Product
    {
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Producto ruta '.uniqid(),
            'code' => 'R-'.uniqid(),
            'cost_price' => 50,
            'sale_price' => $salePrice,
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        ProductBranchStock::query()->updateOrCreate(
            ['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id],
            ['stock' => $stock],
        );

        $priceType = PriceType::query()->updateOrCreate(
            ['business_id' => $business->id, 'name' => 'General'],
            ['is_default' => true, 'is_active' => true],
        );

        ProductPrice::query()->updateOrCreate(
            ['business_id' => $business->id, 'product_id' => $product->id, 'price_type_id' => $priceType->id],
            ['price' => $salePrice, 'is_active' => true],
        );

        return $product;
    }

    private function startedVisit(Business $business, Branch $branch, User $seller, string $customerName = 'Cliente ruta'): RouteVisit
    {
        $zone = $this->zone($business, $branch, $seller, 'Ruta '.uniqid());
        $customer = $this->customer($business, $customerName);
        RouteZoneCustomer::query()->create([
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'visit_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone))
            ->assertRedirect();

        return RouteVisit::query()
            ->where('business_id', $business->id)
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->firstOrFail();
    }
}
