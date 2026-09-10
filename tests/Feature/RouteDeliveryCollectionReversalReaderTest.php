<?php

namespace Tests\Feature;

use App\Models\RouteDeliveryCollection;
use App\Models\Sale;
use App\Models\SalePayment;
use Tests\TestCase;

class RouteDeliveryCollectionReversalReaderTest extends TestCase
{
    public function test_current_route_payment_and_collection_readers_are_explicitly_captured(): void
    {
        $this->assertSame('captured', (new RouteDeliveryCollection)->captured()->getQuery()->wheres[0]['value']);
        $this->assertSame('captured', (new SalePayment)->captured()->getQuery()->wheres[0]['value']);
    }

    public function test_sale_exposes_unambiguous_current_and_historical_route_payment_relations(): void
    {
        $sale = new Sale;

        $this->assertStringContainsString('"status" = ?', $sale->capturedPayments()->toSql());
        $this->assertStringContainsString('"status" = ?', $sale->activeRouteDeliveryCollection()->toSql());
        $this->assertSame('route_delivery_collections', $sale->routeDeliveryCollections()->getRelated()->getTable());
    }
}
