# Fase 3E-A Cash Settlement Variances Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Confirmar liquidaciones de ruta con faltantes o sobrantes físicos auditables sin alterar el pago del cliente ni la integridad de caja.

**Architecture:** 3D mantiene un settlement y un movimiento consolidado por el efectivo realmente recibido. Una variance 1:1 firmada conserva la diferencia agregada; events documentan investigación y resolutions forman un ledger de entradas/salidas físicas posteriores. Un constraint trigger PostgreSQL diferido protege la relación cross-table al commit.

**Tech Stack:** Laravel, Eloquent, PostgreSQL checks/constraint triggers/partial indexes, Inertia React, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-09-routes-phase-3ea-cash-settlement-variances-design.md`

## Global Constraints

- No modificar sales, sale_payments, collections de cliente, FEL, stock, reservas, CxC ni pending collection cases 3E-C.
- El movimiento original de settlement es único y equivale a `received_amount`, no a `expected_amount`.
- Confirmación con variance exige `received_amount > 0`, caja abierta actual, razón, explicación, confirmación explícita y ambos permisos de confirmación.
- Toda collection de settlement confirmado pasa a `posted_to_branch_cash`; no existe custodia parcial por collection.
- Variance usa importe firmado; tipo y saldo se derivan. No hay tolerancia, cierre manual, tercero, write-off ni reclasificación.
- Shortage resolution crea movimiento positivo; overage resolution crea movimiento negativo. Ambas requieren caja actual y collector original como contraparte.
- No hacer commit, push, deploy ni cambios de producción sin aprobación posterior.

---

## File Structure

- Create: `app/Models/RouteCashSettlementVariance.php` — entidad 1:1 e invariantes de presentación.
- Create: `app/Models/RouteCashSettlementVarianceEvent.php` — event append-only.
- Create: `app/Models/RouteCashSettlementVarianceResolution.php` — ledger físico append-only.
- Create: `app/Services/Routes/RouteCashSettlementVarianceService.php` — events, assignment y resolutions con locks.
- Modify: `app/Services/Routes/RouteCashSettlementService.php` — confirmación exacta o con variance.
- Modify: `app/Http/Controllers/RouteCashSettlementController.php` — payload y autorización de confirm variance.
- Create: `app/Http/Controllers/RouteCashSettlementVarianceController.php` — bandeja, detalle y writes administrativos.
- Modify: `app/Support/CashRegister.php`, `app/Support/SystemIntegrityAuditor.php`, `app/Support/Permissions.php`, `routes/web.php`.
- Create: migrations `2026_09_09_000018` a `000021` para tablas, reemplazo del check y trigger diferido.
- Create: `resources/js/Pages/Routes/CashSettlements/Variances/Index.tsx` y `Show.tsx`; modify `CashSettlements/Show.tsx` e `Index.tsx`.
- Create: tests `RouteCashSettlementVariancePersistenceTest.php`, `RouteCashSettlementVarianceTest.php`, `RouteCashSettlementVarianceHttpTest.php`, `RouteCashSettlementVarianceIntegrityTest.php`.
- Modify: tests 3D existentes sólo para actualizar la expectativa de difference no cero bajo permiso y reason/explanation.

### Task 1: Regresión exacta 3D y contratos de variance

**Files:** Modify `tests/Feature/RouteCashSettlementConfirmationTest.php`, `tests/Feature/RouteCashSettlementIntegrityTest.php`; Create `tests/Feature/RouteCashSettlementVarianceTest.php`.

**Interfaces:** Produce fixtures reutilizables de settlement draft con collections cash `held_by_collector`, caja abierta y roles con permisos separados.

- [ ] **Step 1: Escribir tests que fijen la regresión exacta**

```php
public function test_exact_confirmation_stays_confirmed_without_variance(): void
{
    $settlement = $this->draftWithExpected(1250);
    $this->confirm($settlement, 1250);

    $this->assertSame('confirmed', $settlement->fresh()->status);
    $this->assertDatabaseCount('route_cash_settlement_variances', 0);
}
```

- [ ] **Step 2: Ejecutar el filtro antes de implementar**

Run: `php artisan test --env=testing --filter=RouteCashSettlementConfirmation`

Expected: los nuevos escenarios de variance aún no existen; los exactos pasan.

- [ ] **Step 3: Añadir fixtures que permitan reason, explicación y permiso de variance sin alterar el flujo exacto**

```php
private function grantVarianceConfirmation(User $user): void
{
    Permissions::assignDirectPermissions($user, [
        Permissions::ROUTES_CASH_SETTLEMENTS_CONFIRM,
        Permissions::ROUTES_CASH_SETTLEMENTS_CONFIRM_VARIANCE,
    ]);
}
```

- [ ] **Step 4: Ejecutar los tests focalizados**

Run: `php artisan test --env=testing --filter=RouteCashSettlement`

Expected: los tests existentes siguen verdes y los nuevos fallan sólo por schema/servicio aún ausentes.

### Task 2: Migraciones, modelos, CHECK reemplazado y trigger diferido

**Files:** Create migrations `2026_09_09_000018_create_route_cash_settlement_variances.php`, `000019_create_route_cash_settlement_variance_events.php`, `000020_create_route_cash_settlement_variance_resolutions.php`, `000021_allow_route_cash_settlement_variances.php`; Create three modelos; Create `tests/Feature/RouteCashSettlementVariancePersistenceTest.php`.

**Interfaces:** Produce `RouteCashSettlementVariance`, `RouteCashSettlementVarianceEvent`, `RouteCashSettlementVarianceResolution` con relaciones `settlement()`, `events()`, `resolutions()`, `cashMovement()` y `counterparty()`.

- [ ] **Step 1: Escribir tests PostgreSQL de schema e integridad diferida**

```php
public function test_confirmed_nonzero_difference_requires_one_matching_variance_at_commit(): void
{
    DB::transaction(function () use ($settlement) {
        $settlement->update(['status' => 'confirmed', 'difference_amount' => -20]);
    });
}
```

Expected: commit falla sin variance; pasa al crear una variance con mismo scope, collector y `-20`; falla para difference cero, branch distinta o segunda variance.

- [ ] **Step 2: Ejecutar persistence antes de migrar**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVariancePersistence`

