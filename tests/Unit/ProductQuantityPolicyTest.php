<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Support\Inventory\ProductQuantityPolicy;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductQuantityPolicyTest extends TestCase
{
    public function test_integer_products_reject_fractional_quantities_without_rounding(): void
    {
        $product = new Product(['allow_decimal_quantity' => false]);

        $this->assertSame('10', ProductQuantityPolicy::positive($product, '10.0000'));
        $this->expectException(ValidationException::class);
        ProductQuantityPolicy::positive($product, '0.1250');
    }

    public function test_decimal_products_canonicalize_and_compare_exact_values(): void
    {
        $product = new Product(['allow_decimal_quantity' => true]);

        $this->assertSame('0.125', ProductQuantityPolicy::positive($product, '0.1250'));
        $this->assertSame('1.5', ProductQuantityPolicy::positive($product, '1.5000'));
        $this->assertSame('10.0001', ProductQuantityPolicy::positive($product, '10.0001'));
        $this->assertSame('9', ProductQuantityPolicy::subtract('10.5000', '1.5000'));
        $this->assertTrue(ProductQuantityPolicy::isAvailable('9.0000', '9'));
    }

    public function test_quantity_policy_rejects_excess_precision_and_non_positive_movements(): void
    {
        $product = new Product(['allow_decimal_quantity' => true]);

        foreach (['1.00001', '0', '-1', '1e2'] as $quantity) {
            try {
                ProductQuantityPolicy::positive($product, $quantity);
                $this->fail("{$quantity} debe rechazarse.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('0', ProductQuantityPolicy::absolute($product, '0.0000'));
    }
}
