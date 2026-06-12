<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\CustomerAccountMovement;
use App\Models\FelReconciliationRequest;
use App\Models\Sale;
use App\Services\Fel\FelException;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Services\Fel\Providers\Digifact\DigifactReconciliationService;
use App\Support\AccountsReceivable;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FelReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Permissions::userHas($request->user(), Permissions::FEL_RECONCILE), 403);

        $requests = FelReconciliationRequest::query()
            ->where('business_id', currentBusinessId())
            ->with(['sale:id,business_id,business_number,certification_status', 'branch:id,name'])
            ->latest()
            ->paginate(25)
            ->through(fn (FelReconciliationRequest $item) => [
                'id' => $item->id,
                'internal_reference' => $item->internal_reference,
                'sale_id' => $item->sale_id,
                'sale_number' => $item->sale ? format_sale_number($item->sale) : null,
                'issued_date' => $item->issued_date?->format('Y-m-d H:i:s'),
                'provider' => $item->provider,
                'environment' => $item->environment,
                'status' => $item->status,
                'last_error' => $item->last_error,
                'attempts' => $item->attempts,
                'branch' => $item->branch?->name,
                'checked_at' => $item->checked_at?->format('Y-m-d H:i'),
                'resolved_at' => $item->resolved_at?->format('Y-m-d H:i'),
                'response' => $item->response_snapshot,
            ])
            ->withQueryString();

        return Inertia::render('Fel/Reconciliation/Index', [
            'requests' => $requests,
        ]);
    }

    public function check(
        Request $request,
        FelReconciliationRequest $reconciliation,
        DigifactReconciliationService $service,
        DigifactInvoiceService $invoiceService,
    ): RedirectResponse {
        abort_unless(Permissions::userHas($request->user(), Permissions::FEL_RECONCILE), 403);
        abort_unless((int) $reconciliation->business_id === (int) currentBusinessId(), 403);

        $business = Business::query()
            ->with('tenantFelSetting')
            ->findOrFail($reconciliation->business_id);

        try {
            $result = $service->findByInternalReference(
                $business,
                $reconciliation->internal_reference,
                $reconciliation->issued_date ?: $reconciliation->created_at,
            );
        } catch (FelException $exception) {
            $reconciliation->update([
                'attempts' => $reconciliation->attempts + 1,
                'last_error' => $exception->getMessage() ?: 'No se pudo consultar Digifact.',
                'checked_at' => now(),
            ]);

            return back()->withErrors([
                'reconciliation' => 'No se pudo consultar Digifact. Intenta nuevamente.',
            ]);
        }

        if (! ($result['found'] ?? false)) {
            $reconciliation->update([
                'status' => 'not_found',
                'attempts' => $reconciliation->attempts + 1,
                'last_error' => null,
                'response_snapshot' => $result,
                'checked_at' => now(),
            ]);

            return back()->with('success', 'Digifact no reporta un DTE certificado para esta referencia.');
        }

        $sale = $reconciliation->sale_id
            ? Sale::query()
                ->where('business_id', $reconciliation->business_id)
                ->find($reconciliation->sale_id)
            : null;

        if (! $sale) {
            $reconciliation->update([
                'status' => 'found',
                'attempts' => $reconciliation->attempts + 1,
                'last_error' => 'Digifact reporta un DTE certificado, pero la venta local no existe o fue revertida. Requiere revisión manual.',
                'response_snapshot' => $result,
                'checked_at' => now(),
            ]);

            return back()->withErrors([
                'reconciliation' => 'Digifact reporta un DTE certificado, pero la venta local no existe o fue revertida. Requiere revisión manual.',
            ]);
        }

        DB::transaction(function () use ($request, $reconciliation, $sale, $invoiceService, $result) {
            $document = $invoiceService->applyReconciledResponse($sale, $result['raw'], $reconciliation->issued_date);

            if ($sale->refresh()->is_credit_sale && ! CustomerAccountMovement::query()
                ->where('business_id', $sale->business_id)
                ->where('sale_id', $sale->id)
                ->where('type', 'charge')
                ->exists()) {
                AccountsReceivable::createCharge($sale, $request->user()->id);
            }

            $reconciliation->update([
                'status' => 'resolved',
                'attempts' => $reconciliation->attempts + 1,
                'last_error' => null,
                'response_snapshot' => $result,
                'resolved_sale_id' => $sale->id,
                'resolved_electronic_document_id' => $document->id,
                'resolved_by' => $request->user()->id,
                'checked_at' => now(),
                'resolved_at' => now(),
            ]);
        });

        return back()->with('success', 'FEL conciliado correctamente.');
    }
}
