# Trazabilidad POS/FEL de producción

## Objetivo

Diagnosticar una facturación FEL real desde el clic del POS hasta el inicio de impresión sin alterar certificación, finalización comercial, inventario, caja ni idempotencia.

## Enfoque

Se reutiliza el canal operativo opt-in (`blunk_operations`) y el UUID ya enviado por POS. Los eventos compactos guardan solamente IDs operativos, ambiente, fase y milisegundos. El UUID se propaga explícitamente al flujo de intención fiscal, no se añade al snapshot ni a la huella idempotente.

## Alternativas consideradas

1. Nueva tabla de telemetría: descartada; exige migración y despliegue más amplio.
2. Añadir la traza a `operation_payload`: descartada; afectaría el snapshot y podría alterar la huella fiscal.
3. Eventos estructurados opt-in y lector acotado: elegido; es compatible con producción y no cambia el ciclo comercial.

## Límites

La carga efectiva de la ventana de impresión es una señal del documento HTTP; no prueba que el diálogo de impresión ni la impresión física hayan terminado. Las métricas ausentes se muestran como incompletas, nunca como cero.
