<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Purchase;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasePrecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_purchase_persists_five_decimal_costs_and_average_cost(): void
    {
        [$business, $user, $product] = $this->context();

        $this->actingAs($user)->post(route('purchases.store'), $this->payload($product, '0.33333', 3))->assertRedirect(route('purchases.index'));

        $purchase = Purchase::query()->with('items')->sole();
        $item = $purchase->items->sole();
        $this->assertSame('0.33333', $item->unit_cost);
        $this->assertSame('0.99999', $item->total);
        $this->assertSame('0.99999', $purchase->total);
        $this->assertSame('0.00', $purchase->transport_cost);
        $this->assertSame('0.00000', $item->previous_cost);
        $this->assertSame('0.33333', $item->new_average_cost);
        $this->assertSame('0.33333', $product->fresh()->cost_price);
        $this->assertSame($business->id, $purchase->business_id);
    }

    public function test_purchase_average_cost_preserves_five_decimal_precision(): void
    {
        [, $user, $product] = $this->context(stock: 2, cost: '1.11111');

        $this->actingAs($user)->post(route('purchases.store'), $this->payload($product, '2.22222', 3))->assertRedirect(route('purchases.index'));

        $item = Purchase::query()->sole()->items()->sole();
        $this->assertSame('1.11111', $item->previous_cost);
        $this->assertSame('1.77778', $item->new_average_cost);
        $this->assertSame('1.77778', $product->fresh()->cost_price);
    }

    public function test_purchase_rejects_a_unit_cost_with_more_than_five_decimals(): void
    {
        [, $user, $product] = $this->context();

        $this->actingAs($user)
            ->post(route('purchases.store'), $this->payload($product, '0.333333'))
            ->assertSessionHasErrors('items.0.unit_cost');

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_cash_movement_rounds_a_five_decimal_purchase_total_only_at_the_cash_boundary(): void
    {
        [$business, $user, $product] = $this->context();
        $branch = BranchInventory::defaultBranch($business->id);
        CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'opened_at' => now(),
        ]);

        $payload = $this->payload($product, '0.33333', 3);
        $payload['payment_method'] = 'cash';
        $payload['paid_from_cash'] = true;
        $this->actingAs($user)->post(route('purchases.store'), $payload)->assertRedirect(route('purchases.index'));

        $this->assertSame('0.99999', Purchase::query()->sole()->total);
        $this->assertSame('-1.00', CashMovement::query()->sole()->amount);
    }

    public function test_transport_is_added_to_purchase_total_without_changing_stock_or_unit_cost_average(): void
    {
        [, $user, $product] = $this->context(stock: 0, cost: '0.00000');
        $payload = $this->payload($product, '10.00000', 245);
        $payload['transport_cost'] = '150.00';

        $this->actingAs($user)->post(route('purchases.store'), $payload)->assertRedirect(route('purchases.index'));

        $purchase = Purchase::query()->with('items')->sole();
        $this->assertSame('2450.00000', $purchase->items->sole()->total);
        $this->assertSame('150.00', $purchase->transport_cost);
        $this->assertSame('2600.00000', $purchase->total);
        $this->assertSame('10.00000', $product->fresh()->cost_price);
        $this->assertSame(245.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
    }

    public function test_cash_purchase_includes_transport_once_at_the_cash_boundary(): void
    {
        [$business, $user, $product] = $this->context();
        $branch = BranchInventory::defaultBranch($business->id);
        CashRegisterSession::query()->create([
            'business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $user->id,
            'status' => 'open', 'opening_amount' => 0, 'opened_at' => now(),
        ]);
        $payload = $this->payload($product, '10.00000', 245);
        $payload['payment_method'] = 'cash';
        $payload['paid_from_cash'] = true;
        $payload['transport_cost'] = '150.00';

        $this->actingAs($user)->post(route('purchases.store'), $payload)->assertRedirect(route('purchases.index'));

        $this->assertSame('2600.00000', Purchase::query()->sole()->total);
        $this->assertSame('-2600.00', CashMovement::query()->sole()->amount);
    }

    public function test_purchase_rejects_negative_or_more_than_two_decimal_transport_cost(): void
    {
        [, $user, $product] = $this->context();
        $negative = $this->payload($product, '10.00000');
        $negative['transport_cost'] = '-0.01';
        $this->actingAs($user)->post(route('purchases.store'), $negative)->assertSessionHasErrors('transport_cost');

        $precision = $this->payload($product, '10.00000');
        $precision['transport_cost'] = '0.001';
        $this->actingAs($user)->post(route('purchases.store'), $precision)->assertSessionHasErrors('transport_cost');

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_transport_participates_in_purchase_idempotency_without_duplicate_cash_charge(): void
    {
        [$business, $user, $product] = $this->context();
        $branch = BranchInventory::defaultBranch($business->id);
        CashRegisterSession::query()->create([
            'business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $user->id,
            'status' => 'open', 'opening_amount' => 0, 'opened_at' => now(),
        ]);
        $payload = $this->payload($product, '10.00000', 1);
        $payload['idempotency_key'] = 'purchase-transport-idempotency';
        $payload['payment_method'] = 'cash';
        $payload['paid_from_cash'] = true;
        $payload['transport_cost'] = '5.00';

        $this->actingAs($user)->post(route('purchases.store'), $payload)->assertRedirect(route('purchases.index'));
        $this->actingAs($user)->post(route('purchases.store'), $payload)->assertRedirect(route('purchases.index'));
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('cash_movements', 1);

        $payload['transport_cost'] = '6.00';
        $this->actingAs($user)->post(route('purchases.store'), $payload)->assertStatus(409);
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    /** @return array{Business, User, Product} */
    private function context(int $stock = 0, string $cost = '0.00000'): array
    {
        $business = Business::query()->create(['name' => 'Precisión '.uniqid(), 'slug' => 'precision-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => false, 'products_shared_across_branches' => true, 'pricing_scope' => 'global']);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'purchases', 'is_enabled' => true, 'enabled_at' => now()]);
        $user = User::factory()->create(['business_id' => $business->id, 'role' => 'owner', 'is_active' => true, 'all_branches_access' => true]);
        Permissions::assignRole($user, 'owner');
        $branch = BranchInventory::defaultBranch($business->id);
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Producto precisión', 'code' => 'PREC-'.uniqid(), 'cost_price' => $cost, 'sale_price' => 2, 'stock' => $stock, 'min_stock' => 0, 'is_active' => true]);
        ProductBranchStock::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => $stock,
        ]);

        return [$business, $user, $product];
    }

    private function payload(Product $product, string $cost, int $quantity = 1): array
    {
        return ['idempotency_key' => 'purchase-precision-'.uniqid(), 'payment_method' => 'card', 'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost]]];
    }
}
