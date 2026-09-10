<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteDeliveryCollectionReversalHttpTest extends TestCase
{
    public function test_administrative_reversal_route_is_registered_as_a_post_endpoint_with_its_specific_permission(): void
    {
        $route = Route::getRoutes()->getByName('routes.delivery-collections.reverse');

        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertContains('permission:routes.delivery_collections.reverse', $route->gatherMiddleware());
        $this->assertSame('routes.delivery-collections.reverse', Route::getRoutes()->match(Request::create('/routes/delivery-collections/42/reverse', 'POST'))->getName());
    }
}
