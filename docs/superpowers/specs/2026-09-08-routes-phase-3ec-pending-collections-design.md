# Fase 3E-C — Pendientes de cobro de rutas

## Objetivo, alcance y fuentes de verdad

3E-C proporciona seguimiento administrativo para una venta de ruta que ya fue entregada físicamente, continúa sin pago y cuya responsabilidad de cobro es del entregador. El nombre de la bandeja es **Pendientes de cobro de rutas**; nunca “Cuentas por cobrar”.

Este módulo sólo atiende el patrón:

~~~
resultado físico = delivered
collection_responsibility_snapshot = delivery_agent
sale.payment_status = unpaid
sale.amount_paid = 0
sale.is_credit_sale = false
sale.credit_balance = 0
sin route_delivery_collection existente
~~~

Fuentes de verdad inmutables por responsabilidad:

| Hecho | Fuente autoritativa |
|---|---|
| Entrega external | route_external_delivery_reconciliation_items |
| Entrega in-app | route_delivery_stops |
| Cobro postventa real | route_delivery_collections |
| Pago financiero | sale_payments |
| Estado financiero | sales.payment_status y sales.amount_paid |
| Seguimiento operativo | route_pending_collection_cases y route_pending_collection_events |

Un case nunca guarda un saldo financiero autoritativo, nunca crea ni reemplaza una collection/payment y nunca puede marcar una venta como pagada. 3E-C no implementa pagos parciales, split payments, CxC contractual, write-offs, descuentos, devoluciones, reintentos, reversión FEL, arqueo ni liquidación automática.

Quedan fuera de la bandeja normal:

- delivered + unpaid con responsibility pre_seller: anomalía de integridad, no case normal.
- not_delivered + unpaid: incidencia de entrega.
- not_delivered + paid: caso futuro de excepción logística/financiera.
- paid, is_credit_sale=true o credit_balance distinto de cero.

## Elegibilidad común external/in-app

Crear RoutePendingCollectionEligibility como adaptador de lectura. Normaliza las dos fuentes a una proyección común:

| Campo normalizado | External | In-app |
|---|---|---|
| origin | external_reconciliation | in_app_stop |
| origin_id | reconciliation item id | stop id |
| delivered_at | reconciled_at | completed_at |
| original_delivery_user_id | snapshot/entregador de la conciliación | delivery_user_id de la run |
| batch_pre_sale_id | route_delivery_batch_pre_sale_id | route_delivery_batch_pre_sale_id |

La consulta de candidatos exige tenant y branch actuales, entrega delivered, snapshot delivery_agent, venta unpaid sin pagos, no crédito contractual y ausencia de una route_delivery_collection de esa sale. Debe cargar sólo las relaciones necesarias: sale, customer, pre-sale, origen, entregador original y FEL contextual.

La bandeja híbrida se obtiene mediante UNION ALL de:

1. cases persistidos status=open que aún cumplen elegibilidad; y
2. candidatos derivados sin ningún case persistido para su sale.

La segunda rama usa NOT EXISTS sobre route_pending_collection_cases.sale_id. Por ello una sale aparece una sola vez aunque sea histórica. Cases resolved y not_applicable no aparecen. Si un case not_applicable vuelve a tener origen delivered + unpaid, el servicio de corrección lo reabre antes de la lectura; si no sucede, el auditor debe reportarlo.

El aging no se persiste. La consulta calcula días desde delivered_at y clasifica: Hoy, 1–3 días, 4–7 días y 8+ días.

## Esquema persistido

### route_pending_collection_cases

Crear la tabla con:

| Campo | Regla |
|---|---|
| id | clave primaria |
| business_id, branch_id | FKs reales, scope obligatorio |
| sale_id | FK real, único global por case |
| pre_sale_id | FK real, obligatorio |
| route_delivery_batch_pre_sale_id | FK real, obligatorio |
| route_external_delivery_reconciliation_item_id | FK nullable |
| route_delivery_stop_id | FK nullable |
| delivery_origin | external_reconciliation o in_app_stop |
| original_delivery_user_id | FK nullable al entregador original |
| assigned_to | FK nullable a usuario activo del tenant/branch |
| status | open, resolved o not_applicable |
| opened_at | timestamp físico de entrega que abrió/reabrió elegibilidad |
| resolved_at, resolved_by | nullable; evidencia operacional de resolución |
| resolution_route_delivery_collection_id | FK nullable, único |
| next_follow_up_at | nullable |
| not_applicable_at, not_applicable_by, not_applicable_reason | metadata de la última corrección que dejó la entrega no aplicable |
| timestamps | estándar |

Checks PostgreSQL:

