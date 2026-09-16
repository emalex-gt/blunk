# Fase 3G — Política de cobros y UX operativa de rutas

## Objetivo

3G separa quién cobra una venta de ruta, cuándo se registra el pago y qué métodos puede usar una sucursal. También corrige inmediatamente el feedback de Operación Global de Rutas: 3G-A entrega a Inertia los resultados de preparar y generar ventas.

## Scope y exclusiones

3G cubre feedback global, política de cobro por sucursal, métodos permitidos, conversión policy-aware, trazabilidad de sesión y reporting multimedio. En esta entrega se implementa sólo 3G-A. La configuración financiera, pagos y reporting permanecen diseñados y no implementados.

Quedan fuera CxC contractual, pagos parciales, split payments, catálogo de métodos, refund, reversas, manifiesto, conciliación física, entregas parciales, devoluciones, productos extra y diferencias de reparto. Documento de reparto + conciliación pertenece exclusivamente a 3H.

## Relación con fases existentes

3F sigue siendo orquestador: RouteGlobalOperationsService delega preparación y venta a RoutePreparationBatchService y RouteDeliveryBatchService. No hay transacción global ni una segunda implementación de stock, Sale, payment o FEL.

3A–3E conservan sus fuentes de verdad de preventa, collection, SalePayment, custodia, delivery, settlement y seguimiento. B2a permanece aislada en el stash y no se modifica. 3G no crea un ledger financiero paralelo.

## Conceptos independientes

| Concepto | Autoridad | Significado |
| --- | --- | --- |
| Responsable de cobro | tenant setting existente route_collection_responsibility | pre_seller o delivery_agent responde quién cobra. |
| Workflow | política nueva por sucursal | immediate_paid o per_order_collection responde cuándo y cómo se registra un pago. |
| Método previsto | PreSale.agreed_payment_method | Es un acuerdo; nunca es evidencia de pago. |
| Método real | collection y SalePayment.method | Es un hecho financiero al cobrar. |

Responsabilidad y workflow no se infieren uno del otro.

## Matriz workflow × responsable

| Workflow | Responsable | Conversión | Resultado financiero y seguimiento |
| --- | --- | --- | --- |
| immediate_paid | pre_seller | Convierte dentro de la transacción child. | Generar ventas registra la única cadena financiera real, deja Sale paid y termina el ciclo. No aplica el guard legado de collection previa ni existe pending collection. |
| immediate_paid | delivery_agent | Igual que pre_seller. | El workflow termina el cobro al convertir. La responsabilidad queda como política/snapshot, sin cobro posterior ni pending collection. |
| per_order_collection | delivery_agent | Convierte Sale unpaid sin hechos financieros. | Mantiene el flujo existente: el cobro real es por pedido; 3E-C sólo nace al existir entrega física elegible. |
| per_order_collection | pre_seller | Convierte Sale unpaid sin hechos financieros. | El preventista registra el cobro posterior mediante la trace post-conversión; el método real se valida contra la policy vigente de sucursal. |

Immediate paid usa una sola cadena financiera seleccionada por auditoría para ambos responsables. No se bifurca por pre_seller versus delivery_agent ni se exige una collection legacy previa.

## Configuración por sucursal

La futura tabla uno-a-uno se llama route_branch_collection_settings y contiene:

| Campo | Regla |
| --- | --- |
| id | Clave primaria. |
| branch_id | FK restrictiva a Branch y única. |
| collection_workflow_mode | Sólo immediate_paid o per_order_collection. |
| allowed_payment_methods | JSON no vacío, sin duplicados y sólo cash, card, transfer, check. |
| primary_payment_method | Debe pertenecer a allowed_payment_methods. |
| timestamps | Trazabilidad estándar. |

La decisión de integridad es opción A: la tabla no persiste business_id. El negocio se deriva de Branch.business_id, por lo que una combinación business A con branch de B no es persistible. Los readers hacen join/scope por Branch; los writes cargan y bloquean Branch en el negocio activo. La migración futura tendrá FK restrictiva, UNIQUE(branch_id), check del workflow y validación transaccional de JSON/membresía. Las pruebas deben cubrir inserción DB directa de FK/unique y scope cross-business, no sólo ValidationException.

