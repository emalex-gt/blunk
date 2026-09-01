# Routes / Pre-sales Phase 2E: Internal Receipts and FEL Certification Design

## Goal

Make the route pre-sale lifecycle documentally complete without treating FEL as the sale itself. A prepared pre-sale becomes one internal sale/comprobante exactly once. FEL is an optional fiscal certification attached to that existing sale.

## Confirmed Business Rules

1. A route pre-sale that is closed documentally creates one internal comprobante/venta.
2. The internal receipt is printable using the existing tenant `receipt_format` (`ticket` or `document`).
3. FEL certifies an existing sale; it does not create, delete, pay, reserve, or deduct stock from that sale.
4. `manual` creates internal receipts and leaves FEL as a user-directed action.
5. `automatic_all` creates internal receipts and dispatches an independent FEL attempt for every eligible sale.
6. A failed or uncertain FEL attempt preserves the internal sale and all its already-committed inventory, payment, cash, credit, and pre-sale conversion effects.
7. Stock timing remains unchanged: `picking` decreases at preparation; `invoice` decreases when the internal sale is created.
8. Normal POS behavior and existing POS invoice semantics are out of scope and remain unchanged.

## Current State

`RoutePreSaleInvoiceService::convert()` currently uses the requested `document_type` for two responsibilities:

- `receipt`: creates an internal sale, inventory/payment effects, and converts the pre-sale without FEL.
- `invoice`: creates the same sale-side records, creates an `electronic_documents` row, calls Digifact inside the database transaction, then converts the pre-sale.

An exception from Digifact rolls back the route sale transaction. The pre-sale remains `picked`; a durable `fel_reconciliation_requests` row is retained only for the uncertain provider attempt. This is correct for Phase 2C but contradicts the Phase 2E rule that the internal receipt must remain after a FEL failure.

The database already separates `sales` from `electronic_documents`. The coupling is in application behavior: `sales.document_type` currently gates first certification, retry, FEL display/download, cancellation, and invoice reporting.

## Chosen Architectural Boundary

### Internal sale

For new route pre-sales, the internal sale is persisted with:

- `sales.document_type = receipt`
- `pre_sales.converted_sale_id = sales.id`
- the existing sale number, customer snapshot, sale items, payment/credit records, stock movements, and reservation transitions

This means the existing `/sales/{sale}/receipt` behavior remains the internal printable document. No POS sale is rewritten and no historical route invoice is migrated.

### FEL certification

FEL state is represented by the existing one-to-one `electronic_documents` relationship and sale certification fields:

| State | `electronic_documents` | Meaning |
| --- | --- | --- |
| Not requested | none | Internal receipt exists; manual FEL has not been requested. |
| Pending | `pending` | A certification request has been created or queued. |
| Failed | `failed` | Provider rejected the request; receipt remains valid internally. |
| Uncertain | `unknown` plus reconciliation request | Provider outcome is not known; do not reissue until reconciled. |
| Certified | `certified` | FEL data, UUID, series, number, and printable documents are available. |

The FEL endpoints must authorize and inspect the electronic document/status instead of using `sale.document_type === 'invoice'`. `DigifactInvoiceService` already creates invoice electronic documents for a supplied sale and does not itself require the sale document type to be `invoice`.

## Route Pre-sale Lifecycle

### Manual mode

1. An authorized route user closes a `picked` pre-sale through the internal-receipt conversion action.
2. The conversion locks the pre-sale and its relevant stock/reservation rows, creates one `receipt` sale, and marks the pre-sale `converted` in the same transaction.
3. With timing `invoice`, the conversion decreases stock and consumes the active reservation once. With timing `picking`, it verifies the earlier deduction and does neither a second time.
4. The converted pre-sale and its sale appear in a route FEL queue with state `Not requested` when FEL is available.
5. A user with `fel.certify` requests FEL for a selected sale. The certification action validates tax/customer/tenant/branch requirements, creates or locks the electronic document, and then calls Digifact.

### Automatic-all mode

1. The batch/close action first creates all internal receipts using only local database work and the same idempotent conversion service.
2. The request commits before any external Digifact call.
3. It dispatches one durable FEL job per eligible, newly converted sale. The job is idempotent per sale/internal reference and locks the sale/electronic document before it calls Digifact.
4. Each job independently records `certified`, `failed`, or `unknown`. A failure affects only that sale's FEL state; it never rolls back the receipt or other sales in the batch.
5. The UI exposes failed and uncertain entries for the same manual retry/reconciliation path.

No HTTP request may hold a database transaction open while calling Digifact. Automatic certification requires a configured and monitored queue worker; it must be unavailable in configuration/UI until that operational prerequisite is confirmed.

## Required Services and Routes

### Route pre-sale internal conversion

Rename the user-facing operation to **Generar comprobante interno**. Extract the local part of `RoutePreSaleInvoiceService` into a route-specific internal conversion service. Its contract is:

```php
public function convertToInternalReceipt(
    PreSale $preSale,
    RoutePreSaleReceiptData $data,
    User $user,
): IdempotencyResult;
```

It always creates `document_type=receipt`, preserves the current locks and idempotency semantics, and owns the only transition from `picked` to `converted`.

### First FEL certification and retry

