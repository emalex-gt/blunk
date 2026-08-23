# Routes / Pre-sales Phase 2D Design

## Goal

Add configurable stock-deduction timing for route pre-sales, atomic bulk preparation with persistent batches, and historical PDF documents without changing POS sales or automatically issuing FEL documents.

## Scope And Defaults

- `route_pre_sale_stock_deduction_timing` defaults to `invoice` and accepts `picking` or `invoice`.
- `route_pre_sale_invoicing_mode` defaults to `manual` and accepts `manual` or `automatic_all`.
- Existing `automatic` values are interpreted as `automatic_all` during migration and when reading legacy data.
- `automatic_all` is persisted in each preparation-batch snapshot only. It does not create sales, cash movements, receivables, or FEL documents in Phase 2D.
- The normal POS flow, existing credit reservations, and approved Phase 2C invoice flow remain intact except for the necessary stock-timing branch.

## Stock Invariants

### Invoice timing

1. Individual picking and bulk preparation set valid pre-sales to `picked`.
2. Physical branch stock does not change during picking.
3. Active pre-sale reservations remain active through picking.
4. Invoice conversion performs the existing stock decrease and consumes the active reservations.
5. A failed FEL invoice rolls back the sale-side transaction; the pre-sale remains `picked` and its active reservation remains available for retry.

### Picking timing

1. Individual picking and bulk preparation lock the pre-sale, reservation, product, and branch-stock rows.
2. Each prepared line decreases physical stock once for its prepared quantity and records a `pre_sale_picking` stock movement.
3. The corresponding active reservation is consumed/released in the same transaction.
4. Per-line stock-deducted quantity is persisted, so later invoicing can prove which prepared quantity has already left stock.
5. Invoice conversion creates sale-side records but neither decreases physical stock nor consumes reservations a second time.
6. A failed FEL invoice rolls back only the sale-side transaction. The previous picking deduction and consumed reservation remain intact, and the pre-sale remains `picked` for a later invoice retry.

## Preparation Service

`RoutePreSalePreparationService` is the sole writer for preparing a pre-sale. It accepts a locked pre-sale, prepared quantities, the acting user, timing snapshot, and optional batch. It validates eligible states (`submitted` or `processing`), item quantities, active reservations, business and branch scope, and stock availability before mutating any stock.

The existing individual-picking controller delegates to this service. The batch service validates every target pre-sale before preparing any one of them. A validation or stock failure rolls back the entire batch operation, creating no partial preparations or stock deductions.

## Preparation Batches

`route_preparation_batches` records the work-day, business, branch, optional zone, preparer, timestamps, status, stock-timing snapshot, invoicing-mode snapshot, totals, and notes.

`route_preparation_batch_pre_sales` records every pre-sale successfully prepared by a batch and stores its per-pre-sale totals and status. Both tables are tenant and branch scoped. A unique relation between a batch and a pre-sale prevents duplicate membership.

`RoutePreparationBatchService::prepareAll()` runs through `IdempotencyService` as `route_prepare_all`. Its request payload contains the business, branch, user, work day, setting snapshots, and stable pre-sale id/status/updated-at values. It locks the work day and every eligible pre-sale in deterministic id order.

## Invoice Integration

`RoutePreSaleInvoiceService` branches only at the existing stock/reservation stage:

- `invoice`: preserves its current decrease-and-consume behavior.
- `picking`: requires persisted prepared/deducted quantities, creates no second stock movement, and does not require an active reservation already consumed by preparation.

All validation, cash, credit, FEL, reconciliation, status conversion, idempotency, and rollback behavior otherwise remain in the existing Phase 2C service.

## User Interface And Permissions

- Super Admin tenant settings exposes both controls in the Routes / Pre-sales section.
- `PREPARAR TODO` is available from an eligible route work day only to users with `routes.pre_sales.pick`; it is disabled while submitting and hidden when no valid pre-sales are available.
- Historical batches and their PDF documents use `routes.pre_sales.admin_view`.
- `Rutas > Preparaciones` lists batches, and batch detail shows its pre-sales, snapshots, totals, and converted-sale links.
- When `automatic_all` is selected, the interface communicates that defaults are still required before any mass invoicing. It never invokes the invoice endpoint automatically.

## Documents

DomPDF renders data from the stored batch and its linked pre-sales on demand:

1. Consolidated customer document: seller, customer, phone, address, route, per-customer total, and total general.
2. Individual receipts: one page per pre-sale/customer with customer, route, seller, reference, observation, prepared product rows, and total.
3. Product summary: prepared quantities grouped by product, with name, code, and brand, ordered by brand and name.

Every document route verifies the batch business, active/allowed branch, Routes module, and `routes.pre_sales.admin_view` permission before rendering.

## Verification

Feature coverage must include settings normalization, both timing behaviors for individual and bulk preparation, all-or-nothing batch rollback, idempotency replay/conflict, tenant and branch isolation, no duplicate stock movement on invoice after picking deduction, no automatic FEL in `automatic_all`, PDF contents, PDF access control, and unchanged Phase 2C invoice behavior.

Run the targeted Route Preparation, Route Pre-sale Invoice, Route, Stock, and Idempotency tests, followed by the full suite, Vite build, and `git diff --check`.
