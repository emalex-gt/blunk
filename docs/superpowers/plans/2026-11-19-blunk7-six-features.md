# Blunk 7 Six Features Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add tenant print identifiers, audited purchase/transfer cancellation, transfer confirmation, expense-aware profit reporting, and deposit payments.

**Architecture:** Reuse tenant settings, stock movements, branch access, idempotency and existing Inertia pages.  Cancellations are transactional compensating operations, never deletes.  Deposit is a canonical non-cash payment method represented separately from transfer in presentation and closing summaries.

**Tech Stack:** Laravel, PostgreSQL, Inertia, React/TypeScript.

## Global Constraints

- Work only on `feature/blunk7-consolidation-local`; no push or deployment.
- Use focused tests with the isolated test connection only; no FEL provider calls.
- Preserve tenant, branch, inventory, cash, idempotency and historical snapshot contracts.

### Task 1: Tenant product identifiers in sale prints

Modify tenant settings model/controller/settings UI, add nullable-safe boolean migration, and pass settings to existing sale/FEL print views.  Print current product code/barcode only when enabled and nonempty; historical sales remain printable when the product relation is missing.

### Task 2: Audited purchase cancellation

Add cancellation audit columns and a guarded controller action.  Lock purchase and products in one transaction, validate branch stock availability with existing policy, create compensating stock movements, mark cancelled, and retain payment/cash history unchanged.

### Task 3: Audited completed-transfer cancellation

Add transfer cancellation audit columns and a guarded action.  Lock source/destination products, verify destination can be decreased, move exact quantities back with compensating movements, then mark cancelled.  Add the confirmation modal to the existing create page.

### Task 4: Expense-aware profit report

Extend the existing profitability data source and React presentation with expense totals drawn from real non-cancelled cash expenses for the same tenant/branch/date range.  Keep inventory purchases outside operating expenses.

### Task 5: Deposit payment method

Add `deposit` to sales/purchases and shared payment validation/presentation points, with separate cash-closing bucket.  Never treat it as physical cash.

### Task 6: Focused regression coverage and final integration checks

Add targeted feature coverage for the new settings, cancellation guards, profit fields, transfer confirmation payload, and deposit method.  Run only the targeted tests after first proving the configured test connection is isolated; run one TypeScript/build verification if dependencies are available.
