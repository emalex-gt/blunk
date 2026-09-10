# Fase 3E-B1 Delivery Collection Reversals Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Revertir de forma administrativa y auditable un cobro post-sale de ruta registrado por error, sin borrar historia ni afectar entrega, FEL, stock, reservas, CxC o liquidaciones confirmadas.

**Architecture:** La collection y su payment conservan evidencia y proyectan vigencia con `captured|reversed`. Un ledger `route_delivery_collection_reversals` es la fuente de verdad de la reversa y controla la reapertura excepcional del case 3E-C. El efectivo posted se netea sólo dentro de su sesión original aún abierta; para sesión cerrada la corrección es ledger sin movement.

**Tech Stack:** Laravel, Eloquent, PostgreSQL partial indexes/checks/constraint triggers, Inertia React, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-09-routes-phase-3eb1-delivery-collection-reversals-design.md`

## Global Constraints

- Alcance exclusivo: `route_delivery_collections` de `delivery_agent`, external, in-app y late collection 3E-C.
- No delete, refund, partial/split payment, pre-seller, delivery reversal, FEL, stock, reservas, CxC, settlement/variance/resolution reversal ni producción.
- Financial/current readers usan exclusivamente `status='captured'`; history muestra ambos.
- Reversa completa exige reason, explanation, confirmation, permiso y llave de idempotencia.
- Settlement draft activo bloquea; cualquier settlement confirmed bloquea y corresponde a B2.
- CASH-C está prohibido: nunca escribir ni modificar cash movements de sesión cerrada.
- No hacer commit, push, deploy ni cambios de producción sin aprobación posterior.

---

## File Structure

- Create: cinco migrations `000022` a `000026` descritas en spec.
- Create: `app/Models/RouteDeliveryCollectionReversal.php` — ledger y relaciones auditables.
- Create: `app/Services/Routes/RouteDeliveryCollectionReversalService.php` — único write path B1.
- Create: `app/Http/Controllers/RouteDeliveryCollectionReversalController.php` — contrato HTTP administrativo.
- Modify: `RouteDeliveryCollection`, `SalePayment`, `Sale`, pending-case services/eligibility, collection service, settlement services/eligibility, permissions, routes, reports, auditor y payloads administrativos.
- Create: `resources/js/Pages/Routes/PendingCollections/Reversal.tsx` o integrar el modal en el detalle existente, siguiendo el patrón Inertia ya usado para pending collections.
- Create: tests de persistence, service, HTTP, integrity y concurrency de reversal; modificar sólo fixtures/regresiones afectadas.

### Task 1: Baseline de readers vigentes y regresión capturada

**Files:** Modify `tests/Feature/RoutePendingCollectionEligibilityTest.php`, `tests/Feature/RouteCashSettlementEligibilityTest.php`, `tests/Feature/RouteExternalDeliveryReconciliationTest.php`, `tests/Feature/RouteDeliveryRunTest.php`; Create `tests/Feature/RouteDeliveryCollectionReversalReaderTest.php`.

**Interfaces:** Define `RouteDeliveryCollection::captured()`, `SalePayment::captured()`, `Sale::capturedPayments()` y `Sale::activeRouteDeliveryCollection()`; conserva relaciones históricas explícitas.

- [ ] **Step 1: Write failing reader tests**

```php
public function test_financial_readers_ignore_reversed_route_payment_but_history_keeps_it(): void
{
    $this->markCollectionAndPaymentReversed($collection);

    $this->assertFalse($sale->fresh()->capturedPayments()->exists());
    $this->assertNull($sale->fresh()->activeRouteDeliveryCollection);
    $this->assertCount(1, $sale->fresh()->routeDeliveryCollections);
}
```

- [ ] **Step 2: Run the focused tests before schema work**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversalReader`

Expected: FAIL because status/scopes and plural historical relation do not yet exist.

- [ ] **Step 3: Inventory every reader before editing**

Use `rg` to list all uses of `sale_payments`, `route_delivery_collections`, `payments()`, `routeDeliveryCollection`, `whereHas`, `doesntHave`, `sum`, `count`, `exists` and `first`. Mark each as `current` or `historical`; do not do a blind replacement.

- [ ] **Step 4: Keep existing regressions green where schema is not yet required**

Run: `php artisan test --env=testing --filter=RoutePendingCollection; php artisan test --env=testing --filter=RouteCashSettlement`

Expected: existing routes behavior remains green before reader migration.

### Task 2: Schema, status, partial indexes, ledger and deferred trigger