Manifest reconciliation no es persistible ni aceptado por endpoint en 3G. La UI futura sólo muestra Documento de reparto + conciliación — Próximamente, deshabilitado.

## Métodos y preventas

Los únicos métodos de Rutas son cash, card, transfer y check. No existe un catálogo nuevo.

En una preventa nueva, un único método permitido se asigna automáticamente; con varios, primary_payment_method es el valor inicial/default. Backend rechaza cualquier valor fuera del conjunto permitido.

En edición, un agreed_payment_method existente y permitido se conserva y nunca se reemplaza por primary. Si está vacío, la UI puede proponer primary como valor inicial, pero sólo se persiste al guardar. Si existe y ya no está permitido, se muestra inválido y exige selección deliberada de un método permitido; no se sustituye silenciosamente. Con un único método, edición puede mostrarlo como selección obligatoria y sólo lo asigna al guardar. Cambiar agreed_payment_method no crea collection, SalePayment, CashMovement, CxC ni deuda.

## Activación y backward compatibility

Una sucursal sin route_branch_collection_settings conserva exactamente su flujo legado. No hay backfill de modo, de responsable, de collections, de pagos, de caja ni de CxC.

Activar política no modifica preventas existentes:

- agreed_payment_method vacío bloquea generación con missing_agreed_payment_method.
- Un método previsto fuera de allowed_payment_methods bloquea con payment_method_not_allowed.
- Un único método se autoasigna sólo en preventas nuevas; en edición se persiste únicamente al guardar.
- El principal múltiple es default de una preventa nueva; en edición sólo se propone si agreed_payment_method está vacío.
- Un método existente que ya no esté permitido se conserva visible como inválido hasta que el usuario lo reemplace deliberadamente.

No hay actualización masiva silenciosa. Ventas convertidas siguen interpretándose por sus hechos y snapshots, nunca por la configuración actual de sucursal.

## Snapshot histórico obligatorio

RouteDeliveryBatch ya conserva collection_responsibility_snapshot. Antes de permitir conversiones policy-aware, Task 5 congela el diseño y la migración de los siguientes snapshots inmutables:

1. collection_workflow_mode_snapshot en RouteDeliveryBatch.
2. allowed_payment_methods_snapshot en RouteDeliveryBatch.
3. primary_payment_method_snapshot en RouteDeliveryBatch.
4. agreed_payment_method_snapshot efectivo por RouteDeliveryBatchPreSale.

SalePayment.method, la collection real y CashMovement continúan siendo los hechos financieros autoritativos. Ninguna implementación puede usar la configuración actual de la sucursal para reinterpretar una conversión anterior. Task 5 no permite writes financieros antes de que este diseño y sus readers estén revisados.

## Immediate paid

Generar ventas con immediate_paid requiere caja abierta para todo el batch, incluso si todos los métodos son non-cash: pertenecen al mismo turno financiero. Sólo cash modifica efectivo físico.

Antes de Task 6 se identifica, prueba y aprueba una única cadena reutilizable:

Generar ventas → Sale → pago real exacto → collection/traza compatible → CashMovement sólo para cash → Sale paid.

La acción Generar ventas es el evento autorizado que declara el cobro. No depende de una collection legacy previa porque el responsable sea pre_seller. No se crean payment, collection ni ledger duplicados.

Cada transacción child es atómica: Sale, SalePayment, collection/traza y CashMovement cash confirman o revierten juntos. Si falla cualquiera, no puede sobrevivir una Sale paid parcial ni un movimiento físico huérfano. Task 6 fuerza fallos de collection, payment y cash para demostrar rollback, reintento e idempotencia.

Card, transfer y check crean SalePayment real sin CashMovement. Cash crea exactamente un movimiento físico y aumenta efectivo esperado una vez.

## Per-order collection

