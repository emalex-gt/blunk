# Routes / Pre-sales Phase 2E Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate one internal receipt for every documentally closed route pre-sale, then perform FEL manually or automatically as a separate, non-stock-mutating certification lifecycle.

**Architecture:** Route receipt conversion becomes a local, idempotent transaction that always persists `sales.document_type=receipt` and links it to `pre_sales.converted_sale_id`. FEL becomes a sale-level orchestration layer using `electronic_documents`; automatic mode dispatches independent jobs only after receipt conversion commits.

**Tech Stack:** Laravel 12, PHP 8.5, PostgreSQL, React/Inertia, Laravel queues, Digifact provider service, PHPUnit feature tests.

**Spec:** `docs/superpowers/specs/2026-09-01-routes-pre-sales-phase-2e-documentary-fel-design.md`

## Global Constraints

- Do not alter POS request payloads, checkout behavior, or existing POS invoice semantics.
- Do not deploy or modify production while implementing.
- Keep `route_pre_sale_stock_deduction_timing` defaults: `invoice` and `picking` only.
- Keep `route_pre_sale_invoicing_mode` defaults: `manual` and `automatic_all` only.
- FEL must never create or change stock, reservations, cash, payments, or credit charges.
- Every operation must scope by `business_id`, enforce active/allowed branch access, server-side permissions, and idempotency.
- Do not issue a new FEL request after an `unknown` provider outcome until reconciliation completes.
- Do not begin the automatic-all tasks until the payment/default and queue-worker decisions in the spec are approved.

---

## File Structure

- Modify: `app/Services/Routes/RoutePreSaleInvoiceService.php` — retain a compatibility adapter or remove only after callers move.
- Create: `app/Services/Routes/RoutePreSaleReceiptService.php` — sole writer for local route receipt conversion.
- Create: `app/Services/Routes/RoutePreSaleFelService.php` — first certification/retry orchestration for route-linked receipt sales.
- Create: `app/Jobs/CertifyRoutePreSaleFelJob.php` — one queued automatic FEL attempt per eligible converted sale.
- Modify: `app/Http/Controllers/RoutePreSaleInvoiceController.php` — accept the manual receipt-conversion payload only.
- Create: `app/Http/Controllers/RoutePreSaleFelController.php` — certify/retry route receipt FEL actions.
- Modify: `app/Http/Controllers/SaleController.php` — authorize FEL printing/downloading/cancellation from electronic-document state rather than sale document type.
- Modify: `app/Http/Controllers/RouteController.php` — provide route FEL queue data and filter state.
- Modify: `app/Http/Controllers/RoutePreparationBatchController.php` — dispatch automatic FEL only after local receipt batch completion.
- Modify: `app/Services/Routes/RoutePreparationBatchService.php` — expose converted receipt candidates; keep preparation all-or-nothing.
- Modify: `routes/web.php` — add route FEL routes under existing auth/routes/FEL middleware.
- Modify: `resources/js/Pages/Routes/PreSales/Show.tsx` — rename conversion to internal receipt and show FEL state/actions.
- Modify: `resources/js/Pages/Routes/PreSales/Index.tsx` — add FEL status and queue filters.
- Modify: `resources/js/Pages/Routes/PreparationBatches/Show.tsx` — show receipt/FEL state and automatic dispatch summary.
- Modify: `resources/js/Pages/SuperAdmin/Tenants/Form.tsx` — rename the setting copy and add approved automatic defaults.
- Modify: `app/Http/Controllers/SuperAdmin/TenantController.php` and `app/Models/TenantSetting.php` — validate/persist only the approved automatic defaults.
- Create: migration only if approved defaults require persisted columns.
- Modify: `tests/Feature/RoutePreSaleInvoiceTest.php`, `tests/Feature/RoutePreparationBatchTest.php`, `tests/Feature/CriticalPosFelFlowTest.php`, and add `tests/Feature/RoutePreSaleFelTest.php`.

## Task 1: Freeze Existing POS and Route Regression Behavior

**Files:**
- Modify: `tests/Feature/RoutePreSaleInvoiceTest.php`
- Modify: `tests/Feature/CriticalPosFelFlowTest.php`

**Interfaces:**
- Consumes: existing `RoutePreSaleInvoiceService::convert()` and POS `SaleController::store()` behavior.
- Produces: explicit baseline tests proving the refactor cannot alter POS document types or existing route receipt conversion.

- [ ] **Step 1: Write failing characterization tests for the existing separation.**

