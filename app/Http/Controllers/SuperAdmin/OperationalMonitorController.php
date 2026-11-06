<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\OperationalIncident;
use App\Services\System\OperationalIncidentManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OperationalMonitorController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['status' => ['nullable', 'in:open,acknowledged,resolved'], 'severity' => ['nullable', 'in:P0,P1,P2'], 'type' => ['nullable', 'string', 'max:100']]);
        $query = OperationalIncident::query()->with(['business:id,name', 'acknowledgedBy:id,name', 'resolvedBy:id,name'])->latest('last_occurred_at');
        foreach (['status', 'severity', 'type'] as $field) if (filled($filters[$field] ?? null)) $query->where($field, $filters[$field]);
        return Inertia::render('SuperAdmin/System/Monitor', [
            'filters' => ['status' => $filters['status'] ?? '', 'severity' => $filters['severity'] ?? '', 'type' => $filters['type'] ?? ''],
            'incidents' => $query->paginate(50)->withQueryString(),
            'summary' => [
                'p0_open' => OperationalIncident::query()->whereIn('status', ['open', 'acknowledged'])->where('severity', 'P0')->count(),
                'p1_open' => OperationalIncident::query()->whereIn('status', ['open', 'acknowledged'])->where('severity', 'P1')->count(),
                'active' => OperationalIncident::query()->whereIn('status', ['open', 'acknowledged'])->count(),
            ],
        ]);
    }

    public function acknowledge(Request $request, OperationalIncident $incident, OperationalIncidentManager $manager): RedirectResponse
    {
        $manager->acknowledge($incident, $request->user());
        return back();
    }
}
