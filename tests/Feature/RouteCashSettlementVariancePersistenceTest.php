<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RouteCashSettlement;
use App\Models\RoutePreSaleCollection;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteCashSettlementDraftService;
use App\Services\Routes\RouteCashSettlementService;
use App\Support\BranchInventory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RouteCashSettlementVariancePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_variance_tables_have_the_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('route_cash_settlement_variances'));
        $this->assertTrue(Schema::hasTable('route_cash_settlement_variance_events'));
        $this->assertTrue(Schema::hasTable('route_cash_settlement_variance_resolutions'));
        $this->assertTrue(Schema::hasColumn('route_cash_settlement_variances', 'difference_amount'));
        $this->assertTrue(Schema::hasColumn('route_cash_settlement_variance_resolutions', 'cash_movement_id'));
    }

    public function test_deferred_trigger_blocks_missing_or_deleted_variance_for_confirmed_difference(): void
    {
        [$settlement] = $this->confirmedShortage();
        $varianceId = $settlement->variance->id;

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($varianceId) {
            DB::table('route_cash_settlement_variances')->whereKey($varianceId)->delete();
            DB::statement('SET CONSTRAINTS route_cash_settlement_variance_settlement_trigger, route_cash_settlement_variance_variance_trigger IMMEDIATE');
        });
    }

    public function test_deferred_trigger_blocks_variance_on_exact_settlement_and_wrong_snapshot_scope(): void
    {
        [$exact] = $this->confirmedExact();
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($exact) {
            DB::table('route_cash_settlement_variances')->insert([
                'business_id' => $exact->business_id, 'branch_id' => $exact->branch_id, 'route_cash_settlement_id' => $exact->id,
                'collector_user_id' => $exact->collector_user_id, 'difference_amount' => 1, 'status' => 'open',
                'reason_code' => 'unidentified_extra_cash', 'explanation' => 'Fixture corrupto.', 'opened_by' => $exact->confirmed_by,
                'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::statement('SET CONSTRAINTS route_cash_settlement_variance_settlement_trigger, route_cash_settlement_variance_variance_trigger IMMEDIATE');
        });
    }

    /** @return array{0: RouteCashSettlement} */
    private function confirmedShortage(): array
    {
        [$actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'trigger-shortage-draft');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 40, null, 'trigger-shortage-confirm', ['reason_code' => 'missing_cash', 'explanation' => 'Faltante real.', 'confirmed' => true]);
        return [$settlement->fresh()->load('variance')];
    }

    /** @return array{0: RouteCashSettlement} */
    private function confirmedExact(): array
    {
        [$actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'trigger-exact-draft');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 45, null, 'trigger-exact-confirm');
        return [$settlement->fresh()];
    }

    /** @return array{0: User, 1: User, 2: RoutePreSaleCollection} */
    private function fixture(): array
    {
        $business = Business::query()->create(['name' => 'Trigger '.uniqid(), 'slug' => 'trigger-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $collector->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45]);
        $collection = RoutePreSaleCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'pre_sale_id' => $preSale->id, 'collected_by' => $collector->id, 'recorded_by' => $collector->id, 'amount' => 45, 'payment_method' => 'cash', 'collected_at' => now(), 'status' => 'captured', 'custody_status' => 'held_by_collector']);
        return [$actor, $collector, $collection];
    }
}
