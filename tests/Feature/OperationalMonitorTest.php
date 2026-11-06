<?php

namespace Tests\Feature;

use App\Models\OperationalIncident;
use App\Models\User;
use App\Services\System\OperationalIncidentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