- delivery_origin sólo permite external_reconciliation o in_app_stop.
- Exactamente un origen físico: external exige reconciliation item no nulo y stop nulo; in_app exige stop no nulo y external nulo.
- status sólo permite open, resolved o not_applicable.
- resolved exige resolved_at, resolved_by y resolution_route_delivery_collection_id.
- not_applicable exige not_applicable_at, not_applicable_by y not_applicable_reason.
- Cuando el estado actual es open o resolved puede conservarse la última metadata de not_applicable como historial; sólo el estado actual not_applicable la exige. La revisión física existente sigue siendo la evidencia autoritativa del cambio.

Índices:

- único sale_id;
- único route_external_delivery_reconciliation_item_id cuando no nulo;
- único route_delivery_stop_id cuando no nulo;
- (business_id, branch_id, status, next_follow_up_at);
- (business_id, branch_id, status, opened_at);
- (assigned_to, status, next_follow_up_at).

La consistencia cruzada business/branch/sale/pre_sale/origen se revalida en el servicio con filas bloqueadas; una FK aislada no puede expresar todas esas igualdades.

### route_pending_collection_events

Crear una tabla append-only:

| Campo | Regla |
|---|---|
| id | clave primaria |
| route_pending_collection_case_id | FK real, obligatorio |
| business_id, branch_id | scope duplicado para consulta y auditoría |
| type | note, contact, visit, promise, no_response o dispute |
| note | texto; obligatorio para note, promise y dispute |
| occurred_at | timestamp real de la acción |
| recorded_by | FK real a usuario |
| operation_idempotency_key_id | FK nullable y único |
| timestamps | estándar |

Índices: (route_pending_collection_case_id, occurred_at, id) y (business_id, branch_id, occurred_at). No existe UPDATE o DELETE administrativo silencioso en MVP. Una rectificación futura será un evento compensatorio.

## Materialización y máquina de estados

Para operaciones nuevas, el mismo transaction que confirma el resultado delivered sincroniza el case después de que se determine el estado final de la venta:

- delivered + unpaid + delivery_agent: asegurar case open por sale/origen, idempotentemente.
- delivered + paid: no crear case.
- pre_seller + delivered + unpaid: no crear case; generar anomalía de auditoría.

Transiciones autorizadas:

~~~
open -> resolved
open -> not_applicable
not_applicable -> open
~~~

open -> resolved ocurre exclusivamente en la transacción de cobro posterior exitosa, después de crear collection, sale_payment y actualizar la sale a paid.

open -> not_applicable ocurre exclusivamente durante una corrección auditada delivered -> not_delivered sin route_delivery_collection.

not_applicable -> open ocurre exclusivamente durante una corrección auditada not_delivered -> delivered, si la venta continúa unpaid, no es crédito contractual, responsibility es delivery_agent y no existe collection. El servicio conserva la metadata anterior de not_applicable y la revisión física ya existente es la evidencia del cambio. resolved es terminal en 3E-C.

Para históricos no hay backfill en la migration. Al crear primer evento o intentar cobro de un candidato derivado, ensureCaseForEligibleOutcome bloquea origen, sale y case; crea el case con opened_at igual a delivered_at real y continúa la operación. Un futuro comando administrativo puede materializar casos idempotentemente, con dry-run y scope explícito; no forma parte de esta fase.

## Servicios y cobro posterior

### RoutePendingCollectionCaseService

Responsabilidades:

- normalizar y verificar elegibilidad;
- asegurar/reabrir/not-applicable cases bajo lock;
- actualizar assigned_to y next_follow_up_at sólo para cases open;
- crear eventos append-only con llave de idempotencia;
- resolver un case sólo desde una collection financiera válida.

assigned_to debe ser usuario activo del mismo business y branch actual. No se implementan notificaciones, una cola de tareas genérica ni permisos view_own.

### RoutePendingCollectionService

Crear un servicio específico de 3E-C para registrar cobranza posterior. No relaja RouteDeliveryStopService::collect ni los servicios de ejecución viva.

El servicio recibe un case persistido o materializa un candidato histórico dentro de la misma transacción. Bloquea case, origen físico y sale; exige:

- actor activo, mismo business y current_branch_id;
- permiso routes.pending_collections.collect;
- origen delivered y snapshot delivery_agent;
- sale unpaid, amount_paid=0, no sale_payment y no route_delivery_collection;
- importe exactamente igual a sale.total;
- método cash, card, transfer o check;
- collected_by activo, mismo business y branch;
- idempotency key de la operación.

Reutiliza el núcleo transaccional financiero de RouteDeliveryCollectionService mediante un contexto/policy post_delivery, no duplicando la creación de collection, payment, sale ni custody. El contexto post_delivery no exige que una run permanezca open, no exige que el actor sea el entregador original y no permite un origen not_delivered. Debe conservar las constraints existentes: una collection por sale, un payment por collection y movimiento de efectivo único cuando aplica.

recorded_by siempre es el actor. El flujo normal fija collected_at al NOW del servidor. Si payload solicita fecha/hora explícita anterior o collected_by distinto del actor, exige routes.delivery_collections.override y override_reason no vacío; la razón se persiste en la collection.

