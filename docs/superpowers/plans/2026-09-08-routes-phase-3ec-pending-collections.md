# Fase 3E-C Pending Collections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Dar seguimiento y cobrar posteriormente ventas de ruta entregadas, unpaid y responsabilidad delivery_agent sin convertirlas en crédito contractual.

**Architecture:** RoutePendingCollectionEligibility normaliza candidatos external e in-app; cases y events guardan únicamente seguimiento operativo. RoutePendingCollectionService usa el núcleo financiero de RouteDeliveryCollectionService con un contexto post_delivery, resuelve el case en la misma transacción y conserva las reglas de custodia 3A–3D.

**Tech Stack:** Laravel, PostgreSQL checks/partial indexes, Eloquent, Inertia React, PHPUnit.

**Spec:** docs/superpowers/specs/2026-09-08-routes-phase-3ec-pending-collections-design.md

## Global Constraints

- Sólo delivered + unpaid + delivery_agent; no partial, split, CxC ni crédito contractual.
- Collection, payment, sale y custodia siguen siendo las únicas fuentes financieras.
- Cash fuera de sucursal no necesita caja y queda held_by_collector; posting inmediato exige caja actual y recepción física explícita.
- No reabrir jornadas ni conciliaciones; no tocar FEL, stock, reservas o POS.
- No commit, push, deploy ni producción sin aprobación explícita.

---

### Task 1: Eligibility normalizer external/in-app

**Files:**
- Create: app/Services/Routes/RoutePendingCollectionEligibility.php
- Create: tests/Feature/RoutePendingCollectionEligibilityTest.php
- Modify: tests/Feature/RouteExternalDeliveryReconciliationTest.php
- Modify: tests/Feature/RouteDeliveryRunTest.php

**Interfaces:**
- Produces RoutePendingCollectionEligibility::candidates(int businessId, int branchId, array filters = []): Builder-like normalized query.
- Each projected row exposes sale_id, pre_sale_id, batch_pre_sale_id, origin, origin_id, delivered_at and original_delivery_user_id.
- Excludes paid, credit, pre_seller, not_delivered and already-collected sales.

- [ ] **Step 1: Write failing eligibility tests.**

Cover external delivered/unpaid, in-app delivered/unpaid, closed run, completed reconciliation, tenant/branch isolation, paid exclusion, contractual-credit exclusion, pre_seller anomaly exclusion and not_delivered exclusion.

- [ ] **Step 2: Run the focused test and confirm the absence of the adapter.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionEligibility

Expected: FAIL because RoutePendingCollectionEligibility does not exist.

- [ ] **Step 3: Implement normalized source queries.**

Implement two explicit source builders and union them only after selecting compatible aliases:

~~~
origin, origin_id, sale_id, pre_sale_id, batch_pre_sale_id,
business_id, branch_id, delivered_at, original_delivery_user_id
~~~

Require delivered outcome, delivery_agent snapshot, unpaid sale, zero paid amount, non-credit sale, zero credit balance and no route_delivery_collection.

- [ ] **Step 4: Run the focused test and inspect SQL shape.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionEligibility

Expected: PASS; no cross-tenant candidates and no N+1 relation access in payload tests.

### Task 2: Case/event migrations, models and database constraints

**Files:**
- Create: database/migrations/2026_09_08_000016_create_route_pending_collection_cases.php
- Create: database/migrations/2026_09_08_000017_create_route_pending_collection_events.php
- Create: app/Models/RoutePendingCollectionCase.php
- Create: app/Models/RoutePendingCollectionEvent.php
- Create: tests/Feature/RoutePendingCollectionPersistenceTest.php

**Interfaces:**
- RoutePendingCollectionCase has origin relations, sale, preSale, batchPreSale, assignedTo, resolvedBy, resolutionCollection and events.
- RoutePendingCollectionEvent belongs to case and recordedBy.
- Case status values are open, resolved and not_applicable.

- [ ] **Step 1: Write failing persistence tests.**

Test exact-one-origin checks, origin/status checks, unique sale, unique origin, unique resolution collection, event idempotency key uniqueness and required resolution/not-applicable metadata.

- [ ] **Step 2: Run fresh migration and persistence filter.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionPersistence

Expected: FAIL because tables and models do not exist.

