<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RouteBranchCollectionSetting;
use App\Models\User;
use App\Services\Routes\RouteBranchCollectionSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RouteBranchCollectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_without_policy_returns_null_and_reader_does_not_create_a_default_row(): void
    {
        [$business, $branch] = $this->businessAndBranch();

        $setting = app(RouteBranchCollectionSettingsService::class)->forBranch($business->id, $branch->id);

        $this->assertNull($setting);
        $this->assertDatabaseCount('route_branch_collection_settings', 0);
    }

    public function test_it_creates_an_immediate_paid_policy_and_exposes_it_through_the_branch_relation(): void
    {
        [$business, $branch] = $this->businessAndBranch();

        $setting = app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['cash', 'card'],
            'primary_payment_method' => 'cash',
        ]);

        $this->assertSame($branch->id, $setting->branch_id);
        $this->assertSame('immediate_paid', $setting->collection_workflow_mode);
        $this->assertSame(['cash', 'card'], $setting->allowed_payment_methods);
        $this->assertSame($setting->id, $branch->refresh()->collectionSetting?->id);
    }

    public function test_it_creates_a_per_order_collection_policy(): void
    {
        [$business, $branch] = $this->businessAndBranch();

        $setting = app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['transfer'],
            'primary_payment_method' => 'transfer',
        ]);

        $this->assertSame('per_order_collection', $setting->collection_workflow_mode);
        $this->assertSame(['transfer'], $setting->allowed_payment_methods);
    }

    public function test_it_persists_allowed_payment_methods_in_canonical_order_without_changing_primary(): void
    {
        [$business, $branch] = $this->businessAndBranch();

        $setting = app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['check', 'card', 'cash'],
            'primary_payment_method' => 'check',
        ])->fresh();

        $this->assertSame(['cash', 'card', 'check'], $setting->allowed_payment_methods);
        $this->assertSame('check', $setting->primary_payment_method);
    }

    public function test_second_save_updates_the_existing_row_without_creating_a_duplicate(): void
    {
        [$business, $branch] = $this->businessAndBranch();
        $service = app(RouteBranchCollectionSettingsService::class);
        $first = $service->save($business->id, $branch, $this->policy());
        $second = $service->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['card'],
            'primary_payment_method' => 'card',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RouteBranchCollectionSetting::query()->where('branch_id', $branch->id)->count());
        $this->assertSame('per_order_collection', $second->collection_workflow_mode);
    }

    public function test_cross_business_reader_never_returns_a_foreign_branch_policy(): void
    {
        [$owner, $branch] = $this->businessAndBranch();
        $foreign = $this->business();
        app(RouteBranchCollectionSettingsService::class)->save($owner->id, $branch, $this->policy());

        $this->assertNull(app(RouteBranchCollectionSettingsService::class)->forBranch($foreign->id, $branch->id));
    }

    public function test_cross_business_save_is_rejected_before_writing(): void
    {
        [$owner, $branch] = $this->businessAndBranch();
        $foreign = $this->business();

        try {
            app(RouteBranchCollectionSettingsService::class)->save($foreign->id, $branch, $this->policy());
            $this->fail('Una branch de otro business no debe aceptar escritura.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('route_branch_collection_settings', 0);
        }
    }

    public function test_service_rejects_unknown_and_manifest_workflows(): void
    {
        [$business, $branch] = $this->businessAndBranch();

        foreach (['manifest_reconciliation', 'unknown'] as $workflow) {
            try {
                app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
                    ...$this->policy(),
                    'collection_workflow_mode' => $workflow,
                ]);
                $this->fail('El workflow no persistible debe rechazarse.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('route_branch_collection_settings', 0);
            }
        }
    }

    public function test_service_rejects_empty_duplicate_and_unknown_allowed_methods(): void
    {
        [$business, $branch] = $this->businessAndBranch();
        $invalidLists = [[], ['cash', 'cash'], ['cash', 'crypto']];

        foreach ($invalidLists as $methods) {
            try {
                app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
                    ...$this->policy(),
                    'allowed_payment_methods' => $methods,
                ]);
                $this->fail('La lista de métodos inválida debe rechazarse sin normalización silenciosa.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('route_branch_collection_settings', 0);
            }
        }
    }

    public function test_service_rejects_a_primary_method_outside_allowed_methods(): void
    {
        [$business, $branch] = $this->businessAndBranch();

        $this->expectException(ValidationException::class);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['cash'],
            'primary_payment_method' => 'card',
        ]);
    }

    public function test_service_rejects_non_string_allowed_methods_and_missing_primary_method(): void
    {
        [$business, $branch] = $this->businessAndBranch();
        $service = app(RouteBranchCollectionSettingsService::class);

        foreach ([
            [...$this->policy(), 'allowed_payment_methods' => ['cash', 7]],
            [...$this->policy(), 'primary_payment_method' => null],
        ] as $policy) {
            try {
                $service->save($business->id, $branch, $policy);
                $this->fail('Los métodos no string y primary ausente deben rechazarse.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('route_branch_collection_settings', 0);
            }
        }
    }

    public function test_invalid_save_preserves_the_existing_valid_policy(): void
    {
        [$business, $branch] = $this->businessAndBranch();
        $service = app(RouteBranchCollectionSettingsService::class);
        $existing = $service->save($business->id, $branch, $this->policy());

        try {
            $service->save($business->id, $branch, [
                ...$this->policy(),
                'allowed_payment_methods' => [],
            ]);
            $this->fail('La actualización inválida debe fallar.');
        } catch (ValidationException) {
            $existing->refresh();
            $this->assertSame('immediate_paid', $existing->collection_workflow_mode);
            $this->assertSame(['cash', 'card'], $existing->allowed_payment_methods);
        }
    }

    public function test_database_rejects_invalid_workflow_when_service_is_bypassed(): void
    {
        [$business, $branch] = $this->businessAndBranch();

        $this->expectException(QueryException::class);
        $this->insertPolicyDirectly($branch->id, 'manifest_reconciliation', '["cash"]');
    }

    public function test_database_rejects_empty_json_array_when_service_is_bypassed(): void
    {
        [, $branch] = $this->businessAndBranch();

        $this->expectException(QueryException::class);
        $this->insertPolicyDirectly($branch->id, 'immediate_paid', '[]');
    }

    public function test_database_rejects_non_array_json_when_service_is_bypassed(): void
    {
        [, $branch] = $this->businessAndBranch();

        $this->expectException(QueryException::class);
        $this->insertPolicyDirectly($branch->id, 'immediate_paid', '"cash"');
    }

    public function test_database_rejects_invalid_branch_fk_when_service_is_bypassed(): void
    {
        $this->businessAndBranch();

        $this->expectException(QueryException::class);
        $this->insertPolicyDirectly(999999, 'immediate_paid', '["cash"]');
    }

    public function test_database_enforces_one_to_one_branch_uniqueness_when_service_is_bypassed(): void
    {
        [$business, $branch] = $this->businessAndBranch();
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, $this->policy());

        $this->expectException(QueryException::class);
        $this->insertPolicyDirectly($branch->id, 'per_order_collection', '["transfer"]', 'transfer');
    }

    public function test_deleting_branch_cascades_its_policy(): void
    {
        [$business, $branch] = $this->businessAndBranch();
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, $this->policy());

        $branch->delete();

        $this->assertDatabaseCount('route_branch_collection_settings', 0);
    }

    public function test_saving_policy_does_not_mutate_existing_pre_sales_or_add_business_id_to_policy_table(): void
    {
        [$business, $branch] = $this->businessAndBranch();
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $seller = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $preSale = PreSale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'seller_id' => $seller->id,
            'status' => PreSale::STATUS_DRAFT,
            'subtotal' => 25,
            'discount_total' => 0,
            'total' => 25,
            'payment_method' => 'transfer',
            'agreed_payment_method' => 'transfer',
        ]);

        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, $this->policy());

        $this->assertSame('transfer', $preSale->refresh()->agreed_payment_method);
        $this->assertSame(PreSale::STATUS_DRAFT, $preSale->status);
        $this->assertFalse(Schema::hasColumn('route_branch_collection_settings', 'business_id'));
    }

    /** @return array{collection_workflow_mode:string,allowed_payment_methods:array<int,string>,primary_payment_method:string} */
    private function policy(): array
    {
        return [
            'collection_workflow_mode' => 'immediate_paid',
            'allowed_payment_methods' => ['cash', 'card'],
            'primary_payment_method' => 'cash',
        ];
    }

    /** @return array{Business,Branch} */
    private function businessAndBranch(): array
    {
        $business = $this->business();

        return [$business, Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal '.uniqid(),
            'code' => 'BR-'.uniqid(),
            'is_active' => true,
        ])];
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'Política '.uniqid(),
            'slug' => 'policy-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
    }

    private function insertPolicyDirectly(int $branchId, string $workflow, string $methods, string $primary = 'cash'): void
    {
        DB::table('route_branch_collection_settings')->insert([
            'branch_id' => $branchId,
            'collection_workflow_mode' => $workflow,
            'allowed_payment_methods' => $methods,
            'primary_payment_method' => $primary,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
