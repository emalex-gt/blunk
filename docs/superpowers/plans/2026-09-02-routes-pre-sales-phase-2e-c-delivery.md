# Route Pre-Sales Phase 2E-C Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an atomic, paid, route delivery batch for physically picked pre-sales while keeping preparation and FEL certification separate.

**Architecture:** `PREPARAR TODO` remains the existing physical preparation writer. `ENTREGAR TODO` uses a separate delivery service and traceability tables to validate every picked pre-sale, create receipt sales and payments in one local transaction, and only dispatch independent FEL work after commit. A shared route cash guard protects operational mutations; FEL remains non-financial.

**Tech Stack:** Laravel 12, PostgreSQL, React/Inertia, database queues, PHPUnit.

**Spec:** `docs/superpowers/plans/2026-09-01-routes-pre-sales-phase-2e-documentary-fel.md`

## Global Constraints

- Do not modify POS behavior or production.
- `PREPARAR TODO` remains physical preparation only.
- `ENTREGAR TODO` accepts only `picked` pre-sales and only paid methods `cash`, `card`, `transfer`, or `check`.
- Require an open cash-register session for every route operational mutation and lock it while posting payments.
- `picking` stock timing must not be repeated during delivery; `invoice` timing is deducted during receipt creation.
- FEL never changes stock, reservations, payments, cash, or CxC.
- `FEL_ROUTE_AUTOMATION_ENABLED` defaults to false; no automatic FEL is dispatched while disabled.

---

### Task 1: Persist delivery inputs and traceability

**Files:**
- Create: route delivery migration and delivery models.
- Modify: `app/Models/PreSale.php`.
- Test: `tests/Feature/RouteDeliveryBatchTest.php`.

- [ ] Add nullable `pre_sales.payment_method`, selection audit columns, delivery batch and entry tables, all business/branch scoped.
- [ ] Verify the migration supports payment selection without changing existing pre-sales.

### Task 2: Enforce the route cash boundary

**Files:**
- Create: `app/Services/Routes/RouteCashOperationGuard.php`.
- Modify: route controller, preparation service/controller, and receipt conversion.
- Test: `tests/Feature/RouteDeliveryBatchTest.php` and route pre-sale tests.

- [ ] Expose read-only cash availability to the route UI.
- [ ] Reject operational mutations without an open session for the exact branch.
- [ ] Lock the session in receipt/delivery transactions before posting a payment.

### Task 3: Deliver picked pre-sales atomically

**Files:**
- Create: `app/Services/Routes/RouteDeliveryBatchService.php`.
- Modify: receipt conversion writer, delivery controller/routes and work-day UI.
- Test: `tests/Feature/RouteDeliveryBatchTest.php`.

- [ ] Write failing tests for picked-only targets, missing payment method, paid sale/payment/cash effects, timing behavior, idempotency and all-or-nothing validation.
- [ ] Extract a transaction-safe receipt writer shared by manual conversion and the batch service.
- [ ] Persist delivery batch and entries only after all targets have passed validation.

### Task 4: Gate automatic FEL after delivery

**Files:**
- Create: FEL route automation config and job.
- Modify: delivery service and work-day UI.
- Test: `tests/Feature/RouteDeliveryBatchTest.php`.

- [ ] Dispatch one idempotent FEL job per eligible delivered sale only after commit and only when mode is `automatic_all` and the feature gate is true.
- [ ] Keep manual mode at `not_requested`; never dispatch ineligible pre-sales.
- [ ] Preserve local delivery state on FEL failure or unknown reconciliation.

### Task 5: Verify the route UI and regressions

**Files:**
- Modify: `resources/js/Pages/Routes/WorkDays/Show.tsx`, route detail pages as necessary.
- Test: route, preparation, invoice, FEL, stock, idempotency and POS regression suites.

- [ ] Keep the physical action labelled `PREPARAR TODO`.
- [ ] Add `ENTREGAR TODO` with counts, cash state, delivery validation messaging and disabled automation warning.
- [ ] Run focused and full tests, Vite build, and `git diff --check`.