```php
public function test_pos_invoice_still_creates_an_invoice_sale_and_certifies_fel(): void
{
    // Submit the existing POS invoice payload with a mocked certified Digifact response.
    // Assert sales.document_type is invoice and electronic_documents.status is certified.
}

public function test_route_receipt_conversion_creates_no_electronic_document(): void
{
    // Convert a picked pre-sale as receipt.
    // Assert one receipt sale, converted pre-sale, and no electronic document.
}
```

- [ ] **Step 2: Run the targeted tests to verify the baseline.**

Run: `php artisan test --env=testing --filter=RoutePreSaleInvoice`

Expected: existing behavior passes before the refactor.

- [ ] **Step 3: Add assertions that a normal POS receipt remains unrelated to route FEL queues.**

```php
$this->assertDatabaseHas('sales', ['id' => $posReceipt->id, 'document_type' => 'receipt']);
$this->assertDatabaseMissing('pre_sales', ['converted_sale_id' => $posReceipt->id]);
```

- [ ] **Step 4: Re-run the targeted regression tests.**

Run: `php artisan test --env=testing --filter=CriticalPosFelFlow`

Expected: PASS.

- [ ] **Step 5: Commit the regression characterization.**

```bash
git add tests/Feature/RoutePreSaleInvoiceTest.php tests/Feature/CriticalPosFelFlowTest.php
git commit -m "test route receipt and POS FEL compatibility"
```

## Task 2: Extract Atomic Internal Receipt Conversion

**Files:**
- Create: `app/Services/Routes/RoutePreSaleReceiptService.php`
- Modify: `app/Services/Routes/RoutePreSaleInvoiceService.php`
- Modify: `app/Http/Controllers/RoutePreSaleInvoiceController.php`
- Modify: `tests/Feature/RoutePreSaleInvoiceTest.php`

**Interfaces:**
- Consumes: `PreSale`, `User`, `IdempotencyService`, `StockReservationService`, `CashRegister`, `AccountsReceivable`, and existing timing rules.
- Produces: `RoutePreSaleReceiptService::convertToInternalReceipt(PreSale $preSale, array $data, User $user): IdempotencyResult`.

- [ ] **Step 1: Write failing receipt conversion tests for both timings.**

```php
public function test_route_internal_receipt_is_created_once_and_consumes_invoice_timing_stock(): void
{
    // Convert a picked pre-sale with invoice timing.
    // Assert receipt sale, one sale stock movement, consumed reservation, and converted_sale_id.
}

public function test_route_internal_receipt_does_not_repeat_picking_timing_stock_or_reservations(): void
{
    // Convert a pre-sale already deducted by picking.
    // Assert receipt sale and no additional sale stock movement or reservation mutation.
}
```

- [ ] **Step 2: Run the focused test to verify it fails before extraction.**

Run: `php artisan test --env=testing --filter=RoutePreSaleInvoice`

Expected: FAIL because the route request still requires a selected invoice/receipt document type.

- [ ] **Step 3: Implement `RoutePreSaleReceiptService` by moving only local sale-side work.**

```php
public function convertToInternalReceipt(PreSale $preSale, array $data, User $user): IdempotencyResult
{
    return app(IdempotencyService::class)->run(
        $preSale->business_id,
        $preSale->branch_id,
        $user->id,
        'route_pre_sale_receipt',
        $data['idempotency_key'],
        $this->payload($preSale, $data),
        fn () => DB::transaction(fn () => $this->createLockedReceipt($preSale, $data, $user)),
        'sale',
    );
}
```

The persisted sale must use `document_type => 'receipt'`. Do not instantiate `ElectronicDocument` or call Digifact here.

- [ ] **Step 4: Make the old service a thin compatibility adapter or redirect its sole route caller.**

```php
public function convert(PreSale $preSale, array $data, User $user): IdempotencyResult
{
    return app(RoutePreSaleReceiptService::class)->convertToInternalReceipt($preSale, $data, $user);
}
```

Remove document-type/FEL validation from the route internal conversion path; retain payment and credit validation.

- [ ] **Step 5: Run route and stock tests.**

Run: `php artisan test --env=testing --filter=RoutePreSaleInvoice`

Run: `php artisan test --env=testing --filter=Stock`

Expected: PASS, with no second stock deduction for picking timing.

- [ ] **Step 6: Commit the local conversion boundary.**

```bash
git add app/Services/Routes/RoutePreSaleReceiptService.php app/Services/Routes/RoutePreSaleInvoiceService.php app/Http/Controllers/RoutePreSaleInvoiceController.php tests/Feature/RoutePreSaleInvoiceTest.php
git commit -m "separate route internal receipt conversion"
```

