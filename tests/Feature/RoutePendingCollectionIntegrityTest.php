<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RoutePendingCollectionCase;
use App\Models\RoutePendingCollectionEvent;
use App\Models\RouteZone;
use App\Models\RouteWorkDay;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RouteDeliveryBatchService;
use App\Services\Routes\RouteExternalDeliveryReconciliationService;
use App\Services\Routes\RoutePendingCollectionService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use App\Support\SystemIntegrityAuditor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoutePendingCollectionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_open_case_with_paid_sale_is_reported(): void
    {
        [$business, , , $case] = $this->externalCase();
        DB::table('sales')->where('id', $case->sale_id)->update(['payment_status' => 'paid', 'amount_paid' => 60]);
        $this->assertIssue($business, 'pending_collection_case_financial_state_mismatch');
    }

    public function test_open_case_with_existing_delivery_collection_is_reported(): void
    {
        [$business, , $actor, $case] = $this->externalCase();
        app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'integrity-open-collection-0001');
        DB::table('route_pending_collection_cases')->where('id', $case->id)->update(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null, 'resolution_route_delivery_collection_id' => null]);
        $this->assertIssue($business, 'pending_collection_open_with_delivery_collection');
    }

    public function test_resolved_case_with_unpaid_sale_is_reported(): void
    {
        [$business, , $actor, $case] = $this->externalCase();
        app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'integrity-resolved-unpaid-0001');
        DB::table('sales')->where('id', $case->sale_id)->update(['payment_status' => 'unpaid', 'amount_paid' => 0]);
        $this->assertIssue($business, 'pending_collection_case_financial_state_mismatch');
    }

    public function test_resolved_case_with_resolution_collection_from_wrong_branch_is_reported(): void
    {
        [$business, $branch, $actor, $case] = $this->externalCase();
        app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'integrity-resolution-wrong-branch-0001');
        $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra '.uniqid(), 'code' => 'OTRA-'.uniqid(), 'is_active' => true]);
        DB::table('route_delivery_collections')->where('id', $case->fresh()->resolution_route_delivery_collection_id)->update(['branch_id' => $other->id]);
        $this->assertIssue($business, 'pending_collection_invalid_resolution_collection');
    }

    public function test_open_case_with_external_origin_not_delivered_is_reported(): void
    {
        [$business, , , $case] = $this->externalCase();
        DB::table('route_external_delivery_reconciliation_items')->where('id', $case->route_external_delivery_reconciliation_item_id)->update(['delivery_status' => 'not_delivered']);
        $this->assertIssue($business, 'pending_collection_external_origin_mismatch');
    }

    public function test_open_case_with_in_app_origin_not_delivered_is_reported(): void
    {
        [$business, , , $case] = $this->inAppCase();
        DB::table('route_delivery_stops')->where('id', $case->route_delivery_stop_id)->update(['status' => 'not_delivered', 'not_delivered_reason_code' => 'customer_absent']);
        $this->assertIssue($business, 'pending_collection_in_app_origin_mismatch');
    }

    public function test_open_case_with_pre_seller_responsibility_is_reported(): void
    {
        [$business, , , $case] = $this->externalCase();
        DB::table('route_external_delivery_reconciliation_items')->where('id', $case->route_external_delivery_reconciliation_item_id)->update(['collection_responsibility_snapshot' => 'pre_seller']);
        $this->assertIssue($business, 'pending_collection_external_origin_mismatch');
    }

    public function test_stale_not_applicable_case_is_reported_when_delivery_agent_eligibility_returns(): void
    {
        [$business, , $actor, $case] = $this->externalCase();
        $case->update(['status' => 'not_applicable', 'not_applicable_at' => now(), 'not_applicable_by' => $actor->id, 'not_applicable_reason' => 'Fixture corrupto.']);
        $this->assertIssue($business, 'pending_collection_stale_not_applicable');
    }

    public function test_origin_fk_mismatch_is_rejected_by_check_constraint_and_semantic_mismatch_is_audited(): void
    {
        [$business, $branch, $actor, $case] = $this->externalCase();
        $this->expectException(QueryException::class);
        RoutePendingCollectionCase::query()->create([
            'business_id' => $business->id, 'branch_id' => $branch->id, 'sale_id' => $this->secondSale($business, $branch, $actor)->id,
            'pre_sale_id' => $case->pre_sale_id, 'route_delivery_batch_pre_sale_id' => $case->route_delivery_batch_pre_sale_id,
            'route_external_delivery_reconciliation_item_id' => $case->route_external_delivery_reconciliation_item_id,
            'route_delivery_stop_id' => null, 'delivery_origin' => 'in_app_stop', 'status' => 'open', 'opened_at' => now(),
        ]);
    }

    public function test_origin_tracking_semantic_mismatch_is_reported_when_fk_shape_is_valid(): void
    {
        [$business, , , $case] = $this->externalCase();
        DB::table('route_external_delivery_reconciliation_items')->where('id', $case->route_external_delivery_reconciliation_item_id)->update(['delivery_tracking_snapshot' => 'in_app']);
        $this->assertIssue($business, 'pending_collection_external_origin_mismatch');
    }

    public function test_business_mismatch_between_case_and_external_origin_is_reported(): void
    {
        [$business, , , $case] = $this->externalCase();
        $other = Business::query()->create(['name' => 'Otro '.uniqid(), 'slug' => 'otro-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        DB::table('route_external_delivery_reconciliation_items')->where('id', $case->route_external_delivery_reconciliation_item_id)->update(['business_id' => $other->id]);
        $this->assertIssue($business, 'pending_collection_external_origin_mismatch');
    }

    public function test_branch_mismatch_between_case_and_external_origin_is_reported(): void
    {
        [$business, , , $case] = $this->externalCase();
        $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra '.uniqid(), 'code' => 'OTRA-'.uniqid(), 'is_active' => true]);
        DB::table('route_external_delivery_reconciliation_items')->where('id', $case->route_external_delivery_reconciliation_item_id)->update(['branch_id' => $other->id]);
        $this->assertIssue($business, 'pending_collection_external_origin_mismatch');
    }

    public function test_event_scope_mismatch_is_reported(): void
    {
        [$business, , $actor, $case] = $this->externalCase();
        $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra '.uniqid(), 'code' => 'OTRA-'.uniqid(), 'is_active' => true]);
        RoutePendingCollectionEvent::query()->create(['route_pending_collection_case_id' => $case->id, 'business_id' => $business->id, 'branch_id' => $other->id, 'type' => 'contact', 'occurred_at' => now(), 'recorded_by' => $actor->id]);
        $this->assertIssue($business, 'pending_collection_event_scope_mismatch');
    }

    public function test_resolved_case_collection_on_not_delivered_origin_is_reported_but_legacy_not_delivered_collected_without_case_is_not(): void
    {
        [$business, , $actor, $case] = $this->externalCase();
        app(RoutePendingCollectionService::class)->collect($case, ['amount' => 60, 'payment_method' => 'transfer'], $actor, 'integrity-resolved-not-delivered-0001');
        DB::table('route_external_delivery_reconciliation_items')->where('id', $case->route_external_delivery_reconciliation_item_id)->update(['delivery_status' => 'not_delivered']);
        $issues = $this->issues($business);
        $this->assertContains('pending_collection_resolved_from_not_delivered_origin', $issues);
        DB::table('route_pending_collection_cases')->where('id', $case->id)->delete();
        $this->assertNotContains('pending_collection_resolved_from_not_delivered_origin', $this->issues($business));
    }

    public function test_delivered_unpaid_pre_seller_without_case_is_reported_as_route_anomaly(): void
    {
        [$business, , , $case] = $this->externalCase();
        DB::table('route_pending_collection_cases')->where('id', $case->id)->delete();
        DB::table('route_external_delivery_reconciliation_items')->where('id', $case->route_external_delivery_reconciliation_item_id)->update(['collection_responsibility_snapshot' => 'pre_seller']);
        $this->assertIssue($business, 'pre_seller_delivered_unpaid_anomaly');
    }

    public function test_artificial_contractual_credit_for_delivered_delivery_agent_sale_is_reported(): void
    {
        [$business, $branch, , $case] = $this->externalCase();
        $sale = DB::table('sales')->where('id', $case->sale_id)->first();
        $accountId = DB::table('customer_credit_accounts')->insertGetId(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $sale->customer_id, 'credit_limit' => null, 'current_balance' => 60, 'is_blocked' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_account_movements')->insert(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $sale->customer_id, 'customer_credit_account_id' => $accountId, 'sale_id' => $sale->id, 'type' => 'sale', 'direction' => 'debit', 'amount' => 60, 'balance_after' => 60, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertIssue($business, 'route_pending_collection_artificial_credit');
    }

    private function assertIssue(Business $business, string $issue): void { $this->assertContains($issue, $this->issues($business)); }
    private function issues(Business $business): array { return collect(app(SystemIntegrityAuditor::class)->audit(['business' => $business->id, 'section' => 'sales'])['results']['sales'])->pluck('issue_type')->all(); }

    /** @return array{Business, Branch, User, RoutePendingCollectionCase} */
    private function externalCase(): array
    {
        [$business, $branch, $actor, $entry] = $this->entry('external');
        $result = app(RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, ['idempotency_key' => 'integrity-external-'.uniqid(), 'delivery_status' => 'delivered', 'collected' => false], $actor);
        $item = \App\Models\RouteExternalDeliveryReconciliationItem::query()->findOrFail($result->resultId);
        return [$business, $branch, $actor, RoutePendingCollectionCase::query()->where('sale_id', $item->sale_id)->firstOrFail()];
    }

    /** @return array{Business, Branch, User, RoutePendingCollectionCase} */
    private function inAppCase(): array
    {
        [$business, $branch, $actor, $entry] = $this->entry('in_app');
        $run = app(\App\Services\Routes\RouteDeliveryRunAssignmentService::class)->createDraft($actor, $actor, [$entry->id]);
        app(\App\Services\Routes\RouteDeliveryRunService::class)->start($run, $actor, 'integrity-run-start-'.uniqid());
        $stop = $run->fresh()->stops()->firstOrFail();
        app(\App\Services\Routes\RouteDeliveryStopService::class)->complete($stop, ['delivery_status' => 'delivered', 'collected' => false], $actor, 'integrity-stop-'.uniqid());
        return [$business, $branch, $actor, RoutePendingCollectionCase::query()->where('sale_id', $stop->sale_id)->firstOrFail()];
    }

    /** @return array{Business, Branch, User, RouteDeliveryBatchPreSale} */
    private function entry(string $tracking): array
    {
        $business = Business::query()->create(['name' => 'Integrity '.uniqid(), 'slug' => 'integrity-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $actor = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        Permissions::assignRole($actor, 'owner');
        TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'allow_receipts' => true, 'allow_invoices' => true, 'route_collection_responsibility' => 'delivery_agent', 'route_delivery_tracking' => $tracking]);
        foreach (['routes', 'cash_register'] as $module) TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $actor->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $day = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $actor->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'doc_type' => 'CF', 'doc_number' => 'CF'.uniqid(), 'country' => 'GT']);
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Producto '.uniqid(), 'code' => 'PCI-'.uniqid(), 'cost_price' => 10, 'sale_price' => 20, 'stock' => 10, 'min_stock' => 0, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10]);
        $preSale = PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $day->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $actor->id, 'status' => PreSale::STATUS_PICKED, 'subtotal' => 60, 'discount_total' => 0, 'total' => 60, 'payment_method' => 'cash', 'agreed_payment_method' => 'cash', 'picked_at' => now(), 'picked_by' => $actor->id]);
        $line = PreSaleItem::query()->create(['business_id' => $business->id, 'pre_sale_id' => $preSale->id, 'product_id' => $product->id, 'quantity' => 3, 'picked_quantity' => 3, 'unit_price' => 20, 'original_price' => 20, 'discount' => 0, 'total' => 60]);
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'source_type' => 'pre_sale', 'source_id' => $preSale->id, 'source_item_id' => $line->id, 'quantity' => 3, 'status' => 'active', 'created_by' => $actor->id]);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $actor->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $delivery = app(RouteDeliveryBatchService::class)->deliverAll($day, $actor, 'integrity-deliver-'.uniqid());
        return [$business, $branch, $actor, RouteDeliveryBatchPreSale::query()->with('batch')->where('route_delivery_batch_id', $delivery->resultId)->firstOrFail()];
    }

    private function secondSale(Business $business, Branch $branch, User $actor): \App\Models\Sale
    {
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Otro '.uniqid(), 'country' => 'GT']);
        return \App\Models\Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'total' => 1, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'credit_balance' => 0, 'is_credit_sale' => false, 'created_by' => $actor->id]);
    }
}
