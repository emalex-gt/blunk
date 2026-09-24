# Route Settings in Superadmin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Relocate route policy administration to canonical Superadmin tenant and branch surfaces without changing the underlying route-setting domain.

**Architecture:** Keep the six existing `tenant_settings` route fields in the existing tenant update form, regrouped visually under Rutas. Replace the active-branch client policy endpoint with URL-bound Superadmin GET/PUT endpoints that verify the nested branch belongs to the selected tenant and reuse `RouteBranchCollectionSettingsService`.

**Tech Stack:** Laravel, Inertia React/TypeScript, PostgreSQL, PHPUnit feature tests.

**Spec:** `docs/superpowers/specs/2026-09-24-route-settings-superadmin-design.md`

## Global Constraints

- No migration, data move, default change, or backfill.
- Branch scope comes only from `{business}` and `{branch}`, never client active-branch context.
- Superadmin authorization is `auth` plus `super.admin`; no tenant route permission grants this authority.
- Reuse `RouteBranchCollectionSettingsService`; do not change operational readers or financial semantics.
- Remove the old client settings routes and navigation; do not leave an alternate writer URL.
- Do not touch Task 8, 3H, settlements, financial semantics, or B2a.
- Do not commit, push, deploy, or use production during this task unless the user later authorizes it.

## Review Focus

- A URL with a valid branch from a different tenant must return not found and write nothing.
- A Superadmin with an unrelated active client branch must still edit the URL branch only.
- An operational tenant user must have neither visible navigation nor a reachable writer endpoint.
- Existing stored tenant and branch settings must be returned and persisted unchanged except for submitted fields.
- The former route name must not be referenced by the client layout or a newly introduced Superadmin component.

---

### Task 1: Establish Superadmin branch-policy endpoint and scope tests

**Files:**
- Create: `app/Http/Controllers/SuperAdmin/TenantBranchRouteSettingsController.php`
- Modify: `routes/web.php`
- Delete: `app/Http/Controllers/RouteBranchCollectionSettingsController.php`
- Test: `tests/Feature/RouteBranchCollectionSettingsHttpTest.php`

**Interfaces:**
- Consumes: `RouteBranchCollectionSettingsService::forBranch(int $businessId, int $branchId): ?RouteBranchCollectionSetting` and `save(int $businessId, Branch $branch, array $policy): RouteBranchCollectionSetting`.
- Produces: `super-admin.tenants.branches.route-settings.index` and `.update`, bound to `(Business $business, Branch $branch)`.

- [ ] **Step 1: Replace old endpoint tests with failing Superadmin cases**

```php
$this->actingAs($superAdmin)
    ->get(route('super-admin.tenants.branches.route-settings.index', [$business, $branch]))
    ->assertOk()
    ->assertInertia(fn ($page) => $page
        ->component('SuperAdmin/Tenants/BranchRouteSettings')
        ->where('tenant.id', $business->id)
        ->where('branch.id', $branch->id));

$this->actingAs($superAdmin)
    ->put(route('super-admin.tenants.branches.route-settings.update', [$business, $branch]), $policy)
    ->assertRedirect(route('super-admin.tenants.branches.route-settings.index', [$business, $branch]));
```

Add cases for branch A/B isolation, cross-tenant URL rejection, a non-superadmin forbidden response, independence from `current_branch_id`, and a 404 response for the removed old client URL.

- [ ] **Step 2: Run the focused test to verify RED**

Run: `php artisan test --env=testing tests/Feature/RouteBranchCollectionSettingsHttpTest.php`

Expected: failures because the named Superadmin routes and component do not exist.

- [ ] **Step 3: Create a separate Superadmin controller**

```php
public function index(Business $business, Branch $branch, RouteBranchCollectionSettingsService $settings): Response
{
    $this->branchForBusiness($business, $branch);

    return Inertia::render('SuperAdmin/Tenants/BranchRouteSettings', [
        'tenant' => $business->only('id', 'name'),
        'branch' => $branch->only('id', 'name'),
        'policy' => $this->policyPayload($settings->forBranch($business->id, $branch->id)),
        'payment_methods' => $this->paymentMethods(),
    ]);
}

private function branchForBusiness(Business $business, Branch $branch): void
{
    abort_unless((int) $branch->business_id === (int) $business->id, 404);
}
```

Validate the same three policy fields as the existing client controller and call `save($business->id, $branch, $data)`. Do not reference session tenant or branch helpers.

- [ ] **Step 4: Register the two routes and remove old ones**

Inside the existing Superadmin route group, register GET and PUT routes below tenant branch routes. Remove the client `/routes/branch-collection-settings` GET/PUT registrations and their controller import. Delete the client-only controller after no route references it.

- [ ] **Step 5: Run the HTTP test to verify GREEN**

Run: `php artisan test --env=testing tests/Feature/RouteBranchCollectionSettingsHttpTest.php`

Expected: all Superadmin, tenant isolation, and legacy-route-removal cases pass.

### Task 2: Move the branch policy UI into Superadmin

**Files:**
- Create: `resources/js/Pages/SuperAdmin/Tenants/BranchRouteSettings.tsx`
- Modify: `resources/js/Pages/SuperAdmin/Tenants/Branches.tsx`
- Delete: `resources/js/Pages/Routes/BranchCollectionSettings/Index.tsx`
- Modify: `resources/js/Layouts/AuthenticatedLayout.tsx`
- Test: `tests/Feature/RouteBranchCollectionSettingsHttpTest.php`

