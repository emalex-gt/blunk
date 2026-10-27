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

    public function test_pos_draft_listing_is_scoped_to_the_requested_branch(): void
    {
        [$business, $user, $branchA] = $this->tenant('admin');
        $branchB = $this->branch($business, 'Sucursal B');
        $draftA = $this->activeDraft($business, $user, $branchA, 'pos_sale');
        $this->activeDraft($business, $user, $branchB, 'pos_sale');

        $this->actingAs($user)
            ->getJson(route('operation-drafts.index', ['type' => 'pos_sale', 'branch_id' => $branchA->id]))
            ->assertOk()
            ->assertJsonCount(1, 'drafts')
            ->assertJsonPath('drafts.0.id', $draftA->id)
            ->assertJsonPath('drafts.0.branch.id', $branchA->id);
    }

    public function test_purchase_draft_listing_is_scoped_to_the_requested_branch(): void
    {
        [$business, $user, $branchA] = $this->tenant('admin');
        $branchB = $this->branch($business, 'Sucursal B');
        $draftA = $this->activeDraft($business, $user, $branchA, 'purchase');
        $this->activeDraft($business, $user, $branchB, 'purchase');

        $this->actingAs($user)
            ->getJson(route('operation-drafts.index', ['type' => 'purchase', 'branch_id' => $branchA->id]))
            ->assertOk()
            ->assertJsonCount(1, 'drafts')
            ->assertJsonPath('drafts.0.id', $draftA->id)
            ->assertJsonPath('drafts.0.branch.id', $branchA->id);
    }

    public function test_transfer_draft_listing_is_scoped_to_its_source_branch_context(): void
    {
        [$business, $user, $branchA] = $this->tenant('admin');
        $branchB = $this->branch($business, 'Sucursal B');
        $branchC = $this->branch($business, 'Sucursal C');
        $draftA = $this->activeDraft($business, $user, $branchA, 'transfer', [
            'source_branch_id' => $branchA->id,
            'destination_branch_id' => $branchB->id,
        ]);
        $this->activeDraft($business, $user, $branchC, 'transfer', [
            'source_branch_id' => $branchC->id,
            'destination_branch_id' => $branchB->id,
        ]);

        $this->actingAs($user)
            ->getJson(route('operation-drafts.index', ['type' => 'transfer', 'branch_id' => $branchA->id]))
            ->assertOk()
            ->assertJsonCount(1, 'drafts')
            ->assertJsonPath('drafts.0.id', $draftA->id)
            ->assertJsonPath('drafts.0.source_branch.id', $branchA->id);
    }

    public function test_branch_filter_rejects_a_branch_from_another_business(): void
    {
        [$businessA, $userA] = $this->tenant('admin', 'Tenant A');
        [, , $branchB] = $this->tenant('admin', 'Tenant B');

        $this->actingAs($userA)
            ->getJson(route('operation-drafts.index', ['type' => 'pos_sale', 'branch_id' => $branchB->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_pos_final_consumer_draft_round_trips_without_changing_its_payload(): void
    {
        [$business, $user, $branch] = $this->tenant();

        $this->actingAs($user)
            ->postJson(route('operation-drafts.store'), [
                'type' => 'pos_sale',
                'branch_id' => $branch->id,
                'payload_version' => OperationDrafts::PAYLOAD_VERSION,
                'payload' => [
                    'branch_id' => $branch->id,
                    'customer' => [
                        'id' => null,
                        'consumidor_final' => true,
                        'doc_type' => 'CF',
                        'doc_number' => 'CF',
                        'name' => 'Consumidor Final',
                    ],
                    'cart' => [[
                        'product_id' => 123,
                        'quantity' => '2',
                        'unit_price' => '10.00',
                    ]],
                ],
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->getJson(route('operation-drafts.index', ['type' => 'pos_sale', 'branch_id' => $branch->id]))
            ->assertOk()
            ->assertJsonCount(1, 'drafts')
            ->assertJsonPath('drafts.0.payload.customer.id', null)
            ->assertJsonPath('drafts.0.payload.customer.consumidor_final', true)
            ->assertJsonPath('drafts.0.payload.customer.doc_type', 'CF')
            ->assertJsonPath('drafts.0.payload.customer.doc_number', 'CF')
            ->assertJsonPath('drafts.0.payload.customer.name', 'Consumidor Final')
            ->assertJsonPath('drafts.0.payload.cart.0.product_id', 123)
            ->assertJsonPath('drafts.0.payload.cart.0.quantity', '2')
            ->assertJsonPath('drafts.0.payload.cart.0.unit_price', '10.00');
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

    private function branch(Business $business, string $name): Branch
    {
        return Branch::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function activeDraft(Business $business, User $user, Branch $branch, string $type, array $attributes = []): OperationDraft
    {
        return OperationDraft::query()->create(array_merge([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'type' => $type,
            'payload' => ['cart' => []],
            'status' => OperationDraft::STATUS_ACTIVE,
        ], $attributes));
    }
}