Expected: FAIL por tablas, modelos o trigger inexistentes.

- [ ] **Step 3: Implementar tablas y restricciones exactamente como la spec**

```sql
CREATE CONSTRAINT TRIGGER route_cash_settlement_variance_integrity
AFTER INSERT OR UPDATE OR DELETE ON route_cash_settlements
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION validate_route_cash_settlement_variance_integrity();
```

Crear el segundo trigger equivalente sobre variances. Sustituir únicamente `route_cash_settlements_state_check`; conservar requirements de draft/cancelled y la fórmula de difference. El `down()` elimina triggers y función antes de restaurar la constraint segura.

- [ ] **Step 4: Verificar migración limpia y tests**

Run: `php artisan migrate:fresh --env=testing --force; php artisan test --env=testing --filter=RouteCashSettlementVariancePersistence`

Expected: PASS, incluidos `UNIQUE`, FKs, checks y commit diferido.

### Task 3: Confirmación con faltante

**Files:** Modify `app/Services/Routes/RouteCashSettlementService.php`; Modify `app/Models/RouteCashSettlement.php`; Modify `tests/Feature/RouteCashSettlementVarianceTest.php`.

**Interfaces:** Extend `confirm()` with normalized `variance_reason_code`, `variance_explanation` and `confirm_variance`; produce a confirmed settlement and `RouteCashSettlementVariance` when `difference < 0`.

- [ ] **Step 1: Escribir tests de shortage real**

```php
public function test_shortage_confirmation_creates_one_received_cash_movement_and_open_variance(): void
{
    $result = $this->confirmVariance($settlement, received: 1230, reason: 'missing_cash');

    $this->assertSame(-20.0, (float) $result->difference_amount);
    $this->assertDatabaseHas('cash_movements', ['id' => $result->cash_movement_id, 'amount' => 1230]);
    $this->assertDatabaseHas('route_cash_settlement_variances', ['route_cash_settlement_id' => $result->id, 'difference_amount' => -20, 'status' => 'open']);
}
```

- [ ] **Step 2: Ejecutar antes de implementar**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVarianceTest`

Expected: FAIL en confirmación no exacta.

- [ ] **Step 3: Implementar confirmación transaccional con orden de locks documentado**

```php
$locked = RouteCashSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
$items = RouteCashSettlementItem::query()->where('route_cash_settlement_id', $locked->id)->where('is_active', true)->lockForUpdate()->get();
[$pre, $delivery] = $this->lockAndValidateCollections($items, $locked);
$session = CashRegister::requireOpenSession($businessId, null, true, $branchId);
```

Para difference negativa, exigir `received_amount > 0`, ambos permisos en controller, reason code válido, explicación no vacía y confirmación explícita. Crear exactamente un movimiento `route_cash_settlement` por recibido y una variance firmada.

- [ ] **Step 4: Ejecutar el filtro**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVarianceTest`

