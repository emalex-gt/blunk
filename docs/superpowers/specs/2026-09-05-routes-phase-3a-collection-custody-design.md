# Routes Phase 3A: Collection and Cash Custody Design

## Goal

Separate route pre-sale payment intent, real collection, cash custody, receipt creation, and FEL certification without changing POS behavior.

## Scope

Phase 3A introduces tenant configuration, agreed payment methods, a traceable pre-sale collection record, custody states, and receipt conversion behavior by collection responsibility. It does not implement physical delivery runs, settlements, returns, partial deliveries, maps, GPS, a worker, or POS changes.

## Definitions

- `agreed_payment_method`: the method expected or agreed with the customer. It is not evidence of payment.
- Collection: a real payment received from the customer.
- Custody: the physical location of cash after collection and before settlement.
- Receipt batch: the existing `RouteDeliveryBatch`, created by ENTREGAR TODO. It is a document batch, not proof of physical delivery.

## Tenant Settings

`route_collection_responsibility`:

- `pre_seller`: the pre-seller records real collection during the visit.
- `delivery_agent`: ENTREGAR TODO creates an unpaid receipt; a future delivery or external reconciliation flow records collection.

`route_delivery_tracking`:

- `external`: delivery is managed outside Blunk.
- `in_app`: a future `RouteDeliveryRun` module will manage physical delivery.

`route_cash_custody_policy`:

- `collector_custody_until_settlement` (default): cash is held by the collector and does not create a branch cash movement until liquidation.
- `immediate_branch_register`: cash creates a branch cash movement when collected, before a sale exists.

Existing settings default to `pre_seller`, `external`, and `collector_custody_until_settlement`. They apply only to future operations.

## Payment Method Contract

`pre_sales.agreed_payment_method` replaces the functional meaning of the current `pre_sales.payment_method`. A migration backfills it from the legacy field. The legacy field remains temporarily for historical compatibility and is never treated as proof of payment.

The financial source of truth is the real collection method in `route_pre_sale_collections.payment_method`, then the linked `sale_payments.method` after receipt creation.

## Collections Before Sales Exist

`route_pre_sale_collections` records one active, full collection in Phase 3A. The schema allows future multiple or partial collections, but application rules reject a second active collection and require the amount to equal the pre-sale total.

Required fields:

- `business_id`, `branch_id`, `pre_sale_id`, `route_work_day_id`
- `collected_by`: person who received the money
- `recorded_by`: user who registered it in Blunk
- `amount`, `payment_method`, `reference`, `details`, `collected_at`
- `status`: `captured`, `linked`, `voided`
- `custody_status`: `held_by_collector`, `posted_to_branch_cash`, `not_applicable`, `settled`, `voided`
- `cash_register_session_id`, `cash_movement_id` nullable
- `override_reason`, `void_reason`, timestamps and the existing operation-idempotency record

An administrator may record a collection on behalf of the pre-seller only with `routes.collections.override`, a mandatory reason, and a recorded audit trail. The actual collector remains `collected_by`; the administrator is `recorded_by`.

## Cash Custody

RouteCashOperationGuard remains an operational authorization guard. It does not imply that collected cash is physically inside a branch register.

For cash under `collector_custody_until_settlement`, capture creates a collection with `held_by_collector` and no cash movement. ENTREGAR TODO links the collection to a sale payment without creating a cash movement. A future liquidation creates the single movement and changes custody to `settled`.

For cash under `immediate_branch_register`, capture locks the open branch cash session, creates one cash movement referenced to `route_pre_sale_collection`, and stores the movement id. ENTREGAR TODO creates no second movement.

Card, transfer, and check collections use `not_applicable` custody and never create cash movements.

## Receipt Conversion

For `pre_seller`, ENTREGAR TODO locks the pre-sale and its captured collection. It creates the receipt, creates one linked `sale_payment`, sets `payment_status=paid`, and preserves the custody state. It must not create or infer another collection.

For `delivery_agent`, ENTREGAR TODO creates:

```text
document_type = receipt
payment_status = unpaid
amount_paid = 0
payment_method = null
is_credit_sale = false
credit_balance = 0
```

It creates no collection, sale payment, cash movement, customer credit account, or customer account movement.

`payment_status=unpaid` here means pending operational collection, not contractual credit. The route domain will expose `pending_delivery_collection` so UI and reports do not call it a credit sale.

## Sale Payment Provenance

`sale_payments` currently lacks collector, collection timestamp, source, and cash-session provenance. Phase 3A adds nullable route provenance fields:

- `collected_by`, `collected_at`
- `cash_register_session_id`
- `route_pre_sale_collection_id` unique nullable foreign key

POS keeps creating payments with null route provenance. A collection may derive exactly one sale payment, enforced by the single, directional unique link from `sale_payments` and locked conversion. The collection does not duplicate a `sale_payment_id`, avoiding a circular foreign-key relationship.

## Full-Collection Conversion Guard

Phase 3A supports neither partial delivery nor partial/split collection. A pre-seller collection must equal the receipt total that will be generated. ENTREGAR TODO blocks the batch before posting a sale if picked quantities or pricing make the receipt total differ from the active captured collection. The collection must be formally voided before a corrected collection can replace it. Collection voiding is available only before its receipt is linked; voiding a converted sale remains outside this phase.

## FEL

FEL is independent from collection, cash custody, payment status, physical delivery, stock, reservations, and CxC. Route FEL services must remove the RouteCashOperationGuard requirement. An unpaid route receipt can be certified if it is fiscally eligible and FEL is available.

## CxC

Operationally unpaid route sales do not use `customer_credit_accounts`. CxC remains limited to explicit contractual credit sales through the existing credit flow.

## Historical Compatibility

Existing converted and paid route sales are untouched. Existing picked pre-sales receive only the agreed method backfill; they must use the normal Registrar cobro flow before a `pre_seller` receipt can be paid. There is no inferred or special legacy collection.

## Idempotency and Isolation

Capture uses `operation_idempotency_keys` with `route_pre_sale_collection_capture`. The pre-sale and active collections are locked; duplicate capture is rejected after the complete amount exists. Receipt conversion locks captured collections and uses a unique collection-to-payment relation. Every query scopes business and branch, and the collector must belong to the tenant. A non-seller collector requires the override permission and reason.

## Deferred Work

- physical delivery runs and stops
- external delivery reconciliation
- settlement and cash liquidation UI
- returns, cancellation orchestration, and partial delivery
- split or partial payments
- FEL automation activation
- POS changes