**Interfaces:**
- Consumes: the controller props `tenant`, `branch`, `policy`, and `payment_methods` from Task 1.
- Produces: a Superadmin policy form posting `PUT super-admin.tenants.branches.route-settings.update` with `[tenant.id, branch.id]`.

- [ ] **Step 1: Add failing UI/markup assertions**

```php
$branchMarkup = file_get_contents(resource_path('js/Pages/SuperAdmin/Tenants/Branches.tsx'));
$this->assertStringContainsString('Configuración de rutas', $branchMarkup);
$this->assertStringContainsString("super-admin.tenants.branches.route-settings.index", $branchMarkup);

$layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.tsx'));
$this->assertStringNotContainsString("label: 'Configuración de rutas'", $layout);
$this->assertStringNotContainsString('routes.branch-collection-settings', $layout);
```

- [ ] **Step 2: Implement the Superadmin page**

Adapt the existing form controls into `BranchRouteSettings.tsx`, changing only its layout/context:

```tsx
<SuperAdminLayout title={`Rutas - ${branch.name}`}>
  <Link href={route('super-admin.tenants.branches', tenant.id)}>Volver a sucursales</Link>
  <form onSubmit={(event) => { event.preventDefault(); form.put(route(
    'super-admin.tenants.branches.route-settings.update', [tenant.id, branch.id]
  )); }}>
```

Preserve the two current workflows, method selection rules, legacy-policy notice, and disabled future option. Do not duplicate policy validation in React.

- [ ] **Step 3: Add the branch-list action and remove client navigation**

In each branch row, add a link to the route-settings index with tenant and branch IDs. Remove only the client navigation object labeled `Configuración de rutas`; leave all operational Rutas entries unchanged.

- [ ] **Step 4: Remove the orphaned client page and update markup tests**

Delete the old page only after the Superadmin page carries the legacy notice and fields. Point markup assertions to the new file.

- [ ] **Step 5: Run focused HTTP/UI tests**

Run: `php artisan test --env=testing tests/Feature/RouteBranchCollectionSettingsHttpTest.php`

Expected: GREEN, including Superadmin UI props, branch-list action, and absence of client navigation.

### Task 3: Reorganize tenant-scoped route controls and protect persistence

**Files:**
- Modify: `resources/js/Pages/SuperAdmin/Tenants/Form.tsx`
- Test: `tests/Feature/CriticalPosFelFlowTest.php`
- Test: `tests/Feature/RouteCollectionSettingsTest.php`

**Interfaces:**
- Consumes: existing `SuperAdminTenantController` props and update validation unchanged.
- Produces: a Rutas visual section containing all six tenant route settings.

- [ ] **Step 1: Add a failing source/HTTP assertion for the Rutas section**

```php
$markup = file_get_contents(resource_path('js/Pages/SuperAdmin/Tenants/Form.tsx'));
$this->assertStringContainsString('Rutas', $markup);
$this->assertStringContainsString('route_collection_responsibility', $markup);
$this->assertStringContainsString('route_cash_custody_policy', $markup);
```

Use an existing Superadmin tenant update test to assert that the six submitted route values remain stored in `tenant_settings`.

- [ ] **Step 2: Move only visual controls**

Remove responsibility, delivery tracking, and cash custody controls from the Credits block. Place them within the existing Rutas block beside invoicing, stock timing, and FEL eligibility. Keep all TypeScript fields, form defaults, and HTTP payload names unchanged.

- [ ] **Step 3: Run tenant settings and route-reader tests**

Run sequentially:

```powershell
php artisan test --env=testing --filter=RouteCollectionSettings
php artisan test --env=testing --filter=RouteBranchCollectionSettings
php artisan test --env=testing --filter=CriticalPosFelFlow
```

Expected: GREEN. These prove settings still persist and route readers retain their current behavior.

### Task 4: Final focused verification and frontend build

**Files:**
- Test: `tests/Feature/RouteBranchCollectionSettingsHttpTest.php`
- Test: `tests/Feature/RouteBranchCollectionSettingsTest.php`

- [ ] **Step 1: Run all route-setting related suites sequentially**

```powershell
php artisan test --env=testing tests/Feature/RouteBranchCollectionSettingsHttpTest.php
php artisan test --env=testing tests/Feature/RouteBranchCollectionSettingsTest.php
php artisan test --env=testing --filter=RouteGlobal
php artisan test --env=testing --filter=RouteDeliveryBatch
```

Record test and assertion totals from each result. Stop for any unexpected regression; do not change operational services unless the failure directly proves an integration requirement.

- [ ] **Step 2: Build the changed React/TypeScript**

Run: `cmd /c npm run build`

Expected: exit code 0.

- [ ] **Step 3: Perform clean diff checks without staging**

```powershell
git diff --check
git diff --cached --check
git status --short
git diff --name-only
git diff --stat
```

Expected: only route-settings Superadmin relocation files and related focused tests/docs; no migration, build artifact, or B2a change. Do not stage or commit in this task.

## Plan self-review

- Scope coverage: tenant grouping, Superadmin branch endpoint/UI, client-route removal, cross-scope authorization, reader preservation, tests, and build each have an owning task.
- No migration or persistence operation is planned.
- Naming consistency: controller routes, component path, and test expectations all use `super-admin.tenants.branches.route-settings`.
- Review focus mapping: cross-tenant and active-branch cases belong to Task 1; client visibility belongs to Task 2; persistence belongs to Task 3; stale references and build are caught in Task 4.
