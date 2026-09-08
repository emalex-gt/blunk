# Fase 3D — Liquidación y custodia del cobrador

## Objetivo y límites

3D registra la recepción física actual en sucursal del efectivo que un preventista o entregador ya cobró y que permanece bajo su custodia. No crea un segundo `sale_payment`, no cambia ventas, FEL, stock, reservas ni CxC. Sólo procesa `cash`; card, transfer y check quedan fuera. No hay liquidación parcial de una collection, diferencias confirmadas, reversas, sesiones históricas, offline ni arqueo del cobrador.

El hecho financiero queda separado así: collection registra el cobro y custodia; `sale_payments` registra el pago del cliente; settlement prueba la entrega física consolidada; `cash_movements` prueba el ingreso a caja.

## Elegibilidad común

Crear un adaptador de lectura `RouteCashSettlementEligibility` que una, sin escribir, `route_pre_sale_collections` y `route_delivery_collections`. Una colección elegible debe tener exactamente: mismo `business_id`, `branch_id` y `collected_by` del settlement; `payment_method = cash`; `custody_status = held_by_collector`; ningún item activo de settlement; y seguir existiendo.

`cash_posting_state = awaiting_physical_receipt` de delivery es compatible porque también conserva `held_by_collector`. Se excluyen `posted_to_branch_cash`, `not_applicable`, todos los métodos no cash y cualquier collection con item draft o confirmed. No hay backfill histórico ni reinterpretación de collections ya posted.

## Esquema

### route_cash_settlements

Campos: `id`, `business_id`, `branch_id`, `collector_user_id`, `received_by` nullable, `recorded_by`, `confirmed_by` nullable, `cash_register_session_id` nullable, `cash_movement_id` nullable unique, `expected_amount decimal(14,2)`, `received_amount decimal(14,2) nullable`, `difference_amount decimal(14,2) nullable`, `status varchar(16)`, `notes text nullable`, `confirmed_at nullable`, `cancelled_by nullable`, `cancelled_at nullable`, `cancellation_reason text nullable`, `operation_idempotency_key_id nullable unique`, timestamps.

FKs reales apuntan a business, branch, users, cash register session, cash movement y operation idempotency key. Índices: `(business_id, branch_id, collector_user_id, status)`, `(branch_id, status, created_at)` y `cash_movement_id` único. Check de estado: `draft|confirmed|cancelled`; confirmed requiere actores, sesión, movimiento, `confirmed_at`, importes no nulos e `difference_amount=0`; draft/cancelled requieren movimiento nulo; cancelled requiere metadata de cancelación.

### route_cash_settlement_items

Campos: `id`, `route_cash_settlement_id`, `route_pre_sale_collection_id nullable`, `route_delivery_collection_id nullable`, `amount_snapshot decimal(14,2)`, `is_active boolean default true`, timestamps.

FKs reales a settlement y ambas collections. Check `amount_snapshot > 0` y exactamente uno de los dos orígenes. Índices parciales únicos PostgreSQL: un `route_pre_sale_collection_id` activo y un `route_delivery_collection_id` activo. Así un draft reserva una collection; al quitarla o cancelar se marca `is_active=false`, conservando auditoría y liberando la reserva. No se usa `source_type/source_id`.

## Estados y operaciones

`draft → confirmed` y `draft → cancelled` son las únicas transiciones. Confirmed y cancelled son terminales e inmutables. Un draft puede agregar/quitar items y modificar `received_amount`/notas. `expected_amount` se persiste como snapshot derivado y se recalcula exclusivamente desde `SUM(amount_snapshot WHERE is_active)` en cada cambio; frontend no lo envía como autoridad. `difference_amount = received_amount - expected_amount` se calcula en backend.

Puede guardarse un draft con diferencia, pero confirmarlo es inválido salvo `received_amount = expected_amount`. Cancelar exige motivo, no crea movimiento ni modifica collections; marca los items activos inactivos.

## Confirmación atómica

`RouteCashSettlementService::confirm(RouteCashSettlement $settlement, array $data, User $actor, string $key)` usa `IdempotencyService` y una transacción. Bloquea settlement, items activos, las collections de ambos orígenes y `CashRegister::requireOpenSession(..., lock: true, branchId)`. Revalida tenant, branch, collector, método cash, custody held, snapshots de importe y ausencia de otra reserva. Recalcula expected/difference y bloquea diferencia no cero.

Entonces crea exactamente un movimiento `route_cash_settlement`, con `reference_type=route_cash_settlement`, `reference_id=settlement.id`, `amount=received_amount` y la sesión abierta actual. En la misma transacción cambia cada collection a `posted_to_branch_cash`; no copia ese `cash_movement_id` consolidado a collections. La evidencia autoritativa es `collection → active settlement item → confirmed settlement → cash_movement`. El settlement guarda el movimiento único y la sesión real.

## Actores, permisos y aislamiento

Separar `collector_user_id`, `received_by`, `recorded_by` y `confirmed_by`. Todos deben pertenecer al business; el actor, recibidor y sesión deben operar la branch actual. Proponer permisos: `routes.cash_settlements.view`, `.create`, `.confirm`, `.review`. El rol `delivery_agent` no recibe ninguno automáticamente. `review` sólo permite lectura/historial; no existe `view_own` en MVP.

## UX administrativa

Pantallas: índice de cobradores con efectivo pendiente; detalle con filtros mínimos por collector/origen/fecha y selección; formulario draft con esperado, recibido, diferencia, notas y quitar items; confirmación con sesión abierta actual y recibidor; historial inmutable. Si diferencia no es cero, mostrar “Existe una diferencia de QX.XX. Corrija el conteo antes de confirmar” y deshabilitar confirmación; backend mantiene el bloqueo.

## Integridad, compatibilidad e invariantes

Extender `SystemIntegrityAuditor` para reservas duplicadas, origen doble/nulo, scope/collector incorrecto, expected distinto a items, collection posted con settlement inválido, confirmed sin/un más de un movimiento, draft/cancelled con movimiento, importe/referencia/sesión del movimiento incorrectos, confirmed con diferencia, item no cash y custody sin cadena de evidencia. El auditor de ventas debe reconocer efectivo route liquidado mediante settlement; POS cash continúa exigiendo su movimiento directo estricto.

No cambiar collections históricas sin una confirmación 3D. La liquidación puede ocurrir tras cierre de jornadas. No crea CxC ni deuda de empleado.
