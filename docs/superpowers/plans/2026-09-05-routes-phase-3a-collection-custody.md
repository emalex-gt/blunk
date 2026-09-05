# Routes Phase 3A: Collection and Cash Custody Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Decouple route receipt creation from collection while preserving cash custody, tenant isolation, idempotency, existing stock behavior, and POS behavior.

**Architecture:** Store payment intent on the pre-sale, real pre-sale collections before a sale exists, and real sale payments after receipt creation. Use RouteDeliveryBatch as a document batch only. Receipt conversion dispatches by collection responsibility, while FEL remains independent of cash and payment state.

**Tech Stack:** Laravel 12, PostgreSQL, React/Inertia, existing IdempotencyService, CashRegister, route services.

**Spec:** `docs/superpowers/specs/2026-09-05-routes-phase-3a-collection-custody-design.md`

## Global Constraints

- Do not change normal POS behavior or payloads.
- Do not add CxC for a route sale pending collection by a delivery agent.
- Keep `RouteCashOperationGuard` as an operational routes guard.
- FEL must not require cash, payment status, a payment, custody, delivery confirmation, stock mutation, reservation mutation, or CxC.
- No physical delivery runs, settlement UI, returns, partial delivery, split payment, worker, or FEL activation in this phase.
- Preserve existing converted route sales and their payments without reinterpretation.

---

### Task 1: Tenant Settings and Agreed Payment Migration

**Files:**
- Create: `database/migrations/2026_09_05_000001_add_route_collection_contract_settings.php`
- Modify: `app/Models/TenantSetting.php`
- Modify: `app/Http/Controllers/SuperAdmin/TenantController.php`
- Modify: `resources/js/Pages/SuperAdmin/Tenants/Form.tsx`
- Test: `tests/Feature/RouteCollectionSettingsTest.php`

**Interfaces:**
- Produces `TenantSetting::route_collection_responsibility`, `route_delivery_tracking`, `route_cash_custody_policy`.
- Produces `PreSale::agreed_payment_method` while retaining legacy `payment_method`.

- [ ] Write failing migration/feature tests for defaults, accepted values, Super Admin persistence, and backfill from legacy `payment_method`.
- [ ] Run `php artisan test --env=testing --filter=RouteCollectionSettings` and confirm failure before implementation.
- [ ] Add nullable `agreed_payment_method`; backfill from `payment_method`; add the three non-null settings with defaults; update model fillable/casts and Super Admin validation/UI.
- [ ] Run the focused test again and confirm it passes.

### Task 2: Route Pre-Sale Collection Persistence and Permission

**Files:**
- Create: `database/migrations/2026_09_05_000002_create_route_pre_sale_collections.php`
- Create: `app/Models/RoutePreSaleCollection.php`
- Modify: `app/Models/PreSale.php`
- Modify: `app/Support/Permissions.php`
- Modify: permission seeder used by the repository
- Test: `tests/Feature/RoutePreSaleCollectionTest.php`

**Interfaces:**
- Produces `PreSale::collections()`.
- Produces permission `routes.collections.override`.
- Produces one active full collection rule enforced by application locks, not a permanent one-row schema constraint.

- [ ] Write failing tests for model relations, tenant/branch foreign keys, override permission, and an active collection’s required audit fields.
- [ ] Run `php artisan test --env=testing --filter=RoutePreSaleCollection` and confirm failure.
- [ ] Create `route_pre_sale_collections` with collection, custody, cash-movement, operation-idempotency, and void traceability fields. Add indexes for business/branch/pre-sale/status and unique nullable `cash_movement_id`. Keep the sale-payment link directional from `sale_payments` to avoid a circular foreign key.
- [ ] Seed the override permission into existing route roles only where an administrator already has route administration authority.
- [ ] Run focused tests and confirm pass.

### Task 3: Capture a Real Pre-Seller Collection

**Files:**
- Create: `app/Services/Routes/RoutePreSaleCollectionService.php`
- Create: `app/Http/Controllers/RoutePreSaleCollectionController.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/RouteController.php`
- Test: `tests/Feature/RoutePreSaleCollectionTest.php`

**Interfaces:**
- Produces `capture(PreSale $preSale, array $data, User $actor): IdempotencyResult`.
- Uses operation type `route_pre_sale_collection_capture`.

