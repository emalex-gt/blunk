<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceAnnouncement;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceAnnouncementController extends Controller
{
    public function index(): Response
    {
        $now = now();
        $announcement = MaintenanceAnnouncement::query()
            ->where('ends_at', '>', $now)
            ->latest('starts_at')
            ->first();

        return Inertia::render('SuperAdmin/System/MaintenanceAnnouncements', [
            'announcement' => $announcement ? $this->adminPayload($announcement) : null,
            'history' => MaintenanceAnnouncement::query()
                ->with(['createdBy:id,name', 'updatedBy:id,name'])
                ->latest('starts_at')
                ->limit(10)
                ->get()
                ->map(fn (MaintenanceAnnouncement $item) => $this->adminPayload($item))
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $existing = MaintenanceAnnouncement::query()
            ->where('ends_at', '>', now())
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'announcement' => 'Actualiza o cancela el anuncio de mantenimiento vigente antes de crear otro.',
            ]);
        }

        MaintenanceAnnouncement::query()->create([
            ...$this->validatedData($request),
            'created_by_user_id' => $request->user()->id,
        ]);

        return redirect()
            ->route('super-admin.system.maintenance-announcements.index')
            ->with('success', 'Anuncio de mantenimiento creado.');
    }

    public function update(Request $request, MaintenanceAnnouncement $maintenanceAnnouncement): RedirectResponse
    {
        $maintenanceAnnouncement->update([
            ...$this->validatedData($request),
            'updated_by_user_id' => $request->user()->id,
        ]);

        return redirect()
            ->route('super-admin.system.maintenance-announcements.index')
            ->with('success', 'Anuncio de mantenimiento actualizado.');
    }

    public function destroy(MaintenanceAnnouncement $maintenanceAnnouncement): RedirectResponse
    {
        $maintenanceAnnouncement->delete();

        return redirect()
            ->route('super-admin.system.maintenance-announcements.index')
            ->with('success', 'Anuncio de mantenimiento cancelado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        $noHtml = function (string $attribute, mixed $value, Closure $fail): void {
            if ((string) $value !== strip_tags((string) $value)) {
                $fail('No se permite HTML.');
            }
        };

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160', $noHtml],
            'message' => ['required', 'string', 'max:2000', $noHtml],
            'visible_from' => ['required', 'date_format:Y-m-d\\TH:i'],
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'expected_downtime' => ['nullable', 'string', 'max:160', $noHtml],
            'enabled' => ['required', 'boolean'],
        ]);

        $visibleFrom = CarbonImmutable::createFromFormat('Y-m-d\\TH:i', $data['visible_from'], 'UTC');
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d\\TH:i', $data['starts_at'], 'UTC');
        $endsAt = CarbonImmutable::createFromFormat('Y-m-d\\TH:i', $data['ends_at'], 'UTC');

        if ($visibleFrom->gt($startsAt) || $startsAt->gte($endsAt)) {
            throw ValidationException::withMessages([
                'visible_from' => 'La visibilidad debe iniciar antes o al inicio del mantenimiento.',
                'ends_at' => 'La finalización debe ser posterior al inicio del mantenimiento.',
            ]);
        }

        return [
            'title' => $data['title'],
            'message' => $data['message'],
            'visible_from' => $visibleFrom,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'expected_downtime' => $data['expected_downtime'] ?: null,
            'enabled' => (bool) $data['enabled'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminPayload(MaintenanceAnnouncement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'message' => $announcement->message,
            'visible_from' => $announcement->visible_from->copy()->utc()->format('Y-m-d\\TH:i'),
            'starts_at' => $announcement->starts_at->copy()->utc()->format('Y-m-d\\TH:i'),
            'ends_at' => $announcement->ends_at->copy()->utc()->format('Y-m-d\\TH:i'),
            'expected_downtime' => $announcement->expected_downtime,
            'enabled' => $announcement->enabled,
            'status' => $announcement->statusAt(),
            'created_by' => $announcement->createdBy?->name,
            'updated_by' => $announcement->updatedBy?->name,
            'updated_at' => $announcement->updated_at?->utc()->format('Y-m-d H:i'),
        ];
    }
}
