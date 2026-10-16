<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\MaintenanceAnnouncement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MaintenanceAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_non_super_admin_cannot_manage_maintenance_announcements(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get(route('super-admin.system.maintenance-announcements.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_create_a_scheduled_maintenance_announcement(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($superAdmin)
            ->post(route('super-admin.system.maintenance-announcements.store'), $this->payload())
            ->assertRedirect(route('super-admin.system.maintenance-announcements.index'));

        $announcement = MaintenanceAnnouncement::query()->sole();

        $this->assertSame($superAdmin->id, $announcement->created_by_user_id);
        $this->assertTrue($announcement->enabled);
        $this->assertSame('Mantenimiento programado', $announcement->title);
        $this->assertSame('2026-10-20 12:00:00', $announcement->visible_from->utc()->format('Y-m-d H:i:s'));
    }

    public function test_super_admin_can_view_the_maintenance_announcement_screen(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($superAdmin)
            ->get(route('super-admin.system.maintenance-announcements.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/System/MaintenanceAnnouncements')
                ->where('announcement', null)
                ->where('history', []));
    }

    public function test_invalid_maintenance_visibility_windows_are_rejected(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($superAdmin)
            ->post(route('super-admin.system.maintenance-announcements.store'), $this->payload([
                'visible_from' => '2026-10-20T14:00',
                'starts_at' => '2026-10-20T12:00',
                'ends_at' => '2026-10-20T12:00',
            ]))
            ->assertSessionHasErrors(['visible_from', 'ends_at']);

        $this->assertDatabaseCount('maintenance_announcements', 0);
    }

    public function test_future_announcement_is_not_shared_before_its_visibility_window(): void
    {
        CarbonImmutable::setTestNow('2026-10-16 12:00:00 UTC');
        $this->createAnnouncement([
            'visible_from' => now()->addHour(),
            'starts_at' => now()->addHours(24),
            'ends_at' => now()->addHours(26),
        ]);

        $this->authenticatedResponse()
            ->assertInertia(fn (Assert $page) => $page->where('maintenance_announcement', null));
    }

    public function test_visible_scheduled_announcement_is_shared_with_safe_public_fields(): void
    {
        CarbonImmutable::setTestNow('2026-10-16 12:00:00 UTC');
        $this->createAnnouncement([
            'visible_from' => now()->subHour(),
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(3),
            'expected_downtime' => '10 minutos',
        ]);

        $this->authenticatedResponse()
            ->assertInertia(fn (Assert $page) => $page
                ->where('maintenance_announcement.title', 'Mantenimiento programado')
                ->where('maintenance_announcement.message', 'Actualizaremos el sistema.')
                ->where('maintenance_announcement.expected_downtime', '10 minutos')
                ->where('maintenance_announcement.status', 'scheduled')
                ->missing('maintenance_announcement.created_by_user_id')
                ->missing('maintenance_announcement.updated_by_user_id'));
    }

    public function test_active_announcement_is_shared(): void
    {
        CarbonImmutable::setTestNow('2026-10-16 12:00:00 UTC');
        $this->createAnnouncement([
            'visible_from' => now()->subHours(2),
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->authenticatedResponse()
            ->assertInertia(fn (Assert $page) => $page->where('maintenance_announcement.status', 'active'));
    }

    public function test_expired_announcement_is_not_shared(): void
    {
        CarbonImmutable::setTestNow('2026-10-16 12:00:00 UTC');
        $this->createAnnouncement([
            'visible_from' => now()->subHours(3),
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);

        $this->authenticatedResponse()
            ->assertInertia(fn (Assert $page) => $page->where('maintenance_announcement', null));
    }

    public function test_disabled_announcement_is_not_shared(): void
    {
        CarbonImmutable::setTestNow('2026-10-16 12:00:00 UTC');
        $this->createAnnouncement([
            'visible_from' => now()->subHour(),
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'enabled' => false,
        ]);

        $this->authenticatedResponse()
            ->assertInertia(fn (Assert $page) => $page->where('maintenance_announcement', null));
    }

    public function test_authenticated_layout_renders_the_maintenance_banner_contract(): void
    {
        $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.tsx'));

        $this->assertStringContainsString('MaintenanceAnnouncementBanner', $layout);
        $this->assertStringContainsString('maintenance_announcement', $layout);
    }

    public function test_guest_login_remains_available_without_a_maintenance_announcement(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('maintenance_announcement', null));
    }

    private function authenticatedResponse()
    {
        $business = Business::query()->create([
            'name' => 'Tenant de prueba',
            'currency' => 'GTQ',
            'country' => 'GT',
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);

        return $this->actingAs($user)->get(route('profile.edit'));
    }

    private function createAnnouncement(array $attributes = []): MaintenanceAnnouncement
    {
        return MaintenanceAnnouncement::query()->create(array_merge([
            'title' => 'Mantenimiento programado',
            'message' => 'Actualizaremos el sistema.',
            'visible_from' => now()->subHour(),
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'enabled' => true,
        ], $attributes));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Mantenimiento programado',
            'message' => 'Actualizaremos el sistema.',
            'visible_from' => '2026-10-20T12:00',
            'starts_at' => '2026-10-20T14:00',
            'ends_at' => '2026-10-20T15:00',
            'expected_downtime' => '10 minutos',
            'enabled' => true,
        ], $overrides);
    }
}