- [ ] Write failing tests for seller capture, exact-total validation, duplicate-key replay, over-collection rejection, other-tenant rejection, wrong-branch rejection, and seller mismatch rejection.
- [ ] Run the focused test and confirm failure.
- [ ] Implement locked pre-sale capture: require submitted/processing/picked pre-sale, require current active branch and open route cash guard, require the actor to equal seller unless override permission plus reason is supplied, and write `collected_by` versus `recorded_by` correctly.
- [ ] Ensure the service rejects a second active complete collection but permits a replacement only after formal voiding. A void is only allowed before receipt linking; it records actor, time, and reason, and reverses the immediate-register movement through the existing cash API when applicable.
- [ ] Run focused tests and confirm pass.

### Task 4: Cash Custody Policies

**Files:**
- Modify: `app/Services/Routes/RoutePreSaleCollectionService.php`
- Modify: `app/Support/CashRegister.php` only if a dedicated route collection movement helper removes duplication
- Test: `tests/Feature/RoutePreSaleCollectionTest.php`

**Interfaces:**
- `collector_custody_until_settlement`: stores `held_by_collector`, no movement.
- `immediate_branch_register`: stores `posted_to_branch_cash`, creates one movement referenced as `route_pre_sale_collection`.

- [ ] Write failing tests for cash under both policies, non-cash collection methods, locked cash session use, and no duplicate movement after idempotent replay.
- [ ] Run focused tests and confirm failure.
- [ ] Implement custody assignment and, only for immediate cash, create `cash_movements` using the collection as the reference before a sale exists.
- [ ] Persist the single movement id back to the collection in the same transaction.
- [ ] Run focused tests and confirm pass.

### Task 5: Sale Payment Route Provenance

**Files:**
- Create: `database/migrations/2026_09_05_000003_add_route_collection_provenance_to_sale_payments.php`
- Create: `database/migrations/2026_09_05_000004_make_sales_payment_method_nullable_for_route_delivery_collections.php`
- Modify: `app/Models/SalePayment.php`
- Modify: `app/Models/RoutePreSaleCollection.php`
- Test: `tests/Feature/RoutePreSaleCollectionTest.php`

**Interfaces:**
- `SalePayment` receives nullable `collected_by`, `collected_at`, `cash_register_session_id`, and unique `route_pre_sale_collection_id`.
- `Sale::payment_method` becomes nullable to represent delivery-agent receipts pending operational collection; POS continues supplying it as today.

- [ ] Write failing tests that POS-created payments remain valid with null route fields and a route collection can link to only one sale payment.
- [ ] Run focused tests and confirm failure.
- [ ] Add nullable provenance columns and relations. Make `sales.payment_method` nullable in its own additive migration. Do not alter POS creation paths.
- [ ] Run focused tests and `php artisan test --env=testing --filter=CriticalPosFelFlow`.

### Task 6: Receipt Conversion by Collection Responsibility

**Files:**
- Modify: `app/Services/Routes/RoutePreSaleReceiptService.php`
- Modify: `app/Services/Routes/RoutePreSaleInvoiceService.php`
- Modify: `app/Services/Routes/RouteDeliveryBatchService.php`
- Modify: `app/Models/RouteDeliveryBatchPreSale.php`
- Test: `tests/Feature/RouteDeliveryBatchTest.php`
- Test: `tests/Feature/RoutePreSaleInvoiceTest.php`

**Interfaces:**
- `pre_seller` links a captured collection to one `SalePayment` and creates a paid receipt.
- `delivery_agent` creates an unpaid, non-credit receipt with no payment or cash movement.

- [ ] Write failing tests for both settings, no automatic CxC, stock timing preservation, no duplicate payment/movement, and idempotent ENTREGAR TODO replay.
- [ ] Run RouteDeliveryBatch and RoutePreSaleInvoice filters; confirm failure.
- [ ] Split receipt conversion’s payment posting from stock/reservation conversion. Preserve existing `picking` and `invoice` stock semantics.
- [ ] For pre-seller, lock and link the captured collection, require its amount to equal the computed receipt total, create its sole sale payment, and set receipt payment fields from actual collection data. Block conversion before side effects when picked quantities would yield a different total.
- [ ] For delivery agent, create the specified unpaid non-credit receipt with null payment method and an explicit route collection status.
- [ ] Rename UI-facing batch semantics to “Lote de comprobantes” without renaming tables/models.
- [ ] Run focused suites and confirm pass.

