# Routes Phase 3C: In-App Delivery Runs Design

## Goal and scope

Phase 3C implements the mobile physical-delivery workflow for document batches whose durable snapshot is `route_delivery_tracking=in_app`. It is separate from pre-seller `route_work_days`, preparation, document batches and Phase 3B external reconciliation.

The MVP supports complete delivery or non-delivery, a full post-sale collection only when the delivery agent is responsible, custody traceability, auditable outcome corrections and run closure. It excludes GPS, owned navigation, photos, signatures, partial delivery/payment, split payments, refunds, FEL cancellation, stock/reservation reversal, automatic retries, settlement and cash counting.

## Sources of truth and non-reuse

| Concern | Source of truth |
| --- | --- |
| Documentary batch | `route_delivery_batches` / `route_delivery_batch_pre_sales` |
| In-app physical result | `route_delivery_stops` |
| External physical result | `route_external_delivery_reconciliation_items` |
| Pre-sale collection | `route_pre_sale_collections` |
| Post-sale delivery collection | `route_delivery_collections` |
| Financial payment | `sale_payments` |
| Cash physically posted to branch | `cash_movements` |

`RouteDeliveryBatch.delivered_by`, `RouteDeliveryBatch.delivered_at`, `RouteDeliveryBatchPreSale.status`, and `RouteZone.assigned_user_id` retain their existing documentary/pre-seller meaning. They are never physical-delivery evidence or delivery-agent assignment.

FEL, sale status, CxC, stock, inventory movements, picked quantities and reservations remain independent from a run and stop.

## Eligibility, snapshot authority and exclusion

An entry is assignable only when it belongs to the active business/branch, has a receipt sale in that scope, its batch snapshots are `in_app` and either `pre_seller` or `delivery_agent`, and it has neither external reconciliation item nor in-app stop.

The batch snapshot is authority; later `TenantSetting` changes never reinterpret history. Unsnapshotted batches require administrative review. Existing snapshot-backed `in_app` batches may be adopted when they have no conflicting physical record.

Each `route_delivery_batch_pre_sale` may have one in-app stop, enforced by a unique key. External reconciliation locks the entry and rejects non-`external` snapshots. In-app assignment locks the same entry and rejects non-`in_app` snapshots or an external item. This makes physical source exclusion durable without reusing a generic polymorphic relation.

## Data model

### `route_delivery_runs`

```text
id
business_id FK, branch_id FK
delivery_user_id FK users, created_by FK users
started_by FK users nullable, started_at nullable
closed_by FK users nullable, closed_at nullable
status: draft | open | closed
delivery_tracking_snapshot: in_app
collection_responsibility_snapshot: pre_seller | delivery_agent
timestamps
INDEX (business_id, branch_id, delivery_user_id, status)
PARTIAL UNIQUE (business_id, branch_id, delivery_user_id)
  WHERE status IN ('draft', 'open')
```

A run can contain entries from multiple batches only if business, branch and both snapshots agree. A batch can be divided by assigning distinct entries to different runs. There is no run/batch pivot: distinct batches are derived from stops.

### `route_delivery_stops`

```text
id
business_id FK, branch_id FK, route_delivery_run_id FK
route_delivery_batch_id FK
route_delivery_batch_pre_sale_id FK UNIQUE
pre_sale_id FK, sale_id FK, customer_id FK nullable
customer_name_snapshot, customer_address_snapshot, customer_phone_snapshot nullable
position nullable integer
delivery_tracking_snapshot: in_app
collection_responsibility_snapshot: pre_seller | delivery_agent
status: pending | delivered | not_delivered
not_delivered_reason_code nullable, delivery_notes nullable text
assigned_by FK users, assigned_at
completed_by FK users nullable, completed_at nullable
timestamps
INDEX (route_delivery_run_id, status, position, id)
INDEX (business_id, branch_id, sale_id)
INDEX (route_delivery_batch_id, route_delivery_run_id)
CHECK outcome shape
```

The check requires: pending without completed actor/time or reason; delivered with completed actor/time and null reason, with optional notes; not-delivered with completed actor/time and a catalog reason; and `other` with non-blank notes. Reasons are `customer_absent`, `customer_rejected`, `address_issue`, `business_closed`, `damaged_goods`, and `other`.

Customer fields are assignment-time snapshots for delivery evidence, not fiscal truth.

### `route_delivery_stop_revisions`

```text
id, business_id FK, branch_id FK, route_delivery_stop_id FK
version, previous_values JSONB, new_values JSONB
correction_reason, corrected_by FK users, corrected_at
timestamps
UNIQUE (route_delivery_stop_id, version)
```

### Generalized `route_delivery_collections`

Keep this as the only post-sale delivery collection table. Make `route_external_delivery_reconciliation_item_id` nullable; add nullable, unique `route_delivery_stop_id`; add `delivery_origin: external_reconciliation | in_app_stop`.

Use real FKs and a database check:

