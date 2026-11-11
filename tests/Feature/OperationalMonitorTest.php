<?php

namespace Tests\Feature;

use App\Models\OperationalIncident;
use App\Models\OperationalMonitorRun;
use App\Models\User;
use App\Services\System\OperationalIncidentManager;
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
}
