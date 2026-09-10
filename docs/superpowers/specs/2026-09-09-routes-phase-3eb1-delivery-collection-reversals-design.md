# Fase 3E-B1 — Reversa administrativa de cobro post-sale de ruta

## Estado, propósito y límites

Esta especificación es autoritativa para 3E-B1. Corrige un hecho registrado erróneamente: el sistema afirmó que un cliente pagó una venta de ruta, pero el pago nunca ocurrió. La entrega física, la venta y el comprobante permanecen válidos. La corrección revierte la vigencia de `route_delivery_collection` y su `sale_payment`, proyecta la venta nuevamente como `unpaid` y, cuando corresponde, restaura el seguimiento operacional 3E-C.

No representa un refund ni una devolución real de dinero al cliente. Tampoco implementa reversa de entrega, venta, stock, reserva, FEL, settlement, variance, resolution, CxC, deuda laboral, pago parcial, split payment, refund bancario/tarjeta, ni modificación de una sesión cerrada. `route_pre_sale_collections` y `pre_seller` quedan fuera para una futura 3E-B1b. Una collection incluida en liquidación confirmada queda fuera para 3E-B2.

El alcance comprende sólo `route_delivery_collections` cuyo origen físico sigue siendo `external_reconciliation` o `in_app_stop`, cuya responsabilidad snapshot es `delivery_agent`, y que representan un cobro post-sale completo.

## Fuentes de verdad y proyecciones

| Hecho | Fuente autoritativa |
| --- | --- |
| Entrega física | `route_external_delivery_reconciliation_items` o `route_delivery_stops` |
| Cobro post-sale registrado | `route_delivery_collections` y su `sale_payment` |
| Reversa administrativa del cobro | `route_delivery_collection_reversals` append-only |
| Estado financiero vigente | `sales.payment_status`, `amount_paid`, `payment_method` y readers de registros `captured` |
| Seguimiento operativo | `route_pending_collection_cases` y events 3E-C |
| Efectivo de caja físico | `cash_movements` y `cash_register_sessions` |

Las filas originales nunca se borran. `route_delivery_collections.status` y `sale_payments.status` son proyecciones de vigencia con valores `captured` y `reversed`. Un reader financiero vigente usa sólo `captured`; un reader histórico/auditable puede mostrar ambos estados y su reversal relacionado.

## Auditoría del estado previo

Antes de B1, `route_delivery_collections` no tiene status y PostgreSQL contiene los unique globales efectivos sobre `sale_id` y external reconciliation item. La migración histórica declaró un unique para in-app stop encadenado después de `nullOnDelete()`, pero PostgreSQL no llegó a materializarlo; B1 no intenta eliminar ni restaurar un objeto inexistente. `sale_payments` tampoco tiene estado de reversa. `RouteDeliveryCollectionService` exige una venta unpaid sin payments, crea collection y payment, y actualiza la venta a `paid` con importe completo. Los reportes y el auditor suman `sale_payments` de manera directa. Por ello un ledger puro sería insuficiente: necesita proyecciones explícitas y una revisión dirigida de todos los readers que usan `exists`, `sum`, `count`, `first`, `whereHas` o `doesntHave` sobre payments/collections.

La caja es por sesión: la apertura se introduce manualmente y se registra como movimiento; `expected_cash` es la suma de movimientos de esa sesión; el cierre persiste expected, counted y difference. Una sesión nueva no hereda el cierre anterior. Por ello un movimiento negativo en una sesión posterior falsearía su expected físico.

## Esquema persistente

### Status de collections y payments

Agregar `status varchar(16) not null default 'captured'` a `route_delivery_collections` y `sale_payments`, con check `status IN ('captured', 'reversed')`. La migración inicializa las filas existentes como `captured`; no inventa reversals históricos.

Reemplazar los dos unique globales históricos efectivos y añadir la protección capturada que faltaba para stop mediante índices parciales PostgreSQL:

```sql
CREATE UNIQUE INDEX route_delivery_collections_sale_captured_unique
  ON route_delivery_collections (sale_id) WHERE status = 'captured';
CREATE UNIQUE INDEX route_delivery_collections_external_captured_unique
  ON route_delivery_collections (route_external_delivery_reconciliation_item_id)
  WHERE status = 'captured' AND route_external_delivery_reconciliation_item_id IS NOT NULL;
CREATE UNIQUE INDEX route_delivery_collections_stop_captured_unique
  ON route_delivery_collections (route_delivery_stop_id)
  WHERE status = 'captured' AND route_delivery_stop_id IS NOT NULL;
```

Se conserva `sale_payments.route_delivery_collection_id UNIQUE`: cada collection histórica conserva exactamente un payment histórico. Una venta puede tener varias collections históricas, pero solamente una capturada.

### `route_delivery_collection_reversals`

| Campo | Regla |
| --- | --- |
| `id` | clave primaria |
| `business_id`, `branch_id` | FKs reales; iguales a collection, payment, sale y case si existe |
| `route_delivery_collection_id` | FK `restrictOnDelete`, `UNIQUE`, collection reversada |
| `sale_payment_id` | FK `restrictOnDelete`, `UNIQUE`, payment de esa collection |
| `route_pending_collection_case_id` | nullable FK `restrictOnDelete`; sólo cuando el case estaba resolved por esta collection |
| `previous_case_resolved_by` | nullable FK `users`; obligatorio si hay case |
| `previous_case_resolved_at` | nullable timestamp; obligatorio si hay case |
| `reason_code` | `payment_recorded_by_mistake`, `wrong_customer`, `duplicate_collection`, `wrong_amount`, `other` |
| `explanation` | texto no vacío, obligatorio |
| `reversed_by`, `reversed_at` | actor y momento obligatorios |
| `cash_correction_type` | `none`, `current_open_session_adjustment`, `historical_closed_session_ledger` |
| `compensating_cash_movement_id` | nullable FK, `UNIQUE`; sólo para ajuste de sesión abierta |
| `operation_idempotency_key_id` | nullable FK, `UNIQUE` |
| timestamps | estándar |

No se persiste JSON snapshot. La collection, payment, sale, movimiento y case originales son la evidencia relacional. No se duplica `previous_case_resolution_route_delivery_collection_id`: el servicio verifica bajo locks, antes de mutar, que la collection revertida es la que resolvía el case; la FK de reversal a la collection conserva esa evidencia. El trigger sólo valida el estado final persistido.

Checks locales: explicación no vacía, reason y correction type válidos; `none` e `historical_closed_session_ledger` exigen compensating movement nulo; `current_open_session_adjustment` exige compensating movement no nulo; los tres campos previous-case son todos null si no hay case y todos no-null si lo hay.

Índices adicionales: `(business_id, branch_id, reversed_at)`, `(route_pending_collection_case_id)`, además de las uniques declaradas.

### Event interno 3E-C

La migration amplía el check de `route_pending_collection_events.type` con `collection_reversed`. Sólo `RouteDeliveryCollectionReversalService` puede crearlo. No se admite en la validación HTTP genérica de events. Es append-only, registra actor, `occurred_at`, nota de contexto y case. Su `operation_idempotency_key_id` queda null: el reversal padre es idempotente y el FK único de event no puede reutilizar la llave del reversal.

## Reglas de elegibilidad y bloqueo

Una reversa exige, tras locks:

- actor activo, business y branch actuales compatibles y permiso `routes.delivery_collections.reverse`;
- collection `captured`, de responsabilidad `delivery_agent`, con origen externo o in-app válido;
- sale del mismo scope, no crediticia, sin CxC ni saldo de crédito;
- exactamente un `sale_payment` capturado vinculado a la collection, mismo importe, venta y scope;
- sale actualmente `paid`, `amount_paid = collection.amount` y método consistente;
- importe igual al total de sale: B1 sólo es full reversal;
- delivery origin todavía `delivered` y responsibility snapshot todavía `delivery_agent`;
- ningún active settlement item en draft o confirmed.