**Files:** Create migrations `2026_09_09_000022_add_route_delivery_collection_reversal_statuses.php`, `000023_add_sale_payment_reversal_status.php`, `000024_create_route_delivery_collection_reversals.php`, `000025_extend_route_pending_collection_event_types_for_reversal.php`, `000026_add_route_delivery_collection_reversal_integrity_trigger.php`; Create models; Create `tests/Feature/RouteDeliveryCollectionReversalPersistenceTest.php`.

**Interfaces:** Produce `RouteDeliveryCollectionReversal` with `collection()`, `salePayment()`, `pendingCase()`, `previousCaseResolvedBy()`, `reversedBy()` and `compensatingCashMovement()`.

- [ ] **Step 1: Write failing PostgreSQL persistence tests**

```php
public function test_reversed_collection_allows_one_new_captured_collection_for_same_sale(): void
{
    $this->reverseRows($firstCollection);
    RouteDeliveryCollection::factory()->create($this->capturedPayloadForSameSale());

    $this->expectException(QueryException::class);
    RouteDeliveryCollection::factory()->create($this->capturedPayloadForSameSale());
}
```

Cover partial uniques for sale, external item and stop; one reversal per collection/payment; local correction-type checks; and deferred trigger failures for status/reversal/payment/scope mismatch and active settlement item. Add migration rollback tests: an empty B1 schema can roll back in reverse order, while each `down()` rejects the evidence it cannot represent or safely remove.

- [ ] **Step 2: Run before migration**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversalPersistence`

Expected: FAIL for absent status, table, indexes and trigger.

- [ ] **Step 3: Implement migrations in dependency order**

Backfill existing collection/payment rows to `captured`; drop the two historical collection uniques efectivos (sale y external) only after adding status, and create the three partial uniques (sale, external y stop); create reversal ledger and event-type check; finally create `validate_route_delivery_collection_reversal_integrity()` with deferred triggers. The historical chained declaration for stop did not materialize a PostgreSQL unique, so no nonexistent object is dropped or restored. Implement guarded `down()` methods without deleting data: 000026 aborts if reversals exist before removing trigger/function; 000025 aborts if `collection_reversed` events exist; 000024 aborts if the ledger has rows; 000023 aborts for reversed payments or linked reversals; 000022 aborts for reversed collections or duplicate historical sale/external histories incompatible with the old global uniques. Each abort reports the blocking evidence clearly.

- [ ] **Step 4: Verify fresh PostgreSQL installation**

Run: `php artisan migrate:fresh --env=testing --force; php artisan test --env=testing --filter=RouteDeliveryCollectionReversalPersistence`

Expected: PASS for FKs, checks, indexes, deferred commit behavior, clean reverse rollback and defensive rollback guards.

### Task 3: Current-reader migration and settlement/pending eligibility

**Files:** Modify `RouteDeliveryCollection.php`, `SalePayment.php`, `Sale.php`, `RouteDeliveryCollectionService.php`, `RoutePendingCollectionEligibility.php`, `RoutePendingCollectionCaseService.php`, `RoutePendingCollectionService.php`, `RouteCashSettlementEligibility.php`, `RouteCashSettlementDraftService.php`, `RouteCashSettlementService.php`; Modify reader tests.

**Interfaces:** All current predicates use `captured`; historical relations remain available for audit/detail screens.

- [ ] **Step 1: Write regression tests for an historical reversed record**

```php
public function test_reversed_held_cash_is_not_settlement_eligible_and_sale_is_pending_eligible(): void
{
    $this->reverseRows($heldCashCollection);

    $this->assertCount(0, $this->settlementEligibility($collector));
    $this->assertTrue($this->pendingEligibility()->pluck('sale_id')->contains($sale->id));
}
```

- [ ] **Step 2: Run tests before reader changes**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversalReader`

Expected: FAIL because historical records still satisfy old `exists()` and relationship predicates.

- [ ] **Step 3: Implement explicit captured scopes and relations**

Do not filter the original generic history relation invisibly. Add plural historical relation, named current relation and update each financial caller to choose intentionally. In capture service, lock/query captured payments and captured collections only; a reversed historical row must not block the next legitimate full collection.

- [ ] **Step 4: Run routes and settlement regressions**

Run: `php artisan test --env=testing --filter=RoutePendingCollection; php artisan test --env=testing --filter=RouteCashSettlement; php artisan test --env=testing --filter=RouteExternal; php artisan test --env=testing --filter=RouteDeliveryRun`

Expected: PASS, including late collection and current collection flows.

### Task 4: Eligibility, authorization and non-cash/held reversal

