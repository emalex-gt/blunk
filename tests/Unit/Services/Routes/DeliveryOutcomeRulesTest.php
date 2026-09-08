<?php

namespace Tests\Unit\Services\Routes;

use App\Services\Routes\DeliveryOutcomeRules;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryOutcomeRulesTest extends TestCase
{
    public function test_delivered_clears_reason_and_preserves_optional_note(): void
    {
        $outcome = DeliveryOutcomeRules::normalize('delivered', 'customer_absent', 'Recibió recepción.');

        $this->assertSame([
            'delivery_status' => 'delivered',
            'not_delivered_reason_code' => null,
            'delivery_notes' => 'Recibió recepción.',
        ], $outcome);
    }

    public function test_not_delivered_requires_reason_and_other_requires_note(): void
    {
        $this->expectException(ValidationException::class);

        DeliveryOutcomeRules::normalize('not_delivered', 'other', null);
    }

    public function test_not_delivered_accepts_catalog_reason_with_optional_note(): void
    {
        $outcome = DeliveryOutcomeRules::normalize('not_delivered', 'customer_absent', null);

        $this->assertSame('not_delivered', $outcome['delivery_status']);
        $this->assertSame('customer_absent', $outcome['not_delivered_reason_code']);
        $this->assertNull($outcome['delivery_notes']);
    }
}
