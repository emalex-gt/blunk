# Contrato Blunk Fuel API — v1 (fase de captura; NO facturación)

**Origen:** auditoría de `blunk-fuel-laravel(1).zip` contra `emalex-gt/blunk/main` y el código local Blunk 7. El candidato local valida el método `deposit` (confirmado el 2026-11-25). **No instalado ni migrado automáticamente.**

Base URL para emulador Android con Laravel local: `http://10.0.2.2:8000`. Para una SUNMI P3 física, **10.0.2.2 no sirve**: utilizar una dirección accesible de red y HTTPS fuera de desarrollo.

## Autenticación

- `POST /api/fuel/v1/auth/token` (`throttle:5,1`), JSON con campos `email`, `password` y `device_name`; las credenciales se envían **solo por HTTPS**, salvo APP_ENV=local/testing en el emulador.
- Respuesta 200: `token_type: "Bearer"`, `access_token` (se muestra **una sola vez**, no se loguea), `expires_at` ISO-8601, `default_branch_id`, `abilities` (`fuel:read`, opcional `fuel:manual`). Vigencia: 12 horas.
- Encabezados en el resto de endpoints: `Authorization: Bearer {token}`, `Accept: application/json`, `X-Blunk-Branch: {branch_id}` y `Content-Type: application/json` en POST.
- Los tokens llevan alcances explícitos `fuel:tenant:{business_id}` y `fuel:branch:{branch_id}` para cada sucursal actualmente autorizada. Cada petición vuelve a validar el acceso a la sucursal y los permisos vigentes. No se aceptan tokens con `*` en sustitución de esos alcances.
- `POST /api/fuel/v1/auth/logout` elimina únicamente el token Fuel actual.
- El login de operador **no** concede `fuel:automatic`. La ingestión automática está reservada a un token emitido por administración a un dispositivo confiable, con `fuel:automatic`, `fuel:tenant:{id}`, `fuel:branch:{id}`, `fuel:device:{device_id}`, `fuel:read`, y un usuario con permiso `fuel.record_automatic`.
- Permisos Blunk nuevos: `fuel.view` (consulta), `fuel.record_manual` (registro manual), `fuel.record_automatic` (ingestión de dispositivo). **No se asignan a roles automáticamente.**

## Bootstrap

`GET /api/fuel/v1/bootstrap` (capacidad `fuel:read`, permiso `fuel.view`).

**Ejemplo de estructura de respuesta**. Los nombres e IDs siguientes son ilustrativos; los datos se obtienen realmente de las tablas `businesses`, `branches`, `users`, `products`, listas de precios y `fuel_product_configs`:

```json
{
  "api_version": "fuel/v1",
  "business": {"id": 9, "name": "Estación de ejemplo"},
  "branch": {"id": 4, "name": "Sucursal Central", "code": "MAIN"},
  "branches": [{"id": 4, "name": "Sucursal Central", "code": "MAIN"}],
  "operator": {"id": 25, "name": "Operador de ejemplo"},
  "currency": "GTQ",
  "fuel_products": [
    {"id": 6, "product_id": 312, "code": "GAS-REG", "label": "Gasolina regular", "unit": "L", "price_per_liter": "31.50", "currency": "GTQ"}
  ],
  "payment_methods": [
    {"id": "cash", "label": "Efectivo"},
    {"id": "card", "label": "Tarjeta"},
    {"id": "transfer", "label": "Transferencia"},
    {"id": "deposit", "label": "Depósito"},
    {"id": "check", "label": "Cheque"}
  ],
  "capabilities": {
    "manual_consumption": true,
    "automatic_consumption": false,
    "payments": false,
    "invoicing": false,
    "inventory_deduction": false,
    "neonet": false
  }
}
```

- `fuel_products[].id` es el identificador **de configuración Fuel**, que se envía como `fuel_product_id` en el POST.
- `product_id` es el ID **del producto comercial Blunk** (no se ha creado otro catálogo).
- Un combustible aparece solo si está configurado para esa sucursal, el producto Blunk está activo, disponible en esa sucursal y dispone de precio positivo por litro.
- `price_per_liter` se obtiene de la lista de precios predeterminada aplicable a la sucursal, con fallback a `products.sale_price`. Debe configurarse la unidad comercial de ese producto como litro.
- Los métodos de pago reflejan los que acepta el checkout de Blunk (`CheckoutPaymentMethods` compartido con la validación de `SaleController`). **`deposit` sí está habilitado en el checkout GT de la rama local Blunk 7** (confirmación del código compartido el 2026-11-25), y se devuelve con el identificador `deposit`. Los métodos disponibles se comparten con la validación de `SaleController`. Listarlos **no** significa que esta API pueda cobrar; `capabilities.payments=false`.
- Si aún no se han asociado productos, `fuel_products` será `[]`. El paquete no crea combustibles ficticios ni altera productos existentes.

## Consulta de consumos

`GET /api/fuel/v1/dispenses?limit=30&before_id=1000` (parámetros opcionales, `limit` 1..100).

```json
{
  "data": [
    {
      "id": 1802,
      "fuel_product_id": 6,
      "product_id": 312,
      "fuel": "Gasolina regular",
      "pump_number": 2,
      "liters": "10.000",
      "price_per_liter": "31.50",
      "total": "315.00",
      "currency": "GTQ",
      "entry_mode": "liters",
      "source": "manual",
      "status": "recorded",
      "payment_status": "unpaid",
      "invoice_status": "not_requested",
      "created_at": "2026-11-25T16:00:00+00:00",
      "occurred_at": null
    }
  ],
  "pagination": {"has_more": false, "next_before_id": null}
}
```

