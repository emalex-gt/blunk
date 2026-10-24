<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocumentIntent;
use App\Services\BranchAccessService;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

class FiscalDocumentIntentController extends Controller
{
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
