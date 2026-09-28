<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\OperationIdempotencyKey;
use App\Models\PreSale;
use App\Models\RouteCashSettlement;
use App\Models\RouteCashSettlementItem;
use App\Models\RouteCashSettlementReversal;
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

class RouteCashSettlementReversalPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_exposes_reversal_ledger_and_causal_item_release(): void
    {
        $this->assertTrue(Schema::hasTable('route_cash_settlement_reversals'));
        foreach (['business_id', 'branch_id', 'route_cash_settlement_id', 'reason_code', 'explanation', 'reversed_by', 'reversed_at', 'cash_correction_type', 'compensating_cash_movement_id', 'operation_idempotency_key_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('route_cash_settlement_reversals', $column), $column);
        }
        $this->assertTrue(Schema::hasColumn('route_cash_settlement_items', 'reversed_by_route_cash_settlement_reversal_id'));

        $stateCheck = DB::table('pg_constraint')->selectRaw('pg_get_constraintdef(oid) as definition')->where('conname', 'route_cash_settlements_state_check')->value('definition');
        foreach (['draft', 'confirmed', 'cancelled', 'reversed'] as $status) {
            $this->assertStringContainsString("'{$status}'", (string) $stateCheck);
        }
    }

    public function test_reversed_settlement_requires_one_matching_ledger_and_causally_released_items(): void
    {
        [$settlement, $actor] = $this->confirmedSettlement();
        $item = $settlement->items()->sole();

        DB::transaction(function () use ($settlement, $actor, $item): void {
            $reversalId = DB::table('route_cash_settlement_reversals')->insertGetId($this->reversalPayload($settlement, $actor));
            $item->update(['is_active' => false, 'reversed_by_route_cash_settlement_reversal_id' => $reversalId]);
            $settlement->update(['status' => 'reversed']);
        });

        $settlement->refresh();
        $this->assertSame('reversed', $settlement->status);
        $this->assertSame(1, $settlement->reversal()->count());
        $this->assertFalse($item->fresh()->is_active);
        $this->assertNotNull($item->fresh()->reversed_by_route_cash_settlement_reversal_id);
    }

    public function test_deferred_integrity_rejects_reversed_settlement_without_ledger(): void
    {
        [$settlement] = $this->confirmedSettlement();

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($settlement): void {
            $settlement->update(['status' => 'reversed']);
            $this->assertReversalIntegrityNow();
        });
    }

    public function test_deferred_integrity_rejects_ledger_for_non_reversed_settlement(): void
    {
        [$settlement, $actor] = $this->confirmedSettlement();

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($settlement, $actor): void {
            DB::table('route_cash_settlement_reversals')->insert($this->reversalPayload($settlement, $actor));
            $this->assertReversalIntegrityNow();
        });
    }

    public function test_causal_item_release_cannot_cross_settlements(): void
    {
        [$first, $actor] = $this->confirmedSettlement('first');
        [$second] = $this->confirmedSettlement('second');

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($first, $second, $actor): void {
            $reversalId = DB::table('route_cash_settlement_reversals')->insertGetId($this->reversalPayload($first, $actor));
            $second->items()->sole()->update(['is_active' => false, 'reversed_by_route_cash_settlement_reversal_id' => $reversalId]);
            $first->update(['status' => 'reversed']);
            $this->assertReversalIntegrityNow();
        });
    }

    public function test_ledger_only_disallows_compensating_movement_and_uniqueness_is_enforced(): void
    {
        [$settlement, $actor] = $this->confirmedSettlement();
        $payload = $this->reversalPayload($settlement, $actor);
        $payload['cash_correction_type'] = 'ledger_only';
        $payload['compensating_cash_movement_id'] = $settlement->cash_movement_id;

        $this->expectException(QueryException::class);
        DB::table('route_cash_settlement_reversals')->insert($payload);
    }

    public function test_current_session_adjustment_requires_a_compensating_movement(): void
    {
        [$settlement, $actor] = $this->confirmedSettlement();
        $payload = $this->reversalPayload($settlement, $actor);
        $payload['cash_correction_type'] = 'current_session_adjustment';

        $this->expectException(QueryException::class);
        DB::table('route_cash_settlement_reversals')->insert($payload);
    }

    public function test_duplicate_compensating_movement_and_idempotency_links_are_rejected(): void
    {
        [$first, $actor] = $this->confirmedSettlement('duplicate-first');
        [$second] = $this->confirmedSettlement('duplicate-second');
        $movement = CashMovement::query()->create([
            'business_id' => $first->business_id,
            'branch_id' => $first->branch_id,
            'cash_register_session_id' => $first->cash_register_session_id,
            'type' => 'test_compensation',
            'amount' => -1,
            'reference_type' => 'test',
            'reference_id' => $first->id,
            'description' => 'Fixture de integridad.',
            'created_by' => $actor->id,
        ]);
        $key = OperationIdempotencyKey::query()->create([
            'business_id' => $first->business_id,
            'branch_id' => $first->branch_id,
            'user_id' => $actor->id,
            'operation_type' => 'route_cash_settlement_reverse',
            'idempotency_key' => 'b2a-persistence-'.uniqid(),
            'request_hash' => hash('sha256', uniqid('', true)),
            'status' => 'completed',
            'result_type' => 'route_cash_settlement_reversal',
            'result_id' => 0,
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $firstPayload = $this->reversalPayload($first, $actor);
        $firstPayload['cash_correction_type'] = 'current_session_adjustment';
        $firstPayload['compensating_cash_movement_id'] = $movement->id;
        $firstPayload['operation_idempotency_key_id'] = $key->id;
        DB::table('route_cash_settlement_reversals')->insert($firstPayload);

        $secondPayload = $this->reversalPayload($second, $actor);
        $secondPayload['cash_correction_type'] = 'current_session_adjustment';
        $secondPayload['compensating_cash_movement_id'] = $movement->id;
        $secondPayload['operation_idempotency_key_id'] = $key->id;

        $this->expectException(QueryException::class);
        DB::table('route_cash_settlement_reversals')->insert($secondPayload);
    }

    public function test_model_relations_and_timestamp_cast_are_available(): void
    {
        [$settlement, $actor] = $this->confirmedSettlement();
        $item = $settlement->items()->sole();
        DB::transaction(function () use ($settlement, $actor, $item): void {
            $reversal = RouteCashSettlementReversal::query()->create($this->reversalPayload($settlement, $actor));
            $item->update(['is_active' => false, 'reversed_by_route_cash_settlement_reversal_id' => $reversal->id]);
            $settlement->update(['status' => 'reversed']);
        });

        $reversal = $settlement->fresh()->reversal;
        $this->assertTrue($reversal->reversed_at instanceof \Illuminate\Support\Carbon);
        $this->assertTrue($reversal->settlement->is($settlement));
        $this->assertTrue($reversal->reversedItems->sole()->is($item));
        $this->assertTrue($item->fresh()->reversedByReversal->is($reversal));
    }

    public function test_existing_three_source_xor_constraint_remains_unchanged(): void
    {
        $definition = DB::table('pg_constraint')
            ->selectRaw('pg_get_constraintdef(oid) as definition')
            ->where('conname', 'route_cash_settlement_items_origin_check')
            ->value('definition');

        $this->assertStringContainsString('route_pre_sale_collection_id', (string) $definition);
        $this->assertStringContainsString('route_delivery_collection_id', (string) $definition);
        $this->assertStringContainsString('route_post_conversion_collection_id', (string) $definition);
    }

    /** @return array{0: RouteCashSettlement, 1: User} */
    private function confirmedSettlement(string $suffix = ''): array
    {
        $business = Business::query()->create(['name' => 'B2a '.uniqid(), 'slug' => 'b2a-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $collector->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45]);
        $collection = RoutePreSaleCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'pre_sale_id' => $preSale->id, 'collected_by' => $collector->id, 'recorded_by' => $collector->id, 'amount' => 45, 'payment_method' => 'cash', 'collected_at' => now(), 'status' => 'captured', 'custody_status' => 'held_by_collector']);
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'b2a-draft-'.$suffix.uniqid());
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 45, null, 'b2a-confirm-'.$suffix.uniqid());

        return [RouteCashSettlement::query()->with('items')->findOrFail($settlement->id), $actor];
    }

    /** @return array<string, mixed> */
    private function reversalPayload(RouteCashSettlement $settlement, User $actor): array
    {
        return [
            'business_id' => $settlement->business_id,
            'branch_id' => $settlement->branch_id,
            'route_cash_settlement_id' => $settlement->id,
            'reason_code' => 'cash_not_received_by_branch',
            'explanation' => 'El efectivo no llegó físicamente a la sucursal.',
            'reversed_by' => $actor->id,
            'reversed_at' => now(),
            'cash_correction_type' => 'ledger_only',
            'compensating_cash_movement_id' => null,
            'operation_idempotency_key_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function assertReversalIntegrityNow(): void
    {
        DB::statement('SET CONSTRAINTS route_cash_settlement_reversal_settlement_trigger, route_cash_settlement_reversal_ledger_trigger, route_cash_settlement_reversal_item_trigger IMMEDIATE');
    }
}