Orden descendente por `id`, siempre limitado al negocio y sucursal del token/cabecera. Si `has_more=true`, consultar de nuevo pasando `next_before_id` como `before_id`.

## Registro manual

`POST /api/fuel/v1/dispenses/manual` (capacidad `fuel:manual`, permiso `fuel.record_manual`).

```json
{
  "request_key": "3fc29fb8-6398-42b8-bcab-e2da0219c308",
  "fuel_product_id": 6,
  "pump_number": 2,
  "entry_mode": "liters",
  "value": "10.000",
  "expected_price_per_liter": "31.50"
}
```

Alternativamente, `entry_mode: "amount"` y `value: "315.00"` (2 decimales); los litros se calculan por precio con precisión de 3 decimales. Reutilizar siempre el **mismo UUID** cuando Android reintente un envío; generar un UUID nuevo únicamente para un consumo realmente distinto.

**Respuesta de creación (201):**

```json
{
  "data": {
    "id": 1802,
    "fuel_product_id": 6,
    "product_id": 312,
    "fuel": "Gasolina regular",
    "pump_number": 2,
    "liters": "10.000",
    "price_per_liter": "31.50",
    "total": "315.00",
    "currency": "GTQ",
    "entry_mode": "liters",
    "source": "manual",
    "status": "recorded",
    "payment_status": "unpaid",
    "invoice_status": "not_requested",
    "created_at": "2026-11-25T16:00:00+00:00",
    "occurred_at": null
  },
  "idempotent_replay": false
}
```

- Reintento idéntico: **200** + misma `data` + `idempotent_replay: true`.
- Mismo `request_key` con datos distintos: **409**.
- Precio desactualizado: **422**, campo `expected_price_per_liter`; refrescar bootstrap y **solicitar confirmación al usuario** antes de reintentar un consumo cambiado.
- Sin credenciales/token: **401**. Sin permisos o alcance: **403**. Errores de campos: **422**. Exceso de peticiones: **429**.
- Importes y litros son strings decimales, **nunca floats binarios para operaciones monetarias**. Backend calcula en centavos/mililitros y guarda precio por litro como snapshot.

## Registro automático (fase 1: ingestión de eventos confiables)

`POST /api/fuel/v1/dispenses/automatic` (requiere token aprovisionado de dispositivo con `fuel:automatic` **y** permiso `fuel.record_automatic`; **no** está disponible con login de operador). El token debe incluir `fuel:device:{device_id}` y el `device_id` del cuerpo debe coincidir exactamente.

```json
{
  "device_id": "SURTIDOR-02",
  "external_event_id": "evt-20261125-00128",
  "fuel_product_id": 6,
  "pump_number": 2,
  "entry_mode": "liters",
  "value": "10.000",
  "expected_price_per_liter": "31.50",
  "occurred_at": "2026-11-25T10:00:00-06:00"
}
```

`source_key=SHA256(device_id + separador + external_event_id)` dentro de empresa/sucursal. Respuesta idéntica al registro manual con `source: "automatic"`, `occurred_at` si fue suministrado. Los eventos automáticos **no son lectura directa del hardware**; un adaptador confiable debe producirlos. No hay conector SUNMI/NEONET en esta fase.

## Estados independientes

| Dimensión | Valor inicial | Esta API puede cambiarlo |
|---|---|---|
| Consumo `status` | `recorded` | Solo crear (ninguna transición posterior) |
| Cobro `payment_status` | `unpaid` | No |
| Factura `invoice_status` | `not_requested` | No |

Ningún endpoint modifica `sales`, `sale_payments`, `cash_register_sessions`, inventario, FEL ni intenciones fiscales. Los cobros, crédito, depósito y facturación requieren un contrato posterior con el motor Blunk.

## Migraciones

- `2026_11_25_000001_create_fuel_v1_tables.php`: tablas `fuel_product_configs` y `fuel_dispenses` con claves foráneas, referencias al producto comercial, snapshots de precio, origen, fingerprint, estados y unicidad `(business_id,branch_id,source,source_key)`.
- `2026_11_25_000002_ensure_fuel_api_sanctum_tokens.php`: crea `personal_access_tokens` únicamente si no existe. Su `down()` NO borra tokens de otras integraciones.
- `2026_11_25_000003_register_fuel_permissions.php`: incorpora tres permisos sin asignarlos automáticamente. Su rollback tampoco elimina asignaciones ya realizadas.

Para habilitar un combustible, un administrador debe asociar un `products.id` **que pertenezca al mismo negocio** y esté activo en la sucursal a `fuel_product_configs`, por medio de un proceso administrativo controlado. El producto debe tener precio por litro válido. **No** hay seed ficticio.

## Riesgos y verificaciones pendientes en Laragon

- El ZIP fuente no incluía `HasApiTokens`, login/rotación, permisos Fuel ni fuente real de métodos de pago. La adaptación los añade de manera aislada.
- Comparación realizada con GitHub `emalex-gt/blunk/main` (rama local Blunk 7 no disponible en el conector); el instalador verifica anclas para no sobrescribir código local divergente.
- Se han hecho lint de PHP y revisión estática. **Sin ejecutar migraciones ni solicitudes HTTP reales sobre la BD local; pendientes de validación del usuario en Laragon**.
- Antes de habilitar el registro automático para hardware real, debe existir inventario/conciliación, autenticación de dispositivo y contrato físico del surtidor específico.
