<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocumentIntent;
use App\Services\BranchAccessService;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalDocumentIntentController extends Controller
{
    public function acceptProvisionalReceipt(Request $request, FiscalDocumentIntent $intent): RedirectResponse
    {
        $intent = DB::transaction(function () use ($request, $intent) {
            $locked = FiscalDocumentIntent::query()->lockForUpdate()->findOrFail($intent->id);
            abort_unless((int) $locked->business_id === (int) currentBusinessId(), 403);
            abort_unless($locked->branch_id && app(BranchAccessService::class)->canAccessBranch($request->user(), (int) $locked->branch_id, currentBusinessId()), 403);

            if ($locked->status !== FiscalDocumentIntent::UNKNOWN) {
                throw ValidationException::withMessages(['intent' => 'La constancia provisional sólo puede aceptarse mientras la certificación FEL esté en verificación.']);
            }

            if (! $locked->provisional_accepted_at) {
                $locked->update([
                    'provisional_accepted_at' => now(),
                    'provisional_accepted_by' => $request->user()->id,
                ]);
            }

            return $locked->refresh();
        });

        return back()->with('success', 'Constancia provisional de operación aceptada.')
            ->with('provisional_receipt_url', route('fiscal-intents.provisional-receipt', $intent));
    }

    public function provisionalReceipt(Request $request, FiscalDocumentIntent $intent): Response
    {
        abort_unless((int) $intent->business_id === (int) currentBusinessId(), 403);
        abort_unless($intent->branch_id && app(BranchAccessService::class)->canAccessBranch($request->user(), (int) $intent->branch_id, currentBusinessId()), 403);
        abort_unless(in_array($intent->status, [
            FiscalDocumentIntent::PENDING,
            FiscalDocumentIntent::UNKNOWN,
        ], true), 404);

        return response()->view('fiscal-intents.provisional-receipt', [
            'intent' => $intent->load('business', 'branch'),
            'operation' => $intent->operation_payload ?? [],
        ]);
    }
}
