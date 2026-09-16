# Fase 3G — Política de cobros y UX operativa de rutas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Separar responsibility, workflow y métodos de cobro por sucursal, preservar los hechos financieros existentes y entregar feedback visible de Operación Global.

**Architecture:** RouteGlobalOperationsService sigue orquestando jornadas y los servicios child siguen siendo autoridad. RouteBranchCollectionSetting será una política uno-a-uno por Branch sin business_id persistido. Collections, SalePayment, CashMovement, sesión y delivery siguen siendo los únicos hechos financieros.

**Tech Stack:** Laravel, PostgreSQL, Inertia React/TypeScript, Tailwind, DomPDF y PHPUnit.

**Spec:** docs/superpowers/specs/2026-09-15-routes-phase-3g-collection-policy-design.md

## Global Constraints

- Trabajar en main sólo con autorización expresa.
- No aplicar, pop ni drop del stash B2a 0db76006f5209ff076ec876f4c2bb37ebe43f6d0.
- No push, deploy, producción ni commits sin autorización expresa.
- route_collection_responsibility y collection_workflow_mode no son equivalentes.
- agreed_payment_method es previsto; collection y SalePayment.method son pago real.
- Los únicos métodos de Rutas son cash, card, transfer y check; sólo cash crea CashMovement.
- Una Branch sin política conserva flujo legado exacto.
- 3H sigue diferida: manifest_reconciliation no se persiste ni acepta por backend.
- No crear CxC artificial, ledger paralelo, pago duplicado ni Sale unpaid sin camino de cobro probado.
- Ejecutar test runners secuencialmente; nunca dos suites simultáneas contra blunk_test.

---

### Task 1: 3G-A — Resultados y feedback de Operación Global

**Files:**
- Modify: app/Http/Middleware/HandleInertiaRequests.php
- Modify: app/Services/Routes/RouteGlobalOperationsService.php
- Modify: resources/js/Pages/Routes/GlobalOperations/Index.tsx
- Test: tests/Feature/RouteGlobalOperationsTest.php

**Interfaces:**
- Consumes: session flash global_preparation_result y global_sales_result del controller existente.
- Produces: props Inertia flash, resultados con batch_id exacto, mensaje administrativo hijo y protección de submit.

- [ ] **Step 1: escribir tests RED de flash Inertia y feedback**

    Probar que flash.global_preparation_result.processed.0.batch_id, total_pre_sales y razones blocked llegan a Routes/GlobalOperations/Index; probar global_sales_result y ausencia de panel sin flash.

- [ ] **Step 2: ejecutar el test RED**

    Run: php artisan test --env=testing --filter=RouteGlobal

    Expected: fallan props globales hasta compartir las claves en middleware.

- [ ] **Step 3: implementar el cambio mínimo**

    Compartir ambas keys flash con closures de sesión. Mantener downloads construidos sólo desde processed batch_id. Mostrar PREPARACIÓN COMPLETADA y GENERACIÓN DE VENTAS COMPLETADA; usar el primer error de ValidationException para una razón legible. Añadir processing y ref lock alrededor de un router.post.

- [ ] **Step 4: ejecutar suite GREEN y build**

    Run: php artisan test --env=testing --filter=RouteGlobal

    Run: cmd /c npm run build

- [ ] **Step 5: revisar alcance**

    Run: git diff --check

    Confirmar que no cambió collection, SalePayment, CashMovement, caja, stock, FEL, CxC ni servicios child.

### Task 2: Persistencia de política por Branch y activación segura

**Files:**
- Create: migration posterior aprobada para route_branch_collection_settings
- Create: app/Models/RouteBranchCollectionSetting.php
- Create: app/Services/Routes/RouteBranchCollectionSettingsService.php
- Modify: app/Models/Branch.php
- Test: tests/Feature/RouteBranchCollectionSettingsTest.php

**Interfaces:**
- Consumes: Branch bloqueada/cargada dentro del negocio activo.
- Produces: forBranch(int businessId, int branchId) y save(Branch branch, array policy, User actor).

- [ ] **Step 1: escribir tests RED de integridad de base de datos**

    Probar FK restrictiva de branch_id, UNIQUE(branch_id), workflow inválido, lista JSON vacía/duplicada/desconocida, primary fuera de allowed y rechazo de manifest_reconciliation. Insertar por DB una referencia branch inexistente para probar la constraint y consultar desde otro business para probar que el reader no devuelve la policy.

