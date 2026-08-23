# Routes / Pre-sales Phase 2D Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add configurable route pre-sale stock timing, atomic preparation batches, historical batch views, and on-demand preparation PDFs.

**Architecture:** A shared `RoutePreSalePreparationService` performs all individual and bulk preparation writes. A batch service uses it inside a single locked transaction and records snapshot configuration. The approved Phase 2C invoice service only branches at stock/reservation handling according to the persisted per-item deduction state.

**Tech Stack:** Laravel 12, PostgreSQL, React/Inertia, Tailwind, DomPDF, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-24-routes-pre-sales-phase-2d-design.md`

## Global Constraints

- Do not issue FEL, sales, cash movements, or CxC automatically for `automatic_all`.
- Do not change normal POS behavior or production data.
- Defaults are `route_pre_sale_stock_deduction_timing=invoice` and `route_pre_sale_invoicing_mode=manual`.
- Bulk preparation is all-or-nothing; no partial pre-sale, reservation, or stock updates are committed.
- Use `routes.pre_sales.pick` for preparation and `routes.pre_sales.admin_view` for batch history and documents.
- Preserve current Phase 2C behavior for `invoice` timing.

---

## File Map

- `database/migrations/2026_08_24_000002_add_route_preparation_settings_and_traceability.php`: settings, per-item deduction quantity, and batch tables.
- `app/Models/RoutePreparationBatch.php`: batch persistence and relations.
- `app/Models/RoutePreparationBatchPreSale.php`: batch membership persistence and relations.
- `app/Models/PreSale.php`, `app/Models/PreSaleItem.php`, `app/Models/TenantSetting.php`: casts, fillable fields, and relations.
- `app/Services/Routes/RoutePreSalePreparationService.php`: only writer for preparing a pre-sale.
- `app/Services/Routes/RoutePreparationBatchService.php`: idempotent all-or-nothing work-day preparation.
- `app/Services/Routes/RoutePreparationDocuments.php`: batch-scoped PDF view data.
- `app/Services/Routes/RoutePreSaleInvoiceService.php`: timing-aware stock/reservation branch only.
- `app/Http/Controllers/RouteController.php`: delegate individual picking and expose work-day prepare availability.
- `app/Http/Controllers/RoutePreparationBatchController.php`: batch actions, list/detail, and downloads.
- `app/Http/Controllers/SuperAdmin/TenantController.php`, `resources/js/Pages/SuperAdmin/Tenants/Form.tsx`: setting persistence/UI.
- `routes/web.php`, `resources/js/Layouts/AuthenticatedLayout.tsx`: routes and navigation.
- `resources/js/Pages/Routes/WorkDays/Show.tsx`, `resources/js/Pages/Routes/PreparationBatches/Index.tsx`, `resources/js/Pages/Routes/PreparationBatches/Show.tsx`: preparation controls and history.
- `resources/views/pdf/route-preparation-batches/*.blade.php`: three DomPDF documents.
- `tests/Feature/RoutePreparationBatchTest.php`, `tests/Feature/RoutePreSaleInvoiceTest.php`, `tests/Feature/RoutesPreSalesTest.php`: behavior and regression coverage.

### Task 1: Persist Settings, Line Traceability, And Batch Records

**Files:**
- Create: `database/migrations/2026_08_24_000002_add_route_preparation_settings_and_traceability.php`
- Create: `app/Models/RoutePreparationBatch.php`
- Create: `app/Models/RoutePreparationBatchPreSale.php`
- Modify: `app/Models/TenantSetting.php`, `app/Models/PreSale.php`, `app/Models/PreSaleItem.php`
- Test: `tests/Feature/RoutePreparationBatchTest.php`

**Interfaces:**
- Produces `TenantSetting::route_pre_sale_stock_deduction_timing` (`picking|invoice`).
- Produces `PreSaleItem::stock_deducted_quantity` decimal(12,4), default `0`.
- Produces `RoutePreparationBatch` and `RoutePreparationBatchPreSale` relations used by later tasks.

- [ ] Write failing tests that assert new tenants default to `invoice`/`manual`, legacy `automatic` becomes `automatic_all`, and a batch cannot include the same pre-sale twice.
- [ ] Run `php artisan test --env=testing --filter=RoutePreparationBatch` and confirm missing schema/model failures.
- [ ] Add a guarded migration: normalize `tenant_settings.route_pre_sale_invoicing_mode='automatic'` to `automatic_all`; add stock timing default `invoice`; add `stock_deducted_quantity` to `pre_sale_items`; create both batch tables with business/branch/work-day indexes and unique `(route_preparation_batch_id, pre_sale_id)`.
- [ ] Add models with business, branch, work day, preparer, batch-membership, pre-sale, and sale relations; add decimal/datetime casts and model fillable lists.
- [ ] Run the focused test and confirm it passes.

### Task 2: Implement The Shared Preparation Writer

**Files:**
- Create: `app/Services/Routes/RoutePreSalePreparationService.php`
- Modify: `app/Http/Controllers/RouteController.php`
- Test: `tests/Feature/RoutesPreSalesTest.php`, `tests/Feature/RoutePreparationBatchTest.php`

**Interfaces:**
- Produces `RoutePreSalePreparationService::prepare(PreSale $preSale, array $items, User $user, string $timing, ?RoutePreparationBatch $batch = null): array`.
- The caller has a database transaction; the method locks the pre-sale, items, reservations, products, and branch stock rows.

- [ ] Write failing tests for individual picking at `invoice` timing (reservation remains and physical stock does not change) and `picking` timing (stock decreases, `pre_sale_picking` movement exists, reservation is consumed, and deducted quantity equals picked quantity).
- [ ] Run the focused tests and confirm the `picking` assertions fail with current behavior.
- [ ] Extract existing prepared-quantity/reservation validation from `RouteController::storePreSalePicking` into the service. Restrict source state to `submitted|processing`; require each submitted item row and require total prepared quantity greater than zero.
- [ ] For `invoice`, update prepared quantities/status only. For `picking`, call `BranchInventory::decrease`, record one `StockMovement` per item with type `pre_sale_picking`, set item `stock_deducted_quantity`, and consume the matching reservation in the same transaction.
- [ ] Change the controller to validate request shape and idempotency, then invoke the shared service; preserve redirects and messages.
- [ ] Run focused Route tests and confirm both timings pass.

### Task 3: Make Phase 2C Invoice Conversion Timing-Aware

**Files:**
- Modify: `app/Services/Routes/RoutePreSaleInvoiceService.php`
- Test: `tests/Feature/RoutePreSaleInvoiceTest.php`

**Interfaces:**
- Consumes `TenantSetting::route_pre_sale_stock_deduction_timing` and `PreSaleItem::stock_deducted_quantity`.
- Preserves `RoutePreSaleInvoiceService::convert()` signature and Phase 2C idempotency operation.

- [ ] Write failing tests for: invoice after `picking` timing creates a sale without another stock movement/decrease; invoice after `invoice` timing still decreases stock and consumes reservation; FEL failure after picking deduction leaves that prior deduction intact while rolling back sale-side records.
- [ ] Run `php artisan test --env=testing --filter=RoutePreSaleInvoice` and confirm the no-second-decrease test fails.
- [ ] Branch only inside stock/reservation validation and mutation: `invoice` retains active-reservation equality, stock decrease, and consume behavior; `picking` requires `stock_deducted_quantity === picked_quantity`, creates no stock movement, and does not consume reservations.
- [ ] Keep sale creation, payments, cash, CxC, Digifact, reconciliation, converted state, and idempotency unchanged.
- [ ] Run the focused invoice suite and confirm it passes.

### Task 4: Implement Atomic Bulk Preparation And Batch Idempotency

**Files:**
- Create: `app/Services/Routes/RoutePreparationBatchService.php`
- Create: `app/Http/Controllers/RoutePreparationBatchController.php`
- Modify: `routes/web.php`, `app/Support/IdempotencyHealthMonitor.php`
- Test: `tests/Feature/RoutePreparationBatchTest.php`

**Interfaces:**
- Produces `RoutePreparationBatchService::prepareAll(RouteWorkDay $workDay, User $user, string $idempotencyKey): IdempotencyResult`.
- Adds `POST /routes/work-days/{workDay}/prepare-all` named `routes.work-days.prepare-all`.

- [ ] Write failing tests that prepare submitted/processing pre-sales in one batch, exclude cancelled/picked/converted rows, persist membership, replay the same key, reject a changed payload/key after preparation, reject another tenant/branch, and roll back all rows when one reservation is inconsistent.
- [ ] Run the batch suite and confirm missing route/service failures.
- [ ] Under `IdempotencyService::run(..., 'route_prepare_all', ...)`, lock the work day, require its business to match the actor and its branch to match the active branch, query eligible pre-sales in id order, and build a stable request hash from id/status/updated-at plus setting snapshots.
- [ ] Validate every eligible pre-sale through the shared preparation service validation path before creating a completed batch. Create the `processing` batch and members, prepare every pre-sale in the same transaction, compute totals, then mark the batch `completed`.
- [ ] On validation, stock, or reservation failure throw without committing a batch, member, status, reservation, or stock mutation.
- [ ] Run the batch suite and Idempotency suite and confirm they pass.

### Task 5: Super Admin Settings And Work-Day Control

**Files:**
- Modify: `app/Http/Controllers/SuperAdmin/TenantController.php`, `resources/js/Pages/SuperAdmin/Tenants/Form.tsx`
- Modify: `app/Http/Controllers/RouteController.php`, `resources/js/Pages/Routes/WorkDays/Show.tsx`
- Test: `tests/Feature/RoutePreparationBatchTest.php`, `tests/Feature/RoutesPreSalesTest.php`

**Interfaces:**
- Settings values accepted by tenant controller: `route_pre_sale_stock_deduction_timing` in `picking|invoice`, `route_pre_sale_invoicing_mode` in `manual|automatic_all|automatic` with normalization to `automatic_all`.
- Work-day page receives `canPrepareAll`, `preparablePreSalesCount`, and `automaticAllPendingDefaults`.

- [ ] Write failing source/Inertia tests that the tenant form exposes both settings, `automatic` displays as `automatic_all`, and the work-day page exposes `PREPARAR TODO` only when valid pre-sales exist and user has `routes.pre_sales.pick`.
- [ ] Run focused Route tests and confirm props/source assertions fail.
- [ ] Add validation/defaulting and React controls with the approved Spanish labels. Preserve `manual` as default and show the approved non-automatic invoice message for `automatic_all`.
- [ ] Add the prepare-all form action with an idempotency key, disabled submit state, readable errors, and no automatic invoice request.
- [ ] Run focused tests and confirm they pass.

### Task 6: Batch History, Detail, And On-Demand PDF Documents

**Files:**
- Modify: `routes/web.php`, `resources/js/Layouts/AuthenticatedLayout.tsx`
- Modify: `app/Http/Controllers/RoutePreparationBatchController.php`
- Create: `app/Services/Routes/RoutePreparationDocuments.php`
- Create: `resources/js/Pages/Routes/PreparationBatches/Index.tsx`
- Create: `resources/js/Pages/Routes/PreparationBatches/Show.tsx`
- Create: `resources/views/pdf/route-preparation-batches/consolidated.blade.php`
- Create: `resources/views/pdf/route-preparation-batches/receipts.blade.php`
- Create: `resources/views/pdf/route-preparation-batches/products-summary.blade.php`
- Test: `tests/Feature/RoutePreparationBatchTest.php`

**Interfaces:**
- Adds `GET /routes/preparation-batches`, `GET /routes/preparation-batches/{batch}`, and three authenticated document routes: `consolidated`, `receipts`, `products-summary`.
- `RoutePreparationDocuments` returns only batch-linked pre-sales/items and calculated document data.

- [ ] Write failing tests for list/detail tenant scope, branch scope, `routes.pre_sales.admin_view` 403 protection, PDF response content type, consolidated customer/route/total data, receipts product rows, and grouped product summary quantities.
- [ ] Run the batch suite and confirm missing routes/templates fail.
- [ ] Scope every batch query by current business and branch; require Routes module and `routes.pre_sales.admin_view`. Add navigation entry `Preparaciones` only for that permission.
- [ ] Render DomPDF documents on demand from batch memberships. Use batch snapshots and stored pre-sale line prices/quantities; make each receipt page use a page break; group product totals by `product_id`; include business/branch logo only when available.
- [ ] Run PDF and batch tests and confirm they pass.

### Task 7: Full Regression And Review

**Files:**
- Modify only files required by failures from prior tasks.
- Test: `tests/Feature/RoutePreparationBatchTest.php`, `tests/Feature/RoutePreSaleInvoiceTest.php`, `tests/Feature/RoutesPreSalesTest.php`

- [ ] Run `php artisan test --env=testing --filter=RoutePreparationBatch`.
- [ ] Run `php artisan test --env=testing --filter=RoutePreSaleInvoice`.
- [ ] Run `php artisan test --env=testing --filter=Route`.
- [ ] Run `php artisan test --env=testing --filter=Stock`.
- [ ] Run `php artisan test --env=testing --filter=Idempotency`.
- [ ] Run `php artisan test --env=testing` as a single process.
- [ ] Run `cmd /c npm run build` and `git diff --check`.
- [ ] Review the final diff against the spec: verify no normal POS changes and no code path invokes automatic FEL for `automatic_all`.
