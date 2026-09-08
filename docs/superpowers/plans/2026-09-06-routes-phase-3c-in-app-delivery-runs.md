# Routes Phase 3C In-App Delivery Runs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Build a mobile, auditable in-app delivery-day workflow for snapshot-backed route receipt entries without changing FEL, POS, stock, reservations or CxC.

**Architecture:** Stops are the only in-app physical-delivery record and belong to a delivery run. They reference documentary batch entries and reuse generalized post-sale delivery collections; external reconciliation remains a separate source selected by immutable batch snapshot. Shared outcome rules own validation for both external and in-app results.

**Tech Stack:** Laravel, PostgreSQL, Eloquent, Inertia React/TypeScript, PHPUnit, Vite.

**Spec:** docs/superpowers/specs/2026-09-06-routes-phase-3c-in-app-delivery-runs-design.md

## Global Constraints

- Apply only to snapshot tracking in_app; never reinterpret current tenant settings.
- No GPS, owned navigation, photos, signatures, partial delivery/payment, split payment, CxC, refund, settlement or FEL action.
- Batch metadata remains documentary, never physical delivery evidence.
- Require an open route cash guard for start, stop mutation, collection and close; it is not custody proof.
- Every mutation is business/branch scoped, locked and idempotent.
- Do not weaken POS integrity checks.

---

### Task 1: Shared delivery outcome rules

**Files:**
- Create: app/Services/Routes/DeliveryOutcomeRules.php
- Modify: app/Services/Routes/RouteExternalDeliveryReconciliationService.php
- Modify: app/Services/Routes/RouteExternalDeliveryReconciliationCorrectionService.php
- Test: tests/Unit/Services/Routes/DeliveryOutcomeRulesTest.php
- Test: tests/Feature/RouteExternalDeliveryReconciliationTest.php

**Interfaces:** Produce DeliveryOutcomeRules::normalize(string status, ?string reasonCode, ?string notes): array.

- [ ] Write failing tests for delivered optional notes, delivered clearing reason, each valid non-delivery reason, other without notes, invalid status and missing reason.
- [ ] Run: php artisan test --env=testing --filter=DeliveryOutcomeRules. Expected: failure because the class is absent.
- [ ] Implement the six-code catalog and normalizer returning delivery_status, not_delivered_reason_code and delivery_notes.
- [ ] Replace duplicated external validation and map canonical keys to the existing external column.
- [ ] Run RouteExternal and DeliveryOutcomeRules filters. Expected: passing, including consistent delivered notes.
- [ ] Commit: git commit -m "extract route delivery outcome rules".

### Task 2: Persistence, models and collection origin

**Files:**
- Create: migrations for delivery runs, stops, revisions and collection origin
- Create: app/Models/RouteDeliveryRun.php
- Create: app/Models/RouteDeliveryStop.php
- Create: app/Models/RouteDeliveryStopRevision.php
- Modify: app/Models/RouteDeliveryCollection.php, app/Models/RouteExternalDeliveryReconciliationItem.php, app/Models/Sale.php, app/Models/RouteDeliveryBatchPreSale.php
- Test: tests/Feature/RouteDeliveryRunPersistenceTest.php

**Interfaces:** Create run/stop/revision relations and a collection that has exactly one external-item or in-app-stop source.

- [ ] Write failing tests for unique stop entry, one active run per delivery user/branch, revision version, collection origin exclusivity, unique payment and movement links.
- [ ] Run persistence filter. Expected: missing table/migration failure.
- [ ] Add PostgreSQL FKs, indexes, partial unique index, outcome-shape check and origin-exclusivity check exactly as the spec defines.
- [ ] Add casts, fillable fields and focused relations without adding physical semantics to batch fields.
- [ ] Run: php artisan migrate:fresh --env=testing and the persistence filter. Expected: passing.
- [ ] Commit: git commit -m "add in-app delivery run persistence".

### Task 3: Assignment, multi-batch selection and exclusion

**Files:**
- Create: app/Services/Routes/RouteDeliveryRunAssignmentService.php
- Test: tests/Feature/RouteDeliveryRunAssignmentTest.php