**Files:** Create `RouteDeliveryCollectionReversalService.php`; Modify `Permissions.php`; Create `tests/Feature/RouteDeliveryCollectionReversalTest.php`.

**Interfaces:** `reverse(RouteDeliveryCollection $collection, array $data, User $actor, string $key): IdempotencyResult`; permission `Permissions::ROUTES_DELIVERY_COLLECTIONS_REVERSE`.

- [ ] **Step 1: Write failing service tests**

```php
public function test_transfer_and_held_cash_reverse_financial_fact_without_cash_movement(): void
{
    $result = $this->reverse($collection, reason: 'payment_recorded_by_mistake');

    $this->assertSame('reversed', $collection->fresh()->status);
    $this->assertSame('reversed', $collection->salePayment->fresh()->status);
    $this->assertSame('unpaid', $collection->sale->fresh()->payment_status);
    $this->assertDatabaseCount('cash_movements', 0);
}
```

Cover external, in-app and late collection source; reason/explanation/confirmation; pre-seller exclusion; full amount; invalid source; non-cash and held cash `cash_correction_type=none`.

- [ ] **Step 2: Run before implementation**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversalTest`

Expected: FAIL because reversal service and permission do not exist.

- [ ] **Step 3: Implement service with ordered locks**

Apply `physical origin -> sale -> payments -> case -> settlements -> items -> collection`. Lock and revalidate all eligibility conditions. Wrap in `IdempotencyService::run(..., 'route_delivery_collection_reverse', ...)`; create reversal, transition both rows and project sale unpaid in one transaction. Do not create cash movement for non-cash or held custody.

- [ ] **Step 4: Run focused tests**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversalTest`

Expected: PASS for all non-cash and held branches.

### Task 5: Cash posted in original session still open

**Files:** Modify reversal service, `CashRegisterController.php`, `CashRegister.php`, `SystemIntegrityAuditor.php`; Modify service tests.

**Interfaces:** correction type `current_open_session_adjustment`; movement type `route_delivery_collection_reversal_current_session`.

- [ ] **Step 1: Write failing open-session tests**

```php
public function test_posted_cash_in_its_still_open_original_session_creates_one_negative_adjustment(): void
{
    $reversal = $this->reverse($postedCollection, confirmCashAdjustment: true);

    $this->assertSame('current_open_session_adjustment', $reversal->cash_correction_type);
    $this->assertSame(-500.0, (float) $reversal->compensatingCashMovement->amount);
    $this->assertSame($postedCollection->cash_register_session_id, $reversal->compensatingCashMovement->cash_register_session_id);
}
```

Also assert original plus compensating movements net to zero in the same open session, and that another session or missing confirmation is rejected.

- [ ] **Step 2: Run before implementation**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversalTest`

Expected: FAIL for current-session correction.

- [ ] **Step 3: Implement only same-session adjustment**

Require original movement and its session locked/open; require `CashRegister::currentOpenSession()` to be that exact session. Create one negative movement with reversal reference, update session expected through `CashRegister::recordMovement`, and expose an explicit label in cash-register UI. Update auditor negative-type allowlist and validate sign/reference/session.

- [ ] **Step 4: Verify cash regression**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversal; php artisan test --env=testing --filter=Cash`

Expected: PASS; no refund or physical-withdrawal semantics.

### Task 6: Historical ledger for original session closed

**Files:** Modify reversal service, detail payloads and auditor; Modify tests.

**Interfaces:** correction type `historical_closed_session_ledger`, null compensating movement.

- [ ] **Step 1: Write failing closed-session tests**

```php
public function test_posted_cash_from_closed_original_session_reverses_without_mutating_any_cash_session(): void
{
    $before = $this->cashSessionSnapshot($closedSession);
    $reversal = $this->reverse($postedCollection);

    $this->assertSame('historical_closed_session_ledger', $reversal->cash_correction_type);
    $this->assertNull($reversal->compensating_cash_movement_id);
    $this->assertSame($before, $this->cashSessionSnapshot($closedSession));
}
```

Assert no movement is added to a later open session and direct writes against closed sessions remain rejected by B1.

- [ ] **Step 2: Run before implementation**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversalTest`

Expected: FAIL for missing closed-session ledger branch.

- [ ] **Step 3: Implement ledger-only branch**

Inspect original movement/session under lock. If closed, create reversal with no compensating movement and never call `CashRegister::recordMovement`. Provide historical UI context linking collection, reversal and original closed session.

- [ ] **Step 4: Run focused tests and auditor test**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversal; php artisan test --env=testing --filter=SystemIntegrityAudit`

Expected: PASS; a closed-session movement is never inserted or changed.