Expected: PASS para shortage, cero recibido bloqueado, razón/explanación faltante bloqueada y exacto sin variance.

### Task 4: Confirmación con sobrante y custodia agregada

**Files:** Modify `RouteCashSettlementService.php`; Modify `tests/Feature/RouteCashSettlementVarianceTest.php`, `RouteCashSettlementIntegrityTest.php`.

**Interfaces:** Produce variance abierta positiva para overage; todos los items de un settlement confirmado quedan posted sin atribuir diferencia a una collection.

- [ ] **Step 1: Escribir test de overage**

```php
public function test_overage_confirmation_posts_actual_cash_and_preserves_customer_payment_facts(): void
{
    $settlement = $this->confirmVariance($this->draftWithExpected(1250), received: 1270, reason: 'unidentified_extra_cash');

    $this->assertSame(1270.0, (float) $settlement->cashMovement->amount);
    $this->assertSame(20.0, (float) $settlement->variance->difference_amount);
    $this->assertAllSourceCollectionsPosted($settlement);
    $this->assertCustomerPaymentFactsUnchanged();
}
```

- [ ] **Step 2: Ejecutar antes de completar la rama positiva**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVarianceTest`

Expected: FAIL para overage o custodia hasta implementar la rama.

- [ ] **Step 3: Implementar reason codes por signo y transición de custodia**

```php
if ($difference > 0 && ! in_array($reason, ['counting_difference', 'unidentified_extra_cash', 'other'], true)) {
    throw ValidationException::withMessages(['variance_reason_code' => 'El motivo no corresponde a un sobrante.']);
}
```

Aplicar el mismo `posted_to_branch_cash` de 3D a todas las collections activas. No crear payment, CxC, collection adicional ni movimiento de ajuste.

- [ ] **Step 4: Ejecutar regressions financieras focalizadas**

Run: `php artisan test --env=testing --filter=RouteCashSettlement; php artisan test --env=testing --filter=Sale`

Expected: PASS; sales y payments no cambian por shortage/overage.

### Task 5: Events, asignación y consulta de bandeja

**Files:** Create `app/Services/Routes/RouteCashSettlementVarianceService.php`; Modify variance modelos; Create/modify `tests/Feature/RouteCashSettlementVarianceTest.php`.

**Interfaces:** `addEvent(RouteCashSettlementVariance $variance, array $data, User $actor): IdempotencyResult`; `assign(RouteCashSettlementVariance $variance, ?int $assignedTo, User $actor, string $key): IdempotencyResult`; `remainingAmount(RouteCashSettlementVariance $variance): float`.

- [ ] **Step 1: Escribir tests append-only y scope**

```php
public function test_event_and_assignment_are_scoped_and_remaining_is_derived(): void
{
    $this->addEvent($variance, type: 'investigation', note: 'Conteo revisado');
    $this->assign($variance, $manager->id);

    $this->assertDatabaseCount('route_cash_settlement_variance_events', 1);
    $this->assertSame(abs((float) $variance->difference_amount), app(RouteCashSettlementVarianceService::class)->remainingAmount($variance));
}
```

- [ ] **Step 2: Ejecutar antes de implementar**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVarianceTest`

Expected: FAIL por servicio inexistente.

- [ ] **Step 3: Implementar servicios idempotentes y consultas sin N+1**

```php
return app(IdempotencyService::class)->run(
    $businessId, $branchId, $actor->id, 'route_cash_settlement_variance_event', $key, $payload,
    fn () => DB::transaction(fn () => $this->createScopedEvent($variance, $data, $actor)),
    'route_cash_settlement_variance_event',
);
```

Bloquear variance antes de write, validar actor/asignado activo y scope, y calcular remaining mediante `SUM(amount)` SQL. No modificar events existentes.

- [ ] **Step 4: Ejecutar filtros**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVarianceTest`

Expected: PASS para note/investigation/contact, assignment, idempotencia y aislamiento tenant/branch.

### Task 6: Resoluciones parciales de faltante

**Files:** Modify `RouteCashSettlementVarianceService.php`, `CashRegister.php`, `SystemIntegrityAuditor.php`; Modify `tests/Feature/RouteCashSettlementVarianceTest.php`.

**Interfaces:** `resolve(RouteCashSettlementVariance $variance, array $data, User $actor): IdempotencyResult` acepta `type=shortage_cash_received` y crea resolution/movement positivo.

- [ ] **Step 1: Escribir tests de Q40 + Q60**

```php
public function test_shortage_accepts_partial_physical_receipts_and_resolves_only_at_zero_remaining(): void
{
    $this->resolve($shortage100, 'shortage_cash_received', 40);
    $this->assertSame('open', $shortage100->fresh()->status);
    $this->assertSame(60.0, $this->remaining($shortage100));

    $this->resolve($shortage100, 'shortage_cash_received', 60);
    $this->assertSame('resolved', $shortage100->fresh()->status);
}
```

- [ ] **Step 2: Ejecutar antes de implementar**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVarianceTest`