**Interfaces:** createDraft(User manager, User deliveryUser, array entryIds), assignEntries(RouteDeliveryRun run, array entryIds, User manager), assignBatch(RouteDeliveryRun run, RouteDeliveryBatch batch, User manager).

- [ ] Write failing tests for tenant/branch isolation, incompatible snapshots, several batches in one run, a split batch, external item conflict, existing stop and concurrent duplicate assignment.
- [ ] Run assignment filter. Expected: service absent.
- [ ] Implement deterministic entry locking, assignee validation, snapshot checks and stop/customer snapshot creation.
- [ ] Implement whole-batch assignment as eligible-entry selection, returning assigned and skipped ids; add no run/batch pivot.
- [ ] Run assignment and RouteDelivery filters. Expected: passing.
- [ ] Commit: git commit -m "add delivery run assignments".

### Task 4: Start, resume and close under cash guard

**Files:**
- Create: app/Services/Routes/RouteDeliveryRunService.php
- Test: tests/Feature/RouteDeliveryRunLifecycleTest.php

**Interfaces:** start(RouteDeliveryRun run, User actor, string key), close(RouteDeliveryRun run, User actor, string key, bool confirmUnpaid), progress(RouteDeliveryRun run): array.

- [ ] Write failing tests for transitions, assignee restriction, no stops, closed cash, idempotent start/close, pending close rejection and confirmed unpaid warning close.
- [ ] Run lifecycle filter. Expected: failure.
- [ ] Implement locked transitions, permission/scope checks, RouteCashOperationGuard enforcement and SQL aggregate progress.
- [ ] Run lifecycle and Cash filters. Expected: passing.
- [ ] Commit: git commit -m "add delivery run lifecycle".

### Task 5: Stop outcome execution

**Files:**
- Create: app/Services/Routes/RouteDeliveryStopService.php
- Test: tests/Feature/RouteDeliveryStopTest.php

**Interfaces:** complete(RouteDeliveryStop stop, array outcome, User actor, string key): IdempotencyResult.

- [ ] Write failing tests for only assigned user, open run, open cash, delivered notes, required non-delivery reason, other note, replay and payment-independent outcomes.
- [ ] Run stop filter. Expected: failure.
- [ ] Implement source/run/stop locks, shared normalization, completion audit fields and route_delivery_stop_complete idempotency.
- [ ] Ensure pre-seller completion rejects collection fields and never mutates sale/payment/cash/FEL/stock/reservations.
- [ ] Run stop and RouteExternal filters. Expected: passing.
- [ ] Commit: git commit -m "add in-app delivery stop outcomes".

### Task 6: Delivery-agent collection and custody

**Files:**
- Modify: app/Services/Routes/RouteDeliveryCollectionService.php
- Modify: app/Services/Routes/RouteExternalDeliveryReconciliationService.php
- Modify: app/Services/Routes/RouteDeliveryStopService.php
- Create: app/Services/Routes/DeliveryCollectionSourceContext.php
- Test: tests/Feature/RouteDeliveryCollectionSourceTest.php
- Test: tests/Feature/RouteDeliveryStopTest.php

**Interfaces:** captureFull(DeliveryCollectionSourceContext context, array data, User actor): RouteDeliveryCollection.

- [ ] Write failing tests for in-app card, transfer, check, held cash, confirmed current receipt, no-confirmation immediate policy, duplicate payment/movement, override and no CxC.
- [ ] Run collection-source filter. Expected: source context absent.
- [ ] Refactor external capture to create explicit external context without changing its behavior; make stop capture create in-app context.
- [ ] Preserve unpaid sale lock, exact amount, collector/time/method validation, custody snapshot, unique payment/movement and idempotency.
- [ ] Run sequential RouteExternal, RouteDelivery, Cash, Sale and Credit filters. Expected: passing.
- [ ] Commit: git commit -m "reuse delivery collections for in-app stops".

### Task 7: Full delivery/payment matrix and invariants

**Files:**
- Modify: tests/Feature/RouteDeliveryStopTest.php
- Modify: tests/Feature/RouteDeliveryRunLifecycleTest.php

**Interfaces:** Verify Tasks 4-6 service contracts.