### Task 7: Case traceability, exceptional reopen and new collection

**Files:** Modify `RoutePendingCollectionCaseService.php`, `RoutePendingCollectionEligibility.php`, reversal service/models; Modify pending tests; Create `tests/Feature/RouteDeliveryCollectionReversalCaseTest.php`.

**Interfaces:** B1 alone may transition a matching case `resolved -> open`; it writes `collection_reversed` event internally.

- [ ] **Step 1: Write failing traceability tests**

```php
public function test_reversal_reopens_only_case_resolved_by_that_collection_and_preserves_snapshot(): void
{
    $reversal = $this->reverse($resolutionCollection);

    $this->assertSame($case->id, $reversal->route_pending_collection_case_id);
    $this->assertSame($resolvedBy->id, $reversal->previous_case_resolved_by);
    $this->assertNotNull($reversal->previous_case_resolved_at);
    $this->assertSame('open', $case->fresh()->status);
    $this->assertDatabaseCount('route_pending_collection_events', 1);
}
```

Cover derived history with no case, non-delivered source, one internal event only, and subsequent collection #11/payment #11 captured resolving the same case again. Add the six mandatory case pre-state/post-state scenarios: (1) a case correctly resolved by collection #10 permits reversal; (2) a case resolved by #99 blocks before any write; (3) an already `open` case is never treated as a resolved-case reopen; (4) null `resolved_by` in otherwise corrupt resolved state blocks and is reported; (5) null `resolved_at` does the same; (6) the successful path stores both previous snapshots, clears the case projection, leaves it `open`, and writes exactly one internal event.

- [ ] **Step 2: Run before implementation**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversalCase`

Expected: FAIL for transition/event/snapshot behavior.

- [ ] **Step 3: Implement controlled reopen**

Under the service transaction and ordered locks, validate the entire **pre-state before any mutation**: case `resolved`, matching `resolution_route_delivery_collection_id`, non-null `resolved_by/resolved_at`, delivered source, delivery-agent responsibility, captured payment/collection and paid sale. Copy actor/time into reversal, append internal event with null event idempotency FK, then clear projection and set open. Do not expose an independent reopen controller/service method. The deferred trigger must validate only resulting state: case `open`, resolution projection null, previous snapshots present, collection/payment reversed, sale unpaid and no other captured collection; it must not infer the former resolved state.

- [ ] **Step 4: Run pending and collection tests**

Run: `php artisan test --env=testing --filter=RoutePendingCollection; php artisan test --env=testing --filter=RouteDeliveryCollectionReversalCase`

Expected: PASS without duplicate derived/persisted queue rows.

### Task 8: Settlement blocks, controller, permission and administrative UX

**Files:** Create controller; Modify `routes/web.php`, `Permissions.php`, pending collection controller/detail contracts, relevant Inertia page/modal; Create `tests/Feature/RouteDeliveryCollectionReversalHttpTest.php`.

**Interfaces:** `POST /routes/delivery-collections/{collection}/reverse`, protected by `routes.delivery_collections.reverse`.

- [ ] **Step 1: Write failing HTTP and settlement-block tests**

```php
public function test_confirmed_settlement_blocks_reversal_without_changing_settlement_or_variance(): void
{
    $this->actingAs($authorized)->post($this->reverseRoute($collection), $this->payload())
        ->assertSessionHasErrors('collection');

    $this->assertSame('confirmed', $settlement->fresh()->status);
    $this->assertSame('captured', $collection->fresh()->status);
}
```

Cover draft active item, confirmed exact, confirmed variance, permission denial, tenant/branch isolation, no delivery_agent access, idempotency replay and UI contract flags.

- [ ] **Step 2: Run before implementation**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversalHttp`

Expected: FAIL for route/controller/permission absent.

- [ ] **Step 3: Implement HTTP and UX**

Validate reason enum, explanation, confirmation, idempotency key and current-session confirmation. Render original/historical collection status and reversal detail. Never expose reverse control for known blocks; backend still performs all checks.

