# Fase 3E-A — Faltantes y sobrantes en liquidaciones de efectivo

## Estado, objetivo y límites

Esta especificación es autoritativa para 3E-A. Extiende 3D para registrar la realidad física cuando el efectivo contado y recibido en sucursal difiere de las `route_*_collections` reservadas en una liquidación. No implementa reversas administrativas generales, reclasificación contable, CxC de clientes, deuda laboral, arqueo, tolerancias, terceros como contraparte, ni asignación de una variance a una collection individual.

El principio inmutable es:

`customer payment != collector accountability != branch cash receipt`

La collection sigue siendo el hecho de que el cliente pagó; `sale_payments` sigue siendo el hecho financiero de la venta; el settlement sigue siendo la entrega física agregada; y una variance representa exclusivamente una incidencia interna de custodia entre collector y sucursal.

3E-A no modifica `sales`, `sale_payments`, `route_pre_sale_collections` ni `route_delivery_collections` como hechos de cobro, FEL, `electronic_documents`, stock, stock movements, reservations, `customer_credit_accounts`, `customer_account_movements`, `customer_credit_payments`, `credit_balance` ni los pending collection cases de 3E-C. Nunca vuelve una venta a `unpaid`, nunca crea CxC y nunca convierte la variance en deuda legal, laboral o salarial.

## Auditoría de 3D y cambio de semántica

La migración `2026_09_08_000014_create_route_cash_settlements.php` contiene `route_cash_settlements_state_check`: un settlement `confirmed` exige movimiento, sesión, actores, timestamps, `received_amount = expected_amount` y `difference_amount = 0`. `RouteCashSettlementService::confirm()` recalcula el esperado desde los items bloqueados y rechaza diferencia distinta de cero; `CashSettlements/Show.tsx` deshabilita el botón y `SystemIntegrityAuditor` la reporta como crítica.

3E-A reemplaza esa parte de la regla, sin editar la migración histórica 3D:

- exacto: `received_amount = expected_amount`, `difference_amount = 0`, settlement confirmado y sin variance;
- variance: `received_amount > 0`, `received_amount != expected_amount`, `difference_amount = received_amount - expected_amount`, settlement confirmado y exactamente una variance compatible;
- cero recibido con esperado positivo: permanece `draft`; no crea movimiento, no cambia custodia y no crea variance;
- cancelado: conserva la semántica 3D, sin movimiento ni variance.

La confirmación con variance declara: “la sucursal recibió físicamente `received_amount` y terminó la custodia agregada de las collections seleccionadas; la diferencia se convirtió en una incidencia interna de liquidación”. No declara un pago parcial de cliente ni asigna billetes a una collection específica.

`CashRegister::recordMovement()` persiste importes decimales con signo y `CashRegister::expectedCash()` suma todos los movimientos; por tanto soporta una entrada positiva de faltante resuelto y una salida negativa de sobrante devuelto. 3E-A debe ampliar la lista de tipos negativos permitidos del auditor y sus agrupaciones de presentación, para que un movimiento negativo autorizado no sea marcado como inválido. La caja siempre representa efectivo físico real.

## Fuente de verdad y cadena de custodia

| Hecho | Fuente autoritativa |
| --- | --- |
| Cliente pagó | `route_pre_sale_collections` o `route_delivery_collections` y su `sale_payment` |
| Collections reservadas | `route_cash_settlement_items.amount_snapshot` activos |
| Efectivo recibido en la primera entrega | `route_cash_settlements.cash_movement_id` y su `cash_movements.amount = received_amount` |
| Diferencia de custodia | `route_cash_settlement_variances.difference_amount` validado contra settlement |
| Efectivo físico posterior | `route_cash_settlement_variance_resolutions` y su movimiento único |

Al confirmar, exacto o con variance, cada collection activa hace `held_by_collector -> posted_to_branch_cash`. No se introduce `settled_with_variance`. Aquí `posted_to_branch_cash` significa que la custodia de ruta se cerró mediante una liquidación física confirmada; no afirma que exista una correspondencia física uno-a-uno entre la collection y un billete concreto. La evidencia completa es:

`collection -> active settlement item -> confirmed settlement -> original cash movement -> optional variance -> optional resolutions`