- [ ] Add pre-seller paid delivered/not-delivered tests with no duplicate collection/payment/movement.
- [ ] Add delivery-agent delivered+collected, delivered+not-collected, not-delivered+collected and not-delivered+not-collected tests.
- [ ] Assert unchanged FEL, stock, reservations, sale state and customer-account tables for physical outcomes.
- [ ] Run RouteDelivery filter. Expected: passing full matrix.
- [ ] Commit: git commit -m "cover in-app delivery payment matrix".

### Task 8: Audited stop corrections

**Files:**
- Create: app/Services/Routes/RouteDeliveryStopCorrectionService.php
- Test: tests/Feature/RouteDeliveryStopCorrectionTest.php

**Interfaces:** correct(RouteDeliveryStop stop, array outcome, User actor): RouteDeliveryStop.

- [ ] Write failing tests for permission, mandatory reason, delivered optional notes, append-only versions, tenant/branch isolation and destructive-field rejection.
- [ ] Run correction filter. Expected: failure.
- [ ] Implement locked revision creation using shared outcome normalization.
- [ ] Reject collection, payment, cash, custody, FEL, sale, stock, reservation and refund keys before persistence.
- [ ] Run correction and RouteExternal filters. Expected: passing.
- [ ] Commit: git commit -m "add audited delivery stop corrections".

### Task 9: Permissions, controllers and mobile Inertia UI

**Files:**
- Modify: app/Support/Permissions.php, routes/web.php
- Create: app/Http/Controllers/RouteDeliveryRunController.php
- Create: app/Http/Controllers/RouteDeliveryStopController.php
- Create: resources/js/Pages/Routes/Mobile/DeliveryRuns/Index.tsx
- Create: resources/js/Pages/Routes/Mobile/DeliveryRuns/Show.tsx
- Create: resources/js/Pages/Routes/Mobile/DeliveryRuns/Stop.tsx
- Test: tests/Feature/RouteDeliveryRunHttpTest.php

**Interfaces:** Expose manager assignment/start/close and assigned-user mobile endpoints; every write accepts an idempotency key.

- [ ] Write failing HTTP tests for permission authority, assigned-user visibility, tenant/branch isolation, cash block, override and closed immutability.
- [ ] Run HTTP filter. Expected: route/controller failure.
- [ ] Add five permissions and delivery_agent base role with only view/execute; exclude manage/correct/override.
- [ ] Implement scoped controllers; never trust posted branch, delivery user, amount or source ids.
- [ ] Build mobile pages with idempotency keys, progress, large actions, payment form, warning-confirm close, phone/maps links, search and draft-only ordering.
- [ ] Run HTTP filter and cmd /c npm run build. Expected: passing.
- [ ] Commit: git commit -m "add mobile delivery run workflow".

### Task 10: Integrity auditor and full regression

**Files:**
- Modify: app/Support/SystemIntegrityAuditor.php
- Modify/Create: existing auditor test file and tests/Feature/RouteDeliveryRunIntegrityTest.php

**Interfaces:** Add run findings while retaining existing POS error semantics.

- [ ] Write failing audit tests for duplicate stop, active-run conflict, external/in-app conflict, snapshot mismatch, closed pending run, scope mismatch and collection/payment/movement provenance.
- [ ] Run integrity filter. Expected: missing audit findings.
- [ ] Add set-based scoped checks; preserve held-route-cash exceptions and ordinary POS cash-movement requirements.
- [ ] Run sequentially: RouteExternal, RouteDelivery, RouteCollection, Cash, Sale, Credit, FEL and CriticalPosFelFlow filters.
- [ ] Run php artisan test --env=testing, cmd /c npm run build, git diff --check and git status --short; record evidence before commit approval.
- [ ] Commit only after explicit user approval: git commit -m "add in-app route delivery runs".

## Plan self-review

Every specification section maps to a task: common rules (1), data model (2), assignment/exclusion (3), lifecycle/cash/close (4), outcomes (5), collections/custody (6), payment matrix/invariants (7), corrections (8), permissions/mobile UX (9), and integrity/POS regression (10). No POS, FEL, stock, reservation, CxC, offline or owned navigation scope is added.
