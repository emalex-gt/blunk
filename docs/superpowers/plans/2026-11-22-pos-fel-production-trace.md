# POS/FEL Production Trace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Emit and read an opt-in, correlated POS/FEL performance trace.

**Architecture:** POS posts validated browser timing marks asynchronously. The POS controller and fiscal-intent path emit non-sensitive server stages under the same UUID. A bounded Artisan reader aggregates explicit stage durations by trace and environment.

**Tech Stack:** Laravel, Inertia React, PostgreSQL-independent structured logs.

**Spec:** `docs/superpowers/specs/2026-11-22-pos-fel-production-trace-design.md`

## Global Constraints

- No migration, certification behavior, idempotency, inventory, cash or Route change.
- Instrumentation remains disabled unless `BLUNK_OPERATIONS_LOG_ENABLED` and tenant allowlist permit it.
- Browser telemetry is authenticated, CSRF-protected, validated and rate-limited.

## Review Focus

- Trace UUID must not become part of a fiscal snapshot or idempotency fingerprint.
- A telemetry failure must not block submission or printing.
- No browser-supplied tenant, fiscal payload or customer data may be logged.
- Missing stages remain explicitly incomplete in reports.
- Test and production environments remain separated in reports.

### Task 1: Correlated backend and browser events

**Files:** controller, fiscal intent service, Digifact service/client, POS, route, focused tests.

- [ ] Write focused failing tests for validated browser phases and intent-path trace stages.
- [ ] Add opt-in backend stages and asynchronous authenticated browser telemetry.
- [ ] Verify focal tests and frontend type check.

### Task 2: Bounded trace report

**Files:** OperationsLogReader, console route, focused tests.

- [ ] Write failing aggregation test for complete and incomplete traces.
- [ ] Add bounded grouped report with per-trace and percentile output.
- [ ] Verify focal tests.
