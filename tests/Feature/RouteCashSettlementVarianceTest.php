<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RouteCashSettlement;
use App\Models\RouteCashSettlementVarianceResolution;
use App\Models\RoutePreSaleCollection;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteCashSettlementDraftService;
use App\Services\Routes\RouteCashSettlementService;
use App\Services\Routes\RouteCashSettlementVarianceService;
use App\Support\BranchInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteCashSettlementVarianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_shortage_confirmation_creates_actual_cash_movement_and_open_signed_variance(): void
    {
        [$actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'variance-draft-0001');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);

        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 40, null, 'variance-confirm-0001', ['reason_code' => 'missing_cash', 'explanation' => 'Conteo físico confirmado.', 'confirmed' => true]);

        $settlement->refresh();
        $this->assertSame('confirmed', $settlement->status);
        $this->assertSame('-5.00', $settlement->difference_amount);
        $this->assertDatabaseHas('cash_movements', ['id' => $settlement->cash_movement_id, 'amount' => 40, 'type' => 'route_cash_settlement']);
        $this->assertDatabaseHas('route_cash_settlement_variances', ['route_cash_settlement_id' => $settlement->id, 'difference_amount' => -5, 'status' => 'open']);
        $this->assertSame('posted_to_branch_cash', $collection->fresh()->custody_status);
    }

    public function test_partial_shortage_resolution_creates_positive_cash_and_resolves_at_zero(): void
    {
        [$actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'variance-resolution-draft');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 40, null, 'variance-resolution-confirm', ['reason_code' => 'missing_cash', 'explanation' => 'Faltante.', 'confirmed' => true]);
        $variance = $settlement->fresh()->variance;
        $service = app(RouteCashSettlementVarianceService::class);
        $service->resolve($variance, ['idempotency_key' => 'variance-resolve-1', 'type' => 'shortage_cash_received', 'amount' => 3, 'counterparty_user_id' => $collector->id, 'note' => 'Primer abono físico.'], $actor);
        $this->assertSame('open', $variance->fresh()->status);
        $this->assertSame(2.0, $service->remainingAmount($variance->fresh()));
        $service->resolve($variance->fresh(), ['idempotency_key' => 'variance-resolve-2', 'type' => 'shortage_cash_received', 'amount' => 2, 'counterparty_user_id' => $collector->id, 'note' => 'Cierre físico.'], $actor);
        $this->assertSame('resolved', $variance->fresh()->status);
        $this->assertDatabaseHas('cash_movements', ['type' => 'route_cash_variance_shortage_received', 'amount' => 3]);
    }

    public function test_overage_confirmation_and_return_use_actual_signed_cash(): void
    {
        [$actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'overage-draft-0001');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 50, null, 'overage-confirm-0001', ['reason_code' => 'unidentified_extra_cash', 'explanation' => 'Sobrante físico.', 'confirmed' => true]);
        $variance = $settlement->fresh()->variance;
        $resolution = app(RouteCashSettlementVarianceService::class)->resolve($variance, ['idempotency_key' => 'overage-return-0001', 'type' => 'overage_cash_returned', 'amount' => 5, 'counterparty_user_id' => $collector->id, 'note' => 'Devolución física.'], $actor);
        $this->assertSame('resolved', $variance->fresh()->status);
        $this->assertDatabaseHas('cash_movements', ['id' => $resolution->resultId ? \App\Models\RouteCashSettlementVarianceResolution::findOrFail($resolution->resultId)->cash_movement_id : 0, 'type' => 'route_cash_variance_overage_returned', 'amount' => -5]);
        $this->assertSame('posted_to_branch_cash', $collection->fresh()->custody_status);
    }

    public function test_assignment_replay_is_idempotent_and_does_not_mutate_a_resolved_variance(): void
    {
        [$actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'assignment-draft-0001');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 40, null, 'assignment-confirm-0001', ['reason_code' => 'missing_cash', 'explanation' => 'Faltante confirmado.', 'confirmed' => true]);
        $variance = $settlement->fresh()->variance;
        $service = app(RouteCashSettlementVarianceService::class);

        $first = $service->assign($variance, $actor->id, $actor, 'assignment-replay-0001');
        $replay = $service->assign($variance, $actor->id, $actor, 'assignment-replay-0001');

        $this->assertFalse($first->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame($variance->id, $replay->resultId);
        $this->assertSame($actor->id, $variance->fresh()->assigned_to);
    }

    public function test_exact_confirmation_has_one_movement_and_no_variance_while_zero_received_is_rejected(): void
    {
        [$actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'exact-draft-0001');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);

        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 45, null, 'exact-confirm-0001');

        $this->assertSame('confirmed', $settlement->fresh()->status);
        $this->assertSame('0.00', $settlement->fresh()->difference_amount);
        $this->assertDatabaseCount('route_cash_settlement_variances', 0);
        $this->assertDatabaseCount('cash_movements', 1);

        [$actor2, $collector2, $collection2] = $this->fixture();
        $zeroDraft = app(RouteCashSettlementDraftService::class)->create($actor2, $collector2->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection2->id]], 'zero-draft-0001');
        $zero = RouteCashSettlement::query()->findOrFail($zeroDraft->resultId);
        try {
            app(RouteCashSettlementService::class)->confirm($zero, $actor2, $actor2->id, 0, null, 'zero-confirm-0001');
            $this->fail('Expected zero received amount to be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame('draft', $zero->fresh()->status);
            $this->assertSame('held_by_collector', $collection2->fresh()->custody_status);
            $this->assertDatabaseCount('route_cash_settlement_variances', 0);
        }
    }

    public function test_event_replay_and_competing_resolutions_cannot_overconsume_remaining_amount(): void
    {
        [$actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'ledger-draft-0001');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 40, null, 'ledger-confirm-0001', ['reason_code' => 'missing_cash', 'explanation' => 'Faltante físico.', 'confirmed' => true]);
        $variance = $settlement->fresh()->variance;
        $service = app(RouteCashSettlementVarianceService::class);

        $event = $service->addEvent($variance, ['idempotency_key' => 'variance-event-0001', 'type' => 'investigation', 'note' => 'Conteo revisado.'], $actor);
        $replay = $service->addEvent($variance, ['idempotency_key' => 'variance-event-0001', 'type' => 'investigation', 'note' => 'Conteo revisado.'], $actor);
        $this->assertFalse($event->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertDatabaseCount('route_cash_settlement_variance_events', 1);

        $service->resolve($variance, ['idempotency_key' => 'variance-resolution-a', 'type' => 'shortage_cash_received', 'amount' => 3, 'counterparty_user_id' => $collector->id, 'note' => 'Primera recepción.'], $actor);
        try {
            $service->resolve($variance->fresh(), ['idempotency_key' => 'variance-resolution-b', 'type' => 'shortage_cash_received', 'amount' => 3, 'counterparty_user_id' => $collector->id, 'note' => 'Competidor tardío.'], $actor);
            $this->fail('Expected competing resolution to be rejected after the remaining amount changed.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame(2.0, $service->remainingAmount($variance->fresh()));
            $this->assertSame(1, RouteCashSettlementVarianceResolution::query()->where('variance_id', $variance->id)->count());
        }
    }

    public function test_overage_partial_returns_use_negative_movements_and_only_resolve_at_zero(): void
    {
        [$actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'partial-overage-draft');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 50, null, 'partial-overage-confirm', ['reason_code' => 'unidentified_extra_cash', 'explanation' => 'Sobrante físico.', 'confirmed' => true]);
        $variance = $settlement->fresh()->variance;
        $service = app(RouteCashSettlementVarianceService::class);

        $first = $service->resolve($variance, ['idempotency_key' => 'partial-overage-one', 'type' => 'overage_cash_returned', 'amount' => 2, 'counterparty_user_id' => $collector->id, 'note' => 'Primera devolución.'], $actor);
        $firstResolution = RouteCashSettlementVarianceResolution::query()->findOrFail($first->resultId);
        $this->assertSame('open', $variance->fresh()->status);
        $this->assertSame(3.0, $service->remainingAmount($variance->fresh()));
        $this->assertDatabaseHas('cash_movements', ['id' => $firstResolution->cash_movement_id, 'type' => 'route_cash_variance_overage_returned', 'amount' => -2]);
        $service->resolve($variance->fresh(), ['idempotency_key' => 'partial-overage-two', 'type' => 'overage_cash_returned', 'amount' => 3, 'counterparty_user_id' => $collector->id, 'note' => 'Devolución final.'], $actor);
        $this->assertSame('resolved', $variance->fresh()->status);
        $this->assertSame(0.0, $service->remainingAmount($variance->fresh()));
    }

    public function test_independent_confirmation_competitor_cannot_create_second_movement_or_variance(): void
    {
        [$actor, $collector, $collection] = $this->fixture();
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'confirm-race-draft');
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        $service = app(RouteCashSettlementService::class);
        $service->confirm($settlement, $actor, $actor->id, 45, null, 'confirm-race-exact');
        try {
            $service->confirm($settlement->fresh(), $actor, $actor->id, 40, null, 'confirm-race-variance', ['reason_code' => 'missing_cash', 'explanation' => 'Competidor tardío.', 'confirmed' => true]);
            $this->fail('Expected second independent confirmation to be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseCount('cash_movements', 1);
            $this->assertDatabaseCount('route_cash_settlement_variances', 0);
            $this->assertSame('confirmed', $settlement->fresh()->status);
        }
    }

    /** @return array{0: User, 1: User, 2: RoutePreSaleCollection} */
    private function fixture(): array
    {
        $business = Business::query()->create(['name' => 'Variance '.uniqid(), 'slug' => 'variance-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
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