Add a sale-level FEL action that works for a completed route receipt with no electronic document and for a route receipt with `failed`/`unknown` FEL. It must:

- require `module:fel_gt` and `fel.certify`;
- tenant-scope the sale and enforce allowed/active branch access;
- reject cancelled sales and already certified electronic documents;
- validate the current stored customer snapshot against FEL requirements before any provider call;
- lock the sale/electronic document and use a certification idempotency key;
- create a pending electronic document before calling Digifact;
- retain the receipt if Digifact fails and persist the resulting failed/unknown state;
- create or update a durable reconciliation request for uncertain outcomes.

`DigifactInvoiceService` remains the provider abstraction. The route-specific orchestration decides the lifecycle and never re-runs local sale-side mutations.

### Pending FEL views

Extend the route pre-sales list and preparation-batch detail with FEL state for `converted_sale`. Add filters for `not_requested`, `pending`, `failed`, `unknown`, and `certified`. The action labels are:

- `Certificar FEL` for `not_requested` and `failed` after validation;
- `Conciliar FEL` or `Reintentar FEL` for `unknown`, following the existing reconciliation policy;
- `Ver venta` and FEL document links for `certified`.

The queue is defined from `pre_sales` joined to `converted_sale`; it never includes POS-only receipts.

## Compatibility Rules

### POS

POS continues to use `sales.document_type=invoice` for its existing immediate-invoice flow and `receipt` for its existing receipt flow. No POS controller, request payload, menu, or checkout behavior changes.

### Existing sales and route invoices

Existing `invoice` sales keep their present FEL routes and document behavior. Phase 2E applies the decoupled interpretation only when a sale is linked from `pre_sales.converted_sale_id`. No data migration reclassifies historical sales.

### Shared FEL routes

The following current checks must be changed carefully from document-type checks to certified electronic-document checks while preserving POS compatibility:

- `SaleController::retryFelCertification`
- `SaleController::cancel`
- `SaleController::felDocument`
- `SaleController::felDownload`
- `SaleController::felPrint`
- `resources/js/Pages/Sales/Show.tsx`

Sales reports currently count `sales.document_type=invoice` and `receipt`. Those existing metrics remain unchanged in this phase. A separate certified-FEL metric is not included unless explicitly approved.

## Configuration and Mandatory Operational Decisions

`receipt_format` already decides ticket versus document and needs no new route setting.

Automatic creation needs a deterministic financial settlement policy. Phase 2E must not infer it from a seller or customer. Before implementation, the product owner must approve all of the following:

1. **Paid vs credit default:** choose `paid` or `credit` for automatically generated route receipts. Credit requires the normal valid-NIT, credit-enabled, and limit checks per sale.
2. **Payment method when paid:** choose one of `cash`, `card`, `transfer`, or `check`. Cash requires an open cash-register session for the route branch at conversion time.
3. **No open cash register:** choose either block the entire automatic internal-receipt batch before any conversion, or prohibit `cash` as an automatic default. The recommended choice is to prohibit automatic cash until a delivery/cash-collection design exists.
4. **FEL ineligible customers:** approved rule is to create the internal receipt and leave FEL `not_requested` with a visible eligibility reason; no automatic failure/retry loop.
5. **Automatic queue prerequisite:** confirm that a persistent worker processes the FEL queue. The recommended worker is a dedicated queue with a timeout appropriate for Digifact calls and no blind automatic re-certification after `unknown`.

Manual mode keeps the current user-selected payment condition and method in the internal-receipt conversion modal. It does not require these defaults.

## Failure and Cancellation Semantics

- Local internal conversion failure: all local mutations roll back, as today.
- FEL provider rejection after an internal receipt commit: sale, stock, payment, credit charge, and pre-sale conversion remain. The electronic document is failed.
- FEL connection/timeout outcome: the same local records remain, document is unknown, and reconciliation precedes a retry.
- Cancelling a certified route receipt first cancels the electronic document using the existing provider flow; only after that succeeds may normal sale cancellation reverse stock/payment/credit effects.
- Cancelling an uncertified route receipt follows existing sale-cancellation rules and does not call Digifact.

## Scope

Included:

- Route-only internal receipt conversion.
- Manual and automatic-all FEL orchestration after the receipt exists.
- Route FEL status/filter/action UI.
- Electronic-document-based FEL access checks necessary for route receipts.
- Tenant, branch, permission, idempotency, reconciliation, cancellation, print, and download coverage.

Excluded:

- Any POS checkout change.
- Historical data migration.
- New cash-collection or delivery settlement workflow.
- Automatic FEL for sales not sourced from a route pre-sale.
- Changes to normal sales report invoice/receipt counters.

## Acceptance Criteria

- Every documentally closed route pre-sale has exactly one receipt sale and one `converted_sale_id`.
- FEL attempts never create or change inventory, reservations, payments, cash movements, or credit charges.
- A FEL failure leaves the sale visible and printable as an internal receipt.
- `automatic_all` creates receipts locally first and makes independent FEL attempts without a cross-sale external transaction.
- Manual certification can certify a route receipt for the first time, then print/download it once certified.
- No tenant or branch can view, certify, retry, print, download, or cancel another tenant/unauthorized branch's route sale.
- POS invoice and receipt tests remain unchanged and pass.