## Caja y custodia

No existe guard global de caja para 3E-C:

| Cobro | Caja abierta | Custodia | Movimiento |
|---|---|---|---|
| transfer, card o check | no | not_applicable | ninguno |
| cash fuera de sucursal | no | held_by_collector | ninguno |
| cash recibido físicamente ahora en sucursal | sí, sesión actual de misma branch | posted_to_branch_cash cuando la policy permite posting inmediato | uno, sesión actual |

Para efectivo en sucursal, receive_cash_in_current_session sólo es válido con confirmación explícita de recepción física, sesión de caja abierta actual y misma business/branch. Registrar el cobro no equivale a ingresarlo a caja. Si no se confirma recepción física, el efectivo queda held_by_collector/awaiting_physical_receipt y se vuelve elegible para 3D. Card, transfer y check nunca participan en settlements 3D.

La policy snapshot de TenantSetting continúa siendo autoridad: collector_custody_until_settlement mantiene efectivo bajo custodia; immediate_branch_register sólo postea si hay confirmación y caja actual válidas. No se crea sesión histórica.

## Resolución, correcciones e invariantes

Tras crear collection, payment y actualizar sale a paid, la misma transacción cambia el case a resolved y guarda la FK de la collection. Un case resolved + sale unpaid es inconsistente. Resolver un case no borra la metadata histórica de una transición anterior a not_applicable.

Las correcciones de resultado físico external e in-app se integran así:

- antes de collection, delivered -> not_delivered: conservar revisión física, no tocar sale/payment/FEL/stock y cambiar case open a not_applicable;
- antes de collection, not_delivered -> delivered elegible: reabrir not_applicable a open;
- con cualquier route_delivery_collection existente: bloquear delivered -> not_delivered antes de escribir la revisión.

No se cambia el resultado cuando su corrección requeriría devolución, reversión de pago, custodia, caja, FEL, stock o reservas.

3E-C no modifica electronic_documents, FEL, stock, stock movements, reservations, customer_credit_accounts, customer_account_movements, customer_credit_payments ni credit_balance. No genera CxC ni una venta POS nueva.

## Permisos y UX

Crear permisos:

- routes.pending_collections.view: bandeja y detalle.
- routes.pending_collections.manage: asignación, próxima acción y eventos.
- routes.pending_collections.collect: cobro posterior.

Reutilizar routes.delivery_collections.override para collected_by distinto o fecha histórica explícita. El rol delivery_agent no recibe estos permisos automáticamente. Cada endpoint valida permiso, actor activo, business, branch actual y recursos del mismo scope; la UI no es autoridad.

Rutas administrativas sugeridas:

~~~
GET  /routes/pending-collections
GET  /routes/pending-collections/{case-or-derived-sale}
POST /routes/pending-collections/{case}/events
PATCH /routes/pending-collections/{case}/assignment
POST /routes/pending-collections/{case}/collect
~~~

La lista muestra resumen de cantidad/importe, aging, cliente, comprobante, fecha de entrega, importe, origen, entregador original, assigned_to, último evento, próxima acción y FEL sólo contextual. El detalle muestra cliente, teléfono, dirección, entrega, venta, estado financiero, historial y acciones REGISTRAR SEGUIMIENTO / REGISTRAR COBRO. Los casos históricos derivados deben poder abrirse por sale/origen y materializarse al primer write.

Las queries usan eager loading y agregados para último evento y resumen; no cargan eventos por fila ni persisten totales derivados.

## Idempotencia, concurrencia y auditor

Cada write usa IdempotencyService y una llave de operación única. Las transacciones bloquean sale, case y origen en un orden documentado: sale, case por sale, origen físico, collection existente. Las constraints constituyen la segunda barrera contra dos cases, dos collections, dos payments o dos movimientos.

SystemIntegrityAuditor añade checks para:

- case duplicado por sale o por origen;
- case open con sale paid, collection existente, origen no delivered o responsibility distinta;
- case resolved con sale unpaid o resolution collection incorrecta;
- not_applicable que sigue delivered + unpaid;
- FK/origin external-in-app inconsistente;
- business/branch/pre-sale/entry/origen/case/event inconsistentes;
- collection que resuelve un case 3E-C sobre origen no delivered; la pertenencia al flujo se identifica por resolution_route_delivery_collection_id, sin agregar un tipo financiero nuevo a la collection;
- pre_seller + delivered + unpaid;
- CxC contractual artificial.

El auditor no invalida combinaciones históricas válidas de fases anteriores, incluyendo not_delivered + collected. Sólo valida que el flujo nuevo 3E-C se origine en delivered.

## Compatibilidad

3A conserva la separación entre método acordado y cobro real. 3B aporta resultados external; 3C aporta stops in-app aun con jornadas closed; 3D recibe después el efectivo held_by_collector. No se reabre route_delivery_run ni una conciliación external para cobrar tarde.
