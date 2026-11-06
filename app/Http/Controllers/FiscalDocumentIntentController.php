<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocumentIntent;
use App\Services\Fiscal\FiscalDocumentIntentService;
use App\Services\BranchAccessService;
use App\Support\OperationDrafts;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class FiscalDocumentIntentController extends Controller
{
    public function acceptProvisionalReceipt(Request $request, FiscalDocumentIntent $intent, FiscalDocumentIntentService $intents): RedirectResponse
    {
        abort_unless((int) $intent->business_id === (int) currentBusinessId(), 403);
        abort_unless($intent->branch_id && app(BranchAccessService::class)->canAccessBranch($request->user(), (int) $intent->branch_id, currentBusinessId()), 403);

        $intent = $intents->acceptProvisionalCommercialFinalization($intent, $request->user()->id);

        OperationDrafts::markConverted(
            filled(data_get($intent->operation_payload, 'draft_id')) ? (int) data_get($intent->operation_payload, 'draft_id') : null,
            'pos_sale',
            'sale',
            (int) $intent->finalized_sale_id,
            $request,
        );

        return back()->with('success', 'Constancia provisional de operación aceptada.')
            ->with('provisional_receipt_url', route('fiscal-intents.provisional-receipt', $intent));
    }

    public function provisionalReceipt(Request $request, FiscalDocumentIntent $intent): Response
    {
        abort_unless((int) $intent->business_id === (int) currentBusinessId(), 403);
        abort_unless($intent->branch_id && app(BranchAccessService::class)->canAccessBranch($request->user(), (int) $intent->branch_id, currentBusinessId()), 403);
        $sale = $intent->finalizedSale()->first();
        abort_unless(
            $intent->provisional_accepted_at
            && $sale
            && $sale->status === 'completed',
            404,
        );

        return response()->view('fiscal-intents.provisional-receipt', [
            'intent' => $intent->load('business.tenantFelSetting', 'branch'),
            'operation' => $intent->operation_payload ?? [],
        ]);
    }
}