- [ ] **Step 3: Create tables and Eloquent relationships.**

Use real FKs, PostgreSQL checks, nullable unique indexes for physical origins and indexes listed in the spec. Preserve not_applicable metadata after reopen; require it only while current status is not_applicable.

- [ ] **Step 4: Verify schema from a clean testing database.**

Run: php artisan migrate:fresh --env=testing --force

Run: php artisan test --env=testing --filter=RoutePendingCollectionPersistence

Expected: PASS.

### Task 3: Automatic, idempotent case lifecycle

**Files:**
- Create: app/Services/Routes/RoutePendingCollectionCaseService.php
- Create: tests/Feature/RoutePendingCollectionCaseLifecycleTest.php
- Modify: app/Services/Routes/RouteExternalDeliveryReconciliationService.php
- Modify: app/Services/Routes/RouteDeliveryStopService.php

**Interfaces:**
- RoutePendingCollectionCaseService::syncDeliveredOutcome(RouteExternalDeliveryReconciliationItem|RouteDeliveryStop source, User actor): ?RoutePendingCollectionCase.
- RoutePendingCollectionCaseService::markNotApplicableForCorrection(RouteExternalDeliveryReconciliationItem|RouteDeliveryStop source, User actor, string correctionReason): RoutePendingCollectionCase.
- RoutePendingCollectionCaseService::reopenForCorrection(RouteExternalDeliveryReconciliationItem|RouteDeliveryStop source, User actor): RoutePendingCollectionCase.

- [ ] **Step 1: Write failing lifecycle tests.**

Test automatic open during new external/in-app delivered+unpaid confirmation, no case for delivered+paid, no normal case for pre_seller, repeated request creates one case, and concurrent uniqueness failure is translated into the existing case result.

- [ ] **Step 2: Run the lifecycle filter.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionCaseLifecycle

Expected: FAIL before the service hooks exist.

- [ ] **Step 3: Implement synchronized opening in existing outcome transactions.**

Call sync only after the physical outcome and final sale payment state are known. Lock source, sale and case by sale. Do not write a case during a read and do not wait for run/reconciliation closure.

- [ ] **Step 4: Verify lifecycle behavior.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionCaseLifecycle

Expected: PASS, including a single case after retries.

### Task 4: Hybrid historical materialization and queue projection

**Files:**
- Modify: app/Services/Routes/RoutePendingCollectionEligibility.php
- Modify: app/Services/Routes/RoutePendingCollectionCaseService.php
- Create: tests/Feature/RoutePendingCollectionHistoricalTest.php

**Interfaces:**
- RoutePendingCollectionEligibility::queue(int businessId, int branchId, array filters = []): normalized paginatable query.
- RoutePendingCollectionCaseService::ensureForEligibleCandidate(int saleId, User actor): RoutePendingCollectionCase.

- [ ] **Step 1: Write failing historical tests.**

Test a 3B/3C historical delivered+unpaid sale without a case appears once, an open case replaces the derived row, a resolved/not_applicable case is not duplicated, and first event or collection materializes one case with opened_at equal to physical delivered_at.

- [ ] **Step 2: Run the historical filter.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionHistorical

Expected: FAIL before the hybrid queue and lazy materialization exist.

- [ ] **Step 3: Implement the UNION ALL queue and write-time materialization.**

Use persisted open cases as the first branch and derived candidates with NOT EXISTS case-by-sale as the second. Do not backfill in migrations, controllers or reads. Compute aging from delivered_at in SQL/query projection.

- [ ] **Step 4: Verify visual uniqueness and aging boundaries.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionHistorical

Expected: PASS for today, 1–3, 4–7 and 8+ day buckets.

### Task 5: Follow-up events, assignment and next action

**Files:**
- Modify: app/Services/Routes/RoutePendingCollectionCaseService.php
- Create: tests/Feature/RoutePendingCollectionFollowUpTest.php

**Interfaces:**
- addEvent(RoutePendingCollectionCase case, array data, User actor, string idempotencyKey): RoutePendingCollectionEvent.
- updateAssignment(RoutePendingCollectionCase case, ?int assignedTo, ?Carbon nextFollowUpAt, User actor): RoutePendingCollectionCase.

