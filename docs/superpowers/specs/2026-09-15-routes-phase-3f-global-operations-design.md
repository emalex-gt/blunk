# Fase 3F — Operación global de Rutas

## Objetivo

Dar a la sucursal una operación administrativa única para previsualizar y ejecutar preparación y generación de ventas de todas las jornadas elegibles, agrupada por vendedor. El alcance es comercial y no modifica entrega física, cobros, CxC, FEL, stock ni reglas de custodia existentes.

## Arquitectura

`RouteGlobalOperationsService` será un read model y orquestador branch-scoped. Consulta `RouteWorkDay` y `PreSale` en el business y sucursal actual, clasifica cada jornada con las mismas reglas que hoy usan `RoutePreparationBatchService::prepareAll()` y `RouteDeliveryBatchService::deliverAll()`, y llama esos servicios una vez por jornada elegible, en orden estable. Cada child batch conserva sus propias transacciones, locks e idempotencia; no habrá transacción global ni una segunda implementación de picking, conversion, stock o FEL.

No se crea tabla global inicialmente. Los `RoutePreparationBatch` y `RouteDeliveryBatch` existentes siguen siendo la evidencia y trazabilidad por jornada. El resultado global contiene child batch IDs, procesadas, omitidas y errores inesperados para retry seguro.

## Scope y elegibilidad

Todo query exige `business_id=currentBusinessId()` y `branch_id=BranchInventory::activeBranch(...)->id`. Preparación incluye únicamente preventas `submitted|processing` que estén dentro de jornadas que el servicio actual puede preparar. Generación incluye únicamente preventas `picked`, sin `converted_sale_id`, por jornada. La preview no aborta por casos conocidos: devuelve `blocked` con razón por jornada/preventa. La ejecución procesa las elegibles y reporta errores inesperados sin revertir children ya confirmados.

El vendedor procede de la FK real `RouteWorkDay.seller_id` y se carga como `seller`. Documentos y dashboard agrupan por `seller_id`; `route_work_day_id` es trazabilidad secundaria.

## Preparar todo y generar ventas

La operación global usa permisos existentes `routes.pre_sales.pick` (o su constante equivalente) y guard de caja existente, que sigue siendo autoridad dentro de cada servicio child. Un replay global usa una llave propia y cada child deriva una llave estable de `global key + work day id`; un replay no duplica batches, preventas, stock ni ventas.

Generar ventas llama `RouteDeliveryBatchService::deliverAll()`. Por ello conserva `converted_sale_id`, receipt interno, la semántica `pre_seller|delivery_agent`, picking versus invoice y la elegibilidad/automatización FEL. Con FEL apagado se crea la venta y comprobante sin depender de Digifact; un fallo FEL posterior nunca revierte la venta.

## Documentos

Un servicio de documentos global recibe los child preparation batches, carga sus preventas y agrupa primero por vendedor. Genera consolidado y resumen de productos por vendedor; un mismo vendedor con varias jornadas queda en un grupo. Los recibos se ordenan por vendedor y cada preventa empieza una página nueva. Se reutiliza la plantilla de receipt; su tamaño es media carta vertical, 5.5 x 8.5 pulgadas (396 x 612 pt), permitiendo que una orden larga continúe pero nunca iniciando la siguiente en el espacio restante.

## UI

La pantalla administrativa global muestra métricas de vendedores, jornadas, preventas, total, preparación, ventas y bloqueos por vendedor. Las acciones primarias visibles son `PREPARAR TODO` y, si hay preventas picked, `GENERAR VENTAS`. Cada modal de confirmación muestra preview y bloqueos. No crea navegación de entrega, mapas u offline.

## Invariantes

3F no infiere pago desde `agreed_payment_method`, no crea CxC, ni altera collections, delivery outcomes, FEL, documents electrónicos, stock movements, reservations o POS fuera de los servicios children ya autorizados. No persiste totals derivados; usa agregados/eager loading para evitar N+1.

## Pruebas

Cobertura exige dos vendedores/múltiples jornadas, exclusión por tenant/branch y estado, retry, agrupación seller, PDFs media carta/page break, venta única/converted sale, timing picking/invoice, FEL off y regresiones payment `pre_seller|delivery_agent`.
