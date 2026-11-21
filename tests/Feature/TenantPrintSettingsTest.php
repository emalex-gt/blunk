<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CreditReceipt;
use App\Models\CreditReceiptLine;
use App\Models\Customer;
use App\Models\Product;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantPrintSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_super_admin_manages_product_identifier_print_settings_per_tenant(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->get(route('super-admin.tenants.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Tenants/Form')
                ->where('settings.show_product_code_on_receipts', false)
                ->where('settings.show_product_barcode_on_receipts', false));

        $this->actingAs($superAdmin)
            ->post(route('super-admin.tenants.store'), $this->tenantPayload('Tenant sin identificadores'))
            ->assertRedirect(route('super-admin.tenants.index'));

        $defaults = Business::query()->where('name', 'Tenant sin identificadores')->firstOrFail()->tenantSetting;
        $this->assertFalse($defaults->show_product_code_on_receipts);
        $this->assertFalse($defaults->show_product_barcode_on_receipts);

        $this->actingAs($superAdmin)
            ->post(route('super-admin.tenants.store'), $this->tenantPayload('Tenant identificadores', [
                'show_product_code_on_receipts' => true,
                'show_product_barcode_on_receipts' => false,
            ]))
            ->assertRedirect(route('super-admin.tenants.index'));

        $business = Business::query()->where('name', 'Tenant identificadores')->firstOrFail();
        $business->tenantSetting()->update(['allow_negative_stock' => true]);

        $this->assertTrue($business->tenantSetting->show_product_code_on_receipts);
        $this->assertFalse($business->tenantSetting->show_product_barcode_on_receipts);

        $this->actingAs($superAdmin)
            ->get(route('super-admin.tenants.edit', $business))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.show_product_code_on_receipts', true)
                ->where('settings.show_product_barcode_on_receipts', false));

        $this->actingAs($superAdmin)
            ->put(route('super-admin.tenants.update', $business), $this->tenantPayload($business->name, [
                'show_product_code_on_receipts' => false,
                'show_product_barcode_on_receipts' => true,
                'allow_negative_stock' => true,
            ]))
            ->assertRedirect(route('super-admin.tenants.index'));

        $settings = $business->fresh()->tenantSetting;
        $this->assertFalse($settings->show_product_code_on_receipts);
        $this->assertTrue($settings->show_product_barcode_on_receipts);
        $this->assertTrue($settings->allow_negative_stock);

        $this->actingAs($superAdmin)
            ->put(route('super-admin.tenants.update', $business), $this->tenantPayload($business->name, [
                'allow_negative_stock' => true,
            ]))
            ->assertRedirect(route('super-admin.tenants.index'));

        $settings = $business->fresh()->tenantSetting;
        $this->assertFalse($settings->show_product_code_on_receipts);
        $this->assertTrue($settings->show_product_barcode_on_receipts);
    }

    public function test_tenant_owner_cannot_manage_identifier_print_settings_and_tenants_stay_isolated(): void
    {
        $superAdmin = $this->superAdmin();
        $first = $this->business('Tenant A', true, false);
        $second = $this->business('Tenant B', false, true);
        $owner = User::factory()->create([
            'business_id' => $first->id,
            'role' => 'owner',
            'is_active' => true,
            'is_super_admin' => false,
        ]);
        Permissions::assignRole($owner, 'owner');

        $this->actingAs($owner)
            ->put(route('super-admin.tenants.update', $first), $this->tenantPayload($first->name, [
                'show_product_code_on_receipts' => false,
                'show_product_barcode_on_receipts' => false,
            ]))
            ->assertForbidden();

        $this->assertFalse(Route::has('settings.company.update'));
        $this->assertTrue($first->fresh()->tenantSetting->show_product_code_on_receipts);
        $this->assertFalse($first->fresh()->tenantSetting->show_product_barcode_on_receipts);
        $this->assertFalse($second->fresh()->tenantSetting->show_product_code_on_receipts);
        $this->assertTrue($second->fresh()->tenantSetting->show_product_barcode_on_receipts);

        $this->actingAs($superAdmin)
            ->put(route('super-admin.tenants.update', $first), $this->tenantPayload($first->name, [
                'show_product_code_on_receipts' => true,
                'show_product_barcode_on_receipts' => true,
            ]))
            ->assertRedirect(route('super-admin.tenants.index'));

        $this->assertTrue($first->fresh()->tenantSetting->show_product_code_on_receipts);
        $this->assertTrue($first->fresh()->tenantSetting->show_product_barcode_on_receipts);
        $this->assertFalse($second->fresh()->tenantSetting->show_product_code_on_receipts);
        $this->assertTrue($second->fresh()->tenantSetting->show_product_barcode_on_receipts);
    }

    public function test_product_identifier_print_partial_respects_each_tenant_setting_without_changing_amounts(): void
    {
        $item = (object) ['product' => (object) ['code' => 'SKU-001', 'barcode' => '00012345']];
        $settings = new TenantSetting(['show_product_code_on_receipts' => false, 'show_product_barcode_on_receipts' => false]);

        $this->assertSame('', trim(view('print.partials.product-identifiers', compact('item', 'settings'))->render()));

        $settings->show_product_code_on_receipts = true;
        $this->assertStringContainsString('Código: SKU-001', view('print.partials.product-identifiers', compact('item', 'settings'))->render());
        $this->assertStringNotContainsString('Barras:', view('print.partials.product-identifiers', compact('item', 'settings'))->render());

        $settings->show_product_code_on_receipts = false;
        $settings->show_product_barcode_on_receipts = true;
        $this->assertStringContainsString('Barras: 00012345', view('print.partials.product-identifiers', compact('item', 'settings'))->render());
        $this->assertStringNotContainsString('Código:', view('print.partials.product-identifiers', compact('item', 'settings'))->render());

        $settings->show_product_code_on_receipts = true;
        $printed = view('print.partials.product-identifiers', compact('item', 'settings'))->render();
        $this->assertStringContainsString('Código: SKU-001', $printed);
        $this->assertStringContainsString('Barras: 00012345', $printed);
        $this->assertSame(1, substr_count($printed, 'Código: SKU-001'));
        $this->assertSame(1, substr_count($printed, 'Barras: 00012345'));
    }

    public function test_credit_receipt_prints_respect_each_tenant_identifier_setting_and_decimal_quantity(): void
    {
        $codeOnly = $this->creditReceipt($this->business('Reserva código', true, false));
        $barcodeOnly = $this->creditReceipt($this->business('Reserva barras', false, true));

        foreach (['ticket', 'document'] as $format) {
            $codePrint = $this->renderCreditReceipt($codeOnly, $format);
            $this->assertStringContainsString('Código: SKU-001', $codePrint);
            $this->assertStringNotContainsString('Barras: 00012345', $codePrint);
            $this->assertStringContainsString('1.1250', $codePrint);

            $barcodePrint = $this->renderCreditReceipt($barcodeOnly, $format);
            $this->assertStringNotContainsString('Código: SKU-001', $barcodePrint);
            $this->assertStringContainsString('Barras: 00012345', $barcodePrint);
            $this->assertSame(1, substr_count($barcodePrint, 'Barras: 00012345'));
        }
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
    }

    private function business(string $name, bool $showCode, bool $showBarcode): Business
    {
        $business = Business::query()->create([
            'name' => $name,
            'country' => 'GT',
            'currency' => 'GTQ',
            'is_active' => true,
        ]);
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_product_images' => true,
            'max_users' => 2,
            'receipt_format' => 'ticket',
            'allow_receipts' => true,
            'allow_invoices' => false,
            'show_product_code_on_receipts' => $showCode,
            'show_product_barcode_on_receipts' => $showBarcode,
        ]);

        return $business;
    }

    private function creditReceipt(Business $business): CreditReceipt
    {
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente de reserva',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'country' => 'GT',
            'is_final_consumer' => true,
        ]);
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Producto decimal',
            'code' => 'SKU-001',
            'barcode' => '00012345',
            'cost_price' => 10,
            'sale_price' => 20,
            'stock' => '10.0000',
            'min_stock' => '0.0000',
            'allow_decimal_quantity' => true,
            'is_active' => true,
        ]);
        $receipt = CreditReceipt::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Cliente de reserva',
            'customer_doc_type' => 'CF',
            'customer_doc_number' => 'CF',
            'receipt_number' => 1,
            'status' => 'pending',
            'subtotal' => 22.50,
            'discount_amount' => 0,
            'total' => 22.50,
            'pending_total' => 22.50,
        ]);
        CreditReceiptLine::query()->create([
            'business_id' => $business->id,
            'credit_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->code,
            'quantity' => '1.1250',
            'qty_reserved' => '1.1250',
            'qty_pending' => '1.1250',
            'unit_price' => 20,
            'line_total' => 22.50,
            'pending_total' => 22.50,
            'status' => 'pending',
        ]);

        return $receipt->fresh(['business.tenantSetting', 'lines.product']);
    }

    private function renderCreditReceipt(CreditReceipt $receipt, string $format): string
    {
        return view("credits.receipt-{$format}", [
            'receipt' => $receipt,
            'logoUrl' => null,
            'number' => 'CR-000001',
            'previousPending' => 0,
            'newPending' => 22.50,
            'customerLocation' => ['display' => ''],
            'delivery' => ['address_reference' => '', 'phone' => ''],
        ])->render();
    }

    private function tenantPayload(string $name, array $overrides = []): array
    {
        return array_replace([
            'name' => $name,
            'country' => 'GT',
            'is_active' => true,
            'use_product_images' => true,
            'max_users' => 2,
            'receipt_format' => 'ticket',
            'allow_receipts' => true,
            'allow_invoices' => false,
            'modules' => ['pos'],
        ], $overrides);
    }
}
