# Dashboard A2 — Professional profiles

## Objective

Extend the capability-driven dashboard foundation introduced in `64951d3` with
professional dashboard compositions for executive, warehouse, and route
supervisor work. This is composition only: it never authorizes data access.

## Non-negotiable authorization boundary

`DashboardContext` remains the only input to widget visibility. A widget is
executed only when its required enabled modules and effective permissions are
present. `DashboardProfileResolver` selects an ordered composition of widget
keys; it cannot expose a widget that `DashboardWidgetDefinition::isVisibleTo()`
would hide, and it does not query a role name for access.

The application currently stores one standard role slug on `users.role`; it
does not have a native multi-role assignment model. Therefore the resolver
treats a known standard slug only as a deterministic preference after the
capability requirements validate. Direct permissions and custom roles choose a
profile solely through the same capability predicates.

## Profiles and deterministic preference

The resolver evaluates valid compositions in this order:

1. `route_supervisor`: routes module, `routes.view`,
   `routes.pre_sales.admin_view`, and `routes.delivery_runs.view`.
2. `warehouse`: inventory module and `inventory.view`.
3. `executive`: POS module and `reports.sales.view`.
4. `owner_simple`: safe fallback.

Standard slugs may express a preference among already-valid profiles:
`stock_manager` prefers warehouse; `reports` prefers executive; `owner` and
`admin` prefer executive unless the stronger route-supervisor capability set is
also valid. Unknown/custom slugs never block a valid capability-derived
profile. This gives route operations priority over warehouse, warehouse over
management reporting, then the safe fallback.

## Widget catalog and reuse

Existing sales, stock, cash, top-product, and margin providers are reused.
New small providers are allowed only for measurements that do not already have
a provider:

| Widget | Source of truth | Scope | Guard |
| --- | --- | --- | --- |
| `credits.receivable` | `customer_credit_accounts.current_balance > 0` (same source as Accounts Receivable) | business-wide, because accounts are read business-wide today | `credits` + `credits.accounts.view` |
| `stock.active_reservations` | `stock_reservations.status = active` | active branch when branches are enabled; otherwise business | `inventory` + `inventory.view` |
| `routes.open_work_days` | `route_work_days.status = open` | active branch when scoped | `routes` + route-supervisor capabilities |
| `routes.pending_visits` | `route_visits.status = pending` | active branch when scoped | same |
| `routes.operational_pre_sales` | unconverted `submitted`, `processing`, or `picked` `pre_sales` | active branch when scoped; matches the operational states used by global routes | same |
| `routes.pending_delivery_stops` | `route_delivery_stops.status = pending` | active branch when scoped | same |
| `routes.effective_settlements` | `route_cash_settlements.status = confirmed` | active branch when scoped | same |

`reversed` settlements are expressly excluded from `routes.effective_settlements`:
they are no longer cash effectively received by the branch. The metric is not a
cash-register balance and does not aggregate draft, cancelled, or reversed rows.

CxC is included because `CustomerCreditAccount` is already the authoritative
and reusable backing model for the existing Accounts Receivable page. No new
credit formula, account, or reporting model is introduced.

Professional compositions are:

- `executive`: today/month sales, today's estimated margin, top product, stock
  alerts, and CxC when its guard permits it.
- `warehouse`: stock alerts and active reservations.
- `route_supervisor`: open work days, pending visits, operational pre-sales,
  pending delivery stops, and effective settlements.
- `owner_simple`: the existing A1 compact composition, unchanged.

## UI contract

There remains exactly one Inertia React page, `Dashboard.tsx`. The server sends
the profile key plus presentation metadata (title and description); the client
never presents a profile selector or persists a profile/layout preference.
The same generic widget renderer handles existing and A2 `count` / `money`
cards responsively without duplicating providers or widget data.

## Explicit exclusions

No migration, dependency, persistent layout, profile selector, purchase-order,
replenishment, multi-warehouse, user×branch matrix, or new enterprise domain is
part of A2. Existing pages remain the action surfaces; the dashboard is read
only.
