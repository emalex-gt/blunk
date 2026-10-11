# SEC-1 Explicit User × Branch Authorization

## Goal

Separate the capability to switch branches from authorization to access a branch. A tenant user may access only explicitly assigned active branches, unless the tenant user has an explicit all-branches grant.

## Non-goals

No tenant-global master data becomes branch scoped. No procurement work, dependency changes, B2a changes, deployment, or production operation is included.

## Persistence

`users.all_branches_access` is a non-null boolean. `true` grants all *active* branches of the user's tenant; `false` makes `user_branch_accesses` authoritative. The pivot has `business_id`, `user_id`, `branch_id`, timestamps, FK constraints, a unique `(user_id, branch_id)` index, and a deferred PostgreSQL integrity trigger that rejects any row whose user or branch has a different business.

`all_branches_access` is an authorization state, not a role inference and not a consequence of `branches.switch`.

## Backfill

The migration preserves present behavior without expanding it: active non-platform users with `branches.switch` receive explicit all-branches access; other active non-platform users receive a pivot for a valid same-tenant active `current_branch_id`. A legacy user without a valid current branch receives no invented authorization. At runtime that user is denied branch-dependent operations until an administrator assigns an active branch; this is conservative and intentional. Platform superadmins are not granted tenant pivots because their separate platform boundary remains authoritative.

## Resolver

`BranchAccessService` is the only authority for tenant branch authorization. It exposes authorized active branches, `canAccessBranch`, `canAccessAllBranches`, and active branch resolution. For a normal user it checks tenant ownership, active status, and either explicit all-branches access or an assignment. A platform superadmin remains intentionally unrestricted in platform/superadmin flows.

Stale session or current branch state is cleared for navigation and the first authorized active branch is selected. Explicit mutation/switch requests for unauthorized branches fail with 403; they are never silently redirected.

## Capability vs authorization

`branches.switch` only controls whether a user may switch between branches returned by `BranchAccessService`. It never expands that list. A user without it still needs valid branch authorization and works from an authorized current branch.

## Tenant user management

Tenant user create/update supports an access mode (`all` or `selected`) and selected active branch IDs. Reducing all access to selected access is transactionally atomic: assignments are validated and replaced before the all-branches flag is cleared. A selected user must retain at least one active assignment; `current_branch_id` is set to an assignment.

## Scope enforcement

`BranchInventory` delegates active branch, selector options, and switch validation to the resolver. Existing controllers using active branch inherit the safe resolution. Explicit branch-resource checks are hardened to ask the resolver rather than treating `branches.switch` as authority. Transfers require authorization to both origin and destination.

Reports and dashboard continue to use a single active branch, now an authorized one. Products, customers, suppliers, categories, brands, and tenant-level settings stay tenant-global.

## Testing

Tests establish one-, selected-multi-, all-branch, switch-without-assignment, stale-session, cross-tenant, IDOR, selector, transfer, migration, and platform-superadmin cases. The full suite is required after focused suites because this changes a shared authorization boundary.