## Task 3: Add First-Time FEL Certification for Route Receipts

**Files:**
- Create: `app/Services/Routes/RoutePreSaleFelService.php`
- Create: `app/Http/Controllers/RoutePreSaleFelController.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/SaleController.php`
- Create: `tests/Feature/RoutePreSaleFelTest.php`

**Interfaces:**
- Consumes: a completed `Sale` linked from `PreSale::convertedSale`, `DigifactInvoiceService`, `ElectronicDocument`, `FelReconciliationRequest`, and `fel.certify`.
- Produces: `RoutePreSaleFelService::certify(Sale $sale, User $user, string $idempotencyKey): ElectronicDocument` and `RoutePreSaleFelService::retryOrReconcile(...)`.

- [ ] **Step 1: Write failing first-certification and failure-preservation tests.**

```php
public function test_route_receipt_can_be_certified_for_the_first_time_without_new_sale_or_stock_mutation(): void
{
    // Start from one converted receipt with a mocked successful Digifact response.
    // Assert same sale id, one sale stock movement total, and one certified electronic document.
}

public function test_failed_route_receipt_fel_keeps_receipt_stock_and_pre_sale_conversion(): void
{
    // Mock FelException from Digifact.
    // Assert the receipt sale still exists, pre-sale remains converted, stock is unchanged,
    // and electronic document/reconciliation state is failed or unknown as appropriate.
}
```

- [ ] **Step 2: Run the new test to verify it fails.**

Run: `php artisan test --env=testing --filter=RoutePreSaleFel`

Expected: FAIL because receipt sales currently receive 404 from the FEL retry route.

- [ ] **Step 3: Implement the route FEL orchestration with a sale lock and an idempotency record.**

```php
public function certify(Sale $sale, User $user, string $idempotencyKey): ElectronicDocument
{
    return DB::transaction(function () use ($sale, $user, $idempotencyKey) {
        $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
        $this->assertRouteLinkedSale($sale);
        $this->assertCanCertify($sale, $user);
        $this->assertNotCertifiedOrUnknown($sale);
        return $this->createOrLockPendingDocument($sale, $user, $idempotencyKey);
    });
}
```

Commit the pending document before the provider request. Call Digifact outside the local receipt conversion transaction. Persist `failed` or `unknown`; never invoke `BranchInventory`, `StockReservation`, `CashRegister`, or `AccountsReceivable` in this service.

- [ ] **Step 4: Add sale-level routes guarded by `module:fel_gt` and `permission:fel.certify`.**

```php
Route::post('/route-pre-sales/sales/{sale}/fel/certify', [RoutePreSaleFelController::class, 'certify'])
    ->middleware(['module:fel_gt', 'permission:fel.certify'])
    ->name('routes.pre-sales.fel.certify');
```

The controller must tenant-scope the sale and enforce the active/allowed route branch before dispatching to the service.

- [ ] **Step 5: Replace shared FEL document guards with electronic-document guards.**

```php
abort_unless($sale->electronicDocument?->status === 'certified' && filled($sale->fel_uuid), 404);
```

Apply this to FEL print/download/view and certified-sale cancellation. Preserve all existing POS paths by accepting both POS invoice sales and route receipt sales with a certified document.

- [ ] **Step 6: Run FEL, route, and cancellation coverage.**

Run: `php artisan test --env=testing --filter=RoutePreSaleFel`

Run: `php artisan test --env=testing --filter=FEL`

Run: `php artisan test --env=testing --filter=Sale`

Expected: PASS; a failed route FEL leaves one printable receipt and no duplicate sale/stock movement.

- [ ] **Step 7: Commit first-time route receipt certification.**

```bash
git add app/Services/Routes/RoutePreSaleFelService.php app/Http/Controllers/RoutePreSaleFelController.php app/Http/Controllers/SaleController.php routes/web.php tests/Feature/RoutePreSaleFelTest.php
git commit -m "add FEL certification for route receipts"
```

## Task 4: Add Route FEL Queue and Manual UI

**Files:**
- Modify: `app/Http/Controllers/RouteController.php`
- Modify: `resources/js/Pages/Routes/PreSales/Index.tsx`
- Modify: `resources/js/Pages/Routes/PreSales/Show.tsx`
- Modify: `resources/js/Pages/Routes/PreparationBatches/Show.tsx`
- Modify: `tests/Feature/RoutePreSaleFelTest.php`