Bloqueos explícitos: `already_reversed`, `unsupported_pre_seller`, `delivery_not_delivered`, `sale_not_eligible`, `payment_mismatch`, `active_draft_settlement`, `confirmed_settlement`, `scope_mismatch`, `cash_correction_ineligible` y `another_captured_collection`.

Un active draft settlement bloquea: el usuario debe retirar el item o cancelar el draft. Cualquier active item en settlement confirmed bloquea incluso si posee variance o resolutions. B1 no modifica settlements, items, variances, resolutions ni sus movimientos.

## Reversa transaccional y estado de sale

`RouteDeliveryCollectionReversalService::reverse(RouteDeliveryCollection $collection, array $data, User $actor, string $idempotencyKey)` es el único write path. Requiere reason, explicación y confirmación explícita. Dentro de una única transacción idempotente:

1. bloquea y revalida origen físico, sale, payments, case, settlements/items, collection y, si aplica, sesión;
2. antes de cualquier mutación, si existe case, exige que esté `resolved`, que `resolution_route_delivery_collection_id` sea exactamente la collection revertida y que `resolved_by` y `resolved_at` no sean nulos; también revalida origen `delivered`, responsabilidad `delivery_agent` y proyecciones captured/paid coherentes;
3. determina la política de corrección de caja;
4. crea el reversal append-only, copiando `route_pending_collection_case_id`, `previous_case_resolved_by` y `previous_case_resolved_at` cuando aplica;
5. crea el eventual movimiento compensatorio válido y lo relaciona al reversal;
6. actualiza collection y payment a `reversed`;
7. actualiza sale a `payment_status=unpaid`, `amount_paid=0`, `payment_method=null`;
8. si existía el case validado, limpia su proyección de resolución, lo cambia a `open` y crea exactamente un event interno `collection_reversed` con el mismo case, scope, actor y momento;
9. el trigger diferido valida sólo la integridad de post-state al commit.

`is_credit_sale=false` y `credit_balance=0` son condiciones y se preservan. No se crea `customer_credit_accounts`, `customer_account_movements`, `customer_credit_payments`, stock, reserva, FEL ni documento electrónico. `due_date` no adquiere semántica nueva.

Una reversa es terminal y no tiene edit, delete ni unreverse. Una corrección de una reversa equivocada requiere fase futura compensatoria.

## State machine 3E-C

Se conservan:

```text
open -> resolved
open -> not_applicable
not_applicable -> open
```

Se agrega la transición excepcional:

```text
resolved -> open
```

Sólo ocurre desde el reversal service cuando, antes de mutar, `case.resolution_route_delivery_collection_id` era exactamente la collection reversada y `resolved_by` y `resolved_at` existían. El servicio copia ambos valores al reversal antes de limpiar la proyección de resolución del case. El case conserva `opened_at` original: el aging sigue basado en la fecha física de entrega, no se reinicia por una corrección.

Si no existía case persistido, ningún GET escribe: tras revertir la venta vuelve a aparecer como candidato derivado 3E-C. El primer follow-up o nuevo cobro materializa el case normal. La cola híbrida excluye candidates derivados sólo si existe un case persistido `open`; un case resolved antiguo no puede ocultar un pendiente reabierto.

Después de B1 puede ocurrir correctamente:

```text
collection #10 reversed -> sale unpaid -> case open/derived
collection #11 captured -> payment #11 captured -> sale paid -> case resolved
```

## Política de caja

### Sin efecto físico de caja

Card, transfer y check usan `cash_correction_type=none`, sin movimiento. Cash `held_by_collector` también usa `none`: el efectivo nunca entró a branch cash y collection reversed deja de ser elegible para 3D.

### Efectivo posted cuya sesión original sigue abierta

Si la collection tiene movimiento original, su sesión está `open` y coincide exactamente con la sesión abierta actual de business/branch, B1 crea un único movimiento negativo:

```text
type: route_delivery_collection_reversal_current_session
amount: -collection.amount
reference_type: route_delivery_collection_reversal
reference_id: reversal.id
cash_correction_type: current_open_session_adjustment
```

No es refund, gasto ni retiro físico. Netea la entrada errónea dentro de la misma sesión abierta: `+Q500` falso y `-Q500` administrativo producen expected correcto. Requiere confirmación administrativa explícita.

