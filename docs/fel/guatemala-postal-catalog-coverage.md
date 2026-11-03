# Catálogo postal de Guatemala para FEL — fase A

Fecha de verificación: 2026-11-03.

## Fuentes

- Correos de Guatemala, listas departamentales PDF en `https://correos.gob.gt/CodigosPos/ListaCodigos/` (fuente principal).
- CELASA, `https://www.servicios-ws.celasa.com.gt/codigos-postales` (contraste nacional; el sitio puede requerir acceso TLS compatible).

Los valores versionados en `App\Support\GuatemalaPostalCodes` son transcripciones explícitas de las listas de Correos. No se derivan de códigos INE ni de la posición de un municipio dentro de un departamento.

## Cobertura

- Municipios del catálogo Blunk: **337**.
- Código municipal confirmado: **322**.
- Sin código automático por ambigüedad o cambio administrativo: **15**.
- Códigos múltiples: el municipio de **Guatemala** no recibe uno: Correos publica `01000` para municipio/departamento y códigos por zona (`01001`–`01019`, `01021`).

## Correspondencias no automatizadas

| Departamento | Municipio Blunk | Opciones publicadas por Correos | Motivo |
|---|---|---|---|
| Alta Verapaz | Santa Catalina La Tinta | `16016` — LA TINTA (PANZOS) | El documento la atribuye a Panzós; hoy es municipio propio. |
| Alta Verapaz | Raxruhá | `16026` — RAXRUHA (CHISEC) | El documento la atribuye a Chisec; hoy es municipio propio. |
| Escuintla | Guanagazapa | `05015` — BRITO (GUANAGAZAPA) | No hay fila municipal directa. |
| Guatemala | Guatemala | `01000`; zonas `01001`–`01019`, `01021` | Múltiples códigos territoriales; no hay selección municipal segura. |
| Huehuetenango | Unión Cantinil | — | Municipio posterior/no listado directamente. |
| Petén | El Chal | `17020` — EL CHAL (DOLORES) | El documento lo atribuye a Dolores; hoy es municipio propio. |
| Petén | Las Cruces | `17027` — LAS CRUCES (LA LIBERTAD) | El documento lo atribuye a La Libertad; hoy es municipio propio. |
| Quetzaltenango | Huitán | `09015` — HUITLA | Diferencia textual sin confirmación adicional. |
| El Quiche | Chiché | `14002` — QUICHE | No se confunde el nombre departamental con Chiché. |
| San Marcos | Tacaná | `12007` — TANACA | Diferencia textual en la lista postal. |
| San Marcos | Ayutla | `12017` — TOCUN UMAN | Localidad/cabecera distinta del nombre administrativo. |
| San Marcos | San José El Rodeo | `12014` — EL RODEO | Diferencia textual. |
| San Marcos | San José Ojetenam | `12024` — SAN JOSE OJOTEPAN | Diferencia textual. |
| San Marcos | San Cristóbal Cucho | `12025` — SAN CRITOBAL CUCHO | Diferencia textual. |
| San Marcos | La Blanca | `12039` — LA BLANCA (OCOS) | La lista la atribuye a Ocós; hoy es municipio propio. |

`14006` queda asignado a **Chichicastenango**, tal como lo publica Correos. Las coincidencias con contexto municipal explícito, como `14019` — PLAYA GRANDE (IXCAN), se registran sólo cuando la lista identifica inequívocamente el municipio entre paréntesis.

## Alcance

Esta fase únicamente prepara almacenamiento y datos internos. No altera el payload FEL, valores `fel_*`, documentos históricos ni interfaz de usuario. La fase B deberá decidir cómo manejar municipios sin código o con múltiples códigos antes de usar el campo en certificación.
