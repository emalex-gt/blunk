<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CashRegisterSession;
use App\Models\PreSale;
use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\Sale;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteBranchCollectionSettingsService;
use App\Services\Routes\RoutePostConversionCollectionService;
use App\Services\Routes\RoutePostConversionCollectionReversalService;
use App\Services\Routes\RouteCashSettlementDraftService;
use App\Services\Routes\RouteCashSettlementEligibility;
use App\Services\Routes\RouteCashSettlementService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RoutePostConversionCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_collects_a_full_post_conversion_pre_seller_sale_with_the_live_real_method(): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection',
            'allowed_payment_methods' => ['transfer', 'cash'],
            'primary_payment_method' => 'transfer',
        ]);

        $result = app(RoutePostConversionCollectionService::class)->collect($entry, [
            'payment_method' => 'transfer',
            'reference' => 'TRX-12347',
            'collected_at' => now()->toDateTimeString(),
        ], $seller, 'post-conversion-collect-transfer-0001');

        $collection = \App\Models\RoutePostConversionCollection::query()->findOrFail($result->resultId);
        $payment = $sale->fresh()->payments()->sole();

        $this->assertSame('captured', $collection->status);
        $this->assertSame('transfer', $collection->payment_method);
        $this->assertSame(123.47, (float) $collection->amount);
        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertSame(123.47, (float) $sale->fresh()->amount_paid);
        $this->assertSame(0.0, (float) $sale->fresh()->credit_balance);
        $this->assertFalse((bool) $sale->fresh()->is_credit_sale);
        $this->assertSame('transfer', $sale->fresh()->payment_method);
        $this->assertSame('transfer', $payment->method);
        $this->assertSame(123.47, (float) $payment->amount);
        $this->assertSame($collection->id, (int) $payment->route_post_conversion_collection_id);
        $this->assertSame('cash', $entry->fresh()->agreed_payment_method_snapshot);
        $this->assertSame('transfer', $entry->fresh()->payment_method);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
        $this->assertDatabaseCount('route_pending_collection_cases', 0);
    }

    public function test_reverses_a_post_conversion_collection_and_restores_the_agreed_method(): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash'], 'primary_payment_method' => 'cash',
        ]);
        $captured = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'post-conversion-cash-0001');
        $collection = \App\Models\RoutePostConversionCollection::query()->findOrFail($captured->resultId);

        $result = app(RoutePostConversionCollectionReversalService::class)->reverse($collection, [
            'reason_code' => 'wrong_amount', 'explanation' => 'Corrección administrativa.', 'confirmed' => true,
        ], $seller, 'post-conversion-cash-reversal-0001');

        $this->assertDatabaseHas('route_post_conversion_collection_reversals', ['id' => $result->resultId, 'route_post_conversion_collection_id' => $collection->id]);
        $this->assertSame('reversed', $collection->fresh()->status);
        $this->assertSame('reversed', $sale->fresh()->payments()->sole()->status);
        $this->assertSame('unpaid', $sale->fresh()->payment_status);
        $this->assertSame('0.00', $sale->fresh()->amount_paid);
        $this->assertSame('cash', $entry->fresh()->payment_method);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_held_cash_is_the_explicit_third_settlement_source(): void
    {
        [$business, $branch, $seller, $entry] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash'], 'primary_payment_method' => 'cash',
        ]);
        $result = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'post-conversion-held-0001');
        $collection = \App\Models\RoutePostConversionCollection::query()->findOrFail($result->resultId);

        $eligible = app(RouteCashSettlementEligibility::class)->forCollector($business->id, $branch->id, $seller->id);
        $this->assertSame('route_post_conversion_collection', $eligible->sole()['origin']);
        $this->assertDatabaseCount('cash_movements', 0);
        $draft = app(RouteCashSettlementDraftService::class)->create($seller, $seller->id, [
            ['origin' => 'route_post_conversion_collection', 'collection_id' => $collection->id],
        ], 'post-conversion-held-settlement-0001');
        $this->assertDatabaseHas('route_cash_settlement_items', [
            'route_cash_settlement_id' => $draft->resultId, 'route_post_conversion_collection_id' => $collection->id, 'is_active' => true,
        ]);
    }

    public function test_immediate_register_cash_posts_once_and_the_settlement_confirms_held_cash_without_a_second_payment(): void
    {
        [$business, $branch, $seller, $entry] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash'], 'primary_payment_method' => 'cash',
        ]);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $seller->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_cash_custody_policy' => 'immediate_branch_register']);
        $immediate = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'post-conversion-immediate-0001');
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame('posted_to_branch_cash', \App\Models\RoutePostConversionCollection::query()->findOrFail($immediate->resultId)->custody_status);

        [$heldBusiness, $heldBranch, $heldSeller, $heldEntry] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($heldBusiness->id, $heldBranch, [
            'collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash'], 'primary_payment_method' => 'cash',
        ]);
        $held = app(RoutePostConversionCollectionService::class)->collect($heldEntry, ['payment_method' => 'cash'], $heldSeller, 'post-conversion-confirm-held-0001');
        $heldCollection = \App\Models\RoutePostConversionCollection::query()->findOrFail($held->resultId);
        $session = CashRegisterSession::query()->create(['business_id' => $heldBusiness->id, 'branch_id' => $heldBranch->id, 'opened_by' => $heldSeller->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $draft = app(RouteCashSettlementDraftService::class)->create($heldSeller, $heldSeller->id, [['origin' => 'post_conversion_collection', 'collection_id' => $heldCollection->id]], 'post-conversion-confirm-draft-0001');
        app(RouteCashSettlementService::class)->confirm(\App\Models\RouteCashSettlement::query()->findOrFail($draft->resultId), $heldSeller, $heldSeller->id, '123.47', null, 'post-conversion-confirm-0001');
        $this->assertSame('posted_to_branch_cash', $heldCollection->fresh()->custody_status);
        $this->assertSame(2, \App\Models\CashMovement::query()->count());
        $this->assertSame(1, $heldCollection->fresh()->sale->payments()->captured()->count());
        $this->assertDatabaseHas('cash_movements', ['cash_register_session_id' => $session->id, 'type' => 'route_cash_settlement']);
    }

    public function test_missing_live_branch_policy_fails_closed_without_a_financial_trace(): void
    {
        [, , $seller, $entry] = $this->unpaidPreSellerEntry('cash');
        try {
            app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'post-conversion-no-policy-0001');
            $this->fail('Expected the missing live policy to block collection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('route_collection_policy_unavailable', $exception->errors());
        }
        $this->assertDatabaseCount('route_post_conversion_collections', 0);
        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_same_collection_key_replays_without_a_second_trace_or_payment(): void
    {
        [$business, $branch, $seller, $entry] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash'], 'primary_payment_method' => 'cash',
        ]);
        $first = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'post-conversion-replay-0001');
        $second = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'post-conversion-replay-0001');
        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->resultId, $second->resultId);
        $this->assertDatabaseCount('route_post_conversion_collections', 1);
        $this->assertDatabaseCount('sale_payments', 1);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonCashMethods')]
    public function test_non_cash_methods_create_one_paid_sale_payment_without_cash_movement(string $method): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => [$method], 'primary_payment_method' => $method,
        ]);
        app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => $method], $seller, "post-conversion-{$method}-0001");
        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertSame($method, $sale->fresh()->payments()->sole()->method);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public static function nonCashMethods(): array
    {
        return [['card'], ['transfer'], ['check']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonCashMethods')]
    public function test_non_cash_reversal_restores_unpaid_state_without_cash_compensation(string $method): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, [
            'collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => [$method], 'primary_payment_method' => $method,
        ]);
        $captured = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => $method], $seller, "post-reverse-{$method}-capture-0001");
        $collection = \App\Models\RoutePostConversionCollection::query()->findOrFail($captured->resultId);
        app(RoutePostConversionCollectionReversalService::class)->reverse($collection, ['reason_code' => 'wrong_amount', 'explanation' => 'Corrección.', 'confirmed' => true], $seller, "post-reverse-{$method}-0001");
        $this->assertSame('reversed', $collection->fresh()->status);
        $this->assertSame('reversed', $sale->fresh()->payments()->sole()->status);
        $this->assertSame('unpaid', $sale->fresh()->payment_status);
        $this->assertSame('0.00', $sale->fresh()->amount_paid);
        $this->assertSame('0.00', $sale->fresh()->credit_balance);
        $this->assertFalse((bool) $sale->fresh()->is_credit_sale);
        $this->assertSame('cash', $entry->fresh()->payment_method);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    public function test_recollection_uses_live_policy_and_leaves_the_agreed_snapshot_immutable(): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->unpaidPreSellerEntry('cash');
        $settings = app(RouteBranchCollectionSettingsService::class);
        $settings->save($business->id, $branch, ['collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash', 'transfer'], 'primary_payment_method' => 'cash']);
        $first = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'post-recollect-cash-0001');
        app(RoutePostConversionCollectionReversalService::class)->reverse(\App\Models\RoutePostConversionCollection::query()->findOrFail($first->resultId), ['reason_code' => 'wrong_amount', 'explanation' => 'Corrección.', 'confirmed' => true], $seller, 'post-recollect-reverse-0001');
        $settings->save($business->id, $branch, ['collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['transfer'], 'primary_payment_method' => 'transfer']);
        try {
            app(RoutePostConversionCollectionService::class)->collect($entry->fresh(), ['payment_method' => 'cash'], $seller, 'post-recollect-disallowed-0001');
            $this->fail('Expected the new live policy to reject cash.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_method', $exception->errors());
        }
        $second = app(RoutePostConversionCollectionService::class)->collect($entry->fresh(), ['payment_method' => 'transfer'], $seller, 'post-recollect-transfer-0001');
        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertSame('transfer', $sale->fresh()->payment_method);
        $this->assertSame('transfer', $entry->fresh()->payment_method);
        $this->assertSame('cash', $entry->fresh()->agreed_payment_method_snapshot);
        $this->assertSame(2, \App\Models\RoutePostConversionCollection::query()->count());
        $this->assertSame(1, \App\Models\RoutePostConversionCollection::query()->where('status', 'captured')->count());
        $this->assertSame(2, $sale->fresh()->payments()->count());
        $this->assertSame($second->resultId, \App\Models\RoutePostConversionCollection::query()->captured()->value('id'));
    }

    public function test_held_cash_reversal_is_blocked_by_draft_and_confirmed_settlements(): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, ['collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash'], 'primary_payment_method' => 'cash']);
        $captured = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'post-settlement-guard-capture-0001');
        $collection = \App\Models\RoutePostConversionCollection::query()->findOrFail($captured->resultId);
        $draft = app(RouteCashSettlementDraftService::class)->create($seller, $seller->id, [['origin' => 'post_conversion_collection', 'collection_id' => $collection->id]], 'post-settlement-guard-draft-0001');
        foreach (['post-settlement-guard-reverse-draft-0001', 'post-settlement-guard-reverse-confirmed-0001'] as $index => $key) {
            if ($index === 1) {
                $session = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $seller->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
                app(RouteCashSettlementService::class)->confirm(\App\Models\RouteCashSettlement::query()->findOrFail($draft->resultId), $seller, $seller->id, '123.47', null, 'post-settlement-guard-confirm-0001');
                $this->assertDatabaseHas('cash_movements', ['cash_register_session_id' => $session->id, 'type' => 'route_cash_settlement']);
            }
            try {
                app(RoutePostConversionCollectionReversalService::class)->reverse($collection->fresh(), ['reason_code' => 'wrong_amount', 'explanation' => 'No debe revertir.', 'confirmed' => true], $seller, $key);
                $this->fail('Expected settlement guard.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('settlement', $exception->errors());
            }
            $this->assertSame('captured', $collection->fresh()->status);
            $this->assertSame('paid', $sale->fresh()->payment_status);
        }
    }

    public function test_immediate_cash_reversal_compensates_only_the_same_open_current_session(): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, ['collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash'], 'primary_payment_method' => 'cash']);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_cash_custody_policy' => 'immediate_branch_register']);
        $session = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $seller->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $captured = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'post-immediate-reverse-capture-0001');
        $collection = \App\Models\RoutePostConversionCollection::query()->findOrFail($captured->resultId);
        app(RoutePostConversionCollectionReversalService::class)->reverse($collection, ['reason_code' => 'wrong_amount', 'explanation' => 'Corrección.', 'confirmed' => true, 'confirm_cash_adjustment' => true], $seller, 'post-immediate-reverse-0001');
        $this->assertSame('reversed', $collection->fresh()->status);
        $this->assertSame('unpaid', $sale->fresh()->payment_status);
        $this->assertDatabaseHas('cash_movements', ['cash_register_session_id' => $session->id, 'type' => 'route_post_conversion_collection_reversal_current_session', 'amount' => -123.47, 'reference_type' => 'route_post_conversion_collection_reversal']);
        $this->assertSame(2, \App\Models\CashMovement::query()->count());
    }

    public function test_closed_original_cash_session_reversal_is_ledger_only_and_never_uses_a_new_session(): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, ['collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash'], 'primary_payment_method' => 'cash']);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_cash_custody_policy' => 'immediate_branch_register']);
        $original = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $seller->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $captured = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, 'post-closed-reverse-capture-0001');
        $collection = \App\Models\RoutePostConversionCollection::query()->findOrFail($captured->resultId);
        $original->update(['status' => 'closed', 'closed_at' => now()]);
        $new = CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $seller->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        app(RoutePostConversionCollectionReversalService::class)->reverse($collection, ['reason_code' => 'wrong_amount', 'explanation' => 'Corrección.', 'confirmed' => true], $seller, 'post-closed-reverse-0001');
        $this->assertSame('unpaid', $sale->fresh()->payment_status);
        $this->assertSame(1, \App\Models\CashMovement::query()->count());
        $this->assertSame(0, \App\Models\CashMovement::query()->where('cash_register_session_id', $new->id)->count());
        $this->assertDatabaseHas('route_post_conversion_collection_reversals', ['route_post_conversion_collection_id' => $collection->id, 'cash_correction_type' => 'historical_closed_session_ledger']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('collectFailureEvents')]
    public function test_collect_failure_rolls_back_all_financial_state_and_a_retry_succeeds(string $model, string $event, bool $cash, string $method): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, ['collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash', 'transfer'], 'primary_payment_method' => 'cash']);
        if ($cash) {
            TenantSetting::query()->where('business_id', $business->id)->update(['route_cash_custody_policy' => 'immediate_branch_register']);
            CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $seller->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        }
        $model::{$event}(fn () => throw new \RuntimeException('injected post-conversion failure'));
        try {
            app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => $method], $seller, "post-failure-{$event}-0001");
            $this->fail('Expected injected failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('injected post-conversion failure', $exception->getMessage());
        } finally {
            $model::flushEventListeners();
        }
        $this->assertDatabaseCount('route_post_conversion_collections', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame('unpaid', $sale->fresh()->payment_status);
        $this->assertSame('cash', $entry->fresh()->payment_method);
        app(RoutePostConversionCollectionService::class)->collect($entry->fresh(), ['payment_method' => $method], $seller, "post-failure-retry-{$event}-0001");
        $this->assertDatabaseCount('route_post_conversion_collections', 1);
        $this->assertDatabaseCount('sale_payments', 1);
    }

    public static function collectFailureEvents(): array
    {
        return [
            'trace' => [\App\Models\RoutePostConversionCollection::class, 'creating', false, 'cash'],
            'payment' => [\App\Models\SalePayment::class, 'creating', false, 'cash'],
            'cash movement' => [\App\Models\CashMovement::class, 'creating', true, 'cash'],
            'entry update' => [\App\Models\RouteDeliveryBatchPreSale::class, 'updating', false, 'transfer'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reversalFailureEvents')]
    public function test_reversal_failure_rolls_back_and_retry_succeeds(string $model, string $event): void
    {
        [$business, $branch, $seller, $entry, $sale] = $this->unpaidPreSellerEntry('cash');
        app(RouteBranchCollectionSettingsService::class)->save($business->id, $branch, ['collection_workflow_mode' => 'per_order_collection', 'allowed_payment_methods' => ['cash'], 'primary_payment_method' => 'cash']);
        TenantSetting::query()->where('business_id', $business->id)->update(['route_cash_custody_policy' => 'immediate_branch_register']);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $seller->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $captured = app(RoutePostConversionCollectionService::class)->collect($entry, ['payment_method' => 'cash'], $seller, "post-reversal-failure-capture-{$event}");
        $collection = \App\Models\RoutePostConversionCollection::query()->findOrFail($captured->resultId);
        $model::{$event}(fn () => throw new \RuntimeException('injected reversal failure'));
        try {
            app(RoutePostConversionCollectionReversalService::class)->reverse($collection, ['reason_code' => 'wrong_amount', 'explanation' => 'Corrección.', 'confirmed' => true, 'confirm_cash_adjustment' => true], $seller, "post-reversal-failure-{$event}");
            $this->fail('Expected injected reversal failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('injected reversal failure', $exception->getMessage());
        } finally {
            $model::flushEventListeners();
        }
        $this->assertSame('captured', $collection->fresh()->status);
        $this->assertSame('captured', $sale->fresh()->payments()->sole()->status);
        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertSame('cash', $entry->fresh()->payment_method);
        $this->assertSame(1, \App\Models\CashMovement::query()->count());
        app(RoutePostConversionCollectionReversalService::class)->reverse($collection->fresh(), ['reason_code' => 'wrong_amount', 'explanation' => 'Corrección.', 'confirmed' => true, 'confirm_cash_adjustment' => true], $seller, "post-reversal-failure-retry-{$event}");
        $this->assertSame('reversed', $collection->fresh()->status);
    }

    public static function reversalFailureEvents(): array
    {
        return [
            'ledger' => [\App\Models\RoutePostConversionCollectionReversal::class, 'creating'],
            'compensation movement' => [\App\Models\CashMovement::class, 'creating'],
        ];
    }

    /** @return array{0: Business, 1: \App\Models\Branch, 2: User, 3: RouteDeliveryBatchPreSale, 4: Sale} */
    private function unpaidPreSellerEntry(string $agreedMethod): array
    {
        $business = Business::query()->create(['name' => 'Post conversion '.uniqid(), 'slug' => 'post-conversion-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $seller = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignRole($seller, 'owner');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'route_collection_responsibility' => 'pre_seller', 'route_cash_custody_policy' => 'collector_custody_until_settlement']);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $seller->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $seller->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'country' => 'GT']);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $seller->id, 'status' => PreSale::STATUS_CONVERTED, 'subtotal' => 123.47, 'discount_total' => 0, 'total' => 123.47, 'payment_method' => $agreedMethod, 'agreed_payment_method' => $agreedMethod, 'converted_at' => now(), 'converted_by' => $seller->id]);
        $sale = Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'total' => 123.47, 'payment_method' => null, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'credit_balance' => 0, 'is_credit_sale' => false, 'document_type' => 'receipt', 'created_by' => $seller->id]);
        $preSale->update(['converted_sale_id' => $sale->id]);
        $batch = RouteDeliveryBatch::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'delivered_by' => $seller->id, 'status' => RouteDeliveryBatch::STATUS_COMPLETED, 'stock_deduction_timing' => 'invoice', 'invoicing_mode' => 'manual', 'fel_automation_enabled' => false, 'delivery_tracking_snapshot' => 'external', 'collection_responsibility_snapshot' => 'pre_seller', 'collection_workflow_mode_snapshot' => 'per_order_collection', 'allowed_payment_methods_snapshot' => ['cash', 'transfer'], 'primary_payment_method_snapshot' => 'cash', 'operation_settings_snapshotted_at' => now(), 'delivered_at' => now(), 'total_pre_sales' => 1, 'total_items' => 0, 'total_amount' => 123.47]);
        $entry = RouteDeliveryBatchPreSale::query()->create(['route_delivery_batch_id' => $batch->id, 'pre_sale_id' => $preSale->id, 'sale_id' => $sale->id, 'status' => 'delivered', 'payment_method' => $agreedMethod, 'agreed_payment_method_snapshot' => $agreedMethod, 'fel_dispatch_status' => 'not_requested']);

        return [$business, $branch, $seller, $entry, $sale];
    }
}