Expected: FAIL por resolución inexistente.

- [ ] **Step 3: Implementar ledger y movimiento positivo**

```php
$movement = CashRegister::recordMovement(
    $session,
    'route_cash_variance_shortage_received',
    $amount,
    'route_cash_settlement_variance_resolution',
    $resolution->id,
    "Resolución de faltante de liquidación #{$variance->route_cash_settlement_id}",
    $actor->id,
);
```

Bloquear `variance -> resolutions -> session`; exigir counterparty igual al collector, caja actual y `amount <= remaining`. Guardar movimiento único y resolver sólo cuando remaining sea cero.

- [ ] **Step 4: Ejecutar filtros Cash y variance**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVariance; php artisan test --env=testing --filter=Cash`

Expected: PASS; no sale_payment, CxC ni collection se altera.

### Task 7: Resoluciones parciales de sobrante

**Files:** Modify `RouteCashSettlementVarianceService.php`, `SystemIntegrityAuditor.php`; Modify `tests/Feature/RouteCashSettlementVarianceTest.php`.

**Interfaces:** la misma `resolve()` acepta `type=overage_cash_returned` sólo para variance positiva y crea un movement negativo.

- [ ] **Step 1: Escribir tests de devolución parcial y tipo cruzado bloqueado**

```php
public function test_overage_returns_cash_to_original_collector_with_negative_movement(): void
{
    $resolution = $this->resolve($overage20, 'overage_cash_returned', 10);

    $this->assertSame(-10.0, (float) $resolution->cashMovement->amount);
    $this->assertSame('open', $overage20->fresh()->status);
    $this->expectException(ValidationException::class);
    $this->resolve($overage20, 'shortage_cash_received', 10);
}
```

- [ ] **Step 2: Ejecutar antes de implementar**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVarianceTest`

Expected: FAIL por tipo negativo aún no admitido.

- [ ] **Step 3: Implementar salida física conservadora**

```php
$movement = CashRegister::recordMovement(
    $session,
    'route_cash_variance_overage_returned',
    -$amount,
    'route_cash_settlement_variance_resolution',
    $resolution->id,
    "Devolución de sobrante de liquidación #{$variance->route_cash_settlement_id}",
    $actor->id,
);
```

Actualizar la whitelist del auditor para este tipo negativo. No permitir identificación con collection, customer payment, ajuste abstracto ni contraparte distinta al collector original.

- [ ] **Step 4: Ejecutar tests**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVariance; php artisan test --env=testing --filter=CriticalPosFelFlow`

Expected: PASS; POS mantiene su auditoría estricta.

### Task 8: Permisos, controllers y contratos Inertia

**Files:** Modify `Permissions.php`, `RouteCashSettlementController.php`, `routes/web.php`; Create `RouteCashSettlementVarianceController.php`; Create `tests/Feature/RouteCashSettlementVarianceHttpTest.php`.

**Interfaces:** endpoints `index`, `show`, `event`, `assignment`, `resolve`; confirm settlement recibe `variance_reason_code`, `variance_explanation`, `confirm_variance`.

- [ ] **Step 1: Escribir tests HTTP de permisos y aislamiento**

```php
public function test_nonzero_confirmation_requires_confirm_and_confirm_variance(): void
{
    $this->actingAs($exactConfirmer)->post(route('routes.cash-settlements.confirm', $settlement), $this->variancePayload())
        ->assertForbidden();
    $this->actingAs($varianceConfirmer)->post(route('routes.cash-settlements.confirm', $settlement), $this->variancePayload())
        ->assertRedirect();
}
```

- [ ] **Step 2: Ejecutar antes de implementar**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVarianceHttp`

Expected: FAIL por rutas/permisos/controlador inexistentes.

- [ ] **Step 3: Implementar autorización backend**

```php
$this->requireAllPermissions($request, [
    Permissions::ROUTES_CASH_SETTLEMENTS_CONFIRM,
    Permissions::ROUTES_CASH_SETTLEMENTS_CONFIRM_VARIANCE,
]);
```

Registrar permisos en catálogo, no en rol `delivery_agent`. Validar actor, branch, receiver, variance, assigned user y counterparty contra business/branch actuales en cada endpoint.

