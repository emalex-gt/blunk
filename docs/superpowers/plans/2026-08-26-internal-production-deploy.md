# Internal Production Deploy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a disabled-by-default, queue-backed Super Admin production-deploy control with audit records and logs.

**Architecture:** A configuration gate controls whether a dedicated `deploys` queue may execute the single versioned script. HTTP requests only inspect status, persist `DeployRun`, and dispatch a fixed job; the service owns all Process execution and log persistence.

**Tech Stack:** Laravel 12, PostgreSQL, Laravel database queue, Symfony Process, React/Inertia, TypeScript, Tailwind, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-26-internal-production-deploy-design.md`

## Global Constraints

- Default `DEPLOY_QUEUE_WORKER_ENABLED=false`; do not offer synchronous fallback.
- Use only queue `deploys`, timeout `900`, tries `1`, and no automatic retries.
- Execute only `bash scripts/deploy-production.sh`; never use request data in a command.
- Do not run a deploy, modify production, use sudo, touch `.env`, or change POS/business logic.
- Every deploy route and log is restricted by `auth` plus `super.admin` middleware.

---

## File Map

- `config/deploy.php`: disabled-by-default runtime gate and fixed paths.
- `database/migrations/*_create_deploy_runs_table.php`, `app/Models/DeployRun.php`: audit persistence.
- `scripts/deploy-production.sh`: fixed production script with clean-main guard and maintenance restoration.
- `app/Services/System/DeployService.php`: read-only status and fixed Process invocation.
- `app/Jobs/RunProductionDeployJob.php`: dedicated queue execution.
- `app/Http/Controllers/SuperAdmin/DeployController.php`, `routes/web.php`: protected HTTP endpoints.
- `resources/js/Pages/SuperAdmin/System/Deploy.tsx`, `resources/js/Layouts/SuperAdminLayout.tsx`: status, confirmation, history, and logs.
- `docs/deploy-production.md`: Supervisor installation and enablement guidance.
- `tests/Feature/DeployTest.php`: authorization and orchestration tests using a mocked service.

### Task 1: Configuration And Audit Model

**Files:** Create `config/deploy.php`, migration, `app/Models/DeployRun.php`; Test `tests/Feature/DeployTest.php`.

- [ ] Write a failing test that asserts `config('deploy.queue_worker_enabled')` defaults false and a run persists `pending` with fixed branch `main`.
- [ ] Run `php artisan test --env=testing --filter=Deploy` and verify the test fails because the config/model/schema is absent.
- [ ] Add config values for the boolean environment gate, `deploys` queue, fixed script path, and log directory; create `deploy_runs` with status/commit/log/error/exit-code fields and indexes for status and creation time.
- [ ] Add `DeployRun` casts, fillable fields, `user()` relation, status constants, and `isActive()` query scope.
- [ ] Run the focused test and verify it passes.

### Task 2: Fixed Script And Process Service

**Files:** Create `scripts/deploy-production.sh`, `app/Services/System/DeployService.php`; Modify `docs/deploy-production.md`; Test `tests/Feature/DeployTest.php`.

- [ ] Write failing unit/feature assertions that status reports fixed git fields and `runDeploy()` constructs only `bash scripts/deploy-production.sh`.
- [ ] Run the focused test and verify it fails before the service exists.
- [ ] Implement the script exactly with fixed app path, `main`/clean-tree guards, `php8.5` preflight, `php8.5 artisan` operations, and an EXIT trap that runs `php8.5 artisan up` after maintenance begins.
- [ ] Implement `DeployService::status()` with fixed read-only git commands and `runDeploy()` with Symfony Process timeout `900`, captured output in `storage/logs/deploys/deploy-{id}.log`, and final run metadata persistence.
- [ ] Document Supervisor configuration, permissions, the environment gate, and the prohibition on enabling it before worker verification.
- [ ] Run the focused test and verify it passes without executing the script.

### Task 3: Queued Execution And Protected Controller

**Files:** Create `app/Jobs/RunProductionDeployJob.php`, `app/Http/Controllers/SuperAdmin/DeployController.php`; Modify `routes/web.php`; Test `tests/Feature/DeployTest.php`.

- [ ] Write failing tests for non-Super Admin 403 responses, exact `ACTUALIZAR` confirmation, disabled queue gate rejection, dirty checkout rejection, active-run rejection, dispatch to `deploys`, and Super Admin-only log access.
- [ ] Run the focused test and verify missing routes/controller/job failures.
- [ ] Add the job with `ShouldQueue`, queue `deploys`, timeout `900`, tries `1`, no retry backoff, and failure-state persistence.
- [ ] Add only fixed controller operations: page status, status refresh, guarded run record creation/job dispatch, run detail, and safe log response. Apply rate limiter of three run attempts/hour in addition to the active-run query.
- [ ] Register only `auth`/`super.admin` routes under `/super-admin/system/deploy`; do not accept command, branch, path, or script inputs.
- [ ] Run focused tests and verify all pass.

### Task 4: Super Admin User Interface

**Files:** Create `resources/js/Pages/SuperAdmin/System/Deploy.tsx`; Modify `resources/js/Layouts/SuperAdminLayout.tsx`; Test `tests/Feature/DeployTest.php` source/props assertions if no JS test runner exists.

- [ ] Write failing Inertia assertions for queue configuration, deploy status, commits, history, and protected action availability props.
- [ ] Run the focused test and verify props are absent.
- [ ] Implement the compact operational page with status fields, Worker/Supervisor warning, disabled state when gate false/dirty/non-main/running/no-update, a typed-confirmation modal, history table, and monospace log panel.
- [ ] Add a Super Admin navigation entry under Sistema/Actualizaciones without exposing it to tenant navigation.
- [ ] Run the focused tests and `cmd /c npm run build`.

### Task 5: Regression Verification

**Files:** Modify only artifacts required by test/build failures.

- [ ] Run `php artisan test --env=testing --filter=Deploy`.
- [ ] Run `php artisan test --env=testing`.
- [ ] Run `cmd /c npm run build`.
- [ ] Run `git diff --check`.
- [ ] Confirm no test/process invocation calls `scripts/deploy-production.sh` and no route accepts a shell command.
