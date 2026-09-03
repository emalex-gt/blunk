<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Services\Routes\RoutePreSaleFelAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutePreSaleFelAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_fel_availability_reports_an_inactive_module_without_reading_credentials(): void
    {
        $business = $this->business();
        $this->configuredFel($business);

        $result = app(RoutePreSaleFelAvailabilityService::class)->evaluate($business);

        $this->assertFalse($result['available']);
        $this->assertSame('module_disabled', $result['reason_code']);
        $this->assertSame('El módulo FEL no está activo para este negocio.', $result['reason']);
    }

    public function test_fel_availability_reports_missing_or_incomplete_provider_settings(): void
    {
        $business = $this->business();
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'fel_gt', 'is_enabled' => true, 'enabled_at' => now()]);

        $missing = app(RoutePreSaleFelAvailabilityService::class)->evaluate($business);

        $this->assertFalse($missing['available']);
        $this->assertSame('provider_missing', $missing['reason_code']);

        TenantFelSetting::query()->create([
            'business_id' => $business->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'enabled' => true,
            'issuer_tax_id' => '5888492',
        ]);

        $incomplete = app(RoutePreSaleFelAvailabilityService::class)->evaluate($business->fresh());

        $this->assertFalse($incomplete['available']);
        $this->assertSame('credentials_missing', $incomplete['reason_code']);
        $this->assertSame('FEL no está configurado para certificación automática.', $incomplete['reason']);
    }

    public function test_fel_availability_accepts_an_enabled_module_and_complete_digifact_configuration(): void
    {
        $business = $this->business();
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'fel_gt', 'is_enabled' => true, 'enabled_at' => now()]);
        $this->configuredFel($business);

        $result = app(RoutePreSaleFelAvailabilityService::class)->evaluate($business);

        $this->assertTrue($result['available']);
        $this->assertNull($result['reason_code']);
        $this->assertNull($result['reason']);
    }

    private function business(): Business
    {
        $business = Business::query()->create([
            'name' => 'FEL '.uniqid(),
            'slug' => 'fel-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);

        TenantSetting::query()->create(['business_id' => $business->id, 'allow_invoices' => true]);

        return $business;
    }

    private function configuredFel(Business $business): void
    {
        $settings = TenantFelSetting::query()->create([
            'business_id' => $business->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'enabled' => true,
            'issuer_tax_id' => '5888492',
            'username' => 'TESTUSER',
            'password' => 'secret',
            'test_base_url' => 'https://testnucgt.digifact.com/api',
            'affiliate_type' => 'GEN',
        ]);

        TenantFelPhrase::query()->create([
            'business_id' => $business->id,
            'tenant_fel_setting_id' => $settings->id,
            'data_identifier' => '1',
            'phrase_type' => '1',
            'scenario_code' => '2',
            'type_data' => '1',
            'type_value' => '1',
            'scenario_data' => '1',
            'scenario_value' => '2',
        ]);
    }
}
