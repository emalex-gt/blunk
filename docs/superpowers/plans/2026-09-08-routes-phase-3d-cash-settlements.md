# Fase 3D Cash Settlements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrar liquidaciones físicas consolidadas de efectivo bajo custodia sin duplicar pagos ni movimientos.

**Architecture:** Un adaptador normaliza elegibilidad de las dos tablas de collections; settlements e items reservan fuentes con FKs reales; la confirmación atómica crea un único movimiento y actualiza custodia. El auditor sigue la cadena collection→item→settlement→movement.

**Tech Stack:** Laravel, PostgreSQL partial indexes/check constraints, Eloquent, Inertia React, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-08-routes-phase-3d-cash-settlements-design.md`

## Global Constraints

- Sólo cash + `held_by_collector`; no CxC, sale_payment, FEL, stock ni reservas.
- Confirmación sólo con diferencia cero y caja abierta actual de misma branch.
- Un movimiento `route_cash_settlement` consolidado por settlement confirmado.
- No copiar el movimiento consolidado a cada collection.
- No commit, push ni despliegue sin aprobación explícita.

---

### Task 1: Adapter de elegibilidad común

**Files:** Create `app/Services/Routes/RouteCashSettlementEligibility.php`; Test `tests/Feature/RouteCashSettlementEligibilityTest.php`.

- [ ] Escribir tests de cash held, no-cash, posted, ambos orígenes y aislamiento collector/branch.
- [ ] Ejecutar `php artisan test --env=testing --filter=RouteCashSettlementEligibility` y confirmar fallo.
- [ ] Implementar `forCollector(int $businessId, int $branchId, int $collectorId): Collection` con proyección explícita `origin`, `collection_id`, `amount`, `collected_at`.
- [ ] Reejecutar el filtro y confirmar éxito.

### Task 2: Migraciones, modelos y constraints

**Files:** Create dos migraciones 3D, `RouteCashSettlement`, `RouteCashSettlementItem`; Test `RouteCashSettlementPersistenceTest.php`.

- [ ] Escribir tests de checks, origen exclusivo, índices únicos parciales y FK de movimiento único.
- [ ] Ejecutar filtro persistence y confirmar fallo.
- [ ] Crear tablas, checks PostgreSQL e índices parciales definidos por spec; implementar relaciones tipadas.
- [ ] Ejecutar `php artisan migrate:fresh --env=testing --force` y filtro persistence.

### Task 3: Draft, selección y reserva

**Files:** Create `RouteCashSettlementDraftService.php`; Test `RouteCashSettlementDraftTest.php`.

- [ ] Probar subset, mezcla de orígenes, mismo collector/branch, importe snapshot y reserva duplicada.
- [ ] Implementar create/add con transacción, locks y recalculo de expected/difference.
- [ ] Verificar idempotencia create/add y filtro Draft.

### Task 4: Edición, quitar y cancelar

**Files:** Modify DraftService/models; Test `RouteCashSettlementCancellationTest.php`.

- [ ] Probar quitar item, actualización de expected, cancelar con motivo y liberación de reserva.
- [ ] Implementar sólo para draft; marcar `is_active=false`, no borrar historial ni tocar custodia.
- [ ] Probar que confirmed/cancelled son inmutables.

### Task 5: Confirmación, caja, idempotencia y concurrencia

**Files:** Create `RouteCashSettlementService.php`; Test `RouteCashSettlementConfirmationTest.php`.

- [ ] Probar caja cerrada, branch incorrecta, diferencia no cero, retry/doble click y competencia por una collection.
- [ ] Implementar `confirm(settlement, data, actor, key)` con locks de settlement/items/collections/sesión y revalidación completa.
- [ ] Ejecutar filtro Confirmation y comprobar un único confirmed.

### Task 6: Custodia y movimiento consolidado

**Files:** Modify confirmation service; Test `RouteCashSettlementCashTest.php`.

- [ ] Probar un movimiento exacto, referencia correcta, transición held→posted y que sale/payment/FEL/stock/reservas/CxC no cambian.
- [ ] Crear `CashRegister::recordMovement` con tipo/referencia de settlement; guardar vínculo sólo en settlement.
- [ ] Ejecutar filtro Cash y RouteDelivery/RoutePreSale relacionados.

### Task 7: Permisos y controllers

**Files:** Modify `Permissions.php`, `routes/web.php`; Create controller; Test HTTP de autorización.

- [ ] Probar view/create/confirm/review, tenant/branch/actor/received_by y delivery_agent denegado.
- [ ] Añadir permisos, endpoints idempotentes y respuestas Inertia sin confiar en UI.
- [ ] Ejecutar filtro RouteCashSettlementHttp.

### Task 8: UX administrativa

**Files:** Create páginas Inertia `Routes/CashSettlements/*`; Test feature de contratos Inertia.

- [ ] Probar contrato de lista, detalle, draft, diferencia y confirmación disponible sólo a cero.
- [ ] Implementar flujo administrativo, filtros mínimos e historial read-only.
- [ ] Ejecutar build y pruebas HTTP/UI.

### Task 9: Auditor de integridad

**Files:** Modify `SystemIntegrityAuditor.php`; Test `RouteCashSettlementIntegrityTest.php`.

- [ ] Crear fixtures corruptos para cada check de la spec, incluyendo cadena settlement/movement y no debilitamiento POS.
- [ ] Implementar consultas set-based y reconocimiento de efectivo route liquidado.
- [ ] Ejecutar filtros Integrity, Cash y Sale.

### Task 10: Regresión financiera completa

**Files:** Modify tests sólo donde sea necesario.

- [ ] Ejecutar secuencialmente RouteCashSettlement, RoutePreSaleCollection, RouteDeliveryCollection, RouteExternal, Cash, Sale, Credit, FEL y CriticalPosFelFlow.
- [ ] Ejecutar `php artisan test --env=testing`, `cmd /c npm run build`, `git diff --check` y `git status --short`.
- [ ] Solicitar revisión antes de cualquier aprobación de commit.
