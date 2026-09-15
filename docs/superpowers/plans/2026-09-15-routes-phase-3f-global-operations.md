# Fase 3F — Operación global de Rutas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Operar preparación y generación de ventas de rutas para toda la sucursal, agrupando el resultado por vendedor.

**Architecture:** Un orquestador branch-level llama los servicios atómicos existentes por jornada y produce previews/resultados agregados; batches actuales conservan la evidencia.

**Tech Stack:** Laravel 12, Inertia React/TypeScript, PostgreSQL, DomPDF, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-15-routes-phase-3f-global-operations-design.md`

## Global Constraints

- No migraciones ni reemplazo de `RoutePreparationBatch`/`RouteDeliveryBatch` sin necesidad demostrada.
- Reutilizar `RoutePreparationBatchService` y `RouteDeliveryBatchService`.
- Scope obligatorio business + current branch; sin mega-transacción.
- No commit, push, deploy ni producción durante implementación.

### Task 1: Preview global

**Files:** Create `app/Services/Routes/RouteGlobalOperationsService.php`; create `tests/Feature/RouteGlobalOperationsTest.php`.

- [ ] Escribir tests con dos vendedores, varias jornadas y una fila bloqueada por estado/sucursal.
- [ ] Ejecutar el filtro `RouteGlobal` y confirmar RED por servicio inexistente.
- [ ] Implementar `preparationPreview(User $actor): array` y `salesPreview(User $actor): array`, con eager loading, agregados seller y razones bloqueadas.
- [ ] Ejecutar el filtro y confirmar GREEN.

### Task 2: Preparar todo global

**Files:** Modify `RouteGlobalOperationsService`; modify/create controller/routes; test `RouteGlobalOperationsTest`.

- [ ] Añadir test de ejecución secuencial, child batches por jornada y replay sin duplicados.
- [ ] Confirmar RED.
- [ ] Implementar `prepareAll(User $actor, string $key)` que deriva una key child estable y llama `RoutePreparationBatchService::prepareAll` sólo para jornadas preview elegibles.
- [ ] Confirmar GREEN y regresión `RoutePreparation`.

### Task 3: Documentos globales por vendedor

**Files:** Create `app/Services/Routes/RouteGlobalPreparationDocuments.php`; create Blade templates; test PDF/document service.

- [ ] Escribir tests de agrupación por seller cruzando jornadas, consolidado y producto.
- [ ] Implementar cargas eager y agrupación seller, conservando jornada como metadato.
- [ ] Reutilizar datos de templates existentes y verificar GREEN.

### Task 4: Recibos media carta

**Files:** Modify receipt Blade/template and controller; tests de render/PDF.

- [ ] Escribir test de tamaño `396x612`, seller visible y page-break before por preventa.
- [ ] Aplicar plantilla compartida media carta con page-break entre ordenes.
- [ ] Verificar que orden extensa puede fluir y la siguiente inicia página nueva.

### Task 5: Generar ventas global

**Files:** Modify `RouteGlobalOperationsService`; tests global sales.

- [ ] Escribir tests de Sale único, `converted_sale_id`, retry, stock invoice/picking, FEL off y pagos por responsibility.
- [ ] Implementar `generateSales(User $actor, string $key)` llamando `RouteDeliveryBatchService::deliverAll` por jornada elegible.
- [ ] Ejecutar `RouteDelivery`, `Sale`, `Stock`, `FEL` focalizados.

### Task 6: HTTP y dashboard

**Files:** Create `RouteGlobalOperationsController`; modify routes and `resources/js/Pages/Routes/*`; tests HTTP.

- [ ] Testear permiso backend, tenant/branch, preview counts y bloqueos.
- [ ] Implementar pantalla global con acciones principales, resumen seller y resultados/retry.
- [ ] Ejecutar build y tests HTTP.

### Task 7: Verificación

- [ ] Ejecutar migrate:fresh, filtros RoutePreparation, RoutePreSale, RouteDelivery, RouteGlobal, Sale, Stock, Cash, FEL y CriticalPosFelFlow secuencialmente.
- [ ] Ejecutar suite completa, build, `git diff --check` y `git status --short`.