```text
external_reconciliation => external item present AND stop absent
in_app_stop             => stop present AND external item absent
```

Keep unique `sale_id`, `sale_payments.route_delivery_collection_id`, and `cash_movement_id`. Thus an external and in-app flow cannot collect the same sale twice.

## States and transitions

```text
Run:  draft -> open -> closed
Stop: pending -> delivered
               -> not_delivered
```

Draft is the only state for manager assignment and ordering. Open belongs to its delivery user and is resumable without a paused state. Closed is read-only except administrative audited correction.

Start requires an assigned active same-branch user and at least one stop. Close requires no pending stops. It does not require payment completeness; delivery-agent runs with delivered unpaid sales show a confirmation warning with count and amount, then may close. Closure never creates CxC, credit, payment or collection.

## Shared services

### `DeliveryOutcomeRules`

Extract one shared rules class for external and in-app:

```php
DeliveryOutcomeRules::normalize(
    string $status,
    ?string $reasonCode,
    ?string $notes,
): array;
```

It validates the shared reason catalog; clears the reason for delivered while preserving optional delivered notes; requires a reason for non-delivery; and requires notes for `other`. External creation, external correction, stop completion and stop correction must use it.

### Assignment, run and stop services

`RouteDeliveryRunAssignmentService` creates drafts, assigns selected eligible entries, and powers the whole-batch UI shortcut by selecting every eligible entry. It locks run/entries in deterministic id order, validates shared snapshots and branch, and relies on unique entry ownership for concurrency safety.

`RouteDeliveryRunService` starts and closes runs. It validates permissions, business, branch, active user and assignment, locks run/stops, requires an open route cash session, and returns SQL-derived progress and warning amounts.

`RouteDeliveryStopService` completes pending stops. A pre-seller stop writes only outcome. A delivery-agent stop may independently call collection capture. Delivery and collection are deliberately independent; all four combinations are valid.

### Collections

Refactor `RouteDeliveryCollectionService` to accept an immutable delivery-source context with business, branch, sale, pre-sale, responsibility and exactly one source FK. The external item and in-app stop caller each lock their source before invoking the shared service.

It retains its exact-full-amount, unpaid-sale, active collector, timestamp, real-method, override and idempotency rules. Database uniqueness plus locks ensure at most one collection, payment and applicable movement.

## Cash custody and operational guard

Draft creation needs no cash session. Starting, operating a stop, recording an outcome, recording collection and closing require `RouteCashOperationGuard::requireOpen()`; backend is authoritative. A session permits operation but never proves that customer cash is physically in a register.

| Path | Custody and movement |
| --- | --- |
| card, transfer, check | `not_applicable`; no movement |
| cash + collector custody | `held_by_collector`; no movement |
| cash + immediate policy + explicit current receipt | `posted_to_branch_cash`; one locked current-session movement |
| cash + immediate policy without confirmation | `held_by_collector` / `awaiting_physical_receipt`; no movement |

Use the approved explicit confirmation copy unchanged.

## Permissions and mobile UX

Create:

- `routes.delivery_runs.view`
- `routes.delivery_runs.execute`
- `routes.delivery_runs.manage`
- `routes.delivery_runs.correct`
- `routes.delivery_collections.override`

Create base role `delivery_agent` with only view and execute. All authorization additionally requires active business, current branch and assigned `delivery_user_id`; no permission is granted merely by the role name. Management, correction and override are never automatic to the role. No multi-branch user model is added.

Mobile flow:

```text
Mis entregas -> jornada activa -> lista de clientes -> detalle
            -> Entregado / No entregado -> Cobrar si corresponde -> Siguiente
```

List and detail show customer snapshot, address, phone, total, receipt, payment state, physical state and FEL only as context. Existing pre-seller payments are read-only. Use large actions, idempotency keys, search, draft-only manual ordering, phone links and the existing safe external Maps/Waze link pattern. No new GPS or navigation capability is introduced.

## Corrections, auditor, historical compatibility and offline

A completed stop has no normal edit. `routes.delivery_runs.correct` requires a correction reason, appends a revision and changes only outcome/reason/notes. Any request that changes collection, payment, cash, custody, FEL, sale, stock, reservation or refund is rejected as future administrative reversal work.

Extend `SystemIntegrityAuditor` for duplicate stops, more than one active run per user/branch, external plus in-app outcomes, incompatible snapshots, collection/payment/movement provenance and uniqueness, closed runs with pending stops, and business/branch/user mismatch. POS cash integrity remains unchanged.

Offline remains excluded. Idempotency covers HTTP retries/double taps, not local outbox, disconnected conflict resolution or synchronization.

## Required invariants

- Pre-seller never collects again in 3C.
- Delivery-agent collection is full only; no partial, split payment or CxC.
- Non-delivery never reverses money, FEL, stock, reservations or sale state.
- FEL has no action in delivery UI.
- Totals/progress are SQL-derived with indexes; they are not mutable counters in this MVP.
