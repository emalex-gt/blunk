<?php

namespace Tests\Unit;

use App\Support\CustomerPrintLocation;
use PHPUnit\Framework\TestCase;

class CustomerPrintLocationTest extends TestCase
{
    public function test_it_does_not_repeat_a_complete_administrative_location_already_in_the_address(): void
    {
        $this->assertSame(
            '5a Avenida 10-25, Mixco, Guatemala',
            CustomerPrintLocation::format([
                'address' => '5a Avenida 10-25, Mixco, Guatemala',
                'municipality' => 'Mixco',
                'department' => 'Guatemala',
            ]),
        );
    }

    public function test_it_adds_only_the_missing_administrative_component_for_a_partial_address(): void
    {
        $this->assertSame(
            '5a Avenida 10-25, Mixco, Guatemala',
            CustomerPrintLocation::format([
                'address' => '5a Avenida 10-25, Mixco',
                'municipality' => 'Mixco',
                'department' => 'Guatemala',
            ]),
        );

        $this->assertSame(
            '5a Avenida 10-25, Guatemala',
            CustomerPrintLocation::format([
                'address' => '5a Avenida 10-25, Guatemala',
                'municipality' => 'Mixco',
                'department' => 'Guatemala',
            ]),
        );
    }

    public function test_it_formats_missing_administrative_components_without_extra_separators(): void
    {
        $this->assertSame('5a Avenida 10-25', CustomerPrintLocation::format([
            'address' => '5a Avenida 10-25',
            'municipality' => '',
            'department' => '',
        ]));
        $this->assertSame('Mixco, Guatemala', CustomerPrintLocation::format([
            'address' => '',
            'municipality' => 'Mixco',
            'department' => 'Guatemala',
        ]));
    }

    public function test_it_compares_existing_administrative_location_case_insensitively(): void
    {
        $this->assertSame(
            '5A AVENIDA 10-25, MIXCO, GUATEMALA',
            CustomerPrintLocation::format([
                'address' => '5A AVENIDA 10-25, MIXCO, GUATEMALA',
                'municipality' => 'Mixco',
                'department' => 'Guatemala',
            ]),
        );
    }
}
