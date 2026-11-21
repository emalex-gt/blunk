# Dashboards globales y diagnóstico POS/FEL

## Objetivo

Asignar `warehouse` a las claves de rol `cajero-vendedor` y `vendedor` en cualquier tenant cuando sus capacidades efectivas lo permitan, y habilitar una traza opcional del recorrido POS/FEL sin alterar la venta ni la idempotencia.

## Enfoque aprobado

- El resolver usa claves de rol, no IDs físicos ni nombres visibles. Owner y Admin conservan prioridad; los perfiles siguen siendo preferencias sujetas a módulos y permisos.
- La traza reutiliza `OperationsLog`, que ya es opt-in y filtrable por tenant. Se correlaciona con un UUID de diagnóstico independiente de la clave de idempotencia. No contiene cliente, NIT, pagos ni payload fiscal.
- Se reutilizan los timings FEL existentes en `fel_certification_attempts.timings`. Se añaden únicamente límites POST, destino GET y navegador; no se optimiza hasta contar con una traza manual real.

## Límites

No se modifican Rutas, inventario, caja, reglas FEL, cálculo comercial, impresión ni bases de datos. La instrumentación solo se activa cuando `logging.operations` ya está habilitado para el tenant.

## Riesgos controlados

- Un perfil sin módulo Inventario o `inventory.view` cae a la precedencia segura existente; no se muestran widgets no autorizados.
- Inertia combina POST y GET bajo una visita. La traza distingue el POST de servidor y el primer frame del destino; la capa de red queda representada por la diferencia entre ambos relojes, no como una medida de servidor.