- [ ] **Step 2: ejecutar el test RED**

    Run: php artisan test --env=testing --filter=RouteBranchCollectionSettings

- [ ] **Step 3: implementar schema y service**

    Crear tabla sin business_id. Derivar scope de Branch.business_id. Validar allowed_payment_methods como subconjunto no vacío de cash/card/transfer/check y validar primary dentro del conjunto.

- [ ] **Step 4: probar activación sin reescritura**

    Probar que activar una policy no actualiza preventas existentes; método vacío devuelve missing_agreed_payment_method y método fuera de allowed devuelve payment_method_not_allowed al preflight.

- [ ] **Step 5: ejecutar migration fresh y suite GREEN**

    Run: php artisan migrate:fresh --env=testing --force

    Run: php artisan test --env=testing --filter=RouteBranchCollectionSettings

### Task 3: UI y autorización de configuración

**Files:**
- Modify: routes/web.php y controller administrativo de Rutas elegido tras auditoría de permisos
- Create: resources/js/Pages/Routes/BranchCollectionSettings/Index.tsx
- Test: tests/Feature/RouteBranchCollectionSettingsHttpTest.php

**Interfaces:**
- Consumes: RouteBranchCollectionSettingsService::save.
- Produces: política explícita por Branch actual, administrada por backend.

- [ ] **Step 1: escribir tests RED de HTTP**

    Probar usuario autorizado, no autorizado, inactivo, otra Branch y otro business. Probar que manifest_reconciliation no aparece como valor enviable y que el backend lo rechaza.

- [ ] **Step 2: ejecutar el test RED**

    Run: php artisan test --env=testing --filter=RouteBranchCollectionSettingsHttp

- [ ] **Step 3: implementar endpoint y página mínimos**

    Reutilizar permiso administrativo actual de Rutas. Renderizar dos workflows persistibles y Documento de reparto + conciliación — Próximamente disabled.

- [ ] **Step 4: ejecutar HTTP y build GREEN**

    Run: php artisan test --env=testing --filter=RouteBranchCollectionSettingsHttp

    Run: cmd /c npm run build

- [ ] **Step 5: revisar scope backend**

    Run: git diff --check

    Verificar que UI no es autoridad de Branch ni Business.

### Task 4: Métodos permitidos en preventas de ruta

**Files:**
- Modify: app/Http/Controllers/RouteController.php y validadores/service entry points reales de preventa
- Modify: resources/js/Pages/Routes/PreSales y recursos móviles que editan agreed_payment_method
- Test: tests/Feature/RoutesPreSalesTest.php y tests/Feature/RouteCollectionSettingsTest.php

**Interfaces:**
- Consumes: policy de Branch actual si existe.
- Produces: agreed_payment_method previsto y validado, sin hechos financieros.

- [ ] **Step 1: escribir tests RED de creación**

    Probar un único método autoasignado en preventa nueva, principal como default inicial con varios métodos y rechazo backend de un método fuera de allowed.

- [ ] **Step 2: escribir tests RED de edición**

    Probar que edición conserva un método existente permitido; un valor vacío puede mostrar primary pero sólo persiste al guardar; un valor existente no permitido permanece visible como inválido y no se sobrescribe silenciosamente. Probar que el único método en edición se persiste únicamente al guardar.

- [ ] **Step 3: ejecutar tests RED**

    Run: php artisan test --env=testing --filter=RoutesPreSales

    Run: php artisan test --env=testing --filter=RouteCollectionSettings

- [ ] **Step 4: implementar validación branch-aware**

    Resolver Branch en backend. En creación derivar el único método o default. En edición preservar valor válido, proponer primary sólo si vacío y exigir reemplazo deliberado si inválido. No crear collection, SalePayment ni CashMovement.

- [ ] **Step 5: ejecutar suites GREEN**

    Run: php artisan test --env=testing --filter=RoutesPreSales

    Run: php artisan test --env=testing --filter=RouteCollectionSettings

### Task 5: Snapshot gate y preflight policy-aware

