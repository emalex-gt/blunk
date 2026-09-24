# Route Settings in Superadmin Design

## Goal

Make Superadmin the only administrative surface for route configuration while preserving the existing persistence scopes and every operational reader.

## Scope and ownership

### Tenant-scoped settings

These remain columns on `tenant_settings`, managed from `SuperAdmin/Tenants/Form` through the existing `SuperAdmin\\TenantController` update route:

| Setting | Scope | Operational readers |
| --- | --- | --- |
| `route_pre_sale_stock_deduction_timing` | Tenant | preparation, delivery batch, route invoice |
| `route_pre_sale_invoicing_mode` | Tenant | preparation, delivery batch, route controller |
| `route_pre_sale_require_fel_eligible_customer` | Tenant | receipt, invoice, delivery batch |
| `route_collection_responsibility` | Tenant | delivery batch and route collection services |
| `route_delivery_tracking` | Tenant | delivery batch and route controller |
| `route_cash_custody_policy` | Tenant | route collection and settlement services |

The tenant form will visually regroup all six under a single **Rutas** section. This is a presentation-only reorganization: field names, validation, defaults, controller payloads, and readers remain unchanged.

### Branch-scoped settings

`route_branch_collection_settings` remains a one-to-one relation to `branches` with:

- `collection_workflow_mode`
- `allowed_payment_methods`
- `primary_payment_method`

It is edited only from a Superadmin page whose branch is explicitly selected by the URL:

- `GET /super-admin/tenants/{business}/branches/{branch}/route-settings`
- `PUT /super-admin/tenants/{business}/branches/{branch}/route-settings`

The new page displays the tenant and branch, the existing two supported workflow choices, allowed payment methods, the primary method, and the existing disabled manifest/reconciliation future option.

## Authorization and scope boundary

Both routes live inside the existing `auth` + `super.admin` route group. The controller receives bound `Business $business` and `Branch $branch`, verifies `branch.business_id === business.id`, and passes the explicit business and branch to `RouteBranchCollectionSettingsService`.

It must never use `currentBusinessId()`, `current_branch_id`, or `BranchInventory::activeBranch()` to select the edited branch. A Superadmin can configure any branch belonging to the URL tenant regardless of its own active client-branch session state.

Tenant users do not receive a replacement operational permission: `routes.pre_sales.admin_view` is not an authority for structural route policy changes.

## Canonical UI surface

The existing branch list at `Superadmin → Tenant → Sucursales` gains an explicit **Configuración de rutas** action per branch. The linked page uses `SuperAdminLayout`, has a backlink to that tenant's branch list, and is the sole writer UI for the branch policy.

The client menu entry **Configuración de rutas** is removed. The two former client routes under `/routes/branch-collection-settings` are removed rather than redirected, so an operational user cannot continue modifying the policy via an undisclosed endpoint. The legacy controller and client page are removed or replaced; no route points to them.

## Persistence and domain guarantees

No migration, backfill, reset, default alteration, or data move is required. The implementation keeps `RouteBranchCollectionSettingsService` as the single policy write/read authority and preserves all current tenant setting values.

No operational financial or inventory service changes are allowed. In particular this work does not alter delivery batches, policy snapshots, payment-method expectations, immediate payment, per-order collection, SalePayment, CashMovement, FEL, CxC, or settlements.

## Verification contract

Focused HTTP and markup tests prove:

1. Superadmin opens and updates branch A's settings.
2. Updating A does not mutate B.
3. A non-current branch can be selected through the Superadmin URL.
4. A branch URL nested beneath another business is rejected.
5. A tenant/operational user cannot reach the new endpoints.
6. The old client routes no longer resolve for editing and client navigation no longer includes the entry.
7. Existing tenant-scoped route values persist through the Superadmin tenant form.
8. `RouteBranchCollectionSettingsService` still reads the unchanged stored policy.

Run each relevant PHPUnit suite sequentially, then build the frontend because TSX changes. No full suite is required unless a focused regression demonstrates a shared failure.

## Non-goals

- Branch-specific copies of tenant settings.
- A second policy table or API.
- Redirect compatibility for the removed client settings URL.
- Task 8, 3H, historical document changes, or B2a work.