- [ ] **Step 1: Write failing follow-up tests.**

Cover six allowed event types, required notes for note/promise/dispute, tenant/branch/active-user validation for assigned_to, open-only writes, request retry using the same idempotency key, last event ordering and next_follow_up persistence.

- [ ] **Step 2: Run the follow-up filter.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionFollowUp

Expected: FAIL before append-only operations exist.

- [ ] **Step 3: Implement append-only event and case metadata operations.**

Use IdempotencyService for event writes; do not update or delete prior events. Lock case and enforce status=open before assignment, next action or event creation.

- [ ] **Step 4: Verify append-only behavior.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionFollowUp

Expected: PASS and no duplicate event on retry.

### Task 6: Late collection service, payment and custody

**Files:**
- Create: app/Services/Routes/RoutePendingCollectionService.php
- Modify: app/Services/Routes/RouteDeliveryCollectionService.php
- Create: tests/Feature/RoutePendingCollectionCollectionTest.php
- Modify: tests/Feature/RouteExternalDeliveryReconciliationTest.php
- Modify: tests/Feature/RouteDeliveryRunTest.php

**Interfaces:**
- RoutePendingCollectionService::collect(RoutePendingCollectionCase|int saleId, array data, User actor, string idempotencyKey): RouteDeliveryCollection.
- RouteDeliveryCollectionService exposes an internal shared capture operation accepting a post_delivery policy; public live-route behavior remains unchanged.

- [ ] **Step 1: Write failing late-collection tests.**

Cover closed in-app run, completed external reconciliation, full amount only, all four methods, collection/payment uniqueness, actor/collector/recorded_by separation, historical collected_at override, no CxC, and simultaneous attempts by two administrators.

- [ ] **Step 2: Run the collection filter.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionCollection

Expected: FAIL because late collection is currently constrained by live run execution.

- [ ] **Step 3: Extract only the common financial capture core.**

Keep RouteDeliveryStopService::collect as a live-run operation. The post_delivery policy locks sale/case/source, requires delivered and delivery_agent, creates exactly one RouteDeliveryCollection and SalePayment, and updates the sale paid in one transaction.

- [ ] **Step 4: Implement approved cash rules.**

Transfer/card/check do not require cash session and receive not_applicable. Outside cash does not require session, stays held_by_collector and has no movement. Current physical branch receipt requires explicit confirmation and CashRegister::requireOpenSession for the current branch.

- [ ] **Step 5: Verify custody and contention.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionCollection

Expected: PASS with at most one collection, payment and applicable cash movement.

### Task 7: Automatic resolution and physical-correction integration

**Files:**
- Modify: app/Services/Routes/RoutePendingCollectionCaseService.php
- Modify: app/Services/Routes/RoutePendingCollectionService.php
- Modify: app/Services/Routes/RouteDeliveryStopCorrectionService.php
- Modify: app/Services/Routes/RouteExternalDeliveryReconciliationCorrectionService.php
- Create: tests/Feature/RoutePendingCollectionCorrectionTest.php

**Interfaces:**
- resolveFromCollection(RoutePendingCollectionCase case, RouteDeliveryCollection collection, User actor): RoutePendingCollectionCase.
- Correction services synchronize open -> not_applicable and not_applicable -> open only before collection.

- [ ] **Step 1: Write failing resolution/correction tests.**

Test resolved case points to the exact collection after paid sale, resolved+unpaid is impossible, delivered->not_delivered transitions open to not_applicable, restoration reopens an eligible case, and any delivered->not_delivered correction after collection is blocked before revision creation.

- [ ] **Step 2: Run the correction filter.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionCorrection

Expected: FAIL before lifecycle integration is implemented.

- [ ] **Step 3: Implement transactionally ordered transitions.**

Resolve only after collection/payment/sale changes succeed. In correction transactions lock source, case and delivery collection; append the existing physical revision before finishing the permitted transition, but reject destructive correction before persisting any revision.

- [ ] **Step 4: Verify financial invariants.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionCorrection

Expected: PASS with FEL, stock, reservations, sale payment history and CxC unchanged.

### Task 8: Permissions, HTTP contracts and authorization