- [ ] **Step 4: Verify contracts and build**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversalHttp; cmd /c npm run build`

Expected: PASS.

### Task 9: Reporting, integrity auditor and historical views

**Files:** Modify `ReportController.php`, `SystemIntegrityAuditor.php`, cash-register labels/detail payloads; Create `tests/Feature/RouteDeliveryCollectionReversalIntegrityTest.php`, `tests/Feature/RouteDeliveryCollectionReversalReportingTest.php`.

**Interfaces:** reports sum captured payments; history retains reversed payment/collection and reversal metadata; auditor issues B1-specific codes.

- [ ] **Step 1: Write failing reporting and corruption tests**

```php
public function test_payment_totals_exclude_reversed_payment_while_history_displays_reversal(): void
{
    $this->reverse($collection);

    $this->get(route('reports.sales'))->assertDontSee('500.00');
    $this->assertAuditHas('reversed_collection_without_reversal');
}
```

Create corrupt fixtures for every trigger/auditor concern: wrong status, mismatch payment, duplicate reversal, draft/confirmed settlement, invalid correction type, wrong movement sign/reference/session and artificial CxC. For case reopening explicitly cover: reversal case not `open`; case resolution fields still present; previous actor/time missing; collection/payment not reversed; sale not unpaid; a second captured collection; missing/duplicated/wrong-case/wrong-scope/wrong-actor internal event; and a reopened case with non-delivered source or non-delivery-agent responsibility. The auditor verifies persisted post-state/evidence and never attempts to reconstruct the old case state.

- [ ] **Step 2: Run before implementation**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversalIntegrity; php artisan test --env=testing --filter=RouteDeliveryCollectionReversalReporting`

Expected: FAIL for missing captured filters and integrity checks.

- [ ] **Step 3: Implement set-based audit/read updates**

Filter current payment aggregates by captured status. Keep historical screen queries unfiltered and include reversal relation. Add auditor checks without weakening POS cash, credit, pre-seller anomaly, FEL, stock or reservation checks.

- [ ] **Step 4: Run focused regression groups**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversal; php artisan test --env=testing --filter=Cash; php artisan test --env=testing --filter=Sale; php artisan test --env=testing --filter=Credit`

Expected: PASS.

### Task 10: Idempotencia, competidores y verificación final

**Files:** Create `tests/Feature/RouteDeliveryCollectionReversalConcurrencyTest.php`; Modify only tests necessary for true reader contracts.

**Interfaces:** no more than one reversal, one internal event and one compensating movement; a later valid collection may be captured once.

- [ ] **Step 1: Write controlled competitor tests**

```php
public function test_second_independent_reversal_cannot_duplicate_reversal_or_cash_adjustment(): void
{
    $this->reverse($collection, key: 'first-reversal-key');
    $this->expectException(ValidationException::class);
    $this->reverse($collection, key: 'second-reversal-key');

    $this->assertDatabaseCount('route_delivery_collection_reversals', 1);
}
```

Cover same-key replay, reversal versus capture, reversal versus draft reservation, reversal versus settlement confirmation, reversal versus late-collection retry, case reopen and later real collection. Include the clean rollback regression and the five evidence guards from Task 2 when reasonable in the PostgreSQL test harness.

- [ ] **Step 2: Run before final hardening**

Run: `php artisan test --env=testing --filter=RouteDeliveryCollectionReversalConcurrency`

Expected: FAIL for any missing revalidation, partial index or trigger coverage.

- [ ] **Step 3: Harden only real failures**

Use locks, revalidation, partial indexes and deferred trigger. Do not claim simultaneous two-worker testing if the test environment cannot safely supply independent workers; document the controlled second competitor and DB protection instead.

- [ ] **Step 4: Run final verification sequentially**

Run:

```text
php artisan migrate:fresh --env=testing --force
php artisan test --env=testing --filter=RouteDeliveryCollectionReversal
php artisan test --env=testing --filter=RouteDeliveryCollectionReversalPersistence
php artisan test --env=testing --filter=RoutePendingCollection
php artisan test --env=testing --filter=RouteCashSettlement
php artisan test --env=testing --filter=RouteExternal
php artisan test --env=testing --filter=RouteDelivery
php artisan test --env=testing --filter=Cash
php artisan test --env=testing --filter=Sale
php artisan test --env=testing --filter=Credit
php artisan test --env=testing --filter=FEL
php artisan test --env=testing --filter=CriticalPosFelFlow
php artisan test --env=testing
cmd /c npm run build
git diff --check
git status --short
```

Expected: all tests/build/checks pass; no commit, push, deployment or production change.

## Plan Self-Review

- Coverage: Tasks 1–3 cover reader contracts/schema; Tasks 4–7 cover all reversal and case/cash branches; Task 8 covers HTTP/UX; Task 9 covers reports/auditor; Task 10 covers idempotency, competitors and full regression.
- Consistency: every write path uses `RouteDeliveryCollectionReversalService::reverse`, `captured|reversed`, the three approved correction types and the same movement/reference names.
- Scope: no pre-seller, refund, CxC, partial payment, delivery/FEL/stock reversal or settlement/variance mutation is planned.
