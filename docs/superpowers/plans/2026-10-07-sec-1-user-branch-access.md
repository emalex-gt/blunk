# SEC-1 Explicit User × Branch Authorization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce explicit tenant-user branch authorization independently from the `branches.switch` capability.

**Architecture:** A durable all-branches flag and validated assignment pivot feed one `BranchAccessService`; `BranchInventory` becomes its compatibility façade for active branch and selector callers. Controllers/services then use the resolver for direct branch-resource authorization.

**Tech Stack:** Laravel 12, PostgreSQL deferred integrity triggers, Inertia React/TypeScript, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-07-sec-1-user-branch-access-design.md`

## Global Constraints

- `all_branches_access=true` grants all active branches of the user's own tenant only.
- `branches.switch` is only a switching capability.
- Stale active branch state may recover for navigation; explicit unauthorized mutation/switch requests fail.
- Transfer creation requires authorization to both branches.
- Tenant-global catalog/customer/supplier/brand/category semantics remain unchanged.
- No dependency, B2a, procurement, production, deployment, or push change.
- Final local commit: `enforce explicit user branch access`.

## Review Focus

- A stale session must not elevate a user into an unassigned branch.
- Reducing all access to selected branches must never have an intermediate unrestricted state.
- Direct resource IDs from a different assigned/unassigned branch must be distinguished by authorization, not list filtering.
- A cross-tenant pivot row must fail at the database boundary.
- A transfer must reject either unauthorized endpoint.

### Task 1: Persist and resolve branch access

**Files:** migration, `UserBranchAccess` model, `User`, `Branch`, `BranchAccessService`, `BranchInventory`, focused branch-access tests.

**Produces:** `authorizedBranches(User, int)`, `canAccessBranch(User, Branch)`, `resolveActiveBranch(User, int)`.

- [ ] Write persistence and resolver tests for single, multi, all, stale, inactive, foreign and invalid-row cases; run RED.
- [ ] Add the migration, model relations, deferred tenant-integrity trigger, backfill, and resolver; run GREEN.
- [ ] Delegate `BranchInventory` active/switch/options behavior to the resolver and prove switch capability does not grant A3.

### Task 2: Tenant user management and selector

**Files:** `TenantUserController`, `Users/Index.tsx`, `HandleInertiaRequests`, branch controller, tests.

**Consumes:** Task 1 resolver and assignment API.

- [ ] Write RED tests for creating/updating selected/all grants, atomic reduction, selector filtering and rejected manual switch.
- [ ] Implement validated management payloads and minimal UI controls; use a transaction for mode changes.
- [ ] Run focused HTTP/UI-contract tests GREEN.

### Task 3: Critical authorization hardening

**Files:** sales, purchases, cash, transfers, routes/settlements, reports/dashboard scope helpers and focused tests.

**Consumes:** `BranchAccessService::canAccessBranch`.

- [ ] Add RED IDOR/list tests for Sale, Purchase, CashSession, route workday/pre-sale, settlement, dashboard/report and transfer endpoints.
- [ ] Replace `canSwitchBranches` authorization bypasses with explicit branch authorization; enforce both transfer endpoints.
- [ ] Run the affected focused suites GREEN.

### Task 4: Migration and final verification

**Files:** tests only if gaps are discovered.

- [ ] Run migration fresh, rollback and reapply under PHP 8.5; verify deterministic backfill.
- [ ] Re-search `branches.switch` and document every remaining capability-only use.
- [ ] Run sequential focused suites, frontend build, exactly one full PHPUnit suite, and diff checks.
- [ ] Commit all approved SEC-1 work once with `enforce explicit user branch access`.
