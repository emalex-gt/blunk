<?php

namespace Tests\Feature;

use App\Models\RouteDeliveryCollection;
use App\Models\RoutePendingCollectionCase;
use App\Models\User;
use App\Services\Routes\RouteDeliveryCollectionReversalService;
use App\Services\Routes\RouteCashSettlementDraftService;
use App\Services\Routes\RoutePendingCollectionService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesRoutePendingCollectionFixture;
use Tests\TestCase;

class RouteDeliveryCollectionReversalTest extends TestCase
{
    use CreatesRoutePendingCollectionFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_transfer_reversal_uses_the_service_ledger_and_replays_idempotently(): void
    {
        [, , $item] = $this->externalDeliveredUnpaidFixture();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $collection = RouteDeliveryCollection::query()->findOrFail(
            app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'reversal-service-capture-0001')->resultId
        );
        $payload = ['reason_code' => 'payment_recorded_by_mistake', 'explanation' => 'El banco confirmó que no recibió el pago.', 'confirmed' => true];
        $service = app(RouteDeliveryCollectionReversalService::class);
        $first = $service->reverse($collection, $payload, $actor, 'reversal-service-0001');
        $replay = $service->reverse($collection, $payload, $actor, 'reversal-service-0001');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertFalse($first->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->resultId, $replay->resultId);
        $this->assertSame('reversed', $collection->fresh()->status);
        $this->assertSame('reversed', $collection->salePayment->fresh()->status);
        $this->assertSame('unpaid', $collection->sale->fresh()->payment_status);
        $this->assertDatabaseCount('route_delivery_collection_reversals', 1);
    }

    public function test_delivery_agent_cannot_reverse_a_delivery_collection_without_the_administrative_permission(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaidFixture();
        $manager = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $collection = RouteDeliveryCollection::query()->findOrFail(
            app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $manager, 'reversal-service-denied-capture-0001')->resultId
        );
        $deliveryAgent = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignRole($deliveryAgent, 'delivery_agent');

        $this->expectException(HttpException::class);
        app(RouteDeliveryCollectionReversalService::class)->reverse($collection, [
            'reason_code' => 'payment_recorded_by_mistake', 'explanation' => 'No autorizado.', 'confirmed' => true,
        ], $deliveryAgent, 'reversal-service-denied-0001');
    }

    public function test_closed_in_app_delivery_can_be_reversed_then_recaptured_against_the_same_stop(): void
    {
        [$business, , $stop] = $this->closedInAppDeliveredUnpaidFixture();
        $actor = User::query()->findOrFail($stop->run->delivery_user_id);
        Permissions::assignRole($actor, 'owner');
        $case = RoutePendingCollectionCase::query()->where('sale_id', $stop->sale_id)->firstOrFail();
        $collections = app(RoutePendingCollectionService::class);
        $first = RouteDeliveryCollection::query()->findOrFail(
            $collections->collect($case, ['amount' => 60, 'payment_method' => 'card'], $actor, 'reversal-in-app-capture-0001')->resultId
        );
        app(RouteDeliveryCollectionReversalService::class)->reverse($first, [
            'reason_code' => 'wrong_customer', 'explanation' => 'El cobro se atribuyó a otro cliente.', 'confirmed' => true,
        ], $actor, 'reversal-in-app-0001');
        $second = $collections->collect($case->fresh(), ['amount' => 60, 'payment_method' => 'card'], $actor, 'reversal-in-app-recapture-0001');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertDatabaseHas('route_delivery_collections', ['id' => $first->id, 'route_delivery_stop_id' => $stop->id, 'status' => 'reversed']);
        $this->assertDatabaseHas('route_delivery_collections', ['id' => $second->resultId, 'route_delivery_stop_id' => $stop->id, 'status' => 'captured']);
        $issues = collect(app(\App\Support\SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'sales'])['results']['sales'])->pluck('issue_type');
        $this->assertFalse($issues->contains('delivery_collection_reversal_financial_mismatch'));
        $this->assertFalse($issues->contains('delivery_collection_reversal_case_mismatch'));

        $duplicate = RouteDeliveryCollection::query()->findOrFail($second->resultId)->replicate();
        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_external_recapture_is_allowed_once_after_reversal_and_a_second_captured_row_hits_the_partial_indexes(): void
    {
        [, , $item] = $this->externalDeliveredUnpaidFixture();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $collections = app(RoutePendingCollectionService::class);
        $first = RouteDeliveryCollection::query()->findOrFail(
            $collections->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'reversal-external-capture-0001')->resultId
        );
        app(RouteDeliveryCollectionReversalService::class)->reverse($first, [
            'reason_code' => 'wrong_amount', 'explanation' => 'El importe se digitó contra la venta equivocada.', 'confirmed' => true,
        ], $actor, 'reversal-external-0001');
        $second = $collections->collect($case->fresh(), ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'reversal-external-recapture-0001');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertDatabaseHas('route_delivery_collections', ['id' => $second->resultId, 'route_external_delivery_reconciliation_item_id' => $item->id, 'status' => 'captured']);
        $duplicate = RouteDeliveryCollection::query()->findOrFail($second->resultId)->replicate();
        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_active_draft_settlement_blocks_reversal_before_any_financial_mutation(): void
    {
        [, , $item] = $this->externalDeliveredUnpaidFixture();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $collection = RouteDeliveryCollection::query()->findOrFail(
            app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'cash'], $actor, 'reversal-settlement-capture-0001')->resultId
        );
        app(RouteCashSettlementDraftService::class)->create($actor, $actor->id, [['origin' => 'delivery_collection', 'collection_id' => $collection->id]], 'reversal-settlement-draft-0001');

        try {
            app(RouteDeliveryCollectionReversalService::class)->reverse($collection, [
                'reason_code' => 'duplicate_collection', 'explanation' => 'No debe cambiar cobro reservado.', 'confirmed' => true,
            ], $actor, 'reversal-settlement-blocked-0001');
            $this->fail('A draft settlement must block the reversal.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $this->assertSame('captured', $collection->fresh()->status);
        $this->assertSame('paid', $collection->sale->fresh()->payment_status);
        $this->assertDatabaseCount('route_delivery_collection_reversals', 0);
    }
}
