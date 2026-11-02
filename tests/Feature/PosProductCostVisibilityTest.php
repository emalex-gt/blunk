<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Branch;
use App\Models\Product;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosProductCostVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_pos_view_user_does_not_receive_cost_price_in_any_search_result_collection(): void
    {
        [$business, $user, $product] = $this->fixture([Permissions::POS_VIEW]);
        [, , $otherTenantProduct] = $this->fixture([Permissions::POS_VIEW], $product->code, $product->barcode);

        $response = $this->as($user, $business)
            ->get(route('sales.products.search', ['q' => $product->code]))
            ->assertOk();

        foreach (['products', 'exact_barcode_matches', 'exact_code_matches'] as $collection) {
            $matches = $response->json($collection);

            $this->assertNotEmpty($matches, "{$collection} must include the tenant product.");
            $this->assertContains($product->id, array_column($matches, 'id'));
            $this->assertNotContains($otherTenantProduct->id, array_column($matches, 'id'));

            foreach ($matches as $match) {
                $this->assertArrayNotHasKey('cost_price', $match);
            }
        }

        $productPayload = collect($response->json('products'))->firstWhere('id', $product->id);
        $this->assertSame('30', $productPayload['sale_price']);
        $this->assertSame(4.0, $productPayload['physical_stock']);
    }

    public function test_products_create_permission_receives_cost_price_in_pos_search(): void
    {
        $this->assertCostVisibleFor(Permissions::PRODUCTS_CREATE);
    }

    public function test_products_update_permission_receives_cost_price_in_pos_search(): void
    {
        $this->assertCostVisibleFor(Permissions::PRODUCTS_UPDATE);
    }

    public function test_profit_report_permission_receives_cost_price_in_pos_search(): void
    {
        $this->assertCostVisibleFor(Permissions::REPORTS_PROFIT_VIEW);
    }

    public function test_user_without_pos_view_cannot_search_products_even_with_financial_permission(): void
    {
        [$business, $user, $product] = $this->fixture([Permissions::REPORTS_PROFIT_VIEW]);

        $this->as($user, $business)
            ->get(route('sales.products.search', ['q' => $product->code]))
            ->assertForbidden();
    }

    public function test_cost_redaction_preserves_the_active_branch_stock_scope(): void
    {
        [$business, $user, $product] = $this->fixture([Permissions::POS_VIEW]);
        TenantModule::query()->create([
            'business_id' => $business->id,
            'module' => 'branches',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);
        TenantSetting::query()->updateOrCreate(
            ['business_id' => $business->id],
            ['use_branches' => true, 'products_shared_across_branches' => true],
        );
        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal POS secundaria',
            'code' => 'POS-2',
            'is_active' => true,
        ]);
        BranchInventory::adjust($product, $otherBranch->id, 9);

        $payload = $this->as($user, $business)
            ->withSession(['active_branch_id' => $otherBranch->id])
            ->get(route('sales.products.search', ['q' => $product->code]))
            ->assertOk()
            ->json('products.0');

        $this->assertSame(9.0, $payload['physical_stock']);
        $this->assertArrayNotHasKey('cost_price', $payload);
    }

    private function assertCostVisibleFor(string $financialPermission): void
    {
        [$business, $user, $product] = $this->fixture([Permissions::POS_VIEW, $financialPermission]);

        $response = $this->as($user, $business)
            ->get(route('sales.products.search', ['q' => $product->code]))
            ->assertOk();

        foreach (['products', 'exact_barcode_matches', 'exact_code_matches'] as $collection) {
            $match = collect($response->json($collection))->firstWhere('id', $product->id);

            $this->assertNotNull($match, "{$collection} must include the tenant product.");
            $this->assertSame('12.50000', $match['cost_price']);
        }
    }

    private function fixture(array $permissions, ?string $code = null, ?string $barcode = null): array
    {
        $business = Business::query()->create([
            'name' => uniqid('POS Costs '),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        TenantModule::query()->create([
            'business_id' => $business->id,
            'module' => 'pos',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $user = User::factory()->create([
            'business_id' => $business->id,
            'is_super_admin' => false,
            'is_active' => true,
            'all_branches_access' => true,
        ]);
        Permissions::assignDirectPermissions($user, $permissions);

        $productCode = $code ?? 'POS-COST-'.uniqid();

        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Producto POS '.uniqid(),
            'code' => $productCode,
            'barcode' => $barcode ?? $productCode,
            'cost_price' => '12.50000',
            'sale_price' => '30.00',
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        BranchInventory::adjust($product, BranchInventory::defaultBranch($business->id)->id, 4);

        return [$business, $user, $product];
    }

    private function as(User $user, Business $business): static
    {
        return $this->actingAs($user)->withSession([
            'active_business_id' => $business->id,
            'current_business_id' => $business->id,
        ]);
    }
}
