<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RoutePendingCollectionCase;
use App\Models\RouteDeliveryStop;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteDeliveryBatchService;
use App\Services\Routes\RouteDeliveryRunAssignmentService;
use App\Services\Routes\RouteDeliveryRunService;
use App\Services\Routes\RouteDeliveryStopService;
use App\Services\Routes\RouteExternalDeliveryReconciliationService;
use App\Services\Routes\RouteExternalDeliveryReconciliationCorrectionService;
use App\Services\Routes\RoutePendingCollectionCaseService;
use App\Services\Routes\RoutePendingCollectionEligibility;
use App\Services\Routes\RoutePendingCollectionService;
use App\Services\Routes\RouteCashSettlementEligibility;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutePendingCollectionEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_normalizes_delivered_unpaid_external_and_closed_in_app_sources(): void
    {
        [$externalBusiness, $externalBranch, $externalItem] = $this->externalDeliveredUnpaid();
        [$inAppBusiness, $inAppBranch, $inAppStop] = $this->closedInAppDeliveredUnpaid();

        $eligibility = app(RoutePendingCollectionEligibility::class);
        $external = $eligibility->candidates($externalBusiness->id, $externalBranch->id)->values();
        $inApp = $eligibility->candidates($inAppBusiness->id, $inAppBranch->id)->values();

        $this->assertCount(1, $external);
        $this->assertSame([
            'sale_id' => $externalItem->sale_id,
            'pre_sale_id' => $externalItem->pre_sale_id,
            'batch_pre_sale_id' => $externalItem->route_delivery_batch_pre_sale_id,
            'business_id' => $externalBusiness->id,
            'branch_id' => $externalBranch->id,
            'origin' => 'external_reconciliation',
            'origin_id' => $externalItem->id,
        ], collect($external->first())->only(['sale_id', 'pre_sale_id', 'batch_pre_sale_id', 'business_id', 'branch_id', 'origin', 'origin_id'])->all());
        $this->assertNotNull($external->first()['delivered_at']);
        $this->assertSame($externalItem->reconciled_by, $external->first()['original_delivery_user_id']);

        $this->assertCount(1, $inApp);
        $this->assertSame([
            'sale_id' => $inAppStop->sale_id,
            'pre_sale_id' => $inAppStop->pre_sale_id,
            'batch_pre_sale_id' => $inAppStop->route_delivery_batch_pre_sale_id,
            'business_id' => $inAppBusiness->id,
            'branch_id' => $inAppBranch->id,
            'origin' => 'in_app_stop',
            'origin_id' => $inAppStop->id,
        ], collect($inApp->first())->only(['sale_id', 'pre_sale_id', 'batch_pre_sale_id', 'business_id', 'branch_id', 'origin', 'origin_id'])->all());
        $this->assertNotNull($inApp->first()['delivered_at']);
        $this->assertSame($inAppStop->run->delivery_user_id, $inApp->first()['original_delivery_user_id']);
    }

    public function test_excludes_an_external_item_with_an_in_app_tracking_snapshot(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaid();
        $item->update(['delivery_tracking_snapshot' => 'in_app']);

        $candidates = app(RoutePendingCollectionEligibility::class)->candidates($business->id, $branch->id);

        $this->assertCount(0, $candidates);
    }

    public function test_new_delivered_unpaid_sources_open_one_operational_case_without_waiting_for_closure(): void
    {
        [$externalBusiness, , $externalItem] = $this->externalDeliveredUnpaid();
        [$inAppBusiness, , $inAppStop] = $this->closedInAppDeliveredUnpaid();

        $this->assertDatabaseHas('route_pending_collection_cases', [
            'business_id' => $externalBusiness->id,
            'sale_id' => $externalItem->sale_id,
            'route_external_delivery_reconciliation_item_id' => $externalItem->id,
            'delivery_origin' => 'external_reconciliation',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('route_pending_collection_cases', [
            'business_id' => $inAppBusiness->id,
            'sale_id' => $inAppStop->sale_id,
            'route_delivery_stop_id' => $inAppStop->id,
            'delivery_origin' => 'in_app_stop',
            'status' => 'open',
        ]);
        $this->assertSame(2, RoutePendingCollectionCase::query()->count());
    }

    public function test_historical_eligible_source_is_derived_once_then_materialized_only_on_write(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaid();
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignDirectPermissions($actor, [Permissions::ROUTES_PENDING_COLLECTIONS_COLLECT]);
        RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->delete();

        $queue = app(RoutePendingCollectionEligibility::class)->queue($business->id, $branch->id);

        $this->assertCount(1, $queue);
        $this->assertNull($queue->first()['case_id']);
        $this->assertFalse($queue->first()['is_persisted']);
        $this->assertSame($item->sale_id, $queue->first()['sale_id']);

        $case = app(RoutePendingCollectionCaseService::class)->ensureForEligibleCandidate($item->sale_id, $actor);

        $this->assertSame('open', $case->status);
        $this->assertSame($item->reconciled_at?->format('Y-m-d H:i:s'), $case->opened_at?->format('Y-m-d H:i:s'));
        $this->assertCount(1, app(RoutePendingCollectionEligibility::class)->queue($business->id, $branch->id));
        $this->assertTrue(app(RoutePendingCollectionEligibility::class)->queue($business->id, $branch->id)->first()['is_persisted']);
    }

    public function test_open_case_records_one_append_only_follow_up_and_updates_assignment(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $assignee = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);

        $first = app(RoutePendingCollectionCaseService::class)->addEvent($case, [
            'type' => 'promise',
            'note' => 'El cliente confirmó pago el viernes.',
            'occurred_at' => now()->toDateTimeString(),
        ], $actor, 'pending-follow-up-event-0001');
        $second = app(RoutePendingCollectionCaseService::class)->addEvent($case, [
            'type' => 'promise',
            'note' => 'El cliente confirmó pago el viernes.',
            'occurred_at' => now()->toDateTimeString(),
        ], $actor, 'pending-follow-up-event-0001');
        $updated = app(RoutePendingCollectionCaseService::class)->updateAssignment(
            $case->fresh(),
            $assignee->id,
            now()->addDays(2),
            $actor,
            'pending-follow-up-assignment-0001',
        );

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertDatabaseCount('route_pending_collection_events', 1);
        $this->assertDatabaseHas('route_pending_collection_events', [
            'route_pending_collection_case_id' => $case->id,
            'type' => 'promise',
            'note' => 'El cliente confirmó pago el viernes.',
            'recorded_by' => $actor->id,
        ]);
        $this->assertSame($assignee->id, $updated->assigned_to);
        $this->assertNotNull($updated->next_follow_up_at);
    }

    public function test_late_transfer_collection_resolves_an_external_case_without_a_cash_session(): void
    {
        [$business, , $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        \App\Models\CashRegisterSession::query()->where('business_id', $business->id)->update(['status' => 'closed', 'closed_at' => now()]);

        $result = app(RoutePendingCollectionService::class)->collect($case, [
            'amount' => 60,
            'payment_method' => 'transfer',
            'reference' => 'TRX-0001',
        ], $actor, 'pending-late-transfer-0001');

        $this->assertFalse($result->replayed);
        $this->assertDatabaseHas('route_delivery_collections', [
            'sale_id' => $item->sale_id,
            'route_external_delivery_reconciliation_item_id' => $item->id,
            'payment_method' => 'transfer',
            'custody_status' => 'not_applicable',
            'recorded_by' => $actor->id,
            'collected_by' => $actor->id,
        ]);
        $this->assertDatabaseHas('sales', ['id' => $item->sale_id, 'payment_status' => 'paid', 'amount_paid' => 60]);
        $this->assertDatabaseHas('route_pending_collection_cases', [
            'id' => $case->id,
            'status' => 'resolved',
            'resolved_by' => $actor->id,
        ]);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    public function test_late_cash_outside_branch_is_held_and_becomes_eligible_for_settlement_without_an_open_cash_session(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        \App\Models\CashRegisterSession::query()->where('business_id', $business->id)->update(['status' => 'closed', 'closed_at' => now()]);

        $result = app(RoutePendingCollectionService::class)->collect($case, [
            'amount' => 60,
            'payment_method' => 'cash',
        ], $actor, 'pending-late-cash-held-0001');

        $this->assertFalse($result->replayed);
        $collection = \App\Models\RouteDeliveryCollection::query()->findOrFail($result->resultId);
        $this->assertSame('held_by_collector', $collection->custody_status);
        $this->assertSame('awaiting_physical_receipt', $collection->cash_posting_state);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseHas('sales', ['id' => $item->sale_id, 'payment_status' => 'paid', 'amount_paid' => 60]);
        $this->assertDatabaseHas('route_pending_collection_cases', ['id' => $case->id, 'status' => 'resolved']);
        $this->assertTrue(app(RouteCashSettlementEligibility::class)
            ->forCollector($business->id, $branch->id, $actor->id)
            ->pluck('collection_id')->contains($collection->id));
    }

    public function test_late_cash_received_now_posts_once_to_the_current_open_branch_session(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        TenantSetting::query()->where('business_id', $business->id)->update(['route_cash_custody_policy' => 'immediate_branch_register']);
        $session = \App\Models\CashRegisterSession::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('status', 'open')->firstOrFail();

        $result = app(RoutePendingCollectionService::class)->collect($case, [
            'amount' => 60,
            'payment_method' => 'cash',
            'receive_cash_in_current_session' => true,
        ], $actor, 'pending-late-cash-immediate-0001');

        $collection = \App\Models\RouteDeliveryCollection::query()->findOrFail($result->resultId);
        $this->assertSame('posted_to_branch_cash', $collection->custody_status);
        $this->assertSame($session->id, $collection->cash_register_session_id);
        $this->assertNotNull($collection->cash_movement_id);
        $this->assertDatabaseHas('cash_movements', ['id' => $collection->cash_movement_id, 'cash_register_session_id' => $session->id, 'reference_type' => 'route_delivery_collection', 'reference_id' => $collection->id, 'amount' => 60]);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_late_cash_physical_receipt_without_an_open_session_is_atomic(): void
    {
        [$business, , $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        TenantSetting::query()->where('business_id', $business->id)->update(['route_cash_custody_policy' => 'immediate_branch_register']);
        \App\Models\CashRegisterSession::query()->where('business_id', $business->id)->update(['status' => 'closed', 'closed_at' => now()]);

        try {
            app(RoutePendingCollectionService::class)->collect($case, [
                'amount' => 60,
                'payment_method' => 'cash',
                'receive_cash_in_current_session' => true,
            ], $actor, 'pending-late-cash-no-session-0001');
            $this->fail('The physical receipt must require an open current session.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $this->assertDatabaseHas('sales', ['id' => $item->sale_id, 'payment_status' => 'unpaid', 'amount_paid' => 0]);
        $this->assertDatabaseHas('route_pending_collection_cases', ['id' => $case->id, 'status' => 'open']);
        $this->assertDatabaseCount('route_delivery_collections', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_late_collection_override_requires_permission_and_reason_then_preserves_collector_recorder_and_historical_timestamp(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaid();
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignDirectPermissions($actor, [Permissions::ROUTES_PENDING_COLLECTIONS_COLLECT]);
        $otherCollector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $historical = now()->subDay()->startOfMinute();

        try {
            app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'cash', 'collected_by' => $otherCollector->id, 'collected_at' => $historical->toDateTimeString()], $actor, 'pending-override-denied-0001');
            $this->fail('A different collector and historical timestamp require the override permission.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
        }
        Permissions::assignDirectPermissions($actor, [Permissions::ROUTES_PENDING_COLLECTIONS_COLLECT, Permissions::ROUTES_DELIVERY_COLLECTIONS_OVERRIDE]);
        try {
            app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'cash', 'collected_by' => $otherCollector->id, 'collected_at' => $historical->toDateTimeString()], $actor, 'pending-override-no-reason-0001');
            $this->fail('An override reason is required.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $result = app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'cash', 'collected_by' => $otherCollector->id, 'collected_at' => $historical->toDateTimeString(), 'override_reason' => 'Cobro informado por el cobrador original.'], $actor, 'pending-override-ok-0001');
        $collection = \App\Models\RouteDeliveryCollection::query()->findOrFail($result->resultId);
        $this->assertSame($otherCollector->id, $collection->collected_by);
        $this->assertSame($actor->id, $collection->recorded_by);
        $this->assertSame('Cobro informado por el cobrador original.', $collection->override_reason);
        $this->assertSame($historical->format('Y-m-d H:i:s'), $collection->collected_at->format('Y-m-d H:i:s'));
        $this->assertSame('held_by_collector', $collection->custody_status);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_independent_competitor_cannot_create_a_second_late_collection_or_payment(): void
    {
        [$business, , $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $service = app(RoutePendingCollectionService::class);
        $first = $service->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'pending-competition-first-0001');

        try {
            $service->collect($case->fresh(), ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'pending-competition-second-0001');
            $this->fail('A distinct operation must not collect the same sale twice.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $this->assertDatabaseCount('route_delivery_collections', 1);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseHas('route_pending_collection_cases', ['id' => $case->id, 'status' => 'resolved', 'resolution_route_delivery_collection_id' => $first->resultId]);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    public function test_hybrid_queue_applies_origin_aging_assignee_and_search_filters_to_both_candidate_types(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaid();
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $assignee = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $case->update(['assigned_to' => $assignee->id]);
        $item->update(['reconciled_at' => now()->subDays(5)]);

        $queue = app(RoutePendingCollectionEligibility::class)->queue($business->id, $branch->id, [
            'origin' => 'external_reconciliation',
            'aging' => '4_7',
            'assigned_to' => $assignee->id,
            'search' => (string) $item->sale_id,
        ]);

        $this->assertCount(1, $queue);
        $this->assertSame($case->id, $queue->first()['case_id']);
        $this->assertSame('external_reconciliation', $queue->first()['origin']);
        $this->assertSame($assignee->id, $queue->first()['assigned_to']);
        $this->assertSame('4_7', $queue->first()['aging']);
        $this->assertCount(0, app(RoutePendingCollectionEligibility::class)->queue($business->id, $branch->id, ['origin' => 'in_app_stop']));
        $this->assertCount(0, app(RoutePendingCollectionEligibility::class)->queue($business->id, $branch->id, ['aging' => 'today']));
        $this->assertCount(0, app(RoutePendingCollectionEligibility::class)->queue($business->id, $branch->id, ['assigned_to' => $assignee->id + 999]));
        $this->assertCount(0, app(RoutePendingCollectionEligibility::class)->queue($business->id, $branch->id, ['search' => 'cliente inexistente']));
    }

    public function test_first_follow_up_on_a_derived_historical_sale_materializes_one_case_and_one_event_without_a_read_side_write(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $existing = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $existing->delete();

        $this->assertNull(RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->first());
        $this->assertNull(app(RoutePendingCollectionEligibility::class)->queue($business->id, $branch->id)->first()['case_id']);

        $result = app(RoutePendingCollectionCaseService::class)->addEventForEligibleSale($item->sale_id, [
            'type' => 'contact',
            'occurred_at' => now()->toDateTimeString(),
        ], $actor, 'pending-derived-event-0001');

        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $this->assertSame($case->id, $result->responsePayload['case_id']);
        $this->assertSame($item->reconciled_at?->format('Y-m-d H:i:s'), $case->opened_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('route_pending_collection_cases', 1);
        $this->assertDatabaseCount('route_pending_collection_events', 1);
        $replay = app(RoutePendingCollectionCaseService::class)->addEventForEligibleSale($item->sale_id, ['type' => 'contact', 'occurred_at' => now()->toDateTimeString()], $actor, 'pending-derived-event-0001');
        $this->assertTrue($replay->replayed);
        $this->assertDatabaseCount('route_pending_collection_events', 1);
    }

    public function test_first_late_collection_on_a_derived_historical_sale_materializes_and_resolves_one_case_in_one_operation(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->delete();

        $result = app(RoutePendingCollectionService::class)->collectForEligibleSale($item->sale_id, [
            'amount' => 60,
            'payment_method' => 'transfer',
        ], $actor, 'pending-derived-collect-0001');

        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $this->assertSame('resolved', $case->status);
        $this->assertSame($item->reconciled_at?->format('Y-m-d H:i:s'), $case->opened_at?->format('Y-m-d H:i:s'));
        $this->assertSame($result->resultId, $case->resolution_route_delivery_collection_id);
        $this->assertDatabaseCount('route_pending_collection_cases', 1);
        $this->assertDatabaseCount('route_delivery_collections', 1);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseCount('customer_account_movements', 0);
        $this->assertCount(0, app(RoutePendingCollectionEligibility::class)->queue($business->id, $branch->id));
    }

    public function test_integrity_auditor_reports_corrupt_pending_collection_case_origin_event_and_financial_fixtures(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaid();
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $otherBranch = \App\Models\Branch::query()->create(['business_id' => $business->id, 'name' => 'Auditor '.uniqid(), 'code' => 'AUD-'.uniqid(), 'is_active' => true]);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $item->sale_id)->update(['payment_status' => 'paid', 'amount_paid' => 60]);
        \Illuminate\Support\Facades\DB::table('route_external_delivery_reconciliation_items')->where('id', $item->id)->update(['collection_responsibility_snapshot' => 'pre_seller']);
        \App\Models\RoutePendingCollectionEvent::query()->create([
            'route_pending_collection_case_id' => $case->id,
            'business_id' => $business->id,
            'branch_id' => $otherBranch->id,
            'type' => 'contact',
            'occurred_at' => now(),
            'recorded_by' => $item->reconciled_by,
        ]);

        $issues = collect(app(\App\Support\SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'sales'])['results']['sales'])->pluck('issue_type');

        $this->assertTrue($issues->contains('pending_collection_case_financial_state_mismatch'));
        $this->assertTrue($issues->contains('pending_collection_external_origin_mismatch'));
        $this->assertTrue($issues->contains('pending_collection_event_scope_mismatch'));
        $this->assertTrue($issues->contains('pre_seller_delivered_unpaid_anomaly') === false, 'A paid corruption must not be relabeled as the pre_seller unpaid anomaly.');
    }

    public function test_integrity_auditor_reports_stale_not_applicable_and_a_delivered_pre_seller_unpaid_anomaly(): void
    {
        [$business, , $item] = $this->externalDeliveredUnpaid();
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $case->update([
            'status' => 'not_applicable',
            'not_applicable_at' => now(),
            'not_applicable_by' => $item->reconciled_by,
            'not_applicable_reason' => 'Fixture corrupto controlado.',
        ]);
        $item->update(['collection_responsibility_snapshot' => 'pre_seller']);

        $issues = collect(app(\App\Support\SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'sales'])['results']['sales'])->pluck('issue_type');

        $this->assertTrue($issues->contains('pending_collection_external_origin_mismatch'));
        $this->assertTrue($issues->contains('pre_seller_delivered_unpaid_anomaly'));
        $this->assertFalse($issues->contains('pending_collection_stale_not_applicable'), 'A pre_seller anomaly is not a stale delivery_agent pending case.');
    }

    public function test_integrity_auditor_reports_a_stale_not_applicable_case_when_delivery_agent_eligibility_is_restored(): void
    {
        [$business, , $item] = $this->externalDeliveredUnpaid();
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $case->update([
            'status' => 'not_applicable',
            'not_applicable_at' => now(),
            'not_applicable_by' => $item->reconciled_by,
            'not_applicable_reason' => 'Fixture corrupto controlado.',
        ]);

        $issues = collect(app(\App\Support\SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'sales'])['results']['sales'])->pluck('issue_type');

        $this->assertTrue($issues->contains('pending_collection_stale_not_applicable'));
    }

    public function test_physical_correction_cannot_bypass_terminal_non_delivery_cancellation(): void
    {
        [, , $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $corrections = app(RouteExternalDeliveryReconciliationCorrectionService::class);

        try {
            $corrections->correctDeliveryResult($item, [
                'delivery_status' => 'not_delivered',
                'not_delivered_reason' => 'customer_absent',
                'correction_reason' => 'El cliente confirmó que la entrega no ocurrió.',
            ], $actor);
            $this->fail('A simple correction must not create a terminal non-delivery result.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('correction', $exception->errors());
        }

        $this->assertDatabaseHas('route_external_delivery_reconciliation_items', ['id' => $item->id, 'delivery_status' => 'delivered']);
        $this->assertDatabaseHas('route_pending_collection_cases', ['id' => $case->id, 'status' => 'open']);
        $this->assertDatabaseCount('route_external_delivery_reconciliation_item_revisions', 0);
    }

    public function test_route_delivery_collection_reversal_of_late_transfer_leaves_no_cash_movement(): void
    {
        [$business, , $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $collection = \App\Models\RouteDeliveryCollection::query()->findOrFail(
            app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'reversal-transfer-capture-0001')->resultId
        );

        $result = app(\App\Services\Routes\RouteDeliveryCollectionReversalService::class)->reverse($collection, [
            'reason_code' => 'payment_recorded_by_mistake',
            'explanation' => 'El cliente no efectuó el pago registrado.',
            'confirmed' => true,
        ], $actor, 'reversal-transfer-0001');

        $reversal = \App\Models\RouteDeliveryCollectionReversal::query()->findOrFail($result->resultId);
        $this->assertSame('reversed', $collection->fresh()->status);
        $this->assertSame('reversed', $collection->salePayment->fresh()->status);
        $this->assertSame('unpaid', $collection->sale->fresh()->payment_status);
        $this->assertSame('none', $reversal->cash_correction_type);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseHas('route_pending_collection_cases', ['id' => $case->id, 'status' => 'open', 'resolved_by' => null, 'resolution_route_delivery_collection_id' => null]);
        $this->assertDatabaseHas('route_pending_collection_events', ['route_pending_collection_case_id' => $case->id, 'type' => 'collection_reversed', 'recorded_by' => $actor->id]);
    }

    public function test_route_delivery_collection_reversal_replays_one_ledger_and_one_case_event_for_the_same_key(): void
    {
        [, , $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $collection = \App\Models\RouteDeliveryCollection::query()->findOrFail(
            app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'reversal-replay-capture-0001')->resultId
        );
        $payload = ['reason_code' => 'payment_recorded_by_mistake', 'explanation' => 'Doble toque controlado.', 'confirmed' => true];
        $service = app(\App\Services\Routes\RouteDeliveryCollectionReversalService::class);
        $first = $service->reverse($collection, $payload, $actor, 'reversal-replay-0001');
        $second = $service->reverse($collection, $payload, $actor, 'reversal-replay-0001');

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->resultId, $second->resultId);
        $this->assertDatabaseCount('route_delivery_collection_reversals', 1);
        $this->assertSame(1, RoutePendingCollectionCase::query()->findOrFail($case->id)->events()->where('type', 'collection_reversed')->count());
    }

    public function test_route_delivery_collection_reversal_rejects_an_already_open_case(): void
    {
        [, , $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $collection = \App\Models\RouteDeliveryCollection::query()->findOrFail(
            app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'reversal-open-case-capture-0001')->resultId
        );
        $case->fresh()->update(['status' => 'open', 'resolved_by' => null, 'resolved_at' => null, 'resolution_route_delivery_collection_id' => null]);

        try {
            app(\App\Services\Routes\RouteDeliveryCollectionReversalService::class)->reverse($collection, [
                'reason_code' => 'payment_recorded_by_mistake', 'explanation' => 'Fixture corrupto controlado.', 'confirmed' => true,
            ], $actor, 'reversal-open-case-0001');
            $this->fail('An already open case must not be reopened through a collection reversal.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $this->assertSame('captured', $collection->fresh()->status);
        $this->assertDatabaseCount('route_delivery_collection_reversals', 0);
    }

    public function test_route_delivery_collection_reversal_chain_is_accepted_by_the_integrity_auditor(): void
    {
        [$business, , $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $collection = \App\Models\RouteDeliveryCollection::query()->findOrFail(
            app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'reversal-audit-capture-0001')->resultId
        );
        app(\App\Services\Routes\RouteDeliveryCollectionReversalService::class)->reverse($collection, [
            'reason_code' => 'payment_recorded_by_mistake', 'explanation' => 'Prueba de integridad.', 'confirmed' => true,
        ], $actor, 'reversal-audit-0001');

        $issues = collect(app(\App\Support\SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'sales'])['results']['sales'])->pluck('issue_type');

        $this->assertFalse($issues->contains(fn (string $issue) => str_starts_with($issue, 'delivery_collection_reversal_')));
        $this->assertFalse($issues->contains('reversed_delivery_collection_without_ledger'));
    }

    public function test_route_delivery_collection_reversal_allows_one_new_captured_collection_for_the_sale(): void
    {
        [, , $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $first = \App\Models\RouteDeliveryCollection::query()->findOrFail(
            app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'reversal-recapture-first-0001')->resultId
        );
        app(\App\Services\Routes\RouteDeliveryCollectionReversalService::class)->reverse($first, [
            'reason_code' => 'payment_recorded_by_mistake', 'explanation' => 'El primer cobro fue falso.', 'confirmed' => true,
        ], $actor, 'reversal-recapture-reverse-0001');

        $second = app(RoutePendingCollectionService::class)->collect($case->fresh(), ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'reversal-recapture-second-0001');

        $this->assertDatabaseCount('route_delivery_collections', 2);
        $this->assertDatabaseHas('route_delivery_collections', ['id' => $first->id, 'status' => 'reversed']);
        $this->assertDatabaseHas('route_delivery_collections', ['id' => $second->resultId, 'status' => 'captured']);
        $this->assertDatabaseHas('route_pending_collection_cases', ['id' => $case->id, 'status' => 'resolved', 'resolution_route_delivery_collection_id' => $second->resultId]);
    }

    public function test_route_delivery_collection_reversal_of_posted_cash_uses_one_negative_adjustment_in_the_open_original_session(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_cash_custody_policy' => 'immediate_branch_register']);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $collection = \App\Models\RouteDeliveryCollection::query()->findOrFail(
            app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'cash', 'receive_cash_in_current_session' => true], $actor, 'reversal-cash-capture-0001')->resultId
        );
        $session = \App\Models\CashRegisterSession::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('status', 'open')->firstOrFail();

        $result = app(\App\Services\Routes\RouteDeliveryCollectionReversalService::class)->reverse($collection, [
            'reason_code' => 'payment_recorded_by_mistake',
            'explanation' => 'El efectivo nunca fue cobrado.',
            'confirmed' => true,
            'confirm_cash_adjustment' => true,
        ], $actor, 'reversal-cash-open-0001');

        $reversal = \App\Models\RouteDeliveryCollectionReversal::query()->findOrFail($result->resultId);
        $this->assertSame('current_open_session_adjustment', $reversal->cash_correction_type);
        $this->assertSame(-60.0, (float) $reversal->compensatingCashMovement->amount);
        $this->assertSame($session->id, $reversal->compensatingCashMovement->cash_register_session_id);
        $this->assertSame(0.0, (float) $session->fresh()->expected_cash);
    }

    public function test_route_delivery_collection_reversal_of_closed_session_cash_uses_ledger_only(): void
    {
        [$business, $branch, $item] = $this->externalDeliveredUnpaid();
        $actor = User::query()->findOrFail($item->reconciled_by);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_cash_custody_policy' => 'immediate_branch_register']);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail();
        $collection = \App\Models\RouteDeliveryCollection::query()->findOrFail(
            app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'cash', 'receive_cash_in_current_session' => true], $actor, 'reversal-cash-closed-capture-0001')->resultId
        );
        $session = \App\Models\CashRegisterSession::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('status', 'open')->firstOrFail();
        $session->update(['status' => 'closed', 'closed_at' => now()]);
        $before = [
            'expected_cash' => $session->fresh()->expected_cash,
            'counted_cash' => $session->fresh()->counted_cash,
            'difference' => $session->fresh()->difference,
            'closed_at' => $session->fresh()->closed_at?->format('Y-m-d H:i:s'),
        ];

        $result = app(\App\Services\Routes\RouteDeliveryCollectionReversalService::class)->reverse($collection, [
            'reason_code' => 'payment_recorded_by_mistake',
            'explanation' => 'El efectivo no fue recibido en la visita.',
            'confirmed' => true,
        ], $actor, 'reversal-cash-closed-0001');

        $reversal = \App\Models\RouteDeliveryCollectionReversal::query()->findOrFail($result->resultId);
        $this->assertSame('historical_closed_session_ledger', $reversal->cash_correction_type);
        $this->assertNull($reversal->compensating_cash_movement_id);
        $this->assertSame($before, [
            'expected_cash' => $session->fresh()->expected_cash,
            'counted_cash' => $session->fresh()->counted_cash,
            'difference' => $session->fresh()->difference,
            'closed_at' => $session->fresh()->closed_at?->format('Y-m-d H:i:s'),
        ]);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    /** @return array{0: Business, 1: \App\Models\Branch, 2: \App\Models\RouteExternalDeliveryReconciliationItem} */
    private function externalDeliveredUnpaid(): array
    {
        [$business, $branch, $manager, $entry] = $this->routeEntry('external');
        $result = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'pending-eligibility-external-0001',
            'delivery_status' => 'delivered',
            'collected' => false,
        ], $manager);

        return [$business, $branch, \App\Models\RouteExternalDeliveryReconciliationItem::query()->findOrFail($result->resultId)];
    }

    /** @return array{0: Business, 1: \App\Models\Branch, 2: RouteDeliveryStop} */
    private function closedInAppDeliveredUnpaid(): array
    {
        [$business, $branch, $manager, $entry] = $this->routeEntry('in_app');
        $deliveryUser = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignRole($deliveryUser, 'delivery_agent');
        $run = app(RouteDeliveryRunAssignmentService::class)->createDraft($manager, $deliveryUser, [$entry->id]);
        app(RouteDeliveryRunService::class)->start($run, $deliveryUser, 'pending-eligibility-run-start-0001');
        $stop = $run->fresh()->stops()->firstOrFail();
        app(RouteDeliveryStopService::class)->complete($stop, [
            'delivery_status' => 'delivered',
            'collected' => false,
        ], $deliveryUser, 'pending-eligibility-stop-0001');
        app(RouteDeliveryRunService::class)->close($run->fresh(), $deliveryUser, 'pending-eligibility-run-close-0001', true);

        return [$business, $branch, $stop->fresh(['run'])];
    }

    /** @return array{0: Business, 1: \App\Models\Branch, 2: User, 3: RouteDeliveryBatchPreSale} */
    private function routeEntry(string $tracking): array
    {
        $business = Business::query()->create([
            'name' => 'Pending eligibility '.uniqid(),
            'slug' => 'pending-eligibility-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $manager = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignRole($manager, 'owner');
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_branches' => true,
            'allow_receipts' => true,
            'allow_invoices' => true,
            'route_collection_responsibility' => 'delivery_agent',
            'route_delivery_tracking' => $tracking,
        ]);
        foreach (['routes', 'cash_register'] as $module) {
            TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
        }
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $manager->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $manager->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'doc_type' => 'CF', 'doc_number' => 'CF'.uniqid(), 'country' => 'GT']);
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Producto '.uniqid(), 'code' => 'PCE-'.uniqid(), 'cost_price' => 10, 'sale_price' => 20, 'stock' => 10, 'min_stock' => 0, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10]);
        $preSale = PreSale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_work_day_id' => $workDay->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'seller_id' => $manager->id,
            'status' => PreSale::STATUS_PICKED,
            'subtotal' => 60,
            'discount_total' => 0,
            'total' => 60,
            'payment_method' => 'cash',
            'agreed_payment_method' => 'cash',
            'picked_at' => now(),
            'picked_by' => $manager->id,
        ]);
        $item = PreSaleItem::query()->create(['business_id' => $business->id, 'pre_sale_id' => $preSale->id, 'product_id' => $product->id, 'quantity' => 3, 'picked_quantity' => 3, 'unit_price' => 20, 'original_price' => 20, 'discount' => 0, 'total' => 60]);
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'source_type' => 'pre_sale', 'source_id' => $preSale->id, 'source_item_id' => $item->id, 'quantity' => 3, 'status' => 'active', 'created_by' => $manager->id]);
        \App\Models\CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $manager->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $delivery = app(RouteDeliveryBatchService::class)->deliverAll($workDay, $manager, 'pending-eligibility-deliver-'.uniqid());
        $entry = RouteDeliveryBatchPreSale::query()->with(['batch', 'sale'])->where('route_delivery_batch_id', $delivery->resultId)->firstOrFail();

        return [$business, $branch, $manager, $entry];
    }
}