Generar ventas crea Sale, SaleItems, receipt y stock según el setting existente, pero no crea collection, SalePayment, CashMovement, CxC ni crédito. No requiere caja abierta: aún no ocurre pago.

El método real posterior puede coincidir o cambiar respecto del previsto, pero debe pertenecer a los métodos permitidos vigentes de esa sucursal. CashMovement sólo nace cuando existe un cobro cash real y su servicio aplica entonces la política de caja.

Con delivery_agent se preserva 3E-C: una venta unpaid sólo se vuelve pendiente de seguimiento tras un resultado físico delivered elegible.

Con pre_seller no basta retirar el guard legado. Task 7 primero audita si un servicio actual puede cobrar contra la Sale convertida sin falsear origen físico. Si no existe, define y prueba un servicio/UI post-conversión con una única cadena de trazabilidad y SalePayment. Hasta que dicho servicio/UI exista, el preflight bloquea la combinación. Una Sale unpaid sin camino posterior de cobro es inválida.

## Caja, sesión y reporting

CashMovement es la única autoridad de efectivo físico. Card, transfer y check no modifican efectivo esperado.

Task 8 comienza con una auditoría read-only de SalePayment, Sale, CashRegisterSession, CashMovement, POS, Rutas y readers de cierre. Su único objetivo inicial es determinar si existe una relación autoritativa para atribuir los cuatro métodos al turno correcto. Timestamp nunca es autoridad.

Sólo si esa auditoría demuestra que no existe relación suficiente, se propone un schema mínimo para una aprobación específica posterior. Task 8 no crea migration antes de esa aprobación. El cierre multimedio futuro debe mostrar efectivo, tarjeta, transferencia, cheque y total cobrado sin reescribir el cierre de efectivo histórico.

## UX, preflight y autorización

La configuración es administrativa y branch-scoped. La preventa muestra métodos permitidos, principal y método previsto. Los previews globales muestran missing_agreed_payment_method, payment_method_not_allowed, cash_session_required para immediate_paid y guards legados aplicables. En per_order_collection, ambos responsables generan la Sale unpaid; el responsable histórico define el flujo posterior de cobro.

La confirmación global muestra vendedores, jornadas, ventas, total, modo, stock, FEL y bloqueos. React nunca decide elegibilidad. Backend mantiene permiso administrativo de vista/documentos y permiso de preparación para ejecutar; el scope negocio/sucursal es autoridad.

## 3G-A implementado ahora

RouteGlobalOperationsController ya escribe global_preparation_result y global_sales_result como flash. HandleInertiaRequests comparte ambas claves. Index muestra paneles de preparación y ventas, bloqueos/fallos legibles, y construye downloads sólo con processed batch_id de esa ejecución. RouteGlobalPreparationDocuments permanece autoridad de validación all-or-nothing de IDs, negocio, sucursal y batch completed.

Los modales existentes conservan confirmación. Estado processing deshabilita acciones, muestra Procesando y bloquea doble submit con una única llave por submit. El flash dura una request; refresh puede ocultar downloads y no crea persistencia global.

3G-A no cambia responsibility, workflow financiero, collections, pagos, CashMovement, caja, stock, FEL, CxC ni servicios child.

## Invariantes y pruebas

- No CxC artificial, movimientos de cuenta ni credit_balance por una ruta no contractual.
- No pago sin hecho financiero autorizado por el workflow.
- No CashMovement para card, transfer o check.
- No venta unpaid sin camino post-conversión probado.
- No reinterpretación histórica desde una configuración de sucursal actual.
- No cambios a FEL, stock, reservas, delivery, settlement o B2a fuera de sus autoridades.

La matriz de pruebas cubre los cuatro cruces workflow/responsable, activación de preventas abiertas, DB integrity branch/business, snapshots, atomicidad, idempotencia, los cuatro métodos, cash session audit, POS/FEL/stock/CxC y regresiones 3A–3E/B2a.

3H continúa totalmente diferida: no hay modo persistible, endpoint ni cambio de flujo de reparto en 3G.
