<?php

namespace Tests\Feature;

use App\Models\OperationalIncident;
use App\Models\OperationalMonitorRun;
use App\Models\Business;
use App\Models\FiscalDocumentIntent;
use App\Models\User;
use App\Services\System\OperationalIncidentManager;
use App\Services\System\OperationalMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationalMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_deduplicates_an_incident_and_tracks_occurrences(): void
    {
        $manager = app(OperationalIncidentManager::class);
        $manager->observe('monitor:test-db', 'database_unavailable', 'P0', 'Base de datos no disponible.');
        $manager->observe('monitor:test-db', 'database_unavailable', 'P0', 'Base de datos no disponible.');
        $this->assertDatabaseCount('operational_incidents', 1);
        $this->assertSame(2, OperationalIncident::firstOrFail()->occurrence_count);
    }

    public function test_only_super_admin_can_view_or_acknowledge_monitor_incidents(): void
    {
        $incident = OperationalIncident::query()->create([
            'fingerprint' => 'monitor:test-access', 'type' => 'test', 'severity' => 'P1', 'status' => 'open',
            'message' => 'Prueba.', 'detected_at' => now(), 'last_occurred_at' => now(),
        ]);
        $user = User::factory()->create(['is_super_admin' => false]);
        $this->actingAs($user)->get(route('super-admin.system.monitor.index'))->assertForbidden();
        $this->actingAs($user)->post(route('super-admin.system.monitor.acknowledge', $incident))->assertForbidden();
    }

    public function test_absent_incidents_are_resolved_only_for_checks_that_completed(): void
    {
        $manager = app(OperationalIncidentManager::class);
        $queue = OperationalIncident::query()->create([
            'fingerprint' => 'monitor:failed_jobs', 'type' => 'queue_failed_jobs', 'severity' => 'P1', 'status' => 'open',
            'message' => 'Cola.', 'detected_at' => now(), 'last_occurred_at' => now(),
        ]);
        $fiscal = OperationalIncident::query()->create([
            'fingerprint' => 'monitor:fel_stale:1', 'type' => 'fel_stale_intents', 'severity' => 'P0', 'status' => 'open',
            'message' => 'FEL.', 'detected_at' => now(), 'last_occurred_at' => now(),
        ]);

        $manager->recoverAbsent([], ['queue']);

        $this->assertSame(OperationalIncident::RESOLVED, $queue->fresh()->status);
        $this->assertSame(OperationalIncident::OPEN, $fiscal->fresh()->status);
    }

    public function test_super_admin_can_see_the_last_monitor_execution_without_active_incidents(): void
    {
        OperationalMonitorRun::query()->create([
            'status' => 'healthy', 'checks' => ['database' => ['status' => 'ok', 'active_incidents' => 0]],
            'active_incident_count' => 0, 'started_at' => now()->subSecond(), 'finished_at' => now(), 'duration_ms' => 10,
        ]);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->get(route('super-admin.system.monitor.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/System/Monitor')
                ->where('lastRun.status', 'healthy')
                ->where('lastRun.active_incident_count', 0)
            );
    }

    public function test_a_truncated_fiscal_intent_sample_never_resolves_existing_fiscal_incidents(): void
    {
        config(['operational_monitor.enabled' => true, 'operational_monitor.fel_stale_minutes' => 5]);
        $firstBusiness = $this->business('Monitor sample one');
        $secondBusiness = $this->business('Monitor sample two');
        $legacy = OperationalIncident::query()->create([
            'fingerprint' => 'monitor:fel_stale:'.$firstBusiness->id,
            'business_id' => $firstBusiness->id,
            'type' => 'fel_stale_intents',
            'severity' => 'P0',
            'status' => OperationalIncident::OPEN,
            'message' => 'FEL.',
            'detected_at' => now(),
            'last_occurred_at' => now(),
        ]);

        foreach (range(1, 101) as $index) {
            $business = $index % 2 === 0 ? $firstBusiness : $secondBusiness;
            $intent = FiscalDocumentIntent::query()->create([
                'business_id' => $business->id,
                'country' => 'GT',
                'provider' => 'digifact',
                'environment' => $index % 3 === 0 ? 'production' : 'test',
                'document_type' => 'invoice',
                'operation_type' => 'sale',
                'internal_reference' => 'MON-SAMPLE-'.$index,
                'idempotency_key' => 'mon-sample-'.$index,
                'status' => FiscalDocumentIntent::UNKNOWN,
                'operation_payload' => [],
                'fiscal_payload' => [],
            ]);
            // updated_at is guarded on FiscalDocumentIntent, so persist the
            // intended stale timestamp without invoking Eloquent timestamps.
            FiscalDocumentIntent::query()->whereKey($intent->id)->update(['updated_at' => now()->subHour()]);
        }

        app(OperationalMonitor::class)->run();

        $this->assertSame(OperationalIncident::OPEN, $legacy->fresh()->status);
        $this->assertSame('partial', OperationalMonitorRun::query()->latest('id')->firstOrFail()->checks['fiscal']['status']);
    }

    public function test_a_successful_partial_fiscal_check_recovers_only_its_technical_failure(): void
    {
        config(['operational_monitor.enabled' => true, 'operational_monitor.fel_stale_minutes' => 5]);
        $business = $this->business('Monitor technical recovery');
        $technical = OperationalIncident::query()->create([
            'fingerprint' => 'monitor:fiscal_check_failed', 'type' => 'monitor_check_failed', 'severity' => 'P1',
            'status' => OperationalIncident::OPEN, 'message' => 'Comprobación fiscal.', 'detected_at' => now(), 'last_occurred_at' => now(),
        ]);
        $stale = OperationalIncident::query()->create([
            'fingerprint' => 'monitor:fel_stale:'.$business->id, 'business_id' => $business->id,
            'type' => 'fel_stale_intents', 'severity' => 'P0', 'status' => OperationalIncident::OPEN,
            'message' => 'FEL.', 'detected_at' => now(), 'last_occurred_at' => now(),
        ]);

        foreach (range(1, 101) as $index) {
            $intent = FiscalDocumentIntent::query()->create([
                'business_id' => $business->id, 'country' => 'GT', 'provider' => 'digifact', 'environment' => 'test',
                'document_type' => 'invoice', 'operation_type' => 'sale', 'internal_reference' => 'MON-TECH-'.$index,
                'idempotency_key' => 'mon-tech-'.$index, 'status' => FiscalDocumentIntent::UNKNOWN,
                'operation_payload' => [], 'fiscal_payload' => [],
            ]);
            // Keep this source genuinely truncated even though updated_at is guarded.
            FiscalDocumentIntent::query()->whereKey($intent->id)->update(['updated_at' => now()->subHour()]);
        }

        app(OperationalMonitor::class)->run();

        $this->assertSame(OperationalIncident::RESOLVED, $technical->fresh()->status);
        $this->assertSame(OperationalIncident::OPEN, $stale->fresh()->status);
    }

    public function test_ignored_test_tenant_preserves_production_monitoring(): void
    {
        config([
            'operational_monitor.enabled' => true,
            'operational_monitor.fel_stale_minutes' => 5,
            'operational_monitor.ignored_test_business_ids' => [],
            'operational_monitor.telegram.token' => null,
            'operational_monitor.telegram.chat_id' => null,
            'operational_monitor.mail_to' => [],
        ]);

        $ignored = $this->business('Ignored test tenant');
        $other = $this->business('Other tenant');

        $createIntent = function ($business, $environment, $reference) {
            $intent = FiscalDocumentIntent::query()->create([
                'business_id' => $business->id,
                'country' => 'GT',
                'provider' => 'digifact',
                'environment' => $environment,
                'document_type' => 'invoice',
                'operation_type' => 'sale',
                'internal_reference' => $reference,
                'idempotency_key' => $reference,
                'status' => FiscalDocumentIntent::UNKNOWN,
                'operation_payload' => [],
                'fiscal_payload' => [],
            ]);

            FiscalDocumentIntent::query()
                ->whereKey($intent->id)
                ->update(['updated_at' => now()->subHour()]);

            return $intent;
        };

        $intent = $createIntent($ignored, 'test', 'MON-IGNORE-TEST');

        app(OperationalMonitor::class)->run();

        $fingerprint = 'monitor:fel_stale:'.$ignored->id;

        $this->assertDatabaseHas('operational_incidents', [
            'fingerprint' => $fingerprint.':test',
            'status' => OperationalIncident::OPEN,
        ]);

        OperationalIncident::query()->create([
            'fingerprint' => $fingerprint,
            'business_id' => $ignored->id,
            'type' => 'fel_stale_intents',
            'severity' => 'P0',
            'status' => OperationalIncident::OPEN,
            'message' => 'Legacy alert.',
            'detected_at' => now(),
            'last_occurred_at' => now(),
        ]);

        config([
            'operational_monitor.ignored_test_business_ids' => [
                $ignored->id,
            ],
        ]);

        app(OperationalMonitor::class)->run();

        foreach ([$fingerprint, $fingerprint.':test'] as $key) {
            $this->assertDatabaseHas('operational_incidents', [
                'fingerprint' => $key,
                'status' => OperationalIncident::RESOLVED,
            ]);
        }

        $this->assertSame(
            FiscalDocumentIntent::UNKNOWN,
            $intent->fresh()->status
        );

        $createIntent($ignored, 'production', 'MON-IGNORE-PROD');
        $createIntent($other, 'test', 'MON-OTHER-TEST');

        app(OperationalMonitor::class)->run();

        $this->assertDatabaseHas('operational_incidents', [
            'fingerprint' => $fingerprint.':production',
            'status' => OperationalIncident::OPEN,
            'severity' => 'P0',
        ]);

        $this->assertDatabaseHas('operational_incidents', [
            'fingerprint' => 'monitor:fel_stale:'.$other->id.':test',
            'status' => OperationalIncident::OPEN,
        ]);

        $this->assertDatabaseHas('operational_incidents', [
            'fingerprint' => $fingerprint.':test',
            'status' => OperationalIncident::RESOLVED,
        ]);
    }

    private function business(string $name): Business
    {
        return Business::query()->create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
    }
}
