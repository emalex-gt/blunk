<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CustomerTaxLookup;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Services\Fel\FelIdentityLookupException;
use App\Services\Fel\Providers\Digifact\DigifactClient;
use App\Support\GuatemalaCuiCustomerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DigifactCuiLookupTest extends TestCase
{
    use RefreshDatabase;

    private const CUI = '2249256361307';

    public function test_lookup_cui_uses_the_nuc_shared_contract_and_parses_an_active_response(): void
    {
        $business = $this->business();
        $this->felSettings($business);

        Http::fake([
            '*' => Http::response([
                'REQUEST_DATA' => [['Respuesta' => 1, 'Codigo' => 1]],
                'RESPONSE' => [[
                    'PAIS' => 'GT',
                    'CUI' => self::CUI,
                    'NOMBRE' => 'EMANUEL ALEJANDRO LÓPEZ LÓPEZ',
                    'STATUS' => 'A',
                ]],
            ]),
        ]);

        $result = DigifactClient::forBusiness($business)->lookupCui(self::CUI);

        $this->assertSame(self::CUI, $result['cui']);
        $this->assertSame('EMANUEL ALEJANDRO LÓPEZ LÓPEZ', $result['name']);
        $this->assertSame('A', $result['status']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://testnucgt.digifact.com/api/Shared?COUNTRY=GT&TAXID=000005888492&DATA1=SHARED_GETINFOCUI&DATA2=CUI%7C'.self::CUI.'&USERNAME=TESTUSER'
                && $request->hasHeader('Authorization', 'cached-token');
        });
    }

    public function test_lookup_cui_treats_an_empty_response_as_not_found(): void
    {
        $business = $this->business();
        $this->felSettings($business);
        Http::fake(['*' => Http::response(['REQUEST_DATA' => [['Respuesta' => 1, 'Codigo' => 1]], 'RESPONSE' => []])]);

        try {
            DigifactClient::forBusiness($business)->lookupCui(self::CUI);
            $this->fail('Expected CUI not-found exception.');
        } catch (FelIdentityLookupException $exception) {
            $this->assertSame(FelIdentityLookupException::NOT_FOUND, $exception->reason());
        }
    }

    public function test_lookup_cui_rejects_an_inactive_response(): void
    {
        $business = $this->business();
        $this->felSettings($business);
        Http::fake(['*' => Http::response(['RESPONSE' => [['CUI' => self::CUI, 'NOMBRE' => 'Persona inactiva', 'STATUS' => 'I']]])]);

        try {
            DigifactClient::forBusiness($business)->lookupCui(self::CUI);
            $this->fail('Expected inactive CUI exception.');
        } catch (FelIdentityLookupException $exception) {
            $this->assertSame(FelIdentityLookupException::INACTIVE, $exception->reason());
        }
    }

    public function test_lookup_cui_rejects_malformed_provider_data(): void
    {
        $business = $this->business();
        $this->felSettings($business);
        Http::fake(['*' => Http::response(['RESPONSE' => [['CUI' => self::CUI, 'STATUS' => 'A']]])]);

        try {
            DigifactClient::forBusiness($business)->lookupCui(self::CUI);
            $this->fail('Expected malformed CUI response exception.');
        } catch (FelIdentityLookupException $exception) {
            $this->assertSame(FelIdentityLookupException::MALFORMED_RESPONSE, $exception->reason());
        }
    }

    public function test_lookup_cui_reports_unauthorized_after_the_token_retry(): void
    {
        $business = $this->business();
        $this->felSettings($business);
        Http::fakeSequence()
            ->push(['Mensaje' => 'Unauthorized'], 401)
            ->push(['Token' => 'refreshed-token'])
            ->push(['Mensaje' => 'Unauthorized'], 401);

        try {
            DigifactClient::forBusiness($business)->lookupCui(self::CUI);
            $this->fail('Expected unauthorized CUI exception.');
        } catch (FelIdentityLookupException $exception) {
            $this->assertSame(FelIdentityLookupException::UNAUTHORIZED, $exception->reason());
        }
    }

    public function test_cui_resolver_requires_exactly_thirteen_digits_before_calling_digifact(): void
    {
        $business = $this->business();
        Http::fake();

        $this->expectException(ValidationException::class);

        try {
            GuatemalaCuiCustomerResolver::lookupTaxData($business, '12345');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_lookup_cui_validates_the_local_format_before_a_remote_call(): void
    {
        $business = $this->business();
        Http::fake();

        $this->expectException(ValidationException::class);

        try {
            DigifactClient::forBusiness($business)->lookupCui('2249-256');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_lookup_cui_distinguishes_network_failures(): void
    {
        $business = $this->business();
        $this->felSettings($business);
        Http::fake(fn () => throw new ConnectionException('Connection timed out.'));

        try {
            DigifactClient::forBusiness($business)->lookupCui(self::CUI);
            $this->fail('Expected network CUI exception.');
        } catch (FelIdentityLookupException $exception) {
            $this->assertSame(FelIdentityLookupException::NETWORK, $exception->reason());
        }
    }

    public function test_cui_resolver_persists_and_reuses_the_typed_lookup_cache(): void
    {
        $business = $this->business();
        $this->felSettings($business);
        Http::fake(['*' => Http::response([
            'RESPONSE' => [['CUI' => self::CUI, 'NOMBRE' => 'EMANUEL ALEJANDRO LÓPEZ LÓPEZ', 'STATUS' => 'A']],
        ])]);

        $first = GuatemalaCuiCustomerResolver::lookupTaxData($business, self::CUI, allowCache: false);
        $second = GuatemalaCuiCustomerResolver::lookupTaxData($business, self::CUI);

        $this->assertSame('digifact', $first['source']);
        $this->assertSame('cache', $second['source']);
        $this->assertSame('CUI', CustomerTaxLookup::query()->where('business_id', $business->id)->value('doc_type'));
        $this->assertSame(self::CUI, CustomerTaxLookup::query()->where('business_id', $business->id)->value('doc_number'));
        Http::assertSentCount(1);
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'CUI Lookup Test',
            'country' => 'GT',
            'currency' => 'GTQ',
            'timezone' => 'America/Guatemala',
            'is_active' => true,
        ]);
    }

    private function felSettings(Business $business): TenantFelSetting
    {
        $settings = TenantFelSetting::query()->create([
            'business_id' => $business->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'enabled' => true,
            'issuer_tax_id' => '5888492',
            'username' => 'TESTUSER',
            'password' => 'secret',
            'token' => 'cached-token',
            'token_expires_at' => now()->addHour(),
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

        return $settings;
    }
}
