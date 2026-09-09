<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
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
            'routes.cash-settlement-variances.index',
            'routes.cash-settlement-variances.show',
            'routes.cash-settlement-variances.events.store',
            'routes.cash-settlement-variances.assignment.update',
            'routes.cash-settlement-variances.resolutions.store',
        ] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), "Missing route {$name}");
        }
    }

    public function test_variance_index_is_not_shadowed_by_the_settlement_parameter_route(): void
    {
        $matched = Route::getRoutes()->match(Request::create('/routes/cash-settlements/variances', 'GET'));

        $this->assertSame('routes.cash-settlement-variances.index', $matched->getName());
    }
}
