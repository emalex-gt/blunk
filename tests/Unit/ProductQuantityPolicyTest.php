<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Support\Inventory\ProductQuantityPolicy;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductQuantityPolicyTest extends TestCase
{
    public function test_integer_products_reject_fractional_quantities(): void
    {
        $product = new Product(['allow_decimal_quantity' => false]);

        $this->assertSame('1', ProductQuantityPolicy::positive($product, '1.0000'));

        $this->expectException(ValidationException::class);
        ProductQuantityPolicy::positive($product, '1.5');
    }

    public function test_decimal_products_accept_and_normalize_up_to_four_decimal_places(): void
    {
        $product = new Product(['allow_decimal_quantity' => true]);

        $this->assertSame('0.0001', ProductQuantityPolicy::positive($product, '0,0001'));
        $this->assertSame('1.25', ProductQuantityPolicy::positive($product, '1.2500'));
        $this->assertSame('1.2345', ProductQuantityPolicy::positive($product, '1.2345'));
    }

    public function test_decimal_products_reject_more_than_four_places_and_positive_zero(): void
    {
        $product = new Product(['allow_decimal_quantity' => true]);

        foreach (['0', '1.23456', '1e2', 'NaN', 'Infinity'] as $quantity) {
            try {
                ProductQuantityPolicy::positive($product, $quantity);
                $this->fail("{$quantity} should be rejected.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_absolute_adjustments_allow_zero_for_decimal_products(): void
    {
        $product = new Product(['allow_decimal_quantity' => true]);

        $this->assertSame('0', ProductQuantityPolicy::absolute($product, '0.0000'));
    }

    public function test_idempotency_representation_preserves_historical_integers_and_canonical_decimals(): void
    {
        $this->assertSame(1, ProductQuantityPolicy::canonicalForIdempotency('1.0000'));
        $this->assertSame('1.25', ProductQuantityPolicy::canonicalForIdempotency('1.2500'));
        $this->assertSame('1.75', ProductQuantityPolicy::canonicalForIdempotency('1.75'));
    }
}
