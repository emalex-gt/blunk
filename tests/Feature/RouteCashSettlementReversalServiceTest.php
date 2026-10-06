<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\RouteCashSettlement;
use App\Models\RouteCashSettlementReversal;
use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteDeliveryCollection;
use App\Models\RouteDeliveryRun;
use App\Models\RouteDeliveryStop;
use App\Models\RoutePostConversionCollection;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\Sale;
use App\Models\RoutePreSaleCollection;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteCashSettlementDraftService;
use App\Services\Routes\RouteCashSettlementReversalService;
use App\Services\Routes\RouteCashSettlementService;
use App\Services\Routes\RoutePostConversionCollectionService;
use App\Services\Routes\RouteBranchCollectionSettingsService;
use App\Support\BranchInventory;
use App\Support\IdempotencyConflictException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RouteCashSettlementReversalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_confirmed_settlement_reverses_with_one_current_session_adjustment(): void
    {
        [$settlement, $actor, $collection, $session] = $this->confirmedSettlement();
        $originalMovement = $settlement->cash_movement_id;

        $result = app(RouteCashSettlementReversalService::class)->reverse($settlement, [
            'reason_code' => 'cash_not_received_by_branch',
            'explanation' => 'El efectivo nunca fue recibido en la sucursal.',
            'confirm_cash_adjustment' => true,
        ], $actor, 'settlement-reversal-current-0001');

        $this->assertFalse($result->replayed);
        $this->assertSame('reversed', $settlement->fresh()->status);
        $this->assertSame($originalMovement, $settlement->fresh()->cash_movement_id);
        $this->assertSame('held_by_collector', $collection->fresh()->custody_status);
        $this->assertDatabaseHas('route_cash_settlement_reversals', [
            'id' => $result->resultId,
            'route_cash_settlement_id' => $settlement->id,
            'cash_correction_type' => 'current_session_adjustment',
        ]);
        $this->assertDatabaseHas('cash_movements', [
            'cash_register_session_id' => $session->id,
            'type' => 'route_cash_settlement_reversal_current_session',
            'amount' => -45,
            'reference_type' => 'route_cash_settlement_reversal',
            'reference_id' => $result->resultId,
        ]);
        $this->assertDatabaseHas('route_cash_settlement_items', [
            'route_cash_settlement_id' => $settlement->id,
            'is_active' => false,
            'reversed_by_route_cash_settlement_reversal_id' => $result->resultId,
        ]);
    }

    public function test_open_session_adjustment_requires_explicit_confirmation_without_mutating_state(): void
    {
        [$settlement, $actor, $collection] = $this->confirmedSettlement();

        try {
            app(RouteCashSettlementReversalService::class)->reverse($settlement, [
                'reason_code' => 'cash_not_received_by_branch',
                'explanation' => 'Sin confirmación de ajuste.',
            ], $actor, 'settlement-reversal-confirmation-0001');
            $this->fail('Expected explicit cash-adjustment confirmation to be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirm_cash_adjustment', $exception->errors());
        }

        $this->assertSame('confirmed', $settlement->fresh()->status);
        $this->assertSame('posted_to_branch_cash', $collection->fresh()->custody_status);
        $this->assertDatabaseCount('route_cash_settlement_reversals', 0);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_closed_original_session_creates_ledger_only_and_no_new_cash_movement(): void
    {
        [$settlement, $actor, $collection, $session] = $this->confirmedSettlement();
        $session->update(['status' => 'closed', 'closed_at' => now()]);
        $newSession = CashRegisterSession::query()->create([
            'business_id' => $settlement->business_id,
            'branch_id' => $settlement->branch_id,
            'opened_by' => $actor->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);

        $result = app(RouteCashSettlementReversalService::class)->reverse($settlement, [
            'reason_code' => 'cash_not_received_by_branch',
            'explanation' => 'La caja original ya fue cerrada.',
        ], $actor, 'settlement-reversal-closed-0001');

        $this->assertSame('reversed', $settlement->fresh()->status);
        $this->assertSame('held_by_collector', $collection->fresh()->custody_status);
        $this->assertDatabaseHas('route_cash_settlement_reversals', ['id' => $result->resultId, 'cash_correction_type' => 'ledger_only', 'compensating_cash_movement_id' => null]);
        $this->assertSame(1, CashMovement::query()->count());
        $this->assertSame(0, CashMovement::query()->where('cash_register_session_id', $newSession->id)->count());
    }

    public function test_same_idempotency_key_replays_without_a_second_reversal_or_movement(): void
    {
        [$settlement, $actor] = $this->confirmedSettlement();
        $data = ['reason_code' => 'cash_not_received_by_branch', 'explanation' => 'Reversa repetida segura.', 'confirm_cash_adjustment' => true];
        $service = app(RouteCashSettlementReversalService::class);
        $first = $service->reverse($settlement, $data, $actor, 'settlement-reversal-replay-0001');
        $replay = $service->reverse($settlement->fresh(), $data, $actor, 'settlement-reversal-replay-0001');

        $this->assertFalse($first->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->resultId, $replay->resultId);
        $this->assertDatabaseCount('route_cash_settlement_reversals', 1);
        $this->assertSame(2, CashMovement::query()->count());
    }

    public function test_different_key_after_reversal_cannot_create_a_second_reversal(): void
    {
        [$settlement, $actor] = $this->confirmedSettlement();
        $service = app(RouteCashSettlementReversalService::class);
        $data = ['reason_code' => 'cash_not_received_by_branch', 'explanation' => 'Primera reversa.', 'confirm_cash_adjustment' => true];
        $service->reverse($settlement, $data, $actor, 'settlement-reversal-first-0001');

        try {
            $service->reverse($settlement->fresh(), [...$data, 'explanation' => 'Intento posterior.'], $actor, 'settlement-reversal-second-0001');
            $this->fail('Expected a second key after reversal to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement', $exception->errors());
        }

        $this->assertDatabaseCount('route_cash_settlement_reversals', 1);
        $this->assertSame(2, CashMovement::query()->count());
    }

    public function test_same_idempotency_key_with_a_different_request_conflicts(): void
    {
        [$settlement, $actor] = $this->confirmedSettlement();
        $service = app(RouteCashSettlementReversalService::class);
        $service->reverse($settlement, [
            'reason_code' => 'cash_not_received_by_branch',
            'explanation' => 'Explicación original.',
            'confirm_cash_adjustment' => true,
        ], $actor, 'settlement-reversal-conflict-0001');

        $this->expectException(IdempotencyConflictException::class);
        $service->reverse($settlement->fresh(), [
            'reason_code' => 'cash_not_received_by_branch',
            'explanation' => 'Explicación diferente.',
            'confirm_cash_adjustment' => true,
        ], $actor, 'settlement-reversal-conflict-0001');
    }

    public function test_non_exact_confirmed_settlement_is_rejected_before_any_reversal_mutation(): void
    {
        [$settlement, $actor, $collection] = $this->confirmedSettlement();
        $settlement->update(['expected_amount' => 44, 'difference_amount' => 1]);

        try {
            app(RouteCashSettlementReversalService::class)->reverse($settlement->fresh(), [
                'reason_code' => 'cash_not_received_by_branch',
                'explanation' => 'La liquidación tuvo diferencia.',
                'confirm_cash_adjustment' => true,
            ], $actor, 'settlement-reversal-non-exact-0001');
            $this->fail('Expected non-exact settlement to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement', $exception->errors());
        }

        $this->assertSame('confirmed', $settlement->fresh()->status);
        $this->assertSame('posted_to_branch_cash', $collection->fresh()->custody_status);
        $this->assertDatabaseCount('route_cash_settlement_reversals', 0);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_reversal_releases_a_pre_sale_collection_for_a_future_settlement(): void
    {
        [$settlement, $actor, $collection] = $this->confirmedSettlement();
        app(RouteCashSettlementReversalService::class)->reverse($settlement, [
            'reason_code' => 'cash_not_received_by_branch',
            'explanation' => 'El efectivo permanece con el cobrador.',
            'confirm_cash_adjustment' => true,
        ], $actor, 'settlement-reversal-release-0001');

        $replacement = app(RouteCashSettlementDraftService::class)->create(
            $actor,
            $collection->collected_by,
            [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]],
            'settlement-reversal-release-draft-0001',
        );

        $this->assertDatabaseHas('route_cash_settlement_items', [
            'route_cash_settlement_id' => $replacement->resultId,
            'route_pre_sale_collection_id' => $collection->id,
            'is_active' => true,
        ]);
    }

    public function test_open_original_session_that_is_no_longer_current_is_rejected_without_a_cash_adjustment(): void
    {
        [$settlement, $actor, $collection, $originalSession] = $this->confirmedSettlement();
        $newSession = CashRegisterSession::query()->create([
            'business_id' => $settlement->business_id,
            'branch_id' => $settlement->branch_id,
            'opened_by' => $actor->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now()->addSecond(),
        ]);

        try {
            app(RouteCashSettlementReversalService::class)->reverse($settlement, [
                'reason_code' => 'cash_not_received_by_branch',
                'explanation' => 'La caja original dejó de ser la caja actual.',
                'confirm_cash_adjustment' => true,
            ], $actor, 'settlement-reversal-non-current-0001');
            $this->fail('Expected an open but non-current original session to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement', $exception->errors());
        }

        $this->assertSame('confirmed', $settlement->fresh()->status);
        $this->assertSame('posted_to_branch_cash', $collection->fresh()->custody_status);
        $this->assertSame(1, CashMovement::query()->count());
        $this->assertSame(0, CashMovement::query()->where('cash_register_session_id', $newSession->id)->count());
        $this->assertSame('open', $originalSession->fresh()->status);
    }

    public function test_ledger_failure_rolls_back_cash_adjustment_and_a_retry_can_succeed(): void
    {
        [$settlement, $actor, $collection] = $this->confirmedSettlement();
        RouteCashSettlementReversal::creating(function (): void {
            throw new \RuntimeException('Injected reversal ledger failure.');
        });

        try {
            app(RouteCashSettlementReversalService::class)->reverse($settlement, [
                'reason_code' => 'cash_not_received_by_branch',
                'explanation' => 'Fallo transaccional controlado.',
                'confirm_cash_adjustment' => true,
            ], $actor, 'settlement-reversal-ledger-failure-0001');
            $this->fail('Expected the injected ledger failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected reversal ledger failure.', $exception->getMessage());
        } finally {
            RouteCashSettlementReversal::flushEventListeners();
        }

        $this->assertSame('confirmed', $settlement->fresh()->status);
        $this->assertSame('posted_to_branch_cash', $collection->fresh()->custody_status);
        $this->assertDatabaseCount('route_cash_settlement_reversals', 0);
        $this->assertDatabaseCount('cash_movements', 1);

        $result = app(RouteCashSettlementReversalService::class)->reverse($settlement->fresh(), [
            'reason_code' => 'cash_not_received_by_branch',
            'explanation' => 'Fallo transaccional controlado.',
            'confirm_cash_adjustment' => true,
        ], $actor, 'settlement-reversal-ledger-failure-0001');

        $this->assertFalse($result->replayed);
        $this->assertSame('reversed', $settlement->fresh()->status);
        $this->assertDatabaseCount('route_cash_settlement_reversals', 1);
        $this->assertDatabaseCount('cash_movements', 2);
    }

    public function test_cash_movement_failure_rolls_back_and_a_retry_can_succeed(): void
    {
        [$settlement, $actor, $collection] = $this->confirmedSettlement();
        CashMovement::creating(function (CashMovement $movement): void {
            if ($movement->type === 'route_cash_settlement_reversal_current_session') {
                throw new \RuntimeException('Injected cash movement failure.');
            }
        });

        try {
            app(RouteCashSettlementReversalService::class)->reverse($settlement, [
                'reason_code' => 'cash_not_received_by_branch',
                'explanation' => 'Fallo de caja controlado.',
                'confirm_cash_adjustment' => true,
            ], $actor, 'settlement-reversal-movement-failure-0001');
            $this->fail('Expected the injected cash movement failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected cash movement failure.', $exception->getMessage());
        } finally {
            CashMovement::flushEventListeners();
        }

        $this->assertSame('confirmed', $settlement->fresh()->status);
        $this->assertSame('posted_to_branch_cash', $collection->fresh()->custody_status);
        $this->assertDatabaseCount('route_cash_settlement_reversals', 0);
        $this->assertDatabaseCount('cash_movements', 1);

        $result = app(RouteCashSettlementReversalService::class)->reverse($settlement->fresh(), [
            'reason_code' => 'cash_not_received_by_branch',
            'explanation' => 'Fallo de caja controlado.',
            'confirm_cash_adjustment' => true,
        ], $actor, 'settlement-reversal-movement-failure-0001');
        $this->assertFalse($result->replayed);
        $this->assertSame('reversed', $settlement->fresh()->status);
    }

    public function test_post_conversion_cash_returns_to_collector_custody_without_changing_the_sale_or_payment(): void
    {
        [$settlement, $actor, $collection, $sale, $entry] = $this->confirmedPostConversionSettlement();
        $payment = $sale->payments()->sole();
        $stockMovementCount = \App\Models\StockMovement::query()->count();

        app(RouteCashSettlementReversalService::class)->reverse($settlement, [
            'reason_code' => 'cash_not_received_by_branch',
            'explanation' => 'La sucursal no recibió el efectivo liquidado.',
            'confirm_cash_adjustment' => true,
        ], $actor, 'settlement-reversal-post-0001');

        $this->assertSame('reversed', $settlement->fresh()->status);
        $this->assertSame('held_by_collector', $collection->fresh()->custody_status);
        $this->assertSame('awaiting_physical_receipt', $collection->fresh()->cash_posting_state);
        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertSame($payment->id, $sale->fresh()->payments()->sole()->id);
        $this->assertSame('cash', $entry->fresh()->payment_method);
        $this->assertSame($stockMovementCount, \App\Models\StockMovement::query()->count());
        $this->assertDatabaseHas('route_cash_settlement_items', [
            'route_cash_settlement_id' => $settlement->id,
            'route_post_conversion_collection_id' => $collection->id,
            'is_active' => false,
        ]);

        $replacement = app(RouteCashSettlementDraftService::class)->create(
            $actor,
            $collection->collected_by,
            [['origin' => 'post_conversion_collection', 'collection_id' => $collection->id]],
            'settlement-reversal-post-release-0001',
        );
        $this->assertDatabaseHas('route_cash_settlement_items', [
            'route_cash_settlement_id' => $replacement->resultId,
            'route_post_conversion_collection_id' => $collection->id,
            'is_active' => true,
        ]);
    }

    public function test_delivery_cash_returns_to_collector_custody_and_awaiting_physical_receipt(): void
    {
        [$settlement, $actor, $collection] = $this->confirmedDeliverySettlement();

        app(RouteCashSettlementReversalService::class)->reverse($settlement, [
            'reason_code' => 'cash_not_received_by_branch',
            'explanation' => 'El efectivo de entrega no llegó a la sucursal.',
            'confirm_cash_adjustment' => true,
        ], $actor, 'settlement-reversal-delivery-0001');

        $this->assertSame('reversed', $settlement->fresh()->status);
        $this->assertSame('captured', $collection->fresh()->status);
        $this->assertSame('held_by_collector', $collection->fresh()->custody_status);
        $this->assertSame('awaiting_physical_receipt', $collection->fresh()->cash_posting_state);
        $this->assertDatabaseHas('route_cash_settlement_items', [
            'route_cash_settlement_id' => $settlement->id,
            'route_delivery_collection_id' => $collection->id,
            'is_active' => false,
        ]);

        $replacement = app(RouteCashSettlementDraftService::class)->create(
            $actor,
            $collection->collected_by,
            [['origin' => 'delivery_collection', 'collection_id' => $collection->id]],
            'settlement-reversal-delivery-release-0001',
        );
        $this->assertDatabaseHas('route_cash_settlement_items', [
            'route_cash_settlement_id' => $replacement->resultId,
            'route_delivery_collection_id' => $collection->id,
            'is_active' => true,
        ]);
    }

    /** @return array{0: RouteCashSettlement, 1: User, 2: RoutePreSaleCollection, 3: CashRegisterSession} */
    private function confirmedSettlement(): array
    {
        $business = Business::query()->create(['name' => 'B2a service '.uniqid(), 'slug' => 'b2a-service-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $collector = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $session = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'seller_id' => $collector->id, 'status' => PreSale::STATUS_DRAFT, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45]);
        $collection = RoutePreSaleCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'pre_sale_id' => $preSale->id, 'collected_by' => $collector->id, 'recorded_by' => $collector->id, 'amount' => 45, 'payment_method' => 'cash', 'collected_at' => now(), 'status' => 'captured', 'custody_status' => 'held_by_collector']);
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $collector->id, [['origin' => 'pre_sale_collection', 'collection_id' => $collection->id]], 'b2a-service-draft-'.uniqid());
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 45, null, 'b2a-service-confirm-'.uniqid());

        return [RouteCashSettlement::query()->findOrFail($settlement->id), $actor, $collection, $session];
    }

    /** @return array{0: RouteCashSettlement, 1: User, 2: RoutePostConversionCollection, 3: Sale, 4: RouteDeliveryBatchPreSale} */
    private function confirmedPostConversionSettlement(): array
    {
        $business = Business::query()->create(['name' => 'B2a post '.uniqid(), 'slug' => 'b2a-post-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'route_collection_responsibility' => 'pre_seller', 'route_cash_custody_policy' => 'collector_custody_until_settlement']);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $actor->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $actor->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $actor->id, 'status' => PreSale::STATUS_CONVERTED, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45, 'payment_method' => 'cash', 'agreed_payment_method' => 'cash', 'converted_at' => now(), 'converted_by' => $actor->id]);
        $sale = Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'total' => 45, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'credit_balance' => 0, 'is_credit_sale' => false, 'document_type' => 'receipt', 'created_by' => $actor->id]);
        $preSale->update(['converted_sale_id' => $sale->id]);
        $batch = RouteDeliveryBatch::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'delivered_by' => $actor->id, 'status' => RouteDeliveryBatch::STATUS_COMPLETED, 'stock_deduction_timing' => 'invoice', 'invoicing_mode' => 'manual', 'fel_automation_enabled' => false, 'delivery_tracking_snapshot' => 'external', 'collection_responsibility_snapshot' => 'pre_seller', 'collection_workflow_mode_snapshot' => 'per_order_collection', 'allowed_payment_methods_snapshot' => ['cash'], 'primary_payment_method_snapshot' => 'cash', 'operation_settings_snapshotted_at' => now(), 'delivered_at' => now(), 'total_pre_sales' => 1, 'total_items' => 0, 'total_amount' => 45]);
        $entry = RouteDeliveryBatchPreSale::query()->create(['route_delivery_batch_id' => $batch->id, 'pre_sale_id' => $preSale->id, 'sale_id' => $sale->id, 'status' => 'delivered', 'payment_method' => 'cash', 'agreed_payment_method_snapshot' => 'cash', 'fel_dispatch_status' => 'not_requested']);
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, ['collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash'], 'primary_payment_method' => 'cash']);
        $captured = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $actor, 'b2a-post-capture-'.uniqid());
        $collection = RoutePostConversionCollection::query()->findOrFail($captured->resultId);
        $session = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $actor->id, [['origin' => 'post_conversion_collection', 'collection_id' => $collection->id]], 'b2a-post-draft-'.uniqid());
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 45, null, 'b2a-post-confirm-'.uniqid());

        return [RouteCashSettlement::query()->findOrFail($settlement->id), $actor, $collection, $sale, $entry];
    }

    /** @return array{0: RouteCashSettlement, 1: User, 2: RouteDeliveryCollection} */
    private function confirmedDeliverySettlement(): array
    {
        $business = Business::query()->create(['name' => 'B2a delivery '.uniqid(), 'slug' => 'b2a-delivery-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $actor->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $actor->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $actor->id, 'status' => PreSale::STATUS_CONVERTED, 'subtotal' => 45, 'discount_total' => 0, 'total' => 45]);
        $sale = Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'total' => 45, 'payment_method' => 'cash', 'payment_status' => 'paid', 'amount_paid' => 45, 'credit_balance' => 0, 'is_credit_sale' => false, 'created_by' => $actor->id]);
        $batch = RouteDeliveryBatch::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'delivered_by' => $actor->id, 'delivered_at' => now(), 'status' => 'delivered', 'delivery_tracking_snapshot' => 'in_app', 'collection_responsibility_snapshot' => 'delivery_agent']);
        $entry = RouteDeliveryBatchPreSale::query()->create(['route_delivery_batch_id' => $batch->id, 'pre_sale_id' => $preSale->id, 'sale_id' => $sale->id, 'status' => 'delivered', 'payment_method' => 'cash']);
        $run = RouteDeliveryRun::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'delivery_user_id' => $actor->id, 'created_by' => $actor->id, 'status' => 'draft', 'delivery_tracking_snapshot' => 'in_app', 'collection_responsibility_snapshot' => 'delivery_agent']);
        $stop = RouteDeliveryStop::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_delivery_run_id' => $run->id, 'route_delivery_batch_id' => $batch->id, 'route_delivery_batch_pre_sale_id' => $entry->id, 'pre_sale_id' => $preSale->id, 'sale_id' => $sale->id, 'customer_id' => $customer->id, 'position' => 1, 'delivery_tracking_snapshot' => 'in_app', 'collection_responsibility_snapshot' => 'delivery_agent', 'status' => 'pending', 'assigned_by' => $actor->id, 'assigned_at' => now()]);
        $collection = RouteDeliveryCollection::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'sale_id' => $sale->id, 'pre_sale_id' => $preSale->id, 'route_delivery_stop_id' => $stop->id, 'delivery_origin' => 'in_app_stop', 'collected_by' => $actor->id, 'recorded_by' => $actor->id, 'amount' => 45, 'payment_method' => 'cash', 'collected_at' => now(), 'cash_custody_policy_snapshot' => 'collector_custody_until_settlement', 'custody_status' => 'held_by_collector', 'cash_posting_state' => 'awaiting_physical_receipt']);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $draft = app(RouteCashSettlementDraftService::class)->create($actor, $actor->id, [['origin' => 'delivery_collection', 'collection_id' => $collection->id]], 'b2a-delivery-draft-'.uniqid());
        $settlement = RouteCashSettlement::query()->findOrFail($draft->resultId);
        app(RouteCashSettlementService::class)->confirm($settlement, $actor, $actor->id, 45, null, 'b2a-delivery-confirm-'.uniqid());

        return [RouteCashSettlement::query()->findOrFail($settlement->id), $actor, $collection];
    }
}