**Interfaces:**
- Consumes: `pre_sales.converted_sale_id`, `sales.electronicDocument`, and the route FEL service/routes from Task 3.
- Produces: a tenant-and-branch-scoped route FEL queue with the states `not_requested`, `pending`, `failed`, `unknown`, and `certified`.

- [ ] **Step 1: Write failing Inertia assertions for the queue.**

```php
public function test_route_pre_sales_index_only_returns_current_business_fel_queue_rows(): void
{
    // Create converted route receipts for two businesses.
    // Assert the response exposes only the current business receipt and its computed FEL state.
}
```

- [ ] **Step 2: Run the focused test to verify it fails.**

Run: `php artisan test --env=testing --filter=RoutePreSaleFel`

Expected: FAIL because no FEL state/filter is serialized for converted route sales.

- [ ] **Step 3: Serialize a computed FEL state without changing POS report semantics.**

```php
$felState = match ($preSale->convertedSale?->electronicDocument?->status) {
    'pending' => 'pending',
    'failed' => 'failed',
    'unknown' => 'unknown',
    'certified' => 'certified',
    default => 'not_requested',
};
```

Only calculate this for a route-linked converted sale. Filter from a validated `fel_status` query parameter and scope all joins by current `business_id`.

- [ ] **Step 4: Implement manual UI actions.**

Use `Certificar FEL` for `not_requested`/`failed`, route users to reconciliation before reissue for `unknown`, and expose `Ver venta` plus FEL print/download links only for `certified`. Keep the internal receipt print action available in every FEL state.

- [ ] **Step 5: Run UI-contract tests and build.**

Run: `php artisan test --env=testing --filter=RoutePreSaleFel`

Run: `cmd /c npm run build`

Expected: PASS.

- [ ] **Step 6: Commit the manual FEL queue.**

```bash
git add app/Http/Controllers/RouteController.php resources/js/Pages/Routes/PreSales/Index.tsx resources/js/Pages/Routes/PreSales/Show.tsx resources/js/Pages/Routes/PreparationBatches/Show.tsx tests/Feature/RoutePreSaleFelTest.php
git commit -m "add route pre-sale FEL queue"
```

## Task 5: Add Approved Automatic Defaults and Queue Dispatch

**Files:**
- Create: a migration named for the approved `route_pre_sale_default_payment_condition` and `route_pre_sale_default_payment_method` columns, if the product owner approves persisted defaults.
- Modify: `app/Models/TenantSetting.php`
- Modify: `app/Http/Controllers/SuperAdmin/TenantController.php`
- Modify: `resources/js/Pages/SuperAdmin/Tenants/Form.tsx`
- Create: `app/Jobs/CertifyRoutePreSaleFelJob.php`
- Modify: `app/Services/Routes/RoutePreparationBatchService.php`
- Modify: `app/Http/Controllers/RoutePreparationBatchController.php`
- Modify: `tests/Feature/RoutePreparationBatchTest.php`
- Modify: `tests/Feature/RoutePreSaleFelTest.php`

**Interfaces:**
- Consumes: the product-owner-approved paid/credit and payment-method defaults, the queue-worker prerequisite, Task 2 receipt conversion, and Task 3 FEL service.
- Produces: `CertifyRoutePreSaleFelJob` that certifies exactly one eligible receipt and a batch flow that dispatches only after all local receipt conversions commit.

- [ ] **Step 1: Write failing configuration and dispatch tests.**

```php
public function test_automatic_all_requires_approved_payment_defaults_and_a_running_queue_configuration(): void
{
    // Assert configuration validation rejects automatic_all without the approved values.
}

public function test_automatic_all_commits_receipts_before_dispatching_one_fel_job_per_eligible_sale(): void
{
    Queue::fake();
    // Prepare/close a batch.
    // Assert all receipts exist before queued jobs are asserted and each eligible sale has one job.
}
```

- [ ] **Step 2: Run the focused tests to verify they fail.**

Run: `php artisan test --env=testing --filter=RoutePreparationBatch`

Expected: FAIL because `automatic_all` currently only records a snapshot.

- [ ] **Step 3: Add only the approved tenant defaults and validation.**

```php
'route_pre_sale_default_payment_condition' => ['required_if:route_pre_sale_invoicing_mode,automatic_all', Rule::in(['paid', 'credit'])],
'route_pre_sale_default_payment_method' => ['required_if:route_pre_sale_default_payment_condition,paid', Rule::in(['cash', 'card', 'transfer', 'check'])],
```

Reject automatic cash if the approved decision prohibits it. If cash is approved, validate the route branch has an open session before any receipt in the batch is converted.