`collector_user_id` de la variance es responsabilidad operativa de custodia, no una cuenta por cobrar, préstamo, descuento salarial ni obligación jurídica automática.

## Esquema persistente

### `route_cash_settlement_variances`

Una fila representa una diferencia histórica, inmutable en importe y origen, y existe como máximo una vez por settlement.

| Campo | Regla |
| --- | --- |
| `id` | clave primaria |
| `business_id`, `branch_id` | FKs reales; deben coincidir con settlement |
| `route_cash_settlement_id` | FK real, `UNIQUE`, no nullable |
| `collector_user_id` | FK real a user; debe coincidir con settlement |
| `difference_amount decimal(14,2)` | firmado, no cero, snapshot inmutable de settlement |
| `status varchar(16)` | `open` o `resolved` |
| `reason_code varchar(32)` | obligatorio y compatible con signo |
| `explanation text` | obligatorio, `btrim(explanation) <> ''` |
| `assigned_to` | FK nullable a user activo del mismo scope; responsabilidad operativa |
| `opened_by`, `opened_at` | obligatorios; actor y momento de aceptación |
| `resolved_by`, `resolved_at`, `resolution_note` | todos null mientras `open`; todos obligatorios cuando `resolved` |
| timestamps | estándar |

No se persiste `type`: `difference_amount < 0` se presenta como `shortage`; `difference_amount > 0`, como `overage`. No se persiste saldo: `remaining_amount = abs(difference_amount) - SUM(resolutions.amount)`.

Checks locales:

- `difference_amount <> 0`;
- `status IN ('open', 'resolved')`;
- shortage acepta `counting_difference`, `collector_reported_loss`, `missing_cash`, `other`;
- overage acepta `counting_difference`, `unidentified_extra_cash`, `other`;
- `explanation` no vacía;
- `open` conserva metadata de resolución nula; `resolved` exige `resolved_by`, `resolved_at` y nota no vacía.

Índices: `route_cash_settlement_id UNIQUE`, `(business_id, branch_id, status, opened_at)`, `(business_id, branch_id, collector_user_id, status)`, `(business_id, branch_id, assigned_to, status)`.

### `route_cash_settlement_variance_events`

Historial append-only de investigación, no ledger financiero. Campos: `id`, `variance_id` FK real, `business_id`, `branch_id`, `type`, `note`, `occurred_at`, `recorded_by`, `operation_idempotency_key_id nullable unique`, timestamps.

`type IN ('note', 'investigation', 'collector_contact')`; `note` y `occurred_at` son obligatorios. Scope de evento y variance debe validarse por servicio y auditor. No hay edición ni borrado silencioso; una aclaración se registra como evento nuevo. Índice `(variance_id, occurred_at, id)` y `(business_id, branch_id, occurred_at)`.

### `route_cash_settlement_variance_resolutions`

Ledger append-only de entradas o salidas físicas posteriores. Campos: `id`, `variance_id` FK real, `business_id`, `branch_id`, `type`, `amount decimal(14,2)`, `cash_register_session_id`, `cash_movement_id unique`, `counterparty_user_id`, `recorded_by`, `occurred_at`, `note`, `operation_idempotency_key_id nullable unique`, timestamps.

Checks locales:

- `type IN ('shortage_cash_received', 'overage_cash_returned')`;
- `amount > 0`;
- sesión, movimiento, contraparte, actor, momento y nota no nulos;
- la contraparte es el `collector_user_id` original de la variance, validado por servicio y auditor.

Cada resolution crea exactamente un movimiento con `reference_type = 'route_cash_settlement_variance_resolution'`, `reference_id = resolution.id` y relación uno-a-uno mediante `cash_movement_id UNIQUE`.

Índices: `(variance_id, occurred_at, id)`, `(business_id, branch_id, occurred_at)`, `cash_movement_id UNIQUE` y `operation_idempotency_key_id UNIQUE`.

## Migración y trigger diferido PostgreSQL

La implementación crea migraciones nuevas; nunca edita `2026_09_08_000014`:

1. crear variances;
2. crear events;
3. crear resolutions;
4. sustituir `route_cash_settlements_state_check`;
5. crear función y constraint triggers diferidos de coherencia settlement-variance.

El nuevo check de settlement conserva todos los requisitos de `draft`, `confirmed` y `cancelled`, pero para confirmado exige:

```sql
received_amount IS NOT NULL
AND difference_amount IS NOT NULL
AND difference_amount = received_amount - expected_amount
AND cash_movement_id IS NOT NULL
AND cash_register_session_id IS NOT NULL
AND received_by IS NOT NULL
AND confirmed_by IS NOT NULL
AND confirmed_at IS NOT NULL
```

No puede expresar una relación entre tablas. Por eso se implementa una función PostgreSQL `validate_route_cash_settlement_variance_integrity()` con constraint triggers `DEFERRABLE INITIALLY DEFERRED` sobre `route_cash_settlements` y `route_cash_settlement_variances` para validar al final de cada transacción:

- settlement confirmado exacto: ninguna variance;
- settlement confirmado con diferencia: exactamente una variance;
- variance: mismo settlement, business, branch, collector y diferencia firmada;
- settlement no confirmado: ninguna variance.

Los triggers deben dispararse `AFTER INSERT OR UPDATE OR DELETE` en ambas tablas. La migración se prueba con `migrate:fresh --env=testing`; su `down()` elimina triggers y función antes de tablas/constraint, y restaura el check histórico sólo si no hay settlement confirmado con diferencia. En una reversión con datos de 3E-A se debe abortar con mensaje claro en vez de borrar evidencia financiera.

## Estados, transiciones y asignación

Settlement conserva `draft -> confirmed|cancelled`. Confirmed sigue terminal y sólo lectura; una variance no reabre ni modifica el settlement.

Variance:

`open -> resolved`

Mientras el saldo derivado sea positivo permanece `open`, incluso tras resoluciones parciales. Cuando el saldo es exactamente cero, el servicio marca `resolved` junto con actor, timestamp y nota. No existe cierre manual, `cancelled`, write-off ni reapertura. La acción de asignar o desasignar `assigned_to` no cambia estado; exige user activo de mismo business y branch y permiso `routes.cash_variances.manage`.

Un conteo erróneo se corrige antes de confirmar editando el importe del draft. Tras confirmación, una difference es evidencia histórica; una corrección que no represente entrada o salida física corresponde a 3E-B.

## Confirmación atómica

`RouteCashSettlementService::confirm(...)` conserva su ruta exacta y acepta un contexto opcional de variance sólo cuando el importe difiere. El controlador nunca confía en el frontend.

Orden obligatorio de locks en la transacción de confirmación:

`settlement -> active items -> source collections ordered by id -> current cash session -> variance row`

Pasos:

1. Idempotencia por business, branch, actor, operación y llave.
2. Bloquear y revalidar settlement draft, actor, receiver, items, collections, snapshots, collector, scope y reserva exclusiva.
3. Recalcular `expected` y `difference = received - expected`.
4. Exigir `received > 0`; si difference es cero, rechazar payload de variance; si no es cero, exigir ambos permisos de confirmación, `reason_code`, explicación no vacía y confirmación explícita.
5. Exigir sesión abierta actual bloqueada de la misma branch.
6. Crear exactamente un movimiento `route_cash_settlement`, positivo y por `received_amount`, referenciado al settlement.
7. Crear la variance si corresponde, con snapshot firmado y metadata de aceptación.
8. Cambiar las collections a `posted_to_branch_cash`; delivery también queda `cash_posting_state = posted_to_current_session`.
9. Actualizar settlement a confirmado, enlazando sesión y movimiento. El trigger diferido valida la combinación al commit.

Una confirmación exacta no crea variance. Ningún settlement con expected positivo y recibido cero puede llegar al paso 6.

## Resoluciones físicas

Las resoluciones no alteran settlement, collections, sales, payments ni casos 3E-C. Siempre requieren variance abierta, importe positivo no mayor que el saldo derivado, caja abierta actual en business y branch de la variance, contraparte igual al collector original, nota obligatoria, permiso `routes.cash_variances.resolve` e idempotencia.

Orden de locks:

`variance -> existing resolutions ordered by id -> current cash session`

### Faltante

`shortage_cash_received` sólo es válido para `difference_amount < 0`. El collector entrega posteriormente efectivo físico. Se crea una resolution y un único `cash_movement` positivo:

```text
type: route_cash_variance_shortage_received
amount: resolution.amount
reference_type: route_cash_settlement_variance_resolution
reference_id: resolution.id
```

