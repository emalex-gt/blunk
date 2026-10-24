<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\FelIncident;
use App\Models\FiscalDocumentIntent;
use App\Models\FelReconciliationRequest;
use App\Models\Business;
use App\Services\Fiscal\FiscalDocumentIntentService;
use App\Services\Fel\Providers\Digifact\DigifactReconciliationService;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Support\AccountsReceivable;
use Illuminate\Support\Facades\DB;
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

        $intents = FiscalDocumentIntent::query()
            ->with(['business:id,name', 'finalizedSale:id,business_id'])
            ->whereIn('status', [
                FiscalDocumentIntent::PENDING,
                FiscalDocumentIntent::UNKNOWN,
                FiscalDocumentIntent::CERTIFIED,
                FiscalDocumentIntent::REJECTED,
                FiscalDocumentIntent::RETRYABLE,
                FiscalDocumentIntent::FINALIZED,
            ])
            ->latest()
            ->paginate(25, ['*'], 'intent_page')
            ->through(fn (FiscalDocumentIntent $intent) => [
                'id' => $intent->id,
                'business' => $intent->business ? ['id' => $intent->business->id, 'name' => $intent->business->name] : null,
                'sale_id' => $intent->finalized_sale_id,
                'branch_id' => $intent->branch_id,
                'internal_reference' => $intent->internal_reference,
                'provider' => $intent->provider,
                'environment' => $intent->environment,
                'status' => $intent->status,
                'negative_reconciliation_count' => $intent->negative_reconciliation_count,
                'last_reconciliation_at' => $intent->last_reconciliation_at?->format('Y-m-d H:i'),
                'next_reconciliation_at' => $intent->next_reconciliation_at?->format('Y-m-d H:i'),
                'last_reconciliation_result' => $intent->last_reconciliation_result,
                'last_reconciliation_error' => $intent->last_reconciliation_error,
                'last_finalization_error' => $intent->last_finalization_error,
                'created_at' => $intent->created_at?->format('Y-m-d H:i'),
            ]);

        $reconciliations = FelReconciliationRequest::query()
            ->with(['business:id,name', 'sale:id,business_id'])
            ->whereIn('status', ['pending', 'not_found', 'found', 'manual_resolution_required'])
            ->latest()
            ->paginate(25, ['*'], 'legacy_page')
            ->through(fn (FelReconciliationRequest $item) => [
                'id' => $item->id,
                'business' => $item->business ? ['id' => $item->business->id, 'name' => $item->business->name] : null,
                'sale_id' => $item->sale_id,
                'internal_reference' => $item->internal_reference,
                'status' => $item->status,
                'attempts' => $item->attempts,
                'last_error' => $item->last_error,
                'checked_at' => $item->checked_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('SuperAdmin/FelIncidents/Index', [
            'incidents' => $incidents,
            'intents' => $intents,
            'reconciliations' => $reconciliations,
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

        return back()->with('success', 'Alerta administrativa cerrada. La conciliación fiscal mantiene su propio estado.');
    }

    public function reconcileIntent(FiscalDocumentIntent $intent, FiscalDocumentIntentService $service): RedirectResponse
    {
        try {
            $intent = $service->reconcile($intent);
        } catch (\Throwable $exception) {
            return back()->withErrors(['reconciliation' => 'No se pudo conciliar la intención FEL: '.$exception->getMessage()]);
        }

        return back()->with('success', "Conciliación completada: {$intent->status}.");
    }

    public function retryIntent(FiscalDocumentIntent $intent, FiscalDocumentIntentService $service): RedirectResponse
    {
        try {
            $intent = $service->retry($intent);
        } catch (\Throwable $exception) {
            return back()->withErrors(['reconciliation' => $exception->getMessage()]);
        }

        return back()->with('success', "Reintento controlado finalizó en estado {$intent->status}.");
    }

    public function finalizeIntent(FiscalDocumentIntent $intent, FiscalDocumentIntentService $service): RedirectResponse
    {
        if ($intent->status !== FiscalDocumentIntent::CERTIFIED) {
            return back()->withErrors(['finalization' => 'Sólo una intención certificada puede reintentar la finalización local.']);
        }

        $intent = $service->retryFinalization($intent);

        return back()->with($intent->status === FiscalDocumentIntent::FINALIZED ? 'success' : 'warning',
            $intent->status === FiscalDocumentIntent::FINALIZED
                ? 'Finalización local completada.'
                : 'La finalización local sigue pendiente: '.($intent->last_finalization_error ?: 'requiere revisión.'));
    }

    /** Legacy records remain immutable in shape, but reconciliation is Super Admin only. */
    public function reconcileLegacy(FelReconciliationRequest $reconciliation, DigifactReconciliationService $service, DigifactInvoiceService $invoiceService): RedirectResponse
    {
        $business = Business::query()->with('tenantFelSetting')->findOrFail($reconciliation->business_id);

        try {
            $result = $service->findByInternalReference($business, $reconciliation->internal_reference, $reconciliation->issued_date ?: $reconciliation->created_at);
        } catch (\Throwable $exception) {
            $reconciliation->update([
                'attempts' => $reconciliation->attempts + 1,
                'last_error' => $exception->getMessage() ?: 'No se pudo consultar Digifact.',
                'checked_at' => now(),
            ]);
            return back()->withErrors(['reconciliation' => 'No se pudo consultar Digifact.']);
        }

        if (! ($result['found'] ?? false)) {
            $reconciliation->update([
                'status' => 'not_found', 'attempts' => $reconciliation->attempts + 1,
                'last_error' => null, 'response_snapshot' => $result, 'checked_at' => now(),
            ]);
            return back()->with('success', 'Digifact no reporta un DTE para la referencia consultada.');
        }

        if (! $reconciliation->sale) {
            $reconciliation->update([
                'status' => 'found', 'attempts' => $reconciliation->attempts + 1,
                'last_error' => 'DTE encontrado sin venta local legacy; requiere revisión manual.',
                'response_snapshot' => $result, 'checked_at' => now(),
            ]);
            return back()->withErrors(['reconciliation' => 'DTE encontrado sin venta local legacy.']);
        }

        if ($reconciliation->sale->status === 'cancelled') {
            $reconciliation->update([
                'status' => 'manual_resolution_required',
                'attempts' => $reconciliation->attempts + 1,
                'last_error' => 'Digifact reporta un DTE para una venta legacy anulada; requiere resolución manual sin restaurar efectos comerciales.',
                'response_snapshot' => $result,
                'checked_at' => now(),
            ]);

            return back()->withErrors(['reconciliation' => 'DTE encontrado para una venta anulada; requiere resolución manual.']);
        }

        DB::transaction(function () use ($reconciliation, $invoiceService, $result) {
            $sale = $reconciliation->sale()->lockForUpdate()->firstOrFail();
            $document = $invoiceService->applyReconciledResponse($sale, $result['raw'], $reconciliation->issued_date);
            if ($sale->is_credit_sale) {
                AccountsReceivable::createCharge($sale->refresh(), $reconciliation->created_by);
            }
            $reconciliation->update([
                'status' => 'resolved', 'attempts' => $reconciliation->attempts + 1,
                'last_error' => null, 'response_snapshot' => $result, 'checked_at' => now(),
                'resolved_sale_id' => $sale->id, 'resolved_electronic_document_id' => $document->id,
                'resolved_at' => now(),
            ]);
        });

        return back()->with('success', 'DTE legacy conciliado correctamente.');
    }
}