- [ ] **Step 4: Implement the job as an independent, idempotent sale action.**

```php
final class CertifyRoutePreSaleFelJob implements ShouldQueue
{
    public string $queue = 'fel';
    public int $tries = 1;
    public int $timeout = 120;

    public function handle(RoutePreSaleFelService $service): void
    {
        $service->certifyAutomatically($this->saleId, $this->businessId, $this->userId);
    }
}
```

The job may record `failed`/`unknown`, but it must not re-dispatch itself or issue a second request after `unknown`.

- [ ] **Step 5: Dispatch only after local receipt commits.**

```php
DB::afterCommit(fn () => CertifyRoutePreSaleFelJob::dispatch($saleId, $businessId, $userId));
```

The batch remains locally successful even if a later individual FEL job fails. No job is dispatched when the receipt is not FEL-eligible.

- [ ] **Step 6: Run automatic-mode tests.**

Run: `php artisan test --env=testing --filter=RoutePreparationBatch`

Run: `php artisan test --env=testing --filter=RoutePreSaleFel`

Expected: PASS; all local receipts remain after any individual FEL exception.

- [ ] **Step 7: Commit automatic FEL orchestration.**

```bash
git add database/migrations app/Models/TenantSetting.php app/Http/Controllers/SuperAdmin/TenantController.php resources/js/Pages/SuperAdmin/Tenants/Form.tsx app/Jobs/CertifyRoutePreSaleFelJob.php app/Services/Routes/RoutePreparationBatchService.php app/Http/Controllers/RoutePreparationBatchController.php tests/Feature/RoutePreparationBatchTest.php tests/Feature/RoutePreSaleFelTest.php
git commit -m "add automatic FEL certification for route receipts"
```

## Task 6: Verify Cross-Cutting FEL Access, Cancellation, and Isolation

**Files:**
- Modify: `tests/Feature/RoutePreSaleFelTest.php`
- Modify: `tests/Feature/RoutePreSaleInvoiceTest.php`
- Modify: `tests/Feature/CriticalPosFelFlowTest.php`

**Interfaces:**
- Consumes: Tasks 2 through 5.
- Produces: release-level proof that document, stock, tenant, branch, retry, and cancellation invariants hold.

- [ ] **Step 1: Add the complete failure/invariant matrix.**

```php
public function test_unknown_route_fel_requires_reconciliation_before_retry(): void {}
public function test_failed_route_fel_can_retry_without_duplicate_sale_or_stock(): void {}
public function test_certified_route_receipt_prints_and_downloads_fel_document(): void {}
public function test_certified_route_receipt_cancellation_cancels_fel_before_local_reversal(): void {}
public function test_other_tenant_cannot_view_certify_or_download_route_receipt_fel(): void {}
public function test_unallowed_branch_cannot_certify_route_receipt_fel(): void {}
public function test_automatic_fel_ineligible_customer_keeps_internal_receipt_without_job(): void {}
```

- [ ] **Step 2: Run focused coverage.**

Run: `php artisan test --env=testing --filter=RoutePreSaleFel`

Run: `php artisan test --env=testing --filter=RoutePreSaleInvoice`

Run: `php artisan test --env=testing --filter=CriticalPosFelFlow`

Expected: PASS.

- [ ] **Step 3: Run the full verification suite.**

Run: `php artisan test --env=testing`

Run: `cmd /c npm run build`

Run: `git diff --check`

Expected: all commands succeed.

- [ ] **Step 4: Commit release verification updates.**

```bash
git add tests/Feature/RoutePreSaleFelTest.php tests/Feature/RoutePreSaleInvoiceTest.php tests/Feature/CriticalPosFelFlowTest.php
git commit -m "test route receipt FEL lifecycle"
```

## Plan Self-Review

- Spec coverage: Tasks 2 and 3 implement the document/FEL separation; Task 4 provides the pending FEL queue; Task 5 covers automatic mode and defaults; Task 6 covers rollback, retry, cancellation, print/download, and isolation.
- Placeholder scan: no unresolved implementation marker is used. The automatic-default decisions are explicit approval gates, not implementation omissions.
- Type consistency: the internal conversion service returns `IdempotencyResult`; route FEL operations return `ElectronicDocument`; the job calls the route FEL service by sale/business/user identifiers.

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-09-01-routes-pre-sales-phase-2e-documentary-fel.md`.

Two execution options:

1. **Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session using `superpowers:executing-plans`, with checkpoints for review.

Execution must wait for approval of the decisions in the design document.
