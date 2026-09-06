# Routes Phase 3B: External Delivery Reconciliation Design

## Goal and scope

Phase 3B records the result of physical delivery managed outside Blunk and, only for a delivery agent responsible for collection, the real full collection after the sale exists. It applies only to document batches whose operation snapshot says \`route_delivery_tracking=external\`.

It does not implement delivery runs, partial delivery or payment, returns, liquidation/settlement, stock reversal, reservations reversal, FEL cancellation, sales cancellation, CxC, maps, stops or GPS. POS remains functionally unchanged.

\`RouteDeliveryBatch\` remains a **Lote de comprobantes**. Its existing \`delivered_by\` and \`delivered_at\` are document-batch metadata from Phase 3A, not evidence that goods physically reached a customer. The Phase 3B reconciliation item is the physical-delivery source of truth.

## Source of truth

| Concern | Source of truth | Projection/effect |
| --- | --- | --- |
| Physical external delivery | \`route_external_delivery_reconciliation_items\` | delivered/not-delivered; no item means pending |
| Real collection before a sale | \`route_pre_sale_collections\` | Phase 3A provenance |
| Real collection after a sale | \`route_delivery_collections\` | exactly one linked financial payment |
| Financial payment | \`sale_payments\` | \`sales.amount_paid\` and \`sales.payment_status\` |
| Branch cash physically posted | \`cash_movements\` linked to a collection | cash is in a session only when this exists |

There is no generic collection and no reuse of \`route_pre_sale_collections\`. That table continues to mean money received before the sale exists. A \`route_delivery_collection\` means money received after the sale exists. \`sale_payments\` remains financial evidence, not an alternative collection source.

## Snapshots and historical compatibility

Add to \`route_delivery_batches\`:

| Field | Values |
| --- | --- |
| \`delivery_tracking_snapshot\` | \`external\`, \`in_app\` |
| \`collection_responsibility_snapshot\` | \`pre_seller\`, \`delivery_agent\` |
| \`operation_settings_snapshotted_at\` | timestamp |

\`RouteDeliveryBatchService::deliverAll()\` snapshots both settings inside the transaction that creates the batch, before creating its receipt entries. This is the correct point because that operation chooses receipt responsibility and the eventual delivery workflow. \`RouteDeliveryBatchController\` must read this snapshot, replacing its current direct read of today's \`TenantSetting.route_collection_responsibility\`.

The migration leaves existing rows null: it must not backfill from a current setting. For a pre-snapshot batch entry, derive responsibility only from durable evidence:

1. a \`sale_payment.route_pre_sale_collection_id\` link means \`pre_seller\`;
2. an unpaid receipt with no payment and no such link means \`delivery_agent\`;
3. a paid receipt without that link, conflicting/missing payment evidence, or missing sale means **Revisión administrativa requerida**.

An old batch also lacks a durable delivery-tracking snapshot. It is therefore review-required rather than inferred as external from current settings. The endpoint blocks automatic reconciliation. A future, separately designed, auditable legacy attestation may make such history eligible; 3B does not invent one.

## Final persistence model

### Reconciliation header

Create \`route_external_delivery_reconciliations\`:

~~~
id, business_id, branch_id, route_delivery_batch_id
opened_by, opened_at, completed_at, timestamps
UNIQUE(route_delivery_batch_id)
INDEX(business_id, branch_id, route_delivery_batch_id)
~~~

One header is created on first line save. It does not need a persisted business state: UI derives \`pending\` when it has no items, \`in_progress\` when eligible entries remain, and \`conciled\` when all eligible entries have items. \`completed_at\` records first completion.

### Reconciliation item: physical delivery

Create \`route_external_delivery_reconciliation_items\`:

~~~
id, business_id, branch_id, route_external_delivery_reconciliation_id
route_delivery_batch_pre_sale_id UNIQUE, pre_sale_id, sale_id
delivery_tracking_snapshot, collection_responsibility_snapshot
delivery_status: delivered | not_delivered
not_delivered_reason: nullable customer_absent | customer_rejected |
  address_issue | business_closed | damaged_goods | other
notes nullable, reconciled_by, reconciled_at, timestamps
INDEX(business_id, branch_id, sale_id)
~~~

It copies the relevant snapshots, so it remains interpretable independently of tenant settings. Delivery \`pending\` is derived by absence of an item; no \`cancelled\` state exists. A \`not_delivered\` item needs one catalog reason; \`other\` requires a non-blank note. A \`delivered\` item has neither a reason nor a reason-required note.

Delivery and money are independent, so these are all valid:

| Delivery | Financial result |
| --- | --- |
| delivered | collected |
| delivered | not_collected |
| not_delivered | collected — administrative incident, no automatic reversal |
| not_delivered | not_collected |

The financial label is derived: \`already_collected\` from a Phase 3A pre-sale payment, \`collected\` from a delivery collection/payment, \`not_collected\` from an unpaid delivery-agent receipt, or \`review_required\` from ambiguity. It is not a new persisted payment-state enum.

### Delivery collection: real post-sale collection

Create \`route_delivery_collections\`:

~~~
id, business_id, branch_id, sale_id UNIQUE, pre_sale_id
route_external_delivery_reconciliation_item_id UNIQUE
collected_by, recorded_by, amount, payment_method, reference, details, collected_at
cash_custody_policy_snapshot: collector_custody_until_settlement | immediate_branch_register
custody_status: held_by_collector | posted_to_branch_cash | not_applicable
cash_posting_state: not_applicable | awaiting_physical_receipt | posted_to_current_session
physical_branch_receipt_confirmed_at nullable
physical_branch_receipt_confirmed_by nullable
cash_register_session_id nullable, cash_movement_id nullable UNIQUE
operation_idempotency_key_id nullable, override_reason nullable, timestamps
INDEX(business_id, branch_id, sale_id)
~~~

Add nullable \`sale_payments.route_delivery_collection_id\`, unique and FK-linked to this table. The relation is directional — \`SalePayment::routeDeliveryCollection()\` belongs to the collection and \`RouteDeliveryCollection::salePayment()\` has one payment — to avoid a circular foreign key. POS payments retain null route provenance.

The service accepts only a sale that is fully unpaid and has no existing payment. The requested amount must equal the entire sale total; Phase 3B has neither partial nor split payments. Unique sale/item/payment-link/movement constraints reinforce transaction locking against races.

## Service boundaries, transitions and idempotency

\`RouteExternalDeliveryReconciliationService::reconcileItem(RouteDeliveryBatch $batch, RouteDeliveryBatchPreSale $entry, array $data, User $actor): IdempotencyResult\` accepts a client \`idempotency_key\`, delivery result, optional not-delivered reason/note, and delivery-agent collection data.

It requires \`routes.external_delivery.reconcile\`, current business and active branch ownership, an external snapshot, an unambiguous responsibility, and an existing route receipt sale. In one transaction it locks the batch, entry, sale, header/item, relevant collection/payment rows, and the active cash session when applicable. It uses \`operation_idempotency_keys\` with operation \`route_external_delivery_reconcile_item\`.

For \`pre_seller\`, it persists the physical result only and rejects collection fields. It creates no payment, cash movement, CxC, FEL, stock or reservation effects.

For \`delivery_agent\`, \`collected=false\` writes just the item. \`collected=true\` calls \`RouteDeliveryCollectionService::captureFull()\` inside the same transaction. It is permitted for either physical result, including \`not_delivered + collected\`.

The collection service locks the item and sale, validates real method, exact full amount, timestamp and collector, writes the collection plus exactly one linked \`sale_payment\`, then updates the normal paid projection. A replay returns its first stored result; a mismatching repeat key is rejected by the normal idempotency contract. A second key cannot create a duplicate due to unique constraints and locks.

No collection ever creates \`customer_credit_accounts\` or customer account movements.

## Cash custody: exact temporal behavior

The custody policy is snapshotted when the collection is actually recorded. Card, transfer and check always use \`custody_status=not_applicable\`, \`cash_posting_state=not_applicable\`, null cash session/movement, and no cash movement.

| Cash path | Custody | Posting state | Cash session/movement |
| --- | --- | --- | --- |
| \`collector_custody_until_settlement\` | held_by_collector | awaiting_physical_receipt | null / none |
| \`immediate_branch_register\` and explicit physical receipt now | posted_to_branch_cash | posted_to_current_session | locked current open session / exactly one movement |
| \`immediate_branch_register\` without receipt now | held_by_collector | awaiting_physical_receipt | null / none |

The confirmation is backend-validated and allowed only for cash with a currently open branch session. Store confirmation time and authenticated confirming user. The UI copy must say:

> Confirmo que este efectivo está siendo recibido físicamente ahora en la caja abierta actual. Se registrará en esta sesión; no corresponde a una caja histórica.

If cash was collected earlier and is not physically received now, the user can still record the real full payment. It stays explicitly \`held_by_collector\` / \`awaiting_physical_receipt\`, with no movement and no session. It is not \`posted_to_branch_cash\` and awaits the future settlement/custody flow. The backend rejects a posting request without both confirmation and a locked current session; it never chooses a conveniently open or invented historical session.

## Audited corrections

Create \`route_external_delivery_reconciliation_item_revisions\`:

~~~
id, business_id, branch_id, route_external_delivery_reconciliation_item_id
version, previous_values JSONB, new_values JSONB
correction_reason, corrected_by, corrected_at, timestamps
UNIQUE(route_external_delivery_reconciliation_item_id, version)
~~~

Normal lines are closed to ordinary editing. Only \`routes.external_delivery.reconcile.correct\` may call \`RouteExternalDeliveryReconciliationCorrectionService::correctDeliveryResult()\`. It requires a reason, locks the item, saves old and new delivery fields (\`delivery_status\`, reason, notes) as an append-only revision, increments version, and then updates the item's current delivery projection in the same transaction.

Safe 3B corrections are delivery-result-only changes: for example \`not_delivered/customer_absent → delivered\`, or fixing the failed-delivery reason/note. Existing payment does not block such a correction because money and delivery are intentionally independent.

The service blocks changing/deleting/reversing a delivery collection, sale payment, method, amount, collector, custody, cash confirmation/movement, FEL, sale status, inventory, reservations or CxC. It also never auto-refunds a \`not_delivered + collected\` incident. Those requests receive an explicit message that a future administrative financial/logistics reversal flow is required.

## Permissions and UX

Add:

~~~
routes.external_delivery.reconcile
routes.external_delivery.reconcile.correct
routes.external_delivery.collection.override
~~~

The override lets the authenticated recorder register another valid tenant user as the actual collector. It requires explicit \`collected_by\`, \`collected_at\` and non-empty \`override_reason\`. \`collected_by\` is who received the money; \`recorded_by\` is always the signed-in user. Validate active user, business, branch and routes authorization; reject cross-tenant/branch users.

Keep **Lotes de comprobantes → Conciliar entrega**. The batch shows count-based progress, e.g. “17 de 20 conciliados · 3 pendientes.” A row shows customer, total, FEL context, financial state, snapshot/derived responsibility, existing method/collector, delivery result, reason and notes. Only an unpaid delivery-agent row exposes Cobrado/No cobrado and the full collection form. Each row saves independently with an idempotency key. Legacy review-required rows explain why saving is blocked.

## Integrity auditor and invariants

\`SystemIntegrityAuditor\` currently expects every cash sale payment to match a \`cash_movements.reference_type=sale\` row. It must instead:

- accept a cash payment linked to a held \`route_pre_sale_collection\` without a movement;
- accept a cash payment linked to a held \`route_delivery_collection\` without a movement;
- validate a posted route collection against its single collection-referenced movement, amount, business and branch;
- retain the existing \`reference_type=sale\` requirement for ordinary POS cash payments.

It must still flag missing, duplicate, wrong amount, wrong tenant/branch or incompatible-custody movements. This narrows an audited route exception and does not weaken POS.

FEL remains independent of physical delivery, payment, cash, custody, stock, reservations and CxC. Reconciliation/correction never certifies or cancels FEL. No Phase 3B operation changes stock, inventory movement, picked quantities, reservations, sale cancellation or CxC.

## Risks and decision status

The only new consequence is safe handling of batches created before snapshot fields existed: their tracking context cannot be reconstructed from today’s settings, so automated reconciliation is blocked pending a future auditable legacy-attestation flow. This does not block new Phase 3B operations. The future settlement flow must consume only explicitly held cash collections and create one movement; it must never post them into a historical or arbitrary current session. No functional decision is still required for Phase 3B implementation.