- [ ] **Step 4: Ejecutar tests HTTP**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVarianceHttp`

Expected: PASS para view/manage/resolve, 403 de delivery_agent y aislamiento tenant/branch.

### Task 9: UX administrativa de settlement y variances

**Files:** Modify `resources/js/Pages/Routes/CashSettlements/Show.tsx`, `Index.tsx`; Create `resources/js/Pages/Routes/CashSettlements/Variances/Index.tsx`, `Show.tsx`; Modify contracts del controller.

**Interfaces:** Props de settlement incluyen `can_confirm_variance`; variance detail incluye `difference_amount`, `remaining_amount`, `derived_type`, events, resolutions, assignment y permisos de acción.

- [ ] **Step 1: Escribir tests de contrato Inertia**

```php
$this->actingAs($viewer)->get(route('routes.cash-settlement-variances.index'))
    ->assertInertia(fn (Assert $page) => $page
        ->component('Routes/CashSettlements/Variances/Index')
        ->has('summary.open_shortages')
        ->has('variances.data.0.remaining_amount'));
```

- [ ] **Step 2: Ejecutar antes de implementar UI**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVarianceHttp`

Expected: FAIL por componente/props no existentes.

- [ ] **Step 3: Implementar flujos explícitos y sólo lectura histórica**

```tsx
{difference < 0 && <p>Se recibieron {money(Math.abs(difference))} menos que el efectivo esperado.</p>}
{difference > 0 && <p>Se recibieron {money(difference)} adicionales al efectivo asociado a esta liquidación.</p>}
```

Mostrar formulario de reason/explanation/confirmación sólo si hay ambos permisos. La cola debe derivar aging y remaining en backend, cargar relaciones con eager loading y no llamar la variance deuda de cliente.

- [ ] **Step 4: Verificar contratos y build**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVarianceHttp; cmd /c npm run build`

Expected: PASS.

### Task 10: Auditor, idempotencia, concurrencia y regresión completa

**Files:** Modify `SystemIntegrityAuditor.php`; Create `tests/Feature/RouteCashSettlementVarianceIntegrityTest.php`; Modify concurrency tests y cualquier test de regresión necesario.

**Interfaces:** auditor agrega issue types de variance; confirm/resolution mantienen resultado único bajo replay y competencia.

- [ ] **Step 1: Escribir fixtures corruptos y carreras controladas**

```php
public function test_two_resolution_attempts_cannot_over_resolve_the_same_variance(): void
{
    $this->resolve($variance, 'shortage_cash_received', 60, 'first-key');
    $this->expectException(ValidationException::class);
    $this->resolve($variance, 'shortage_cash_received', 60, 'second-key');
}
```

Cubrir confirmed nonzero sin variance, exacto con variance, mismatch de sign/scope/collector, original movement incorrecto, duplicate movement, resolution inválida, total superior, resolved/open inconsistent, exact 3D y no modificación de sale/payment/CxC.

- [ ] **Step 2: Ejecutar tests antes de completar auditor**

Run: `php artisan test --env=testing --filter=RouteCashSettlementVarianceIntegrity`

Expected: FAIL para checks no implementados.

- [ ] **Step 3: Implementar consultas set-based y reglas de movimiento**

```php
$remaining = abs((float) $variance->difference_amount) - (float) $resolutionTotals->get($variance->id, 0);
if ($variance->status === 'resolved' && round($remaining, 2) !== 0.0) {
    $issues[] = $this->issue($base, 'critical', 'route_cash_settlement_variance_resolved_with_balance', 'Variance resuelta conserva saldo.', 'Revisar el ledger físico sin alterar pagos de cliente.');
}
```

Mantener el reconocimiento de `amount_snapshot` de settlement como evidencia de pago del cliente, aunque el movimiento físico original sea menor o mayor.

- [ ] **Step 4: Ejecutar verificación secuencial final**

Run:

```text
php artisan test --env=testing --filter=RouteCashSettlementVariance
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
```

Expected: todas las pruebas, build y diff check correctos; no push ni despliegue.

## Plan Self-Review

- Cobertura de spec: Tasks 1-4 cubren settlement, CHECK, trigger y custodia; Tasks 5-7 cubren events, assignment y ledger físico; Tasks 8-9 cubren permisos/HTTP/UX; Task 10 cubre auditor, concurrencia e invariantes.
- Consistencia: el único servicio de resolución es `RouteCashSettlementVarianceService::resolve`; los tipos de movimiento son `route_cash_variance_shortage_received` y `route_cash_variance_overage_returned` en todo el plan.
- Alcance: no hay customer payments, reversiones, terceros, CxC, reclasificación, write-off ni cambios de POS.
