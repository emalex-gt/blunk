<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\GuatemalaLocations;
use App\Support\GuatemalaPostalCodes;
use Database\Seeders\GuatemalaLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuatemalaLocationsAndBranchDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guatemala_location_seeder_is_idempotent(): void
    {
        $this->seed(GuatemalaLocationSeeder::class);
        $this->seed(GuatemalaLocationSeeder::class);

        $this->assertSame(22, DB::table('guatemala_departments')->count());
        $this->assertGreaterThan(300, DB::table('guatemala_municipalities')->count());
        $this->assertSame(1, DB::table('guatemala_departments')->where('name', 'Guatemala')->count());
        $this->assertSame(1, DB::table('guatemala_municipalities')
            ->join('guatemala_departments', 'guatemala_departments.id', '=', 'guatemala_municipalities.guatemala_department_id')
            ->where('guatemala_departments.name', 'Guatemala')
            ->where('guatemala_municipalities.name', 'Mixco')
            ->count());
        $this->assertSame('01057', $this->postalCode('Guatemala', 'Mixco'));
        $this->assertSame('14006', $this->postalCode('El Quiche', 'Chichicastenango'));
        $this->assertNull($this->postalCode('El Quiche', 'Chiché'));
        $this->assertNull($this->postalCode('Guatemala', 'Guatemala'));
        $this->assertSame(1, DB::table('guatemala_departments')->where('name', 'El Quiche')->count());
        $this->assertSame(0, DB::table('guatemala_departments')->whereIn('name', ['Quiché', 'Quiche'])->count());
    }

    public function test_postal_catalog_has_explicit_coverage_and_accepts_historical_el_quiche_names(): void
    {
        $total = array_sum(array_map('count', GuatemalaLocations::municipalitiesByDepartment()));
        $confirmed = array_sum(array_map('count', GuatemalaPostalCodes::confirmed()));
        $unresolved = array_sum(array_map('count', GuatemalaPostalCodes::unresolved()));

        $this->assertSame(337, $total);
        $this->assertSame(322, $confirmed);
        $this->assertSame(15, $unresolved);

        foreach ([GuatemalaPostalCodes::confirmed(), GuatemalaPostalCodes::unresolved()] as $coverage) {
            foreach ($coverage as $department => $municipalities) {
                $this->assertArrayHasKey($department, GuatemalaLocations::municipalitiesByDepartment());

                foreach (array_keys($municipalities) as $municipality) {
                    $this->assertContains($municipality, GuatemalaLocations::municipalitiesByDepartment()[$department]);
                }
            }
        }

        $this->assertTrue(GuatemalaLocations::isValidDepartment('El Quiche'));
        $this->assertTrue(GuatemalaLocations::isValidDepartment('Quiché'));
        $this->assertTrue(GuatemalaLocations::isValidDepartment('Quiche'));
        $this->assertTrue(GuatemalaLocations::isValidMunicipality('Quiché', 'Chichicastenango'));
        $this->assertSame('14006', GuatemalaPostalCodes::confirmed()['El Quiche']['Chichicastenango']);
    }

    public function test_super_admin_branch_form_accepts_department_and_municipality(): void
    {
        $business = $this->business();
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.tenants.branches.store', $business), [
                'name' => 'Sucursal Centro',
                'code' => 'CENTRO',
                'address' => 'Zona 1',
                'department' => 'Guatemala',
                'municipality' => 'Mixco',
                'phone' => '5555-0000',
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('branches', [
            'business_id' => $business->id,
            'code' => 'CENTRO',
            'department' => 'Guatemala',
            'municipality' => 'Mixco',
        ]);
    }

    public function test_super_admin_branch_form_rejects_invalid_gt_municipality(): void
    {
        $business = $this->business();
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.tenants.branches.store', $business), [
                'name' => 'Sucursal Norte',
                'code' => 'NORTE',
                'department' => 'Guatemala',
                'municipality' => 'Huehuetenango',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('municipality');
    }

    public function test_non_gt_branch_form_allows_free_text_location(): void
    {
        $business = $this->business('AR');
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.tenants.branches.store', $business), [
                'name' => 'Sucursal Sur',
                'code' => 'SUR',
                'department' => 'Buenos Aires',
                'municipality' => 'CABA',
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('branches', [
            'business_id' => $business->id,
            'department' => 'Buenos Aires',
            'municipality' => 'CABA',
        ]);
    }

    private function business(string $country = 'GT'): Business
    {
        $business = Business::query()->create([
            'name' => 'Location Test',
            'slug' => 'location-test-'.uniqid(),
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
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);

        TenantModule::query()->create([
            'business_id' => $business->id,
            'module' => 'branches',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        return $business;
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
    }

    private function postalCode(string $department, string $municipality): ?string
    {
        return DB::table('guatemala_municipalities')
            ->join('guatemala_departments', 'guatemala_departments.id', '=', 'guatemala_municipalities.guatemala_department_id')
            ->where('guatemala_departments.name', $department)
            ->where('guatemala_municipalities.name', $municipality)
            ->value('guatemala_municipalities.postal_code');
    }
}