### Efectivo posted cuya sesión original está cerrada

Si el movimiento original pertenece a sesión `closed`, B1 no crea movimiento, no modifica la sesión original ni agrega un negativo a una sesión nueva. Usa:

```text
cash_correction_type: historical_closed_session_ledger
compensating_cash_movement_id: null
```

La corrección es financiera y auditada mediante reversal ledger. El cierre histórico conserva `expected_cash`, `counted_cash`, `difference` y `closed_at`; la caja física actual no se falsea. La UI histórica debe explicar que aquel movement perteneció a un cobro posteriormente reversado. CASH-C —insertar o editar un movement en sesión cerrada— está prohibido.

## Readers, relations y reporting

Agregar scopes `captured()` y `reversed()` a `RouteDeliveryCollection` y `SalePayment`. `Sale` expone relaciones históricas plurales y relaciones vigentes filtradas, por ejemplo `capturedPayments()` y `activeRouteDeliveryCollection()`; no debe conservarse una `hasOne` histórica ambigua cuando puede haber varias collections.

Se revisan puntualmente `RouteDeliveryCollectionService`, `RoutePendingCollectionService`, `RoutePendingCollectionEligibility`, `RoutePendingCollectionCaseService`, external eligibility/reconciliation, delivery stops/runs, `RouteCashSettlementEligibility`, draft/confirm settlement services, controllers, `ReportController`, modelos y `SystemIntegrityAuditor`.

Readers financieros, totals, payment method y elegibilidad usan `captured`; pantallas históricas cargan ambas relaciones y presentan el reversal. El reporte financiero excluye payments reversed. El historial de una cash session cerrada no se reescribe; reporting financiero corregido y snapshot histórico de caja son conceptos distintos.

## Constraint trigger PostgreSQL

La migration `2026_09_09_000026_add_route_delivery_collection_reversal_integrity_trigger.php` crea `validate_route_delivery_collection_reversal_integrity()` y constraint triggers `DEFERRABLE INITIALLY DEFERRED` en `route_delivery_collections`, `sale_payments`, `route_delivery_collection_reversals`, `route_cash_settlement_items` y `route_cash_settlements`, todos `AFTER INSERT OR UPDATE OR DELETE`. No instala un trigger sobre pending cases para reconstruir el estado anterior.

Al commit valida únicamente invariantes cross-table críticos:

- collection reversed tiene exactamente un reversal; captured no tiene reversal;
- payment vinculado a collection reversed está reversed y el de captured está captured;
- reversal, collection, payment y sale comparten sale, business y branch;
- sin collection capturada de reemplazo, la sale queda `unpaid`, el case asociado está `open` con proyección de resolución nula y sus snapshots previos existen en el reversal;
- con exactamente una collection capturada de reemplazo, la sale vuelve a estar `paid` íntegramente con un payment capturado y el mismo case queda `resolved` por esa nueva collection; el reversal original y su event interno se conservan;
- si no hay case, `route_pending_collection_case_id`, `previous_case_resolved_by` y `previous_case_resolved_at` son todos null;
- ninguna collection reversada conserva active item de settlement draft/confirmed;
- `none` no tiene movimiento compensatorio y no corresponde a cash posted;
- current-open adjustment tiene movimiento original y compensatorio correctos, negativo, con type/reference correctos y la misma sesión abierta;
- historical-closed ledger tiene movimiento original en sesión cerrada y ningún compensatorio.

El trigger no puede ni intenta demostrar quién resolvió el case antes de la reversa: ese pre-state se valida exclusivamente en el servicio bajo locks y queda evidenciado por la collection FK y los snapshots del ledger. No reemplaza validación de permisos, confirmación, motivos, UI ni rules de delivery. El `down()` elimina triggers y función antes de retirar schema; debe abortar si hay reversals para no borrar evidencia financiera.

## Lock order, idempotencia y concurrencia

El orden único de B1 es:

```text
physical origin -> sale -> sale payments (id asc) -> pending case ->
related settlements (id asc) -> settlement items (id asc) ->
route delivery collection -> current cash session
```

