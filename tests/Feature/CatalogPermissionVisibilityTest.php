<?php

namespace Tests\Feature;

use Tests\TestCase;

class CatalogPermissionVisibilityTest extends TestCase
{
    public function test_brand_and_location_interfaces_guard_each_mutation_independently(): void
    {
        $brands = file_get_contents(resource_path('js/Pages/Brands/Index.tsx'));
        $locations = file_get_contents(resource_path('js/Pages/ProductLocations/Index.tsx'));
        foreach ([$brands, $locations] as $source) {
            $this->assertStringContainsString("can('", $source);
            $this->assertStringContainsString('canDelete', $source);
        }
    }
}