**Files:**
- Modify after approval: migration de snapshots, app/Models/RouteDeliveryBatch.php, app/Models/RouteDeliveryBatchPreSale.php
- Modify after approval: app/Services/Routes/RouteDeliveryBatchService.php y app/Services/Routes/RouteGlobalOperationsService.php
- Test: tests/Feature/RouteDeliveryBatchTest.php y tests/Feature/RouteGlobalOperationsTest.php

**Interfaces:**
- Consumes: Branch policy y collection_responsibility_snapshot existente.
- Produces: snapshots inmutables del workflow, allowed methods, primary y agreed method efectivo.

- [ ] **Step 1: auditoría read-only de snapshots actuales**

    Leer migrations, models, services y readers de RouteDeliveryBatch y RouteDeliveryBatchPreSale. Registrar qué representa payment_method actual y dónde se lee collection_responsibility_snapshot.

- [ ] **Step 2: STOP / APPROVAL GATE — congelar DDL y lineage**

    Presentar la evidencia de la auditoría y el DDL mínimo para collection_workflow_mode_snapshot, allowed_payment_methods_snapshot, primary_payment_method_snapshot y agreed_payment_method_snapshot. No escribir migration, no crear write financiero y no cambiar conversión hasta aprobación explícita.

- [ ] **Step 3: escribir tests RED después de aprobación**

    Probar snapshot inmutable por batch/fila, legacy sin policy, missing_agreed_payment_method, payment_method_not_allowed, cash_session_required para immediate_paid y el bloqueo transitorio pre_seller_post_conversion_collection_unavailable (retirado al completar Task 7D).

- [ ] **Step 4: implementar snapshots y preflight mínimos**

    Crear sólo el DDL aprobado. Escribir snapshots dentro de la child transaction antes de conversión y devolver códigos administrativos por preview.

- [ ] **Step 5: ejecutar regresiones GREEN**

    Run: php artisan test --env=testing --filter=RouteDeliveryBatch

    Run: php artisan test --env=testing --filter=RouteGlobal

### Task 6: Immediate paid — cadena financiera única y atómica

**Files:**
- Modify: app/Services/Routes/RouteDeliveryBatchService.php
- Modify: una sola cadena existente elegida por auditoría entre RoutePreSaleCollectionService, RoutePreSaleReceiptService y servicios de payment/cash
- Test: tests/Feature/RouteDeliveryBatchTest.php, tests/Feature/RoutePreSaleCollectionTest.php, tests/Feature/CashTest.php y tests/Feature/SaleTest.php

**Interfaces:**
- Consumes: policy snapshot, método permitido y sesión abierta.
- Produces: Sale paid, un pago real, una traza/collection y CashMovement sólo para cash.

- [ ] **Step 1: auditoría de cadena financiera reutilizable**

    Mapear la creación actual de RoutePreSaleCollection, SalePayment, receipt y CashMovement. Seleccionar una sola cadena que sirva a pre_seller y delivery_agent. Documentar el resultado antes de write.

- [ ] **Step 2: escribir tests RED de los cuatro métodos y responsables**

    Probar ambos responsables con Sale paid, exactamente un SalePayment y ninguna pending collection. Cash tiene un movimiento físico y aumento de expected cash; card/transfer/check no tienen CashMovement. Probar caja abierta obligatoria para todo immediate_paid batch.

- [ ] **Step 3: escribir tests RED de atomicidad**

    Forzar failure injection controlada en collection/traza, SalePayment y CashMovement. En cada caso comprobar rollback de Sale paid, payment, collection y movimiento. Probar same-key replay y competidor controlado sin duplicación.

- [ ] **Step 4: implementar cadena única dentro de child transaction**

    Reutilizar sólo el servicio elegido. No requerir collection legacy previa por pre_seller. Mantener idempotencia y locks child existentes.

- [ ] **Step 5: ejecutar GREEN secuencial**

    Run: php artisan test --env=testing --filter=RouteDeliveryBatch

    Run: php artisan test --env=testing --filter=RoutePreSaleCollection

    Run: php artisan test --env=testing --filter=Sale

    Run: php artisan test --env=testing --filter=Cash

    Run: php artisan test --env=testing --filter=Credit

### Task 7: Per-order collection y camino posterior de pre_seller

**Files:**
- Modify after gate: app/Services/Routes/RouteDeliveryBatchService.php
- Create or modify after gate: servicio/UI de collection post-conversión validado por auditoría
- Test: tests/Feature/RouteDeliveryBatchTest.php, tests/Feature/RoutePendingCollectionTest.php y tests/Feature/RoutePreSaleCollectionTest.php

