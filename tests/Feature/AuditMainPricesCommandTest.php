<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\Business;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\TenantSetting;
use App\Support\BranchInventory;
use App\Support\PriceLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditMainPricesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_dry_run_detects_difference_and_does_not_modify_product_price(): void
    {
        [$business] = $this->tenant();
        $product = $this->product($business, salePrice: 10);
        $default = PriceLists::ensureDefaultPriceType($business->id);
        $this->productPrice($business, $product, $default, 8);

        $this->artisan('products:audit-main-prices', [
            '--business' => $business->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame('8.00', (string) ProductPrice::query()->where('business_id', $business->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
    }

    public function test_dry_run_detects_one_cent_main_price_difference(): void
    {
        [$business] = $this->tenant();
        $product = $this->product($business, name: 'Jabon Palmolive Sandia', salePrice: 8);
        $default = PriceLists::ensureDefaultPriceType($business->id);
        $this->productPrice($business, $product, $default, 7.99);

        $this->artisan('products:audit-main-prices', [
            '--business' => $business->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('diferencias product_prices: 1')
            ->assertExitCode(0);

        $this->assertSame('7.99', (string) ProductPrice::query()->where('business_id', $business->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
    }

    public function test_confirm_fixes_one_cent_main_price_difference(): void
    {
        [$business] = $this->tenant();
        $product = $this->product($business, name: 'Jabon Palmolive Sandia', salePrice: 8);
        $default = PriceLists::ensureDefaultPriceType($business->id);
        $this->productPrice($business, $product, $default, 7.99);

        $this->artisan('products:audit-main-prices', [
            '--business' => $business->id,
            '--confirm' => true,
        ])
            ->expectsOutputToContain('diferencias product_prices: 1')
            ->assertExitCode(0);

        $this->assertSame('8.00', (string) ProductPrice::query()->where('business_id', $business->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
    }

    public function test_decimal_precision_noise_is_normalized_to_system_cents(): void
    {
        [$business] = $this->tenant();
        $product = $this->product($business, salePrice: 8);
        $default = PriceLists::ensureDefaultPriceType($business->id);
        $this->productPrice($business, $product, $default, 7.999999);

        $this->artisan('products:audit-main-prices', [
            '--business' => $business->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('diferencias product_prices: 0')
            ->assertExitCode(0);

        $this->assertSame('8.00', (string) ProductPrice::query()->where('business_id', $business->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
    }

    public function test_confirm_updates_main_product_price_to_sale_price(): void
    {
        [$business] = $this->tenant();
        $product = $this->product($business, salePrice: 10);
        $default = PriceLists::ensureDefaultPriceType($business->id);
        $this->productPrice($business, $product, $default, 8);

        $this->artisan('products:audit-main-prices', [
            '--business' => $business->id,
            '--confirm' => true,
        ])->assertExitCode(0);

        $this->assertSame('10.00', (string) ProductPrice::query()->where('business_id', $business->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
    }

    public function test_confirm_does_not_touch_secondary_price_types_or_other_business_products(): void
    {
        [$business] = $this->tenant();
        [$otherBusiness] = $this->tenant('Otro negocio');
        $product = $this->product($business, salePrice: 10);
        $otherProduct = $this->product($otherBusiness, salePrice: 50);
        $default = PriceLists::ensureDefaultPriceType($business->id);
        $otherDefault = PriceLists::ensureDefaultPriceType($otherBusiness->id);
        $secondary = PriceType::query()->create([
            'business_id' => $business->id,
            'name' => 'Mayorista',
            'is_default' => false,
            'is_active' => true,
        ]);
        $this->productPrice($business, $product, $default, 8);
        $this->productPrice($business, $product, $secondary, 7);
        $this->productPrice($otherBusiness, $otherProduct, $otherDefault, 20);

        $this->artisan('products:audit-main-prices', [
            '--business' => $business->id,
            '--confirm' => true,
        ])->assertExitCode(0);

        $this->assertSame('10.00', (string) ProductPrice::query()->where('business_id', $business->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
        $this->assertSame('7.00', (string) ProductPrice::query()->where('business_id', $business->id)->where('product_id', $product->id)->where('price_type_id', $secondary->id)->firstOrFail()->price);
        $this->assertSame('20.00', (string) ProductPrice::query()->where('business_id', $otherBusiness->id)->where('product_id', $otherProduct->id)->where('price_type_id', $otherDefault->id)->firstOrFail()->price);
    }

    public function test_confirm_creates_missing_main_product_price(): void
    {
        [$business] = $this->tenant();
        $product = $this->product($business, salePrice: 12.5);
        $default = PriceLists::ensureDefaultPriceType($business->id);

        $this->artisan('products:audit-main-prices', [
            '--business' => $business->id,
            '--confirm' => true,
        ])->assertExitCode(0);

        $this->assertSame('12.50', (string) ProductPrice::query()->where('business_id', $business->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
    }

    public function test_branch_pricing_without_include_branch_does_not_touch_branch_price(): void
    {
        [$business, , $branch] = $this->tenant(pricingScope: 'branch');
        $product = $this->product($business, salePrice: 10);
        $default = PriceLists::ensureDefaultPriceType($business->id);
        $this->productPrice($business, $product, $default, 8);
        $this->branchPrice($business, $branch, $product, $default, 6);

        $this->artisan('products:audit-main-prices', [
            '--business' => $business->id,
            '--branch' => $branch->id,
            '--confirm' => true,
        ])->assertExitCode(0);

        $this->assertSame('10.00', (string) ProductPrice::query()->where('business_id', $business->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
        $this->assertSame('6.00', (string) BranchProductPrice::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
    }

    public function test_include_branch_updates_only_requested_branch_price(): void
    {
        [$business, , $branchA] = $this->tenant(pricingScope: 'branch');
        $branchB = Branch::query()->create(['business_id' => $business->id, 'name' => 'Sucursal B', 'is_default' => false, 'is_active' => true]);
        $product = $this->product($business, salePrice: 10);
        $default = PriceLists::ensureDefaultPriceType($business->id);
        $this->productPrice($business, $product, $default, 8);
        $this->branchPrice($business, $branchA, $product, $default, 6);
        $this->branchPrice($business, $branchB, $product, $default, 5);

        $this->artisan('products:audit-main-prices', [
            '--business' => $business->id,
            '--branch' => $branchA->id,
            '--include-branch' => true,
            '--confirm' => true,
        ])->assertExitCode(0);

        $this->assertSame('10.00', (string) BranchProductPrice::query()->where('business_id', $business->id)->where('branch_id', $branchA->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
        $this->assertSame('5.00', (string) BranchProductPrice::query()->where('business_id', $business->id)->where('branch_id', $branchB->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
    }

    public function test_created_after_filter_limits_products_and_only_active_omits_inactive(): void
    {
        [$business] = $this->tenant();
        $default = PriceLists::ensureDefaultPriceType($business->id);
        $old = $this->product($business, name: 'Viejo', salePrice: 10, createdAt: '2026-01-01');
        $inactive = $this->product($business, name: 'Inactivo', salePrice: 20, active: false, createdAt: '2026-03-01');
        $new = $this->product($business, name: 'Nuevo', salePrice: 30, createdAt: '2026-03-01');
        $this->productPrice($business, $old, $default, 1);
        $this->productPrice($business, $inactive, $default, 2);
        $this->productPrice($business, $new, $default, 3);

        $this->artisan('products:audit-main-prices', [
            '--business' => $business->id,
            '--created-after' => '2026-02-01',
            '--only-active' => true,
            '--confirm' => true,
        ])->assertExitCode(0);

        $this->assertSame('1.00', (string) ProductPrice::query()->where('product_id', $old->id)->where('price_type_id', $default->id)->firstOrFail()->price);
        $this->assertSame('2.00', (string) ProductPrice::query()->where('product_id', $inactive->id)->where('price_type_id', $default->id)->firstOrFail()->price);
        $this->assertSame('30.00', (string) ProductPrice::query()->where('product_id', $new->id)->where('price_type_id', $default->id)->firstOrFail()->price);
    }

    public function test_report_option_writes_audit_files(): void
    {
        [$business] = $this->tenant();
        $product = $this->product($business, salePrice: 10);
        $default = PriceLists::ensureDefaultPriceType($business->id);
        $this->productPrice($business, $product, $default, 8);

        $this->artisan('products:audit-main-prices', [
            '--business' => $business->id,
            '--dry-run' => true,
            '--report' => true,
        ])->assertExitCode(0);

        $files = Storage::disk('local')->allFiles('products-price-audits');
        $this->assertNotEmpty($files);
        $this->assertTrue(collect($files)->contains(fn ($file) => str_ends_with($file, 'summary.json')));
        $this->assertTrue(collect($files)->contains(fn ($file) => str_ends_with($file, 'mismatches.csv')));
        $mismatchPath = collect($files)->first(fn ($file) => str_ends_with($file, 'mismatches.csv'));
        $mismatchCsv = Storage::disk('local')->get($mismatchPath);
        $this->assertStringContainsString('product_sale_price_raw', $mismatchCsv);
        $this->assertStringContainsString('main_product_price_raw', $mismatchCsv);
        $this->assertStringContainsString('product_sale_price_formatted', $mismatchCsv);
        $this->assertStringContainsString('main_product_price_formatted', $mismatchCsv);
    }

    public function test_business_option_is_required(): void
    {
        $this->artisan('products:audit-main-prices')
            ->assertExitCode(1);
    }

    private function tenant(string $name = 'Tenant precios', string $pricingScope = 'global'): array
    {
        $business = Business::query()->create([
            'name' => $name,
            'country' => 'GT',
            'currency' => 'GTQ',
            'is_active' => true,
        ]);

        $settings = TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_branches' => true,
            'products_shared_across_branches' => true,
            'pricing_scope' => $pricingScope,
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);

        $branch = BranchInventory::defaultBranch($business->id);

        return [$business, $settings, $branch];
    }

    private function product(
        Business $business,
        string $name = 'Producto prueba',
        float $salePrice = 10,
        bool $active = true,
        ?string $createdAt = null,
    ): Product {
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => 'CODE-'.uniqid(),
            'barcode' => 'BAR-'.uniqid(),
            'cost_price' => 1,
            'sale_price' => $salePrice,
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => $active,
        ]);

        if ($createdAt) {
            $product->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
        }

        return $product;
    }

    private function productPrice(Business $business, Product $product, PriceType $priceType, float $price): ProductPrice
    {
        return ProductPrice::query()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'price_type_id' => $priceType->id,
            'price' => $price,
            'is_active' => true,
        ]);
    }

    private function branchPrice(Business $business, Branch $branch, Product $product, PriceType $priceType, float $price): BranchProductPrice
    {
        return BranchProductPrice::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'price_type_id' => $priceType->id,
            'price' => $price,
            'is_active' => true,
        ]);
    }
}