**Files:**
- Modify: app/Support/Permissions.php
- Modify: routes/web.php
- Create: app/Http/Controllers/RoutePendingCollectionController.php
- Create: tests/Feature/RoutePendingCollectionHttpTest.php

**Interfaces:**
- GET routes.pending-collections.index and show.
- POST routes.pending-collections.events.store and collect.
- PATCH routes.pending-collections.assignment.update.
- Permissions view, manage and collect; override remains routes.delivery_collections.override.

- [ ] **Step 1: Write failing HTTP tests.**

Test view/manage/collect separation, delivery_agent denial, tenant/branch isolation, inactive actor denial, resource ownership, collected_by override, historical date override and idempotent response reuse.

- [ ] **Step 2: Run the HTTP filter.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionHttp

Expected: FAIL because routes and controller are absent.

- [ ] **Step 3: Register permissions and endpoints.**

Validate idempotency keys and inputs server-side. Expose a stable Inertia payload for persisted and derived historical rows without trusting route parameters or frontend flags.

- [ ] **Step 4: Verify authorization matrix.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionHttp

Expected: PASS.

### Task 9: Administrative Inertia UX

**Files:**
- Create: resources/js/Pages/Routes/PendingCollections/Index.tsx
- Create: resources/js/Pages/Routes/PendingCollections/Show.tsx
- Modify: resources/js/Pages/Routes/DeliveryBatches/Index.tsx
- Create: tests/Feature/RoutePendingCollectionInertiaTest.php

**Interfaces:**
- Index props: summary, filters, paginated cases, aging buckets and permissions.
- Show props: case/candidate, delivery context, financial context, events, assignment candidates and action permissions.

- [ ] **Step 1: Write failing Inertia contract tests.**

Assert the administrative page receives only contextual FEL/payment information, one row per sale, aging, last follow-up, next action, and action flags separated by permission.

- [ ] **Step 2: Run the Inertia filter.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionInertia

Expected: FAIL before page contracts exist.

- [ ] **Step 3: Implement index and detail views.**

Use cards/responsive layout, filters for origin/aging/assignee/search, read-only delivery/financial context, append-only follow-up form and full-payment form. For cash receipt show the physical-current-session confirmation only when cash is selected.

- [ ] **Step 4: Verify UI contract and frontend build.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionInertia

Run: cmd /c npm run build

Expected: PASS.

### Task 10: Integrity auditor and full regression

**Files:**
- Modify: app/Support/SystemIntegrityAuditor.php
- Create: tests/Feature/RoutePendingCollectionIntegrityTest.php
- Modify: tests/Feature/RouteCashSettlementEligibilityTest.php only for approved 3D eligibility regression

**Interfaces:**
- SystemIntegrityAuditor reports case/origin/payment/scope/CxC anomalies without weakening POS or historical not_delivered+collected flows.

- [ ] **Step 1: Write corrupt-fixture tests.**

Cover duplicate case, open+paid, resolved+unpaid, stale not_applicable, invalid resolution collection, origin mismatch, tenant/branch/event mismatch, post-delivery collection on not-delivered source, pre_seller anomaly and artificial CxC.

- [ ] **Step 2: Run integrity filter.**

Run: php artisan test --env=testing --filter=RoutePendingCollectionIntegrity

Expected: FAIL before auditor coverage exists.

- [ ] **Step 3: Implement set-based auditor checks.**

Keep existing POS cash requirements strict. Do not flag legacy not_delivered+collected; scope the new source-delivered check by joining resolution_route_delivery_collection_id, without adding a new financial collection type.

- [ ] **Step 4: Execute sequential financial regression.**

Run:

~~~
php artisan test --env=testing --filter=RoutePendingCollection
php artisan test --env=testing --filter=RouteDeliveryCollection
php artisan test --env=testing --filter=RouteExternal
php artisan test --env=testing --filter=RouteDelivery
php artisan test --env=testing --filter=RouteCashSettlement
php artisan test --env=testing --filter=Cash
php artisan test --env=testing --filter=Sale
php artisan test --env=testing --filter=Credit
php artisan test --env=testing --filter=FEL
php artisan test --env=testing --filter=CriticalPosFelFlow
php artisan test --env=testing
cmd /c npm run build
git diff --check
git status --short
~~~

Expected: all tests/build pass; no automated migration backfill, push, deploy or commit.
