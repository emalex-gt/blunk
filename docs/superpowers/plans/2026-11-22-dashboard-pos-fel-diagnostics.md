# Dashboards globales y diagnóstico POS/FEL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preservar perfiles personalizados reutilizables y medir una venta POS/FEL de extremo a extremo de forma opcional.

**Architecture:** El resolver reconoce claves de rol y conserva las puertas de capacidad existentes. POS crea un UUID de trazabilidad solo cuando OperationsLog está habilitado; Laravel y el navegador registran fases seguras bajo el mismo identificador.

**Tech Stack:** Laravel, Inertia React, PHPUnit, `performance.now()`.

**Spec:** `docs/superpowers/specs/2026-11-22-dashboard-pos-fel-diagnostics-design.md`

## Global Constraints

- Sin migraciones, cambios de base, despliegue ni push.
- Sin cambios en Rutas, reglas FEL, idempotencia, inventario o caja.
- Solo pruebas focalizadas con `blunk_test`.

## Review Focus

- Usuarios con Owner/Admin y un rol personalizado: Owner/Admin debe prevalecer.
- Rol personalizado sin permiso de inventario: ningún widget de warehouse debe exponerse.
- Trazas deshabilitadas: el flujo POS debe permanecer idéntico.
- Certificación FEL desconocida o fallida: no debe reiniciarse ni duplicarse la operación.
- Impresión: solo medir su inicio, nunca sustituirla ni bloquearla.

### Task 1: Perfiles globales de roles personalizados

**Files:**
- Modify: `app/Services/Dashboard/DashboardProfileResolver.php`
- Test: `tests/Feature/DashboardFoundationTest.php`

- [ ] Escribir pruebas para ambos roles en tenants con IDs diferentes, sin capacidades y con Owner/Admin.
- [ ] Ejecutar la prueba y confirmar que falla con la excepción Ferrymas actual.
- [ ] Reemplazar la excepción por asignación basada en claves y precedencia explícita.
- [ ] Ejecutar las pruebas focalizadas y crear el commit de dashboard.

### Task 2: Traza POS/FEL opt-in

**Files:**
- Modify: `app/Support/OperationsLog.php`, `app/Http/Controllers/SaleController.php`, `resources/js/Pages/Sales/POS.tsx`
- Test: pruebas focalizadas de POS/FEL existentes

- [ ] Escribir pruebas de contrato para la activación, correlación y ausencia de exposición cuando esté desactivada.
- [ ] Reutilizar timings FEL; emitir solo fases nuevas de POST/destino y propagar un trace ID no sensible mediante flash/Inertia.
- [ ] Medir en POS clic, éxito, primer frame e inicio de impresión con `performance.now()` y `requestAnimationFrame`.
- [ ] Ejecutar pruebas focalizadas, revisar el diff y crear el commit de instrumentación.
