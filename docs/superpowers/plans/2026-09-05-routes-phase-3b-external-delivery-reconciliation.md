# Routes Phase 3B External Delivery Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Add auditable external physical-delivery reconciliation and full delivery-agent collection without changing POS, FEL, stock, reservations, CxC or historic settings interpretation.

**Architecture:** Snapshot tracking and collection responsibility at document-batch creation. Store physical delivery in one reconciliation item per batch entry; when an external delivery agent actually collects, store a separate post-sale collection and one directionally linked financial payment. Cash is posted only after confirmed physical receipt in the open current branch session.

**Tech Stack:** Laravel 12, PostgreSQL, React/Inertia, existing IdempotencyService, CashRegister, routes services, PHPUnit.

**Spec:** docs/superpowers/specs/2026-09-05-routes-phase-3b-external-delivery-reconciliation-design.md

## Global Constraints

- Scope only delivery batches with an external snapshot; reject in-app and legacy review-required entries.
- Do not use current TenantSetting values to reinterpret historical work.
- Keep physical delivery, actual collection, sale payment and branch cash as distinct sources of truth.
- A delivery-agent collection is full only: one collection and one sale payment for an unpaid sale; do not create CxC.
- Post cash only with explicit backend-validated receipt-now confirmation and the locked current branch session.
- Do not alter POS payloads/behavior, FEL, stock, inventory movements, reservations, refunds, cancellation or settlement.
- Scope every query and relation by business and active branch. Every write is idempotent.
- Implement no legacy attestation workflow in this phase.

---

## File map