### Sobrante

`overage_cash_returned` sólo es válido para `difference_amount > 0`. La sucursal devuelve efectivo físico al collector original. Se crea una resolution y un único `cash_movement` negativo:

```text
type: route_cash_variance_overage_returned
amount: -resolution.amount
reference_type: route_cash_settlement_variance_resolution
reference_id: resolution.id
```

No se permite tercero, identificación con otra collection, customer payment, reclasificación contable abstracta ni write-off. Si se descubre otro origen, la variance sigue abierta hasta una fase de reclasificación/reversa. El auditor incorpora ambos tipos a su lista permitida de movimientos negativos/positivos de ruta y exige referencia, session y signo correctos.

## Permisos, HTTP y UX

Se mantienen los permisos 3D existentes. Se agregan:

- `routes.cash_settlements.confirm_variance`;
- `routes.cash_variances.view`;
- `routes.cash_variances.manage`;
- `routes.cash_variances.resolve`.

Una confirmación no exacta requiere simultáneamente `routes.cash_settlements.confirm` y `.confirm_variance`. `delivery_agent` y collector no reciben permisos nuevos por rol base. Toda autorización comprueba usuario activo, business actual y `current_branch_id`; HTTP es autoridad.

Rutas administrativas previstas:

- `GET /routes/cash-settlements/variances` — bandeja;
- `GET /routes/cash-settlements/variances/{variance}` — detalle;
- `POST /routes/cash-settlements/variances/{variance}/events` — event append-only;
- `PATCH /routes/cash-settlements/variances/{variance}/assignment` — asignación;
- `POST /routes/cash-settlements/variances/{variance}/resolutions` — entrada/salida física.

El formulario 3D conserva el flujo exacto. Con difference negativa muestra “Se recibieron QX.XX menos que el efectivo esperado”; con positiva, “Se recibieron QX.XX adicionales al efectivo asociado a esta liquidación”. Sólo quien tenga ambos permisos verá la acción de confirmar con faltante/sobrante, que exige razón, explicación y confirmación explícita.

La bandeja `Rutas -> Liquidaciones -> Diferencias` muestra conteos e importes abiertos por signo, settlement, collector, branch, fecha, expected, received, difference, tipo derivado, remaining derivado, aging derivado, assigned_to y status. El detalle muestra settlement e items de sólo lectura, movimiento original, variance, events, resolutions y saldo; nunca lo llama deuda de cliente.

## Idempotencia, concurrencia y auditor

Las operaciones de confirmación exacta/variance, event, assignment y resolution usan `IdempotencyService` con tipos de operación distintos y payload determinista. La clave repetida devuelve el resultado previo sin duplicar fila ni movimiento.

Dos administradores que confirman el mismo settlement, sea exacto o con variance, compiten por el lock de settlement: el primero confirma; el segundo revalida `status != draft` y falla de forma controlada o recibe su replay idempotente. El índice único de variance y el trigger diferido protegen la cardinalidad. Dos resolutions bloquean la misma variance y recalculan saldo después de bloquear el ledger; sólo una puede consumir el último importe disponible.

`SystemIntegrityAuditor` debe detectar, sin debilitar POS ni crédito:

- confirmed difference sin variance y exact confirmed con variance;
- difference, scope, collector o settlement incompatibles;
- variance duplicada;
- movimiento original diferente de received amount, referencia/sesión/tipo inválidos;
- collection no posted tras settlement confirmado;
- resolution de tipo o signo incompatible, scope/contraparte/sesión/movimiento inválidos;
- movimiento de resolution duplicado o sin referencia;
- total resuelto mayor que `abs(difference)`;
- resolved con saldo no cero y open con saldo cero;
- alteración de sales, payments, collections de cliente, CxC o cases 3E-C causada por flujo de variance;
- regresión de settlement exacto 3D y de POS cash negativo no autorizado.

## Compatibilidad e invariantes

No hay backfill. Antes de 3E-A el check 3D impedía confirmed con diferencia; el entorno local no tiene aún tablas 3D para auditar datos históricos, por lo que no se inventan filas. Las liquidaciones exactas existentes permanecen válidas y sin variance. Collections de 3E-C con efectivo `held_by_collector` siguen entrando a 3D de la misma manera; 3E-A sólo actúa tras la selección y confirmación física.
