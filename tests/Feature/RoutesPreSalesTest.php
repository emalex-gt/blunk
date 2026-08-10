<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CreditReceipt;
use App\Models\CreditReceiptLine;
use App\Models\Customer;
use App\Models\CustomerAccountMovement;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditPayment;
use App\Models\ElectronicDocument;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductBranch;
use App\Models\ProductBranchStock;
use App\Models\ProductPrice;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\RouteZoneCustomer;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use App\Support\StockAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_route_customer_creation_persists_fiscal_commercial_and_contact_names(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        $this->actingAs($admin)
            ->post(route('routes.zones.customers.create', $zone), [
                'name' => 'INVERSIONES BONGO SOCIEDAD ANONIMA',
                'commercial_name' => 'Bongo Repuestos',
                'contact_name' => 'Carlos Perez',
                'doc_number' => ' 33100-4X700 ',
                'phone' => '5555-1111',
                'address' => 'Zona 1',
                'department' => 'Guatemala',
                'municipality' => 'Guatemala',
            ])
            ->assertSessionHasNoErrors();

        $customer = Customer::query()
            ->where('business_id', $business->id)
            ->where('doc_number', '331004X700')
            ->firstOrFail();

        $this->assertSame('INVERSIONES BONGO SOCIEDAD ANONIMA', $customer->name);
        $this->assertSame('Bongo Repuestos', $customer->commercial_name);
        $this->assertSame('Carlos Perez', $customer->contact_name);
        $this->assertSame('Guatemala', $customer->department);
        $this->assertSame('Guatemala', $customer->municipality);
    }

    public function test_route_customer_reuses_existing_nit_and_only_fills_empty_fields(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        $existing = Customer::query()->create([
            'business_id' => $business->id,
            'name' => '',
            'commercial_name' => 'Nombre comercial existente',
            'doc_type' => 'NIT',
            'doc_number' => '1234567',
            'country' => 'GT',
        ]);

        $this->actingAs($admin)
            ->post(route('routes.zones.customers.create', $zone), [
                'name' => 'NOMBRE FISCAL DESDE NIT',
                'commercial_name' => 'Nombre comercial nuevo',
                'contact_name' => 'Contacto nuevo',
                'doc_number' => ' 123-4567 ',
                'address' => 'Direccion nueva',
            ])
            ->assertSessionHasNoErrors();

        $existing->refresh();

        $this->assertSame('NOMBRE FISCAL DESDE NIT', $existing->name);
        $this->assertSame('Nombre comercial existente', $existing->commercial_name);
        $this->assertSame('Contacto nuevo', $existing->contact_name);
        $this->assertSame('Direccion nueva', $existing->address);
        $this->assertSame(1, Customer::query()->where('business_id', $business->id)->where('doc_number', '1234567')->count());
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

    public function test_work_zone_button_start_route_creates_work_day_visits_and_redirects_to_work_day(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        $customer = $this->customer($business, 'Cliente ruta inicio');
        RouteZoneCustomer::query()->create([
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'visit_order' => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone));

        $workDay = RouteWorkDay::query()
            ->where('business_id', $business->id)
            ->where('route_zone_id', $zone->id)
            ->where('seller_id', $seller->id)
            ->firstOrFail();

        $response
            ->assertStatus(303)
            ->assertRedirect(route('routes.mobile.work-days.show', $workDay));

        $this->assertSame(1, RouteVisit::query()->where('route_work_day_id', $workDay->id)->count());
        $this->assertDatabaseHas('route_visits', [
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_work_day_id' => $workDay->id,
            'customer_id' => $customer->id,
            'seller_id' => $seller->id,
            'status' => 'pending',
        ]);
    }

    public function test_work_zone_button_start_route_resumes_existing_open_work_day_without_duplicates(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        $customer = $this->customer($business, 'Cliente ruta resume');
        RouteZoneCustomer::query()->create([
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'visit_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone))
            ->assertStatus(303);

        $workDay = RouteWorkDay::query()->where('route_zone_id', $zone->id)->firstOrFail();

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone))
            ->assertStatus(303)
            ->assertRedirect(route('routes.mobile.work-days.show', $workDay));

        $this->assertSame(1, RouteWorkDay::query()
            ->where('business_id', $business->id)
            ->where('route_zone_id', $zone->id)
            ->where('seller_id', $seller->id)
            ->count());
        $this->assertSame(1, RouteVisit::query()->where('route_work_day_id', $workDay->id)->count());
    }

    public function test_seller_can_start_same_route_again_after_closing_work_day_same_day(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        $customer = $this->customer($business, 'Cliente ruta multiple');
        RouteZoneCustomer::query()->create([
            'business_id' => $business->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'visit_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone))
            ->assertStatus(303);

        $firstWorkDay = RouteWorkDay::query()->where('route_zone_id', $zone->id)->firstOrFail();

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone))
            ->assertStatus(303)
            ->assertRedirect(route('routes.mobile.work-days.show', $firstWorkDay));

        $this->assertSame(1, RouteWorkDay::query()->where('route_zone_id', $zone->id)->count());

        $this->actingAs($seller)
            ->post(route('routes.mobile.work-days.close', $firstWorkDay))
            ->assertRedirect(route('routes.mobile.zones'));

        $firstWorkDay->refresh();
        $this->assertSame('closed', $firstWorkDay->status);

        $this->actingAs($seller)
            ->post(route('routes.mobile.zones.work-day.start', $zone))
            ->assertStatus(303);

        $secondWorkDay = RouteWorkDay::query()
            ->where('route_zone_id', $zone->id)
            ->where('status', 'open')
            ->firstOrFail();

        $this->assertNotSame($firstWorkDay->id, $secondWorkDay->id);
        $this->assertSame(2, RouteWorkDay::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('route_zone_id', $zone->id)
            ->where('seller_id', $seller->id)
            ->whereDate('work_date', now()->toDateString())
            ->count());
        $this->assertSame('closed', $firstWorkDay->refresh()->status);
        $this->assertSame(1, RouteWorkDay::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('route_zone_id', $zone->id)
            ->where('seller_id', $seller->id)
            ->where('status', 'open')
            ->count());
        $this->assertSame(1, RouteVisit::query()->where('route_work_day_id', $secondWorkDay->id)->count());
    }

    public function test_work_day_migration_closes_duplicate_open_rows_before_creating_unique_open_index(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS route_work_days_unique_open');
        }

        $olderWorkDay = RouteWorkDay::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_zone_id' => $zone->id,
            'seller_id' => $seller->id,
            'work_date' => now()->toDateString(),
            'status' => 'open',
            'started_at' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);
        $latestWorkDay = RouteWorkDay::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_zone_id' => $zone->id,
            'seller_id' => $seller->id,
            'work_date' => now()->toDateString(),
            'status' => 'open',
            'started_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $migration = include database_path('migrations/2026_07_23_000001_allow_multiple_route_work_days_per_day.php');
        $migration->up();

        $this->assertSame('closed', $olderWorkDay->refresh()->status);
        $this->assertNotNull($olderWorkDay->closed_at);
        $this->assertSame('open', $latestWorkDay->refresh()->status);
        $this->assertSame(1, RouteWorkDay::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('route_zone_id', $zone->id)
            ->where('seller_id', $seller->id)
            ->where('status', 'open')
            ->count());
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

    public function test_route_mobile_new_customer_defaults_to_branch_location(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $branch->update([
            'department' => 'Huehuetenango',
            'municipality' => 'Huehuetenango',
        ]);
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.zones.work-day.start', $zone))->assertRedirect();
        $workDay = RouteWorkDay::query()->where('route_zone_id', $zone->id)->firstOrFail();

        $this->actingAs($seller)
            ->post(route('routes.mobile.work-days.customers.store', $workDay), [
                'name' => 'Cliente default ubicacion',
                'doc_number' => '121212',
            ])
            ->assertSessionHasNoErrors();

        $customer = Customer::query()->where('business_id', $business->id)->where('doc_number', '121212')->firstOrFail();

        $this->assertSame('Huehuetenango', $customer->department);
        $this->assertSame('Huehuetenango', $customer->municipality);
    }

    public function test_route_mobile_new_customer_can_override_branch_location(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $branch->update([
            'department' => 'Huehuetenango',
            'municipality' => 'Huehuetenango',
        ]);
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.zones.work-day.start', $zone))->assertRedirect();
        $workDay = RouteWorkDay::query()->where('route_zone_id', $zone->id)->firstOrFail();

        $this->actingAs($seller)
            ->post(route('routes.mobile.work-days.customers.store', $workDay), [
                'name' => 'Cliente override ubicacion',
                'doc_number' => '343434',
                'department' => 'Guatemala',
                'municipality' => 'Guatemala',
            ])
            ->assertSessionHasNoErrors();

        $customer = Customer::query()->where('business_id', $business->id)->where('doc_number', '343434')->firstOrFail();

        $this->assertSame('Guatemala', $customer->department);
        $this->assertSame('Guatemala', $customer->municipality);
    }

    public function test_route_customer_existing_nit_is_not_overwritten_by_branch_location_defaults(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $branch->update([
            'department' => 'Huehuetenango',
            'municipality' => 'Huehuetenango',
        ]);
        $seller = $this->user($business, $branch, 'pre_seller');
        $zone = $this->zone($business, $branch, $seller);
        $existing = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente existente',
            'doc_type' => 'NIT',
            'doc_number' => '555888',
            'country' => 'GT',
        ]);

        $this->actingAs($admin)
            ->post(route('routes.zones.customers.create', $zone), [
                'name' => 'Cliente existente',
                'doc_number' => '555888',
                'department' => 'Huehuetenango',
                'municipality' => 'Huehuetenango',
            ])
            ->assertSessionHasNoErrors();

        $existing->refresh();

        $this->assertNull($existing->department);
        $this->assertNull($existing->municipality);
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

    public function test_without_sale_requires_reason_and_note_and_has_no_financial_or_stock_side_effects(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.without-sale', $visit), [])
            ->assertSessionHasErrors(['no_sale_reason', 'no_sale_note']);

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.without-sale', $visit), [
                'no_sale_reason' => 'Tienda cerrada',
                'no_sale_note' => 'El local estaba cerrado.',
            ])
            ->assertSessionHasNoErrors();

        $visit->refresh();

        $this->assertSame('without_sale', $visit->status);
        $this->assertSame('Tienda cerrada', $visit->no_sale_reason);
        $this->assertSame('El local estaba cerrado.', $visit->no_sale_note);
        $this->assertSame(0, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, ElectronicDocument::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerCreditAccount::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerAccountMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(10.0, (float) ProductBranchStock::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('stock'));
    }

    public function test_without_sale_visit_can_become_draft_pre_sale_while_work_day_is_open(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.without-sale', $visit), [
                'no_sale_reason' => 'Cliente surtido',
                'no_sale_note' => 'Pidió revisar después.',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [['product_id' => $product->id, 'quantity' => 4]],
            ])
            ->assertSessionHasNoErrors();

        $visit->refresh();
        $preSale = PreSale::query()->where('route_visit_id', $visit->id)->firstOrFail();

        $this->assertSame('with_pre_sale', $visit->status);
        $this->assertNull($visit->no_sale_reason);
        $this->assertNull($visit->no_sale_note);
        $this->assertSame('draft', $preSale->status);
        $this->assertSame(1, $preSale->items()->count());
        $this->assertSame(1, StockReservation::query()->where('business_id', $business->id)->where('status', 'active')->count());
        $this->assertSame(6.0, StockAvailability::availableStock($product, null, $branch->id));
        $this->assertSame(10.0, (float) ProductBranchStock::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('stock'));
        $this->assertSame(0, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, ElectronicDocument::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerCreditAccount::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerAccountMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->count());
    }

    public function test_draft_pre_sale_to_without_sale_to_new_draft_pre_sale_updates_visit_state(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
            ])
            ->assertSessionHasNoErrors();

        $oldPreSale = PreSale::query()->where('route_visit_id', $visit->id)->firstOrFail();
        $this->assertSame('with_pre_sale', $visit->refresh()->status);
        $this->assertSame(1, StockReservation::query()->where('business_id', $business->id)->where('status', 'active')->count());

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.without-sale', $visit), [
                'no_sale_reason' => 'No quiso comprar',
                'no_sale_note' => 'Al final me llamo que no queria nada',
            ])
            ->assertSessionHasNoErrors();

        $visit->refresh();
        $this->assertSame('without_sale', $visit->status);
        $this->assertSame('No quiso comprar', $visit->no_sale_reason);
        $this->assertSame('Al final me llamo que no queria nada', $visit->no_sale_note);
        $this->assertSame('cancelled', $oldPreSale->refresh()->status);
        $this->assertSame(0, StockReservation::query()->where('business_id', $business->id)->where('status', 'active')->count());
        $this->assertSame(1, StockReservation::query()->where('business_id', $business->id)->where('status', 'released')->count());

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->assertSessionHasNoErrors();

        $visit->refresh();
        $newPreSale = PreSale::query()
            ->where('route_visit_id', $visit->id)
            ->where('status', 'draft')
            ->firstOrFail();

        $this->assertNotSame($oldPreSale->id, $newPreSale->id);
        $this->assertSame('with_pre_sale', $visit->status);
        $this->assertNull($visit->no_sale_reason);
        $this->assertNull($visit->no_sale_note);
        $this->assertSame('cancelled', $oldPreSale->refresh()->status);
        $this->assertSame('draft', $newPreSale->status);
        $this->assertSame(1, $newPreSale->items()->count());
        $this->assertSame(1, StockReservation::query()->where('business_id', $business->id)->where('status', 'active')->count());
        $this->assertSame(1, StockReservation::query()->where('business_id', $business->id)->where('status', 'released')->count());
        $this->assertSame(8.0, StockAvailability::availableStock($product, null, $branch->id));
        $this->assertSame(10.0, (float) ProductBranchStock::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('stock'));
        $this->assertSame(0, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, ElectronicDocument::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerCreditAccount::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerAccountMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->count());
    }

    public function test_failed_pre_sale_save_keeps_without_sale_reason_and_note(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.without-sale', $visit), [
                'no_sale_reason' => 'No encontrado',
                'no_sale_note' => 'No estaba el encargado.',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [],
            ])
            ->assertSessionHasErrors('items');

        $visit->refresh();

        $this->assertSame('without_sale', $visit->status);
        $this->assertSame('No encontrado', $visit->no_sale_reason);
        $this->assertSame('No estaba el encargado.', $visit->no_sale_note);
        $this->assertSame(0, PreSale::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, StockReservation::query()->where('business_id', $business->id)->count());
    }

    public function test_closed_work_day_without_sale_visit_cannot_become_pre_sale(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);
        $workDay = $visit->workDay;

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.without-sale', $visit), [
                'no_sale_reason' => 'Tienda cerrada',
                'no_sale_note' => 'Cerrado por inventario.',
            ])
            ->assertSessionHasNoErrors();

        $workDay->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('pre_sale');

        $visit->refresh();

        $this->assertSame('without_sale', $visit->status);
        $this->assertSame('Tienda cerrada', $visit->no_sale_reason);
        $this->assertSame(0, PreSale::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, StockReservation::query()->where('business_id', $business->id)->count());
    }

    public function test_without_sale_with_draft_pre_sale_cancels_draft_and_releases_reservations(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $preSale = PreSale::query()->where('route_visit_id', $visit->id)->firstOrFail();

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.without-sale', $visit), [
                'no_sale_reason' => 'No quiso comprar',
                'no_sale_note' => 'El cliente decidió no comprar hoy.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('without_sale', $visit->refresh()->status);
        $this->assertSame('No quiso comprar', $visit->no_sale_reason);
        $this->assertSame('El cliente decidió no comprar hoy.', $visit->no_sale_note);
        $this->assertSame('cancelled', $preSale->refresh()->status);
        $this->assertNotNull($preSale->cancelled_at);
        $this->assertSame(0, $preSale->items()->count());
        $this->assertSame(0, StockReservation::query()->where('business_id', $business->id)->where('status', 'active')->count());
        $this->assertSame(1, StockReservation::query()->where('business_id', $business->id)->where('status', 'released')->count());
        $this->assertSame(10.0, (float) ProductBranchStock::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('stock'));
        $this->assertSame(0, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, ElectronicDocument::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerCreditAccount::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerAccountMovement::query()->where('business_id', $business->id)->count());
    }

    public function test_without_sale_is_blocked_for_submitted_pre_sale_and_keeps_reservation_active(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $preSale = PreSale::query()->where('route_visit_id', $visit->id)->firstOrFail();
        $preSale->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.without-sale', $visit), [
                'no_sale_reason' => 'Pedido para otro día',
                'no_sale_note' => 'Quiere comprar mañana.',
            ])
            ->assertSessionHasErrors('pre_sale');

        $this->assertSame('submitted', $preSale->refresh()->status);
        $this->assertSame('with_pre_sale', $visit->refresh()->status);
        $this->assertNull($visit->no_sale_reason);
        $this->assertSame(1, StockReservation::query()->where('business_id', $business->id)->where('status', 'active')->count());
        $this->assertSame(0, StockReservation::query()->where('business_id', $business->id)->where('status', 'released')->count());
    }

    public function test_seller_cannot_mark_another_business_visit_without_sale(): void
    {
        [$businessA, , $branchA] = $this->tenant(role: 'owner');
        $sellerA = $this->user($businessA, $branchA, 'pre_seller');
        [$businessB, , $branchB] = $this->tenant(role: 'owner');
        $sellerB = $this->user($businessB, $branchB, 'pre_seller');
        $visitB = $this->startedVisit($businessB, $branchB, $sellerB);

        $this->actingAs($sellerA)
            ->post(route('routes.mobile.visits.without-sale', $visitB), [
                'no_sale_reason' => 'No encontrado',
                'no_sale_note' => 'No aplica.',
            ])
            ->assertForbidden();

        $this->assertSame('pending', $visitB->refresh()->status);
        $this->assertNull($visitB->no_sale_reason);
    }

    public function test_mobile_pre_sale_source_hides_discount_input_and_requires_confirmations(): void
    {
        $visitSource = file_get_contents(resource_path('js/Pages/Routes/Mobile/Visit.tsx'));
        $workDaySource = file_get_contents(resource_path('js/Pages/Routes/Mobile/WorkDay.tsx'));

        $this->assertStringNotContainsString('placeholder="Descuento"', $visitSource);
        $this->assertStringNotContainsString('item.discount', $visitSource);
        $this->assertStringContainsString('¿Guardar la preventa de', $visitSource);
        $this->assertStringContainsString('¿Estás seguro de editar la orden de', $visitSource);
        $this->assertStringContainsString('Sí, guardar', $visitSource);
        $this->assertStringContainsString('Sí, editar', $visitSource);
        $this->assertStringContainsString('Esta visita está marcada como sin venta. Si guardas una preventa, se quitará ese estado.', $visitSource);
        $this->assertStringContainsString('Esta visita está marcada como sin venta. Al guardar la preventa, se quitará ese estado. ¿Deseas continuar?', $visitSource);
        $this->assertStringContainsString('Jornada cerrada. Ya no se puede editar la visita.', $visitSource);
        $this->assertStringContainsString("only: ['visit', 'preSale']", $visitSource);
        $this->assertStringContainsString('preserveState: false', $visitSource);
        $this->assertStringContainsString('¿Finalizar la ruta?', $workDaySource);
        $this->assertStringContainsString('Al finalizar la ruta, las preventas quedarán enviadas y ya no se podrán editar. ¿Deseas continuar?', $workDaySource);
        $this->assertStringContainsString('Sí, finalizar ruta', $workDaySource);
        $this->assertStringContainsString('Motivo', $workDaySource);
        $this->assertStringContainsString('Observación', $workDaySource);
        $this->assertStringContainsString('Este cliente ya tiene productos agregados. Si marcas la visita como sin venta, se eliminará la preventa actual y se liberará el stock reservado. ¿Deseas continuar?', $workDaySource);
        $this->assertStringContainsString('La preventa ya fue enviada y no se puede cambiar a sin venta.', $workDaySource);
        $this->assertStringContainsString('Confirmar sin venta', $workDaySource);
        $this->assertStringContainsString("visit.status === 'without_sale' && (visit.no_sale_reason || visit.no_sale_note)", $workDaySource);
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

    public function test_seller_cannot_access_another_business_route_zone_or_visit(): void
    {
        [$businessA, , $branchA] = $this->tenant(role: 'owner');
        $sellerA = $this->user($businessA, $branchA, 'pre_seller');
        [$businessB, , $branchB] = $this->tenant(role: 'owner');
        $sellerB = $this->user($businessB, $branchB, 'pre_seller');
        $zoneB = $this->zone($businessB, $branchB, $sellerB, 'Ruta negocio B');
        $visitB = $this->startedVisit($businessB, $branchB, $sellerB, 'Cliente negocio B');

        $this->actingAs($sellerA)
            ->post(route('routes.mobile.zones.work-day.start', $zoneB))
            ->assertForbidden();

        $this->actingAs($sellerA)
            ->get(route('routes.mobile.visits.show', $visitB))
            ->assertForbidden();
    }

    public function test_pre_sale_reservation_is_not_disabled_by_credit_reservation_setting(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner', allowNegativeStock: false);
        TenantModule::query()->create([
            'business_id' => $business->id,
            'module' => 'pos',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);
        TenantSetting::query()->where('business_id', $business->id)->update([
            'reserve_stock_on_credit_reservations' => false,
        ]);

        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(4.0, (float) StockReservation::query()
            ->where('source_type', 'pre_sale')
            ->where('product_id', $product->id)
            ->where('status', 'active')
            ->value('quantity'));
        $this->assertSame(6.0, StockAvailability::availableStock($product, null, $branch->id));

        $this->actingAs($admin)
            ->get(route('sales.products.search', ['q' => $product->code]))
            ->assertOk()
            ->assertJsonPath('products.0.available_stock', 6);
    }

    public function test_pre_sale_has_no_sale_fel_cash_ar_or_physical_stock_side_effects(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $workDay = RouteWorkDay::query()->where('business_id', $business->id)->firstOrFail();
        $this->actingAs($seller)
            ->post(route('routes.mobile.work-days.close', $workDay))
            ->assertRedirect(route('routes.mobile.zones'));

        $this->assertSame(0, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, ElectronicDocument::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerCreditAccount::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerAccountMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerCreditPayment::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(10.0, (float) ProductBranchStock::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('stock'));
        $this->assertSame(4.0, (float) StockReservation::query()
            ->where('business_id', $business->id)
            ->where('source_type', 'pre_sale')
            ->where('status', 'active')
            ->sum('quantity'));
    }

    public function test_pre_sale_product_search_respects_branch_product_assignment(): void
    {
        [$business, , $branchA] = $this->tenant(role: 'owner');
        TenantSetting::query()->where('business_id', $business->id)->update([
            'products_shared_across_branches' => false,
        ]);
        $branchB = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
        ]);
        $seller = $this->user($business, $branchA, 'pre_seller');
        $visit = $this->startedVisit($business, $branchA, $seller);
        $productA = $this->product($business, $branchA, stock: 5);
        $productB = $this->product($business, $branchB, stock: 5);
        ProductBranch::query()->updateOrCreate(
            ['business_id' => $business->id, 'branch_id' => $branchA->id, 'product_id' => $productA->id],
            ['is_active' => true],
        );
        ProductBranch::query()->updateOrCreate(
            ['business_id' => $business->id, 'branch_id' => $branchB->id, 'product_id' => $productB->id],
            ['is_active' => true],
        );

        $this->actingAs($seller)
            ->get(route('routes.mobile.visits.show', [$visit, 'search' => 'Producto ruta']))
            ->assertOk()
            ->assertSee($productA->name)
            ->assertDontSee($productB->name);
    }

    public function test_route_nit_resolution_returns_existing_customer_without_creating_duplicate(): void
    {
        [$business, $admin] = $this->tenant(role: 'owner');
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'NOMBRE FISCAL EXISTENTE',
            'commercial_name' => 'Comercial Existente',
            'contact_name' => 'Contacto Existente',
            'doc_type' => 'NIT',
            'doc_number' => '998877',
            'phone' => '5555-2222',
            'address' => 'Zona 10',
            'department' => 'Guatemala',
            'municipality' => 'Guatemala',
            'country' => 'GT',
        ]);

        $this->actingAs($admin)
            ->getJson(route('routes.resolve-nit', ['nit' => ' 998-877 ']))
            ->assertOk()
            ->assertJsonPath('source', 'existing')
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('customer.name', 'NOMBRE FISCAL EXISTENTE')
            ->assertJsonPath('customer.contact_name', 'Contacto Existente');

        $this->assertSame(1, Customer::query()->where('business_id', $business->id)->where('doc_number', '998877')->count());
    }

    public function test_pre_sale_product_search_and_save_use_configured_price_type(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $visit = $this->startedVisit($business, $branch, $seller);
        $product = $this->product($business, $branch, stock: 8, salePrice: 100);
        $preSalePriceType = PriceType::query()->create([
            'business_id' => $business->id,
            'name' => 'Preventa',
            'is_default' => false,
            'is_active' => true,
        ]);
        ProductPrice::query()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'price_type_id' => $preSalePriceType->id,
            'price' => 75,
            'is_active' => true,
        ]);
        TenantSetting::query()->where('business_id', $business->id)->update([
            'pre_sale_price_type_id' => $preSalePriceType->id,
        ]);

        $this->actingAs($seller)
            ->getJson(route('routes.mobile.visits.products.search', [$visit, 'q' => $product->code]))
            ->assertOk()
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.price_type_id', $preSalePriceType->id)
            ->assertJsonPath('products.0.sale_price', 75);

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [['product_id' => $product->id, 'quantity' => 2, 'discount' => 0]],
            ])
            ->assertSessionHasNoErrors();

        $item = PreSale::query()->where('route_visit_id', $visit->id)->firstOrFail()->items()->firstOrFail();
        $this->assertSame($preSalePriceType->id, (int) $item->price_type_id);
        $this->assertSame(75.0, (float) $item->unit_price);
        $this->assertSame(150.0, (float) $item->total);
    }

    public function test_pre_sale_branch_pricing_uses_active_visit_branch(): void
    {
        [$business, , $branchA] = $this->tenant(role: 'owner');
        TenantSetting::query()->where('business_id', $business->id)->update([
            'pricing_scope' => 'branch',
        ]);
        $branchB = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
        ]);
        $seller = $this->user($business, $branchA, 'pre_seller');
        $visit = $this->startedVisit($business, $branchA, $seller);
        $product = $this->product($business, $branchA, stock: 8, salePrice: 100);
        $priceType = PriceType::query()->where('business_id', $business->id)->where('is_default', true)->firstOrFail();

        BranchProductPrice::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branchA->id,
            'product_id' => $product->id,
            'price_type_id' => $priceType->id,
            'price' => 120,
            'is_active' => true,
        ]);
        BranchProductPrice::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branchB->id,
            'product_id' => $product->id,
            'price_type_id' => $priceType->id,
            'price' => 150,
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->getJson(route('routes.mobile.visits.products.search', [$visit, 'q' => $product->code]))
            ->assertOk()
            ->assertJsonPath('products.0.sale_price', 120);

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertSessionHasNoErrors();

        $item = PreSale::query()->where('route_visit_id', $visit->id)->firstOrFail()->items()->firstOrFail();
        $this->assertSame(120.0, (float) $item->unit_price);
        $this->assertSame(120.0, (float) $item->original_price);
    }

    public function test_pre_sale_manual_price_requires_global_and_pre_sale_setting(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $visit = $this->startedVisit($business, $branch, $seller);
        $product = $this->product($business, $branch, stock: 8, salePrice: 100);

        $this->actingAs($seller)
            ->get(route('routes.mobile.visits.show', $visit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/Mobile/Visit')
                ->where('allowManualPrice', false));

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 80,
                    'manual_price' => true,
                ]],
            ])
            ->assertSessionHasErrors('items');

        TenantSetting::query()->where('business_id', $business->id)->update([
            'allow_manual_price' => true,
            'pre_sale_allow_manual_price' => true,
            'manual_price_min_margin_percent' => 20,
        ]);

        $this->actingAs($seller)
            ->get(route('routes.mobile.visits.show', $visit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/Mobile/Visit')
                ->where('allowManualPrice', true));

        $this->actingAs($seller)
            ->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 70,
                    'manual_price' => true,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $item = PreSale::query()->where('route_visit_id', $visit->id)->firstOrFail()->items()->firstOrFail();
        $this->assertTrue((bool) $item->manual_price);
        $this->assertSame(70.0, (float) $item->unit_price);
        $this->assertSame(100.0, (float) $item->original_price);
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
        $this->actingAs($seller)->post(route('routes.mobile.work-days.close', $visit->workDay))->assertRedirect();

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.index'))
            ->assertOk()
            ->assertSee($visit->customer->name);
    }

    public function test_admin_queue_lists_submitted_pre_sales_and_excludes_other_tenants(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product, quantity: 2, customerName: 'Cliente visible');

        [$otherBusiness, , $otherBranch] = $this->tenant(role: 'owner');
        $otherSeller = $this->user($otherBusiness, $otherBranch, 'pre_seller');
        $otherProduct = $this->product($otherBusiness, $otherBranch);
        $this->submittedPreSale($otherBusiness, $otherBranch, $otherSeller, $otherProduct, customerName: 'Cliente oculto');

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Index')
                ->where('preSales.data.0.id', $preSale->id)
                ->where('preSales.data.0.items_count', 1)
                ->where('preSales.data.0.reserved_quantity_total', '2.0000')
                ->where('preSales.total', 1));
    }

    public function test_admin_queue_filters_by_branch_zone_seller_status_customer_and_product(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal filtro',
            'code' => 'FILTRO',
            'is_active' => true,
        ]);
        $sellerA = $this->user($business, $branch, 'pre_seller');
        $sellerB = $this->user($business, $otherBranch, 'pre_seller');
        $productA = $this->product($business, $branch, stock: 10);
        $productB = $this->product($business, $otherBranch, stock: 10);
        $target = $this->submittedPreSale($business, $branch, $sellerA, $productA, customerName: 'Cliente filtro');
        $this->submittedPreSale($business, $otherBranch, $sellerB, $productB, customerName: 'Cliente otro');

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.index', [
                'branch_id' => $branch->id,
                'zone_id' => $target->route_zone_id,
                'seller_id' => $sellerA->id,
                'status' => 'submitted',
                'customer' => 'filtro',
                'product_search' => $productA->code,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preSales.total', 1)
                ->where('preSales.data.0.id', $target->id));
    }

    public function test_pre_sale_detail_shows_products_and_stock_reservation_info(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product, quantity: 3);

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.show', $preSale))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Show')
                ->where('preSale.id', $preSale->id)
                ->where('preSale.items.0.product_name', $product->name)
                ->where('preSale.items.0.quantity', 3)
                ->where('preSale.items.0.reserved_quantity', 3)
                ->where('preSale.items.0.physical_stock', 10)
                ->where('preSale.items.0.reserved_total', 3)
                ->where('preSale.items.0.available_stock', 7));
    }

    public function test_admin_can_mark_submitted_pre_sale_as_processing_without_mutating_stock_or_financial_rows(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product, quantity: 4);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.processing', $preSale))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Preventa marcada en preparación.');

        $preSale->refresh();
        $this->assertSame('processing', $preSale->status);
        $this->assertNotNull($preSale->processing_started_at);
        $this->assertSame($admin->id, $preSale->processing_user_id);
        $this->assertSame(1, StockReservation::query()->where('business_id', $business->id)->where('status', 'active')->count());
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(0, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, ElectronicDocument::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerCreditAccount::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerAccountMovement::query()->where('business_id', $business->id)->count());
    }

    public function test_admin_can_open_picking_form_for_submitted_pre_sale(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product, quantity: 3);

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.pick', $preSale))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Pick')
                ->where('preSale.id', $preSale->id)
                ->where('preSale.items.0.quantity', 3)
                ->where('preSale.items.0.reserved_quantity', 3)
                ->where('preSale.items.0.picked_quantity', 3));
    }

    public function test_admin_can_pick_submitted_pre_sale_and_reduce_reservation_without_stock_or_financial_mutations(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product, quantity: 4);
        $item = $preSale->items()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.pick.store', $preSale), [
                'items' => [[
                    'id' => $item->id,
                    'picked_quantity' => 2,
                    'picking_note' => 'Solo dos disponibles en bodega.',
                ]],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Preventa lista para facturar.');

        $preSale->refresh();
        $item->refresh();
        $this->assertSame(PreSale::STATUS_PICKED, $preSale->status);
        $this->assertNotNull($preSale->picked_at);
        $this->assertSame($admin->id, $preSale->picked_by);
        $this->assertSame(2.0, (float) $item->picked_quantity);
        $this->assertSame('Solo dos disponibles en bodega.', $item->picking_note);
        $this->assertSame(2.0, (float) StockReservation::query()->where('source_item_id', $item->id)->where('status', 'active')->sum('quantity'));
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, ElectronicDocument::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerCreditAccount::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerAccountMovement::query()->where('business_id', $business->id)->count());

        $breakdown = StockAvailability::getBreakdownForProducts($business->id, $branch->id, [$product->id])->get($product->id);
        $this->assertSame(2.0, $breakdown['reserved_pre_sales']);
        $this->assertSame(8.0, $breakdown['available_stock']);
    }

    public function test_admin_can_pick_processing_pre_sale(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product, quantity: 2);
        $item = $preSale->items()->firstOrFail();

        $this->actingAs($admin)->post(route('routes.pre-sales.processing', $preSale))->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('routes.pre-sales.pick', $preSale))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routes/PreSales/Pick')
                ->where('preSale.status', PreSale::STATUS_PROCESSING));

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.pick.store', $preSale), [
                'items' => [['id' => $item->id, 'picked_quantity' => 2]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(PreSale::STATUS_PICKED, $preSale->refresh()->status);
    }

    public function test_picking_zero_for_one_line_releases_that_line_but_requires_another_prepared_line(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $firstProduct = $this->product($business, $branch, stock: 10);
        $secondProduct = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [
                ['product_id' => $firstProduct->id, 'quantity' => 2],
                ['product_id' => $secondProduct->id, 'quantity' => 3],
            ],
        ])->assertSessionHasNoErrors();
        $this->actingAs($seller)->post(route('routes.mobile.work-days.close', $visit->workDay))->assertRedirect();

        $preSale = PreSale::query()->where('route_visit_id', $visit->id)->firstOrFail();
        $items = $preSale->items()->orderBy('id')->get();

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.pick.store', $preSale), [
                'items' => [
                    ['id' => $items[0]->id, 'picked_quantity' => 0],
                    ['id' => $items[1]->id, 'picked_quantity' => 1],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, (float) StockReservation::query()->where('source_item_id', $items[0]->id)->where('status', 'active')->sum('quantity'));
        $this->assertSame(1.0, (float) StockReservation::query()->where('source_item_id', $items[1]->id)->where('status', 'active')->sum('quantity'));
        $this->assertSame(1, StockReservation::query()->where('source_item_id', $items[0]->id)->where('status', 'released')->count());
    }

    public function test_picking_rejects_empty_or_excess_quantities(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product, quantity: 3);
        $item = $preSale->items()->firstOrFail();

        $this->actingAs($admin)
            ->from(route('routes.pre-sales.pick', $preSale))
            ->post(route('routes.pre-sales.pick.store', $preSale), [
                'items' => [['id' => $item->id, 'picked_quantity' => 0]],
            ])
            ->assertRedirect(route('routes.pre-sales.pick', $preSale))
            ->assertSessionHasErrors(['items' => 'No puedes marcar como listo un pedido sin productos preparados. Cancela la preventa si no se preparará.']);

        $this->actingAs($admin)
            ->from(route('routes.pre-sales.pick', $preSale))
            ->post(route('routes.pre-sales.pick.store', $preSale), [
                'items' => [['id' => $item->id, 'picked_quantity' => 4]],
            ])
            ->assertRedirect(route('routes.pre-sales.pick', $preSale))
            ->assertSessionHasErrors('items');

        StockReservation::query()
            ->where('source_item_id', $item->id)
            ->where('status', 'active')
            ->update(['quantity' => 1]);

        $this->actingAs($admin)
            ->from(route('routes.pre-sales.pick', $preSale))
            ->post(route('routes.pre-sales.pick.store', $preSale), [
                'items' => [['id' => $item->id, 'picked_quantity' => 2]],
            ])
            ->assertRedirect(route('routes.pre-sales.pick', $preSale))
            ->assertSessionHasErrors('items');

        $this->assertSame(PreSale::STATUS_SUBMITTED, $preSale->refresh()->status);
    }

    public function test_cancelled_pre_sale_cannot_be_picked(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product, quantity: 2);
        $item = $preSale->items()->firstOrFail();

        $this->actingAs($admin)->post(route('routes.pre-sales.cancel', $preSale), [
            'cancellation_reason' => 'Otro',
            'cancellation_note' => 'Cliente ya no quiere el pedido.',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->from(route('routes.pre-sales.show', $preSale))
            ->get(route('routes.pre-sales.pick', $preSale))
            ->assertRedirect(route('routes.pre-sales.show', $preSale))
            ->assertSessionHasErrors('pre_sale');

        $this->actingAs($admin)
            ->from(route('routes.pre-sales.show', $preSale))
            ->post(route('routes.pre-sales.pick.store', $preSale), [
                'items' => [['id' => $item->id, 'picked_quantity' => 1]],
            ])
            ->assertRedirect(route('routes.pre-sales.show', $preSale))
            ->assertSessionHasErrors('pre_sale');

        $this->assertSame(PreSale::STATUS_CANCELLED, $preSale->refresh()->status);
    }

    public function test_picked_pre_sale_cannot_be_cancelled_or_edited_by_mobile_seller(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product, quantity: 2);
        $item = $preSale->items()->firstOrFail();

        $this->actingAs($admin)->post(route('routes.pre-sales.pick.store', $preSale), [
            'items' => [['id' => $item->id, 'picked_quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->from(route('routes.pre-sales.show', $preSale))
            ->post(route('routes.pre-sales.cancel', $preSale), [
                'cancellation_reason' => 'Otro',
                'cancellation_note' => 'No se debe cancelar.',
            ])
            ->assertRedirect(route('routes.pre-sales.show', $preSale))
            ->assertSessionHasErrors(['pre_sale' => 'Esta preventa ya está lista para facturar y no se puede cancelar desde esta fase.']);

        $this->actingAs($seller)
            ->from(route('routes.mobile.visits.show', $preSale->visit))
            ->post(route('routes.mobile.visits.pre-sale.store', $preSale->visit), [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('routes.mobile.visits.show', $preSale->visit))
            ->assertSessionHasErrors('pre_sale');

        $this->assertSame(PreSale::STATUS_PICKED, $preSale->refresh()->status);
    }

    public function test_pre_seller_and_other_tenant_cannot_pick_pre_sales(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product);
        $item = $preSale->items()->firstOrFail();

        [$otherBusiness, $otherAdmin] = $this->tenant(role: 'owner');

        $this->actingAs($seller)->get(route('routes.pre-sales.pick', $preSale))->assertForbidden();
        $this->actingAs($seller)->post(route('routes.pre-sales.pick.store', $preSale), [
            'items' => [['id' => $item->id, 'picked_quantity' => 1]],
        ])->assertForbidden();

        $this->actingAs($otherAdmin)->get(route('routes.pre-sales.pick', $preSale))->assertForbidden();
        $this->actingAs($otherAdmin)->post(route('routes.pre-sales.pick.store', $preSale), [
            'items' => [['id' => $item->id, 'picked_quantity' => 1]],
        ])->assertForbidden();

        $this->assertSame($business->id, $preSale->business_id);
        $this->assertNotSame($otherBusiness->id, $preSale->business_id);
        $this->assertSame(PreSale::STATUS_SUBMITTED, $preSale->refresh()->status);
    }

    public function test_admin_can_cancel_submitted_pre_sale_with_reason_and_release_reservations_without_stock_or_financial_mutations(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product, quantity: 4);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.cancel', $preSale), [
                'cancellation_reason' => 'Cliente canceló',
                'cancellation_note' => 'Cliente pidió anular el pedido.',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Preventa cancelada y reserva liberada.');

        $preSale->refresh();
        $this->assertSame('cancelled', $preSale->status);
        $this->assertSame($admin->id, $preSale->cancelled_by);
        $this->assertSame('Cliente canceló', $preSale->cancellation_reason);
        $this->assertSame('Cliente pidió anular el pedido.', $preSale->cancellation_note);
        $this->assertSame(0, StockReservation::query()->where('business_id', $business->id)->where('status', 'active')->count());
        $this->assertSame(1, StockReservation::query()->where('business_id', $business->id)->where('status', 'released')->count());
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(0, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, ElectronicDocument::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CashMovement::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerCreditAccount::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CustomerAccountMovement::query()->where('business_id', $business->id)->count());
    }

    public function test_pre_seller_cannot_access_admin_pre_sale_queue_or_actions(): void
    {
        [$business, , $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product);

        $this->actingAs($seller)->get(route('routes.pre-sales.index'))->assertForbidden();
        $this->actingAs($seller)->get(route('routes.pre-sales.show', $preSale))->assertForbidden();
        $this->actingAs($seller)->post(route('routes.pre-sales.processing', $preSale))->assertForbidden();
    }

    public function test_processing_pre_sale_cannot_be_edited_by_mobile_pre_seller(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch);
        $preSale = $this->submittedPreSale($business, $branch, $seller, $product);

        $this->actingAs($admin)->post(route('routes.pre-sales.processing', $preSale))->assertSessionHasNoErrors();

        $this->actingAs($seller)
            ->from(route('routes.mobile.visits.show', $preSale->visit))
            ->post(route('routes.mobile.visits.pre-sale.store', $preSale->visit), [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('routes.mobile.visits.show', $preSale->visit))
            ->assertSessionHasErrors('pre_sale');

        $this->assertSame('processing', $preSale->refresh()->status);
    }

    public function test_stock_breakdown_counts_pre_sales_and_credit_reservations_when_enabled(): void
    {
        [$business, $seller, $branch] = $this->tenant(role: 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        TenantSetting::query()->where('business_id', $business->id)->update([
            'enable_credit_reservations' => true,
            'reserve_stock_on_credit_reservations' => true,
        ]);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $this->creditReservation($business, $branch, $product, 1);

        $breakdown = StockAvailability::getBreakdownForProducts($business->id, $branch->id, [$product->id])->get($product->id);

        $this->assertSame(10.0, $breakdown['physical_stock']);
        $this->assertSame(4.0, $breakdown['reserved_pre_sales']);
        $this->assertSame(1.0, $breakdown['reserved_credit_reservations']);
        $this->assertSame(5.0, $breakdown['reserved_total']);
        $this->assertSame(5.0, $breakdown['available_stock']);
    }

    public function test_reservation_explain_shows_three_pre_sales_can_reserve_four_units(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);

        foreach ([1, 1, 2] as $index => $quantity) {
            $visit = $this->startedVisit($business, $branch, $seller, 'Cliente '.($index + 1));
            $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
                'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            ])->assertSessionHasNoErrors();
        }

        $response = $this->actingAs($admin)->getJson(route('inventory.products.reservations', $product));

        $response->assertOk()
            ->assertJsonPath('reserved_pre_sales', 4)
            ->assertJsonPath('reserved_total', 4)
            ->assertJsonCount(3, 'pre_sale_reservations')
            ->assertJsonPath('orphan_or_ignored_reservations', []);

        $this->assertSame(4.0, (float) collect($response->json('pre_sale_reservations'))->sum('quantity'));
    }

    public function test_cancelled_pre_sale_active_reservation_is_excluded_and_reported_as_ignored(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $product = $this->product($business, $branch, stock: 10);
        $preSale = $this->manualPreSaleReservation($business, $branch, $admin, $product, 3);
        $preSale->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $breakdown = StockAvailability::getBreakdownForProducts($business->id, $branch->id, [$product->id])->get($product->id);

        $this->assertSame(0.0, $breakdown['reserved_pre_sales']);
        $this->assertSame(10.0, $breakdown['available_stock']);

        $this->actingAs($admin)
            ->getJson(route('inventory.products.reservations', $product))
            ->assertOk()
            ->assertJsonCount(0, 'pre_sale_reservations')
            ->assertJsonPath('orphan_or_ignored_reservations.0.reason', 'invalid_pre_sale_status');
    }

    public function test_missing_pre_sale_item_active_reservation_is_excluded_and_reported_as_ignored(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $product = $this->product($business, $branch, stock: 10);
        $preSale = $this->manualPreSaleReservation($business, $branch, $admin, $product, 3);
        $preSale->items()->delete();

        $breakdown = StockAvailability::getBreakdownForProducts($business->id, $branch->id, [$product->id])->get($product->id);

        $this->assertSame(0.0, $breakdown['reserved_pre_sales']);
        $this->assertSame(10.0, $breakdown['available_stock']);

        $this->actingAs($admin)
            ->getJson(route('inventory.products.reservations', $product))
            ->assertOk()
            ->assertJsonCount(0, 'pre_sale_reservations')
            ->assertJsonPath('orphan_or_ignored_reservations.0.reason', 'missing_pre_sale_item');
    }

    public function test_without_sale_visit_active_reservation_is_excluded_from_breakdown(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $visit->update(['status' => 'without_sale']);

        $breakdown = StockAvailability::getBreakdownForProducts($business->id, $branch->id, [$product->id])->get($product->id);

        $this->assertSame(0.0, $breakdown['reserved_pre_sales']);

        $this->actingAs($admin)
            ->getJson(route('inventory.products.reservations', $product))
            ->assertOk()
            ->assertJsonPath('orphan_or_ignored_reservations.0.reason', 'visit_without_sale');
    }

    public function test_stock_breakdown_excludes_credit_reservations_when_setting_is_disabled(): void
    {
        [$business, $seller, $branch] = $this->tenant(role: 'pre_seller');
        TenantSetting::query()->where('business_id', $business->id)->update([
            'reserve_stock_on_credit_reservations' => false,
        ]);
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $this->creditReservation($business, $branch, $product, 1);

        $breakdown = StockAvailability::getBreakdownForProducts($business->id, $branch->id, [$product->id])->get($product->id);

        $this->assertSame(4.0, $breakdown['reserved_pre_sales']);
        $this->assertSame(0.0, $breakdown['reserved_credit_reservations']);
        $this->assertSame(4.0, $breakdown['reserved_total']);
        $this->assertSame(6.0, $breakdown['available_stock']);
    }

    public function test_cancelled_draft_pre_sale_releases_reserved_stock(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        $seller = $this->user($business, $branch, 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $preSale = PreSale::query()->where('route_visit_id', $visit->id)->firstOrFail();

        $this->actingAs($admin)->post(route('routes.pre-sales.cancel', $preSale))
            ->assertSessionHasNoErrors();

        $breakdown = StockAvailability::getBreakdownForProducts($business->id, $branch->id, [$product->id])->get($product->id);

        $this->assertSame(0.0, $breakdown['reserved_pre_sales']);
        $this->assertSame(10.0, $breakdown['available_stock']);
    }

    public function test_submitted_pre_sale_still_counts_as_reserved_stock(): void
    {
        [$business, $seller, $branch] = $this->tenant(role: 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);
        $visit = $this->startedVisit($business, $branch, $seller);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($seller)->post(route('routes.mobile.work-days.close', $visit->workDay))
            ->assertRedirect();

        $breakdown = StockAvailability::getBreakdownForProducts($business->id, $branch->id, [$product->id])->get($product->id);

        $this->assertSame(4.0, $breakdown['reserved_pre_sales']);
        $this->assertSame(6.0, $breakdown['available_stock']);
    }

    public function test_pre_sale_save_revalidates_current_reserved_stock_before_saving(): void
    {
        [$business, $seller, $branch] = $this->tenant(role: 'pre_seller');
        $product = $this->product($business, $branch, stock: 5);
        $firstVisit = $this->startedVisit($business, $branch, $seller, 'Cliente A');
        $secondVisit = $this->startedVisit($business, $branch, $seller, 'Cliente B');

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $firstVisit), [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($seller)
            ->from(route('routes.mobile.visits.show', $secondVisit))
            ->post(route('routes.mobile.visits.pre-sale.store', $secondVisit), [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('routes.mobile.visits.show', $secondVisit))
            ->assertSessionHasErrors(['items' => 'No hay suficiente stock disponible.']);

        $this->assertSame(1, PreSale::query()->where('business_id', $business->id)->count());
    }

    public function test_stock_breakdown_is_branch_and_tenant_scoped(): void
    {
        [$business, $admin, $branch] = $this->tenant(role: 'owner');
        [$otherBusiness, $otherSeller, $otherBranch] = $this->tenant(role: 'pre_seller');
        $product = $this->product($business, $branch, stock: 10);

        StockReservation::query()->create([
            'business_id' => $otherBusiness->id,
            'branch_id' => $otherBranch->id,
            'product_id' => $product->id,
            'source_type' => 'manual_test',
            'source_id' => 1,
            'source_item_id' => null,
            'quantity' => 9,
            'status' => 'active',
            'created_by' => $otherSeller->id,
        ]);

        ProductBranchStock::query()->updateOrCreate(
            ['business_id' => $business->id, 'branch_id' => $otherBranch->id, 'product_id' => $product->id],
            ['stock' => 99],
        );

        $breakdown = StockAvailability::getBreakdownForProducts($business->id, $branch->id, [$product->id])->get($product->id);

        $this->assertSame(10.0, $breakdown['physical_stock']);
        $this->assertSame(0.0, $breakdown['reserved_total']);
        $this->assertSame(10.0, $breakdown['available_stock']);

        $this->actingAs($admin)
            ->getJson(route('inventory.products.reservations', $product))
            ->assertOk()
            ->assertJsonPath('reserved_total', 0)
            ->assertJsonPath('pre_sale_reservations', [])
            ->assertJsonPath('orphan_or_ignored_reservations', []);
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

        foreach (['routes', 'inventory', 'branches', 'credits'] as $module) {
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

    private function manualPreSaleReservation(Business $business, Branch $branch, User $seller, Product $product, float $quantity): PreSale
    {
        $customer = $this->customer($business, 'Cliente reserva '.uniqid());

        $preSale = PreSale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'seller_id' => $seller->id,
            'status' => 'draft',
            'subtotal' => $quantity * 100,
            'discount_total' => 0,
            'total' => $quantity * 100,
        ]);

        $item = PreSaleItem::query()->create([
            'business_id' => $business->id,
            'pre_sale_id' => $preSale->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => 100,
            'discount' => 0,
            'total' => $quantity * 100,
        ]);

        StockReservation::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'source_type' => 'pre_sale',
            'source_id' => $preSale->id,
            'source_item_id' => $item->id,
            'quantity' => $quantity,
            'status' => 'active',
            'created_by' => $seller->id,
        ]);

        return $preSale;
    }

    private function submittedPreSale(
        Business $business,
        Branch $branch,
        User $seller,
        Product $product,
        float $quantity = 1,
        string $customerName = 'Cliente enviado',
    ): PreSale {
        $visit = $this->startedVisit($business, $branch, $seller, $customerName);

        $this->actingAs($seller)->post(route('routes.mobile.visits.pre-sale.store', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($seller)->post(route('routes.mobile.work-days.close', $visit->workDay))
            ->assertRedirect();

        return PreSale::query()
            ->where('business_id', $business->id)
            ->where('route_visit_id', $visit->id)
            ->firstOrFail();
    }

    private function creditReservation(Business $business, Branch $branch, Product $product, int $quantity): CreditReceiptLine
    {
        $customer = $this->customer($business, 'Cliente crédito '.uniqid());

        $receipt = CreditReceipt::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_doc_type' => $customer->doc_type,
            'customer_doc_number' => $customer->doc_number,
            'receipt_number' => random_int(1000, 9999),
            'status' => 'pending',
            'subtotal' => $quantity * 100,
            'discount_amount' => 0,
            'total' => $quantity * 100,
            'pending_total' => $quantity * 100,
        ]);

        return CreditReceiptLine::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'credit_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->code,
            'quantity' => $quantity,
            'qty_reserved' => $quantity,
            'qty_invoiced' => 0,
            'qty_cancelled' => 0,
            'qty_pending' => $quantity,
            'unit_price' => 100,
            'discount_amount' => 0,
            'line_total' => $quantity * 100,
            'pending_total' => $quantity * 100,
            'status' => 'pending',
        ]);
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