| Path | Responsibility |
| --- | --- |
| database/migrations/*_snapshot_route_delivery_batch_operation_settings.php | operation-time snapshots |
| database/migrations/*_create_route_external_delivery_reconciliations.php | reconciliation header and item |
| database/migrations/*_create_route_delivery_collections.php | actual post-sale collection/custody |
| database/migrations/*_add_route_delivery_collection_to_sale_payments.php | directional financial provenance |
| database/migrations/*_create_route_external_delivery_reconciliation_item_revisions.php | audited delivery correction |
| app/Models/RouteExternalDeliveryReconciliation*.php | reconciliation, item and revision relations |
| app/Models/RouteDeliveryCollection.php | post-sale collection relation model |
| app/Services/Routes/RouteExternalDeliveryReconciliationService.php | line reconciliation and idempotency |
| app/Services/Routes/RouteDeliveryCollectionService.php | full collection/payment/cash transition |
| app/Services/Routes/RouteExternalDeliveryReconciliationCorrectionService.php | append-only corrections |
| app/Http/Controllers/RouteExternalDeliveryReconciliationController.php | request, authorization and response |
| app/Services/Routes/RouteDeliveryBatchService.php | snapshot creation |
| app/Http/Controllers/RouteDeliveryBatchController.php | snapshot-safe detail and progress payload |
| app/Support/SystemIntegrityAuditor.php | provenance-aware route cash validation |
| app/Support/Permissions.php and permission seeder | permissions |
| routes/web.php | endpoints |
| resources/js/Pages/Routes/DeliveryBatches/Show.tsx | per-row reconciliation UI |
| tests/Feature/RouteExternalDeliveryReconciliationTest.php | service/HTTP/state matrix |
| tests/Feature/RouteDeliveryBatchTest.php | snapshot regression |
| tests/Feature/SystemIntegrityAuditorTest.php | cash-auditor regression |

### Task 1: Snapshot delivery operation settings at batch creation

**Files:**
- Create: database/migrations/*_snapshot_route_delivery_batch_operation_settings.php
- Modify: app/Models/RouteDeliveryBatch.php
- Modify: app/Services/Routes/RouteDeliveryBatchService.php
- Modify: app/Http/Controllers/RouteDeliveryBatchController.php
- Test: tests/Feature/RouteDeliveryBatchTest.php

**Interfaces:**
- RouteDeliveryBatch exposes delivery_tracking_snapshot, collection_responsibility_snapshot and isExternalDeliveryTracking(): bool.
- deliverAll() writes external|in_app and pre_seller|delivery_agent in the existing batch creation transaction.

- [ ] **Step 1: Write failing tests.** Create external/pre-seller and external/delivery-agent batches, change tenant settings afterwards, and assert the stored snapshot and detail payload never change. Assert an existing null-snapshot batch is not backfilled.

~~~
$batch = $this->deliverRouteBatch($business, 'delivery_agent', 'external');
$this->setRouteSettings($business, 'pre_seller', 'in_app');
$this->assertSame('delivery_agent', $batch->fresh()->collection_responsibility_snapshot);
$this->assertSame('external', $batch->fresh()->delivery_tracking_snapshot);
~~~

- [ ] **Step 2: Run the test and confirm it fails.**

Run: php artisan test --env=testing --filter=RouteDeliveryBatchTest

Expected: snapshot columns and snapshot payload are absent.

- [ ] **Step 3: Implement the additive migration and code.** Leave old values null, capture only accepted tenant values under the existing transaction, and replace the controller's current TenantSetting responsibility lookup with the batch snapshot.

- [ ] **Step 4: Run the focused test and confirm it passes.**

Run: php artisan test --env=testing --filter=RouteDeliveryBatchTest

- [ ] **Step 5: Commit the checkpoint.**

~~~
git add database/migrations app/Models/RouteDeliveryBatch.php app/Services/Routes/RouteDeliveryBatchService.php app/Http/Controllers/RouteDeliveryBatchController.php tests/Feature/RouteDeliveryBatchTest.php
git commit -m "snapshot route delivery operation settings"
~~~

### Task 2: Add reconciliation persistence and safe legacy eligibility

**Files:**
- Create: database/migrations/*_create_route_external_delivery_reconciliations.php
- Create: app/Models/RouteExternalDeliveryReconciliation.php
- Create: app/Models/RouteExternalDeliveryReconciliationItem.php
- Create: app/Services/Routes/ExternalDeliveryEligibility.php
- Modify: app/Models/RouteDeliveryBatch.php
- Modify: app/Models/RouteDeliveryBatchPreSale.php
- Test: tests/Feature/RouteExternalDeliveryReconciliationTest.php

**Interfaces:**
- RouteDeliveryBatch::externalDeliveryReconciliation(): HasOne.
- RouteExternalDeliveryReconciliation::items(): HasMany.
- ExternalDeliveryEligibility::forEntry(RouteDeliveryBatchPreSale $entry): array with eligible, responsibility and reason.
- Exactly one item can reference a batch entry.

- [ ] **Step 1: Write failing tests.** Cover one header per batch, one item per entry, delivered/not-delivered, all five reasons, other requiring note, external eligibility, in-app rejection, and legacy evidence: linked pre-sale payment => pre-seller; unpaid/no-payment => delivery-agent; paid/no-link => review-required.

~~~
$this->assertFalse($eligibility->forEntry($ambiguousPaidEntry)['eligible']);
$this->assertSame('review_required', $eligibility->forEntry($ambiguousPaidEntry)['reason']);
~~~

- [ ] **Step 2: Run the test and confirm it fails.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 3: Implement migration, models and resolver.** Add unique batch-entry linkage and business/branch/sale indexes. Copy batch snapshots to new items. For legacy rows, use only payment evidence and mark unknown tracking/ambiguous responsibility review-required; never read present tenant settings.

- [ ] **Step 4: Run the focused test and confirm it passes.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 5: Commit the checkpoint.**

~~~
git add database/migrations app/Models app/Services/Routes/ExternalDeliveryEligibility.php tests/Feature/RouteExternalDeliveryReconciliationTest.php
git commit -m "add external delivery reconciliation records"
~~~

### Task 3: Add delivery collection and directional payment provenance

**Files:**
- Create: database/migrations/*_create_route_delivery_collections.php
- Create: database/migrations/*_add_route_delivery_collection_to_sale_payments.php
- Create: app/Models/RouteDeliveryCollection.php
- Modify: app/Models/Sale.php
- Modify: app/Models/SalePayment.php
- Modify: app/Models/RouteExternalDeliveryReconciliationItem.php
- Test: tests/Feature/RouteExternalDeliveryReconciliationTest.php

**Interfaces:**
- RouteDeliveryCollection::salePayment(): HasOne and SalePayment::routeDeliveryCollection(): BelongsTo.
- Collection sale_id and reconciliation item id are unique.
- SalePayment route_delivery_collection_id is nullable/unique so POS records remain valid.

- [ ] **Step 1: Write failing schema/model tests.** Assert collection uniqueness by sale and item, unique payment link, nullable route provenance for a POS payment and presence of business/branch indexes.

~~~
$this->expectException(QueryException::class);
RouteDeliveryCollection::factory()->create(['sale_id' => $sale->id]);
RouteDeliveryCollection::factory()->create(['sale_id' => $sale->id]);
~~~

- [ ] **Step 2: Run the test and confirm it fails.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 3: Implement additive persistence and relations.** Store actors, exact method/amount/reference/details/time, custody-policy snapshot, custody/posting state, confirmation actor/time, optional cash records and idempotency record. Do not add any new payment-state enum.

- [ ] **Step 4: Run the focused test and confirm it passes.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 5: Commit the checkpoint.**

~~~
git add database/migrations app/Models tests/Feature/RouteExternalDeliveryReconciliationTest.php
git commit -m "add route delivery collection provenance"
~~~

### Task 4: Capture one full delivery-agent collection

**Files:**
- Create: app/Services/Routes/RouteDeliveryCollectionService.php
- Modify: app/Models/RouteDeliveryCollection.php
- Test: tests/Feature/RouteExternalDeliveryReconciliationTest.php

**Interfaces:**
- captureFull(RouteExternalDeliveryReconciliationItem $item, array $data, User $actor): RouteDeliveryCollection.
- Requires full amount, real method, collected_by and collected_at; override requires override_reason.
- Produces one collection, one linked payment and a paid sale projection.

- [ ] **Step 1: Write failing financial tests.** Test delivery-agent collected creates exact-total payment; not-collected creates neither; not-delivered plus collected is accepted; partial amount, second collection and existing payment are rejected; no CxC rows are created.

~~~
$result = $service->captureFull($item, $fullCashPayload, $actor);
$this->assertDatabaseCount('sale_payments', 1);
$this->assertSame('paid', $sale->fresh()->payment_status);
$this->assertDatabaseCount('customer_account_movements', 0);
~~~

- [ ] **Step 2: Run the test and confirm it fails.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 3: Implement the locked transaction.** Lock item, sale, collection and payments. Require delivery-agent snapshot, unpaid sale, no payment and amount equal to sale total. Write collection then payment link and normal financial projection. Reject pre-seller collection fields.

- [ ] **Step 4: Run focused tests and POS/FEL regression.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

Run: php artisan test --env=testing --filter=CriticalPosFelFlowTest

- [ ] **Step 5: Commit the checkpoint.**

~~~
git add app/Services/Routes/RouteDeliveryCollectionService.php app/Models tests/Feature/RouteExternalDeliveryReconciliationTest.php
git commit -m "capture full delivery agent route collections"
~~~

### Task 5: Enforce current physical cash receipt

**Files:**
- Modify: app/Services/Routes/RouteDeliveryCollectionService.php
- Modify: app/Support/CashRegister.php only if a dedicated helper avoids duplicated movement code
- Test: tests/Feature/RouteExternalDeliveryReconciliationTest.php

**Interfaces:**
- held cash => held_by_collector, awaiting_physical_receipt, null session/movement.
- immediate cash plus receive_cash_in_current_session=true => posted_to_branch_cash, posted_to_current_session, one movement.
- immediate cash without confirmation => held/awaiting/null; no historic posting.

- [ ] **Step 1: Write failing custody tests.** Cover held cash, immediate confirmed cash in open current session, immediate unconfirmed cash, confirmation without open session, and card/transfer/check without movement.

~~~
$collection = $service->captureFull($item, [...$cashPayload, 'receive_cash_in_current_session' => false], $actor);
$this->assertSame('held_by_collector', $collection->custody_status);
$this->assertNull($collection->cash_register_session_id);
$this->assertDatabaseCount('cash_movements', 0);
~~~

- [ ] **Step 2: Run the test and confirm it fails.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 3: Implement the exact three cash paths.** Accept confirmation only for cash. Lock active current branch session before creating one sale_cash movement with reference_type route_delivery_collection. Store confirming actor/time. Reject posted custody without confirmation and current session.

- [ ] **Step 4: Run the focused test and confirm it passes.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 5: Commit the checkpoint.**

~~~
git add app/Services/Routes/RouteDeliveryCollectionService.php app/Support/CashRegister.php tests/Feature/RouteExternalDeliveryReconciliationTest.php
git commit -m "enforce route delivery cash receipt timing"
~~~

### Task 6: Reconcile one line idempotently

**Files:**
- Create: app/Services/Routes/RouteExternalDeliveryReconciliationService.php
- Modify: app/Services/Routes/RouteDeliveryCollectionService.php
- Test: tests/Feature/RouteExternalDeliveryReconciliationTest.php

**Interfaces:**
- reconcileItem(RouteDeliveryBatch $batch, RouteDeliveryBatchPreSale $entry, array $data, User $actor): IdempotencyResult.
- Operation idempotency type is route_external_delivery_reconcile_item.
- It returns item, optional collection and count-based progress.

- [ ] **Step 1: Write failing orchestration tests.** Cover delivered, every not-delivered reason, other note, pre-seller no duplicate money, delivery-agent collected/not-collected, double click/replay, distinct-key race and progress 17/20.

~~~
$first = $service->reconcileItem($batch, $entry, $payload, $actor);
$second = $service->reconcileItem($batch, $entry, $payload, $actor);
$this->assertTrue($second->replayed);
$this->assertDatabaseCount('route_external_delivery_reconciliation_items', 1);
$this->assertDatabaseCount('sale_payments', 1);
~~~

- [ ] **Step 2: Run the test and confirm it fails.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 3: Implement header/item orchestration.** Require external eligible context, tenant/branch ownership and permission before locks. Create header on demand, copy snapshots to item, persist no money for pre-seller or not-collected line, and delegate collected delivery-agent cases in the same transaction.

- [ ] **Step 4: Run the focused test and confirm it passes.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 5: Commit the checkpoint.**

~~~
git add app/Services/Routes/RouteExternalDeliveryReconciliationService.php app/Services/Routes/RouteDeliveryCollectionService.php tests/Feature/RouteExternalDeliveryReconciliationTest.php
git commit -m "reconcile external route deliveries idempotently"
~~~

### Task 7: Add permissions, HTTP contracts and per-row UI

**Files:**
- Create: app/Http/Controllers/RouteExternalDeliveryReconciliationController.php
- Modify: app/Http/Controllers/RouteDeliveryBatchController.php
- Modify: app/Support/Permissions.php
- Modify: existing permission seeder
- Modify: routes/web.php
- Modify: resources/js/Pages/Routes/DeliveryBatches/Show.tsx
- Test: tests/Feature/RouteExternalDeliveryReconciliationTest.php

**Interfaces:**
- POST /routes/delivery-batches/{batch}/entries/{entry}/external-reconciliation requires routes.external_delivery.reconcile.
- Detail payload has reconciliation_progress and per-line eligibility, snapshot responsibility and financial state.
- Override input requires explicit collector, time and reason.

- [ ] **Step 1: Write failing HTTP/Inertia tests.** Assert tenant isolation, branch isolation, missing permission, external-only access, legacy review block, progress payload and override requirements. Source-assert Conciliar entrega and the exact physical-cash confirmation copy.

~~~
$this->actingAs($otherTenantUser)->post($url, $payload)->assertForbidden();
$this->actingAs($authorizedUser)->get(route('routes.delivery-batches.show', $batch))
  ->assertInertia(fn ($page) => $page->where('batch.reconciliation_progress.reconciled', 17));
~~~

- [ ] **Step 2: Run the test and confirm it fails.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 3: Implement routes, validation, authorization and UI.** Use current-business and active-branch checks before model use. Render Cobrado/No cobrado only for unpaid delivery-agent rows; make pre-seller financial evidence read-only; submit/save one idempotent row at a time.

- [ ] **Step 4: Run focused test and build.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

Run: cmd /c npm run build

- [ ] **Step 5: Commit the checkpoint.**

~~~
git add app/Http/Controllers app/Support/Permissions.php routes/web.php resources/js/Pages/Routes/DeliveryBatches/Show.tsx tests/Feature/RouteExternalDeliveryReconciliationTest.php
git commit -m "add external delivery reconciliation interface"
~~~

### Task 8: Audit delivery-result corrections

**Files:**
- Create: database/migrations/*_create_route_external_delivery_reconciliation_item_revisions.php
- Create: app/Models/RouteExternalDeliveryReconciliationItemRevision.php
- Create: app/Services/Routes/RouteExternalDeliveryReconciliationCorrectionService.php
- Modify: app/Http/Controllers/RouteExternalDeliveryReconciliationController.php
- Modify: app/Support/Permissions.php
- Modify: routes/web.php
- Modify: resources/js/Pages/Routes/DeliveryBatches/Show.tsx
- Test: tests/Feature/RouteExternalDeliveryReconciliationTest.php

**Interfaces:**
- correctDeliveryResult(RouteExternalDeliveryReconciliationItem $item, array $data, User $actor): RouteExternalDeliveryReconciliationItem.
- POST /routes/external-delivery-reconciliation-items/{item}/correct requires routes.external_delivery.reconcile.correct.
- Every correction stores versioned previous/new delivery JSON and reason/actor/time.

- [ ] **Step 1: Write failing correction tests.** Assert required permission/reason, audited customer_absent to delivered correction, preserved old/new values and actor/time, and financial/destructive correction block with payment, cash, FEL, stock and reservation IDs unchanged.

~~~
$corrected = $service->correctDeliveryResult($item, [
  'delivery_status' => 'delivered',
  'correction_reason' => 'Entrega confirmada por cliente',
], $admin);
$this->assertDatabaseHas('route_external_delivery_reconciliation_item_revisions', [
  'route_external_delivery_reconciliation_item_id' => $item->id, 'version' => 1,
]);
$this->assertSame($paymentId, $sale->fresh()->payments()->sole()->id);
~~~

- [ ] **Step 2: Run the test and confirm it fails.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 3: Implement only delivery-field correction.** Lock item; validate new delivery status/reason/note and mandatory correction reason; append revision before updating current delivery fields. Reject collection/payment/custody/cash/FEL/stock/reservation input with the future-reversal-flow message.

- [ ] **Step 4: Run the focused test and confirm it passes.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 5: Commit the checkpoint.**

~~~
git add database/migrations app/Models app/Services/Routes app/Http/Controllers app/Support/Permissions.php routes/web.php resources/js/Pages/Routes/DeliveryBatches/Show.tsx tests/Feature/RouteExternalDeliveryReconciliationTest.php
git commit -m "audit external delivery reconciliation corrections"
~~~

### Task 9: Update the integrity auditor without weakening POS

**Files:**
- Modify: app/Support/SystemIntegrityAuditor.php
- Test: tests/Feature/SystemIntegrityAuditorTest.php
- Test: tests/Feature/RouteExternalDeliveryReconciliationTest.php

**Interfaces:**
- Held route pre-sale/delivery cash is valid without a movement.
- Posted route cash matches one collection-referenced movement by amount, business and branch.
- Ordinary POS cash still requires reference_type sale.

- [ ] **Step 1: Write failing auditor tests.** Cover valid held Phase 3A cash, valid held/posted Phase 3B cash, missing/duplicate/wrong route movement, and invalid normal POS cash without a sale movement.

~~~
$issues = app(SystemIntegrityAuditor::class)->auditBusiness($business->id);
$this->assertFalse($issues->contains(fn ($issue) => $issue->sale_id === $heldDeliverySale->id));
$this->assertTrue($issues->contains(fn ($issue) => $issue->sale_id === $brokenPosSale->id));
~~~

- [ ] **Step 2: Run the test and confirm it fails.**

Run: php artisan test --env=testing --filter=SystemIntegrityAuditorTest

- [ ] **Step 3: Implement provenance-aware validation.** Inspect route collection provenance before applying the old POS sale-reference requirement. Validate custody state, movement amount and business/branch; keep POS branches exactly strict.

- [ ] **Step 4: Run focused suites and confirm they pass.**

Run: php artisan test --env=testing --filter=SystemIntegrityAuditorTest

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

- [ ] **Step 5: Commit the checkpoint.**

~~~
git add app/Support/SystemIntegrityAuditor.php tests/Feature/SystemIntegrityAuditorTest.php tests/Feature/RouteExternalDeliveryReconciliationTest.php
git commit -m "audit route cash payment provenance"
~~~

### Task 10: Final invariant and regression checkpoint

**Files:**
- Modify only scope-local files from Tasks 1-9 if a verified regression requires it.
- Test: tests/Feature/RouteExternalDeliveryReconciliationTest.php
- Test: tests/Feature/RouteDeliveryBatchTest.php
- Test: tests/Feature/RoutePreSaleCollectionTest.php
- Test: tests/Feature/RoutePreSaleFelTest.php
- Test: tests/Feature/SystemIntegrityAuditorTest.php
- Test: tests/Feature/CriticalOperationIdempotencyTest.php
- Test: tests/Feature/CriticalPosFelFlowTest.php

- [ ] **Step 1: Add the final named matrix.** It must explicitly cover tenant/branch isolation, external tracking, snapshots, historic compatibility, progress, delivered, not-delivered, reasons, other note, pre-seller no duplicate payment, delivery-agent collected/not-collected, not-delivered+collected, held cash, immediate confirmed cash, historic-posting attempt, card/transfer/check, idempotency, double click, one payment/movement, no CxC, override, audit correction, destructive block, FEL/stock/reservation invariants, integrity auditor and POS regression.

- [ ] **Step 2: Run focused suites.**

Run: php artisan test --env=testing --filter=RouteExternalDeliveryReconciliationTest

Run: php artisan test --env=testing --filter=RouteDeliveryBatchTest

Run: php artisan test --env=testing --filter=RoutePreSaleCollectionTest

Run: php artisan test --env=testing --filter=RoutePreSaleFelTest

Run: php artisan test --env=testing --filter=SystemIntegrityAuditorTest

- [ ] **Step 3: Run critical and full verification.**

Run: php artisan test --env=testing --filter=CriticalOperationIdempotencyTest

Run: php artisan test --env=testing --filter=CriticalPosFelFlowTest

Run: php artisan test --env=testing

Run: cmd /c npm run build

Run: git diff --check

- [ ] **Step 4: Audit implementation scope.** Inspect every new query for business/branch scope, every movement for one collection reference/current-session confirmation, every correction for forbidden financial/logistic mutation and every payment path for single-source provenance.

- [ ] **Step 5: Commit only after explicit user approval.**

~~~
git status --short
git diff --check
git add database/migrations app/Models app/Services/Routes app/Http/Controllers app/Support/Permissions.php app/Support/SystemIntegrityAuditor.php routes/web.php resources/js/Pages/Routes/DeliveryBatches/Show.tsx tests/Feature/RouteExternalDeliveryReconciliationTest.php tests/Feature/RouteDeliveryBatchTest.php tests/Feature/SystemIntegrityAuditorTest.php docs/superpowers/specs/2026-09-05-routes-phase-3b-external-delivery-reconciliation-design.md docs/superpowers/plans/2026-09-05-routes-phase-3b-external-delivery-reconciliation.md
git commit -m "reconcile external route deliveries"
~~~
