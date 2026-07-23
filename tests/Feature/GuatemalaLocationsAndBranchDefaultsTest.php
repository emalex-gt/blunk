<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
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
}