**Interfaces:**
- Consumes: per_order_collection snapshot, responsibility y método permitido vigente al cobro.
- Produces: Sale unpaid sólo cuando existe un camino posterior real de cobro.

- [ ] **Step 1: escribir RED de delivery_agent**

    Probar Sale unpaid sin SalePayment, collection, CashMovement, CustomerAccountMovement ni CxC; probar que 3E-C sólo aparece tras delivered físico elegible.

- [ ] **Step 2: auditoría read-only de pre_seller post-conversión**

    Inspeccionar RoutePreSaleCollectionService, RouteDeliveryCollectionService, RoutePendingCollectionCaseService, rutas HTTP y UI. Determinar si alguno puede cobrar una Sale convertida preservando origen y trazabilidad.

- [ ] **Step 3: STOP / APPROVAL GATE — resolver pre_seller**

    Si no hay camino existente, presentar diseño del servicio/UI post-conversión, modelo de origen y cadena única de SalePayment. No retirar guard legado ni habilitar per_order_collection + pre_seller hasta aprobación explícita.

- [ ] **Step 4: implementar sólo el camino aprobado**

    Preservar delivery_agent actual. Para pre_seller, registrar cobro real posterior sin origen físico falso y sin ledger paralelo. En per_order_collection, generar venta no exige caja; el cobro real posterior aplica política de sesión.

- [ ] **Step 5: ejecutar GREEN**

    Run: php artisan test --env=testing --filter=RouteDeliveryBatch

    Run: php artisan test --env=testing --filter=RoutePendingCollection

    Run: php artisan test --env=testing --filter=RoutePreSaleCollection

    Run: php artisan test --env=testing --filter=Cash

### Task 8: Auditoría de trazabilidad de Cash Session y cierre multimedio

**Files:**
- Read-only initially: SalePayment, Sale, CashRegisterSession, CashMovement, POS, Route services, closing/report readers y migrations
- Create after approval only: migration/model relation si la auditoría demuestra ausencia de relación autoritativa
- Test after approval only: tests/Feature/CashTest.php y reader/report tests identificados por auditoría

**Interfaces:**
- Consumes: hechos financieros existentes.
- Produces initially: informe de relación autoritativa o evidencia de ausencia; no produce schema en este punto.

- [ ] **Step 1: ejecutar auditoría read-only**

    Mapear FK/campos entre SalePayment, Sale, CashRegisterSession y CashMovement para cash, card, transfer y check. Revisar POS, rutas y cierre. Probar que timestamp no es un vínculo aceptable.

- [ ] **Step 2: documentar evidencia**

    Registrar la relación encontrada, los readers que la consumen y si permite atribuir cada método al turno correcto sin ambigüedad.

- [ ] **Step 3: STOP / APPROVAL GATE — schema sólo si falta relación**

    Si no existe relación autoritativa suficiente, presentar schema mínimo, migración propuesta y matrix de readers para aprobación específica. No crear migration, model, test mutante ni reader antes de aprobación.

- [ ] **Step 4: después de aprobación, escribir tests RED**

    Probar efectivo, tarjeta, transferencia, cheque y total por sesión; aislamiento business/branch; sesión cerrada; y ausencia de atribución por timestamp.

- [ ] **Step 5: después de aprobación, implementar y verificar GREEN**

    Conservar CashMovement como autoridad exclusiva de efectivo y no mutar cierres históricos.

    Run: php artisan test --env=testing --filter=Cash

    Run: php artisan test --env=testing --filter=Sale

### Task 9: Confirmación global policy-aware

**Files:**
- Modify: app/Services/Routes/RouteGlobalOperationsService.php
- Modify: app/Http/Controllers/RouteGlobalOperationsController.php
- Modify: resources/js/Pages/Routes/GlobalOperations/Index.tsx
- Test: tests/Feature/RouteGlobalOperationsTest.php

**Interfaces:**
- Consumes: previews y policy/snapshot evaluados por backend.
- Produces: confirmación clara de workflow, caja, métodos, stock, FEL y bloqueos.

- [ ] **Step 1: escribir tests RED**

    Probar que React recibe modo y motivos backend; immediate_paid muestra caja requerida; per_order_collection comunica que no se cobra aún; no se generan claves duplicadas ni IDs documentales distintos.