`RouteDeliveryCollectionService` ya inicia por origin, sale y payments; B1 conserva ese prefijo. Settlement services bloquean settlement, items y collection; B1 nunca toma collection antes de los settlements relacionados. Cada lock se revalida antes de write.

`IdempotencyService` usa `route_delivery_collection_reverse`. Repetir misma llave devuelve reversal previo; una segunda llave para la misma collection encuentra `reversed` o choca contra los constraints. No se afirma concurrencia de dos workers si el runner no ofrece conexiones independientes; los tests deben demostrar locks, segundo competidor controlado, índices parciales, trigger e imposibilidad de duplicar reversal/movement.

## Permisos, HTTP y UX

Se agrega `routes.delivery_collections.reverse`, no asignado al rol base `delivery_agent` ni al collector. El backend exige actor activo, business, branch y permiso. No existe endpoint genérico de reopen.

La reversa vive en el detalle administrativo de collection/pending case. Muestra customer, sale, origin, collection, method, collector, recorded/collected timestamps, custody, original movement/session, settlement state y FEL contextual. Si es reversible, presenta aviso explícito: “Corrige un cobro registrado por error; no representa una devolución de dinero al cliente.” Solicita reason, explicación y confirmación; para ajuste de sesión abierta solicita la confirmación administrativa adicional. Bloqueos se muestran antes de enviar cuando sean conocidos, pero backend siempre es autoridad.

## Auditor e invariantes

`SystemIntegrityAuditor` añadirá checks para estados/reversal incompatibles, duplicate reversal, scope y payment incorrectos, sale/captured state incoherente, índices parciales violados y settlement activo. Para reapertura de case verifica específicamente: case de reversal no `open`; campos de resolución todavía presentes; snapshots previos `resolved_by/at` ausentes; collection/payment no `reversed`; sale no `unpaid`; otra collection `captured` para la sale; event `collection_reversed` ausente, duplicado o con case/scope/actor/momento inválido; y case abierto por reversal con entrega no `delivered` o responsabilidad distinta de `delivery_agent`. También verifica cash correction type/movement/session/sign/reference inválidos, histórico cerrado con movimiento, `none` incompatible con cash posted y regresiones de FEL/stock/reservas/CxC. Mantiene los checks existentes de anomalía `pre_seller + delivered + unpaid`.

FEL, `electronic_documents`, delivery, stock, stock movements, reservations, CxC contractual, credit balance y POS no reciben cambios funcionales.

## Migraciones previstas

1. `2026_09_09_000022_add_route_delivery_collection_reversal_statuses.php` — status collection, backfill captured, reemplazo de uniques por índices parciales.
2. `2026_09_09_000023_add_sale_payment_reversal_status.php` — status payment y backfill captured.
3. `2026_09_09_000024_create_route_delivery_collection_reversals.php` — ledger, FKs, checks e índices locales.
4. `2026_09_09_000025_extend_route_pending_collection_event_types_for_reversal.php` — ampliar check de event type.
5. `2026_09_09_000026_add_route_delivery_collection_reversal_integrity_trigger.php` — función, triggers diferidos y down seguro.

No se editan migraciones históricas ni se hace backfill de reversals. Cada `down()` es defensivo y nunca elimina evidencia para hacer posible el rollback:

- 000022 aborta si existe una collection `reversed` o más de una historia de collection para una misma sale o external origin que no puede representarse con los unique globales antiguos efectivos; no bloquea ni inventa un unique histórico para in-app stop;
- 000023 aborta si existe un payment `reversed` o un ledger de reversal vinculado;
- 000024 aborta si `route_delivery_collection_reversals` contiene filas;
- 000025 aborta si existe un event `collection_reversed` antes de restaurar el check anterior;
- 000026 aborta si existen reversals; sólo en un esquema sin evidencia puede retirar trigger y función.

Los abortos usan mensajes claros y no borran ledger, events ni estados financieros. En una base limpia deben seguir funcionando `migrate:fresh --env=testing --force` y el rollback inverso de 000026--000022.
