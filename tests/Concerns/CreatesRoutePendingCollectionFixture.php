<?php

namespace Tests\Concerns;

trait CreatesRoutePendingCollectionFixture
{
    /** @return array{0: \App\Models\Business, 1: \App\Models\Branch, 2: \App\Models\RouteExternalDeliveryReconciliationItem} */
    protected function externalDeliveredUnpaidFixture(): array
    {
        [$business, $branch, $manager, $entry] = $this->routePendingCollectionEntryFixture('external');
        $result = app(\App\Services\Routes\RouteExternalDeliveryReconciliationService::class)->reconcileItem($entry->batch, $entry, [
            'idempotency_key' => 'reversal-fixture-external-'.uniqid(),
            'delivery_status' => 'delivered',
            'collected' => false,
        ], $manager);

        return [$business, $branch, \App\Models\RouteExternalDeliveryReconciliationItem::query()->findOrFail($result->resultId)];
    }

    /** @return array{0: \App\Models\Business, 1: \App\Models\Branch, 2: \App\Models\RouteDeliveryStop} */
    protected function closedInAppDeliveredUnpaidFixture(): array
    {
        [$business, $branch, $manager, $entry] = $this->routePendingCollectionEntryFixture('in_app');
        $deliveryUser = \App\Models\User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        \App\Support\Permissions::assignRole($deliveryUser, 'delivery_agent');
        $run = app(\App\Services\Routes\RouteDeliveryRunAssignmentService::class)->createDraft($manager, $deliveryUser, [$entry->id]);
        app(\App\Services\Routes\RouteDeliveryRunService::class)->start($run, $deliveryUser, 'reversal-fixture-run-start-'.uniqid());
        $stop = $run->fresh()->stops()->firstOrFail();
        app(\App\Services\Routes\RouteDeliveryStopService::class)->complete($stop, ['delivery_status' => 'delivered', 'collected' => false], $deliveryUser, 'reversal-fixture-stop-'.uniqid());
        app(\App\Services\Routes\RouteDeliveryRunService::class)->close($run->fresh(), $deliveryUser, 'reversal-fixture-run-close-'.uniqid(), true);

        return [$business, $branch, $stop->fresh(['run'])];
    }

    /** @return array{0: \App\Models\Business, 1: \App\Models\Branch, 2: \App\Models\User, 3: \App\Models\RouteDeliveryBatchPreSale} */
    protected function routePendingCollectionEntryFixture(string $tracking): array
    {
        $business = \App\Models\Business::query()->create(['name' => 'Reversal '.uniqid(), 'slug' => 'reversal-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = \App\Support\BranchInventory::defaultBranchForBusiness($business);
        $manager = \App\Models\User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
        \App\Support\Permissions::assignRole($manager, 'owner');
        \App\Models\TenantSetting::query()->create(['business_id' => $business->id, 'use_branches' => true, 'allow_receipts' => true, 'allow_invoices' => true, 'route_collection_responsibility' => 'delivery_agent', 'route_delivery_tracking' => $tracking]);
        foreach (['routes', 'cash_register'] as $module) {
            \App\Models\TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
        }
        $zone = \App\Models\RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $manager->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = \App\Models\RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $manager->id, 'work_date' => today(), 'status' => 'closed', 'started_at' => now()->subHour(), 'closed_at' => now()]);
        $customer = \App\Models\Customer::query()->create(['business_id' => $business->id, 'name' => 'Cliente '.uniqid(), 'doc_type' => 'CF', 'doc_number' => 'CF'.uniqid(), 'country' => 'GT']);
        $product = \App\Models\Product::query()->create(['business_id' => $business->id, 'name' => 'Producto '.uniqid(), 'code' => 'REV-'.uniqid(), 'cost_price' => 10, 'sale_price' => 20, 'stock' => 10, 'min_stock' => 0, 'is_active' => true]);
        \App\Models\ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10]);
        $preSale = \App\Models\PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $manager->id, 'status' => \App\Models\PreSale::STATUS_PICKED, 'subtotal' => 60, 'discount_total' => 0, 'total' => 60, 'payment_method' => 'cash', 'agreed_payment_method' => 'cash', 'picked_at' => now(), 'picked_by' => $manager->id]);
        $line = \App\Models\PreSaleItem::query()->create(['business_id' => $business->id, 'pre_sale_id' => $preSale->id, 'product_id' => $product->id, 'quantity' => 3, 'picked_quantity' => 3, 'unit_price' => 20, 'original_price' => 20, 'discount' => 0, 'total' => 60]);
        \App\Models\StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'source_type' => 'pre_sale', 'source_id' => $preSale->id, 'source_item_id' => $line->id, 'quantity' => 3, 'status' => 'active', 'created_by' => $manager->id]);
        \App\Models\CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $manager->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 0, 'opened_at' => now()]);
        $delivery = app(\App\Services\Routes\RouteDeliveryBatchService::class)->deliverAll($workDay, $manager, 'reversal-fixture-deliver-'.uniqid());
        $entry = \App\Models\RouteDeliveryBatchPreSale::query()->with(['batch', 'sale'])->where('route_delivery_batch_id', $delivery->resultId)->firstOrFail();

        return [$business, $branch, $manager, $entry];
    }
}
