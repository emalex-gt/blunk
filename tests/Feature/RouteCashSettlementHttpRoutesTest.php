<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteCashSettlementHttpRoutesTest extends TestCase
{
    public function test_cash_settlement_administration_routes_are_registered(): void
    {
        foreach ([
            'routes.cash-settlements.index',
            'routes.cash-settlements.show',
            'routes.cash-settlements.store',
            'routes.cash-settlements.items.store',
            'routes.cash-settlements.items.destroy',
            'routes.cash-settlements.cancel',
            'routes.cash-settlements.confirm',
        ] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), "Missing route {$name}");
        }
    }
}