### Task 7: Remove FEL Cash Coupling

**Files:**
- Modify: `app/Services/Routes/RoutePreSaleFelService.php`
- Modify: `app/Jobs/RoutePreSaleAutomaticFelJob.php` only if assertions need alignment
- Test: `tests/Feature/RoutePreSaleFelTest.php`
- Test: `tests/Feature/CriticalPosFelFlowTest.php`

**Interfaces:**
- Route FEL certification validates fiscal eligibility and availability, not cash or payment state.

- [ ] Write failing tests for certifying an eligible unpaid route receipt with no open cash session and for the absence of cash/payment/stock/reservation/CxC mutation.
- [ ] Run RoutePreSaleFel tests and confirm failure.
- [ ] Remove only the RouteCashOperationGuard dependency from route FEL certification; retain tenant, branch, sale, cancellation, eligibility, availability, certified, pending, and unknown checks.
- [ ] Run RoutePreSaleFel and CriticalPosFelFlow tests and confirm pass.

### Task 8: Route UI Contracts

**Files:**
- Modify: `resources/js/Pages/Routes/Mobile/Visit.tsx`
- Modify: `resources/js/Pages/Routes/WorkDays/Show.tsx`
- Modify: `resources/js/Pages/Routes/PreSales/Show.tsx`
- Modify: `resources/js/Pages/Routes/PreSales/Index.tsx`
- Modify: `resources/js/Pages/Routes/DeliveryBatches/Index.tsx`
- Modify: `resources/js/Pages/Routes/DeliveryBatches/Show.tsx`
- Test: `tests/Feature/RoutesPreSalesTest.php`

**Interfaces:**
- Display `Método acordado`, real collection state, real method, collector, custody, and override reason.
- Display `Registrar cobro` only when collection responsibility is `pre_seller` and the user is authorized.

- [ ] Add source assertions or Inertia contract tests for agreed method, collection payload, custody, and the “Lote de comprobantes” label.
- [ ] Run RoutesPreSales tests and confirm failure.
- [ ] Replace route forms’ functional use of legacy `payment_method` with `agreed_payment_method`; add collection capture and administrative override UI with required reason.
- [ ] Keep route cash-closed gates. Do not expose delivery-run controls in this phase.
- [ ] Run focused tests and `cmd /c npm run build`.

### Task 9: Historical Compatibility and Operational Guards

**Files:**
- Modify: `app/Services/Routes/RouteDeliveryBatchService.php`
- Modify: `app/Http/Controllers/RouteController.php`
- Test: `tests/Feature/RouteDeliveryBatchTest.php`
- Test: `tests/Feature/RoutesPreSalesTest.php`

**Interfaces:**
- Existing converted route sales remain unchanged.
- Picked pre-sales require the same Registrar cobro path for pre-seller responsibility.

- [ ] Write failing tests proving a legacy picked pre-sale is not treated as paid from its historical method, while an administrator override can capture it with full traceability.
- [ ] Run focused suites and confirm failure.
- [ ] Add explicit blocking messages and payload state for missing real collection. Do not create a legacy collection type or infer cash movement.
- [ ] Run focused suites and confirm pass.

### Task 10: Regression Matrix and Audit Checkpoint

**Files:**
- Test: `tests/Feature/RoutePreSaleCollectionTest.php`
- Test: `tests/Feature/RouteDeliveryBatchTest.php`
- Test: `tests/Feature/RoutePreSaleFelTest.php`
- Test: `tests/Feature/RoutePreSaleInvoiceTest.php`
- Test: `tests/Feature/RoutesPreSalesTest.php`
- Test: `tests/Feature/CriticalOperationIdempotencyTest.php`
- Test: `tests/Feature/CriticalPosFelFlowTest.php`

- [ ] Add a matrix test for tenant, branch, user, override, cash custody, payment linkage, no CxC, stock timing, FEL independence, and cancellation guard boundaries.
- [ ] Run each focused suite sequentially against `blunk_test`.
- [ ] Run `php artisan test --env=testing`, `cmd /c npm run build`, and `git diff --check`.
- [ ] Perform a code audit before requesting commit approval: inspect every new query for business/branch scope, every cash movement for its collection reference, and every receipt path for duplicate payment creation.
