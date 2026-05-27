<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\FelIncident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FelIncidentController extends Controller
{
    public function index(): Response
    {
        $incidents = FelIncident::query()
            ->with(['business:id,name', 'sale:id,business_id'])
            ->latest()
            ->paginate(25)
            ->through(fn (FelIncident $incident) => [
                'id' => $incident->id,
                'business' => $incident->business ? [
                    'id' => $incident->business->id,
                    'name' => $incident->business->name,
                ] : null,
                'sale_id' => $incident->sale_id,
                'internal_reference' => $incident->internal_reference,
                'type' => $incident->type,
                'severity' => $incident->severity,
                'status' => $incident->status,
                'message' => $incident->message,
                'created_at' => $incident->created_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('SuperAdmin/FelIncidents/Index', [
            'incidents' => $incidents,
        ]);
    }

    public function review(Request $request, FelIncident $incident): RedirectResponse
    {
        $incident->update([
            'status' => 'reviewed',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Incidencia marcada como revisada.');
    }

    public function resolve(Request $request, FelIncident $incident): RedirectResponse
    {
        $incident->update([
            'status' => 'resolved',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return back()->with('success', 'Incidencia resuelta.');
    }
}
