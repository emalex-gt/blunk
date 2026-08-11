<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\OperationDraft;
use App\Models\Sale;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\OperationDrafts;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        Permissions::syncDefaults();
    }

    public function test_user_can_create_active_pos_draft_without_creating_sale_or_stock_effects(): void
    {
        [$business, $user, $branch] = $this->tenant();

        $response = $this->actingAs($user)->postJson(route('operation-drafts.store'), [
            'type' => 'pos_sale',
            'title' => 'Venta pausada',
            'branch_id' => $branch->id,
            'payload_version' => OperationDrafts::PAYLOAD_VERSION,
            'payload' => [
                'branch_id' => $branch->id,
                'cart' => [
                    ['product_id' => 10, 'quantity' => '1', 'unit_price' => '25.00'],
                ],
                'customer' => ['name' => 'Cliente prueba'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('draft.type', 'pos_sale')
            ->assertJsonPath('draft.status', null);

        $this->assertDatabaseHas('operation_drafts', [
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'type' => 'pos_sale',
            'status' => 'active',
        ]);
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_draft_store_requires_authentication_for_json_requests(): void
    {
        $this->postJson(route('operation-drafts.store'), [
            'type' => 'pos_sale',
            'payload' => ['cart' => []],
        ])->assertUnauthorized();
    }

    public function test_draft_store_returns_json_created_response_under_web_tenant_context(): void
    {
        [$business, $user, $branch] = $this->tenant();

        $this->actingAs($user)
            ->postJson(route('operation-drafts.store'), [
                'type' => 'pos_sale',
                'branch_id' => $branch->id,
                'payload' => ['branch_id' => $branch->id, 'cart' => []],
            ])
            ->assertCreated()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('draft.type', 'pos_sale');

        $this->assertDatabaseHas('operation_drafts', [
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'type' => 'pos_sale',
        ]);
    }

    public function test_operation_draft_routes_are_web_routes_not_api_routes(): void
    {
        $webRoutes = file_get_contents(base_path('routes/web.php'));
        $apiRoutes = file_exists(base_path('routes/api.php'))
            ? file_get_contents(base_path('routes/api.php'))
            : '';

        $this->assertStringContainsString('/operation-drafts', $webRoutes);
        $this->assertStringNotContainsString('/operation-drafts', $apiRoutes);
    }

    public function test_operation_draft_frontend_helper_uses_configured_axios_xsrf_flow(): void
    {
        $source = file_get_contents(resource_path('js/lib/operationDrafts.ts'));

        $this->assertStringContainsString("import axios from 'axios'", $source);
        $this->assertStringContainsString('axios.post', $source);
        $this->assertStringContainsString("Accept: 'application/json'", $source);
        $this->assertStringNotContainsString('fetch(', $source);
        $this->assertStringNotContainsString('meta[name="csrf-token"]', $source);
    }

    public function test_list_only_returns_active_own_drafts(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $otherUser = $this->user($business, $branch, 'cashier');

        OperationDraft::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'type' => 'pos_sale',
            'payload' => ['cart' => []],
            'status' => 'active',
        ]);
        OperationDraft::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'type' => 'pos_sale',
            'payload' => ['cart' => []],
            'status' => 'discarded',
        ]);
        OperationDraft::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'user_id' => $otherUser->id,
            'type' => 'pos_sale',
            'payload' => ['cart' => []],
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->getJson(route('operation-drafts.index', ['type' => 'pos_sale']))
            ->assertOk()
            ->assertJsonCount(1, 'drafts');
    }

    public function test_discarded_draft_disappears_from_active_list(): void
    {
        [$business, $user, $branch] = $this->tenant('purchases');
        $draft = OperationDraft::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'type' => 'purchase',
            'payload' => ['items' => [['product_id' => 1, 'quantity' => '1']]],
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson(route('operation-drafts.discard', $draft))
            ->assertOk();

        $this->assertDatabaseHas('operation_drafts', [
            'id' => $draft->id,
            'status' => 'discarded',
        ]);

        $this->actingAs($user)
            ->getJson(route('operation-drafts.index', ['type' => 'purchase']))
            ->assertOk()
            ->assertJsonCount(0, 'drafts');
    }

    public function test_tenant_isolation_prevents_listing_or_discarding_other_business_drafts(): void
    {
        [$businessA, $userA, $branchA] = $this->tenant('cashier', 'Tenant A');
        [$businessB, $userB, $branchB] = $this->tenant('cashier', 'Tenant B');

        $draftA = OperationDraft::query()->create([
            'business_id' => $businessA->id,
            'branch_id' => $branchA->id,
            'user_id' => $userA->id,
            'type' => 'pos_sale',
            'payload' => ['cart' => []],
            'status' => 'active',
        ]);
        OperationDraft::query()->create([
            'business_id' => $businessB->id,
            'branch_id' => $branchB->id,
            'user_id' => $userB->id,
            'type' => 'pos_sale',
            'payload' => ['cart' => []],
            'status' => 'active',
        ]);

        $this->actingAs($userB)
            ->getJson(route('operation-drafts.index', ['type' => 'pos_sale']))
            ->assertOk()
            ->assertJsonCount(1, 'drafts');

        $this->actingAs($userB)
            ->postJson(route('operation-drafts.discard', $draftA))
            ->assertForbidden();
    }

    private function tenant(string $role = 'cashier', string $name = 'Tenant drafts'): array
    {
        $business = Business::query()->create([
            'name' => $name,
            'country' => 'GT',
            'currency' => 'GTQ',
            'is_active' => true,
        ]);

        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_branches' => true,
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);

        foreach (['pos', 'purchases', 'branches'] as $module) {
            TenantModule::query()->create([
                'business_id' => $business->id,
                'module' => $module,
                'is_enabled' => true,
                'enabled_at' => now(),
            ]);
        }

        $branch = BranchInventory::defaultBranch($business->id);
        $user = $this->user($business, $branch, $role);

        return [$business, $user, $branch];
    }

    private function user(Business $business, Branch $branch, string $role): User
    {
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => $role,
            'is_active' => true,
            'current_branch_id' => $branch->id,
        ]);
        Permissions::assignRole($user, $role);

        return $user;
    }
}