- [ ] **Step 2: ejecutar RED**

    Run: php artisan test --env=testing --filter=RouteGlobal

- [ ] **Step 3: implementar presentación mínima**

    Extender modal existente sólo con props backend. No decidir elegibilidad en React y no cambiar ownership child.

- [ ] **Step 4: ejecutar GREEN**

    Run: php artisan test --env=testing --filter=RouteGlobal

    Run: cmd /c npm run build

- [ ] **Step 5: revisar no regresión 3G-A**

    Confirmar flash one-request, downloads por batch_id y processing lock.

### Task 10: Auditor, rollout y backward compatibility

**Files:**
- Modify after new facts exist: app/Services/SystemIntegrityAuditor.php
- Modify: documentación de rollout administrativo
- Test: auditor/integrity tests identificados por código real

**Interfaces:**
- Consumes: settings, snapshots y hechos financieros aprobados.
- Produces: findings de solo lectura, nunca reparación automática.

- [ ] **Step 1: escribir tests RED de integridad**

    Cubrir policy inválida, scope cross-business, snapshot faltante, cash/non-cash mismatch, duplicate lineage, CxC artificial y Branch legacy sin setting.

- [ ] **Step 2: ejecutar RED**

    Run: php artisan test --env=testing --filter=SystemIntegrity

- [ ] **Step 3: implementar checks lectores**

    Agregar findings sin corregir ni reescribir preventas, ventas, pagos, cajas o historia.

- [ ] **Step 4: ejecutar GREEN y rollout review**

    Run: php artisan migrate:fresh --env=testing --force

    Run: php artisan test --env=testing --filter=SystemIntegrity

- [ ] **Step 5: verificar backward compatibility**

    Probar Branch sin policy con guards legados y confirmar que activación nueva no backfillea preventas abiertas.

### Task 11: Verificación completa

**Files:**
- Modify: ninguno esperado salvo corrección mínima demostrada por test.
- Test: suites existentes secuenciales.

**Interfaces:**
- Consumes: implementación aprobada de Tasks 2–10.
- Produces: evidencia reproducible de regresión y repositorio íntegro.

- [ ] **Step 1: reconstruir base de testing**

    Run: php artisan migrate:fresh --env=testing --force

- [ ] **Step 2: ejecutar suites focalizadas una por una**

    Run: php artisan test --env=testing --filter=RouteGlobal

    Run: php artisan test --env=testing --filter=RoutePreparation

    Run: php artisan test --env=testing --filter=RoutePreSale

    Run: php artisan test --env=testing --filter=RouteDelivery

    Run: php artisan test --env=testing --filter=RoutePendingCollection

    Run: php artisan test --env=testing --filter=Cash

    Run: php artisan test --env=testing --filter=Sale

    Run: php artisan test --env=testing --filter=Credit

    Run: php artisan test --env=testing --filter=FEL

    Run: php artisan test --env=testing --filter=RouteCashSettlement

    Run: php artisan test --env=testing --filter=RouteDeliveryCollectionReversal

    Run: php artisan test --env=testing --filter=CriticalPosFelFlow

- [ ] **Step 3: ejecutar suite total y build**

    Verificar que no existe otro runner PHP del proyecto.

    Run: php artisan test --env=testing

    Run: cmd /c npm run build

- [ ] **Step 4: inspeccionar Git sin staging**

    Run: git diff --check

    Run: git diff --cached --check

    Run: git status --short

    Run: git diff --stat

- [ ] **Step 5: verificar aislamiento B2a**

    Run: git stash list -n 10

    Localizar la referencia cuyo git rev-parse sea 0db76006f5209ff076ec876f4c2bb37ebe43f6d0.

    Confirmar que esa referencia sigue existiendo sin asumir que es stash@{0}; no ejecutar apply, pop ni drop.

## Plan self-review

Tasks 1–11 tienen pasos ejecutables. Task 5 detiene writes hasta congelar snapshots y DDL; Task 7 bloquea pre_seller hasta probar cobro post-conversión; Task 8 inicia read-only y detiene cualquier schema hasta aprobación específica. Immediate paid conserva cadena única y atomicidad. Per-order collection no exige caja al convertir. 3H queda diferida y 3G-A no cambia.
