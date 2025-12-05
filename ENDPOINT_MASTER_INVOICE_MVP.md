# Endpoint: POST /sync/master-invoice-mvp

## Descripción
Crea una factura simple en Odoo a partir de un registro "Master" de Airtable cuyo estado sea "Prefacturada".

## Funcionamiento

1. **Obtiene el registro Master** desde Airtable usando el `master_id` proporcionado
2. **Verifica el estado** sea igual a "Prefacturada"
3. **Obtiene operaciones vinculadas** desde el campo "Operaciones / Órdenes de Viaje 2"
4. **Suma las tarifas** de cada operación (campo "Tarifa de Venta")
5. **Busca o crea el partner** en Odoo usando el campo "Customer"
6. **Crea una factura básica** en Odoo con:
   - 1 único ítem
   - Producto: ID configurado en `config.php` (parámetro `producto_mvp_id`)
   - Cantidad: 1
   - Precio: Total calculado de tarifas
7. **Actualiza el Master** en Airtable:
   - Campo "Odoo Invoice ID (MVP)" → ID de la factura creada
   - Campo "Estado" → "Facturado MVP"

## Request

```json
POST /sync/master-invoice-mvp
Content-Type: application/json

{
  "master_id": "recXXXXXXXXXXXXXX"
}
```

## Response (Exitoso)

```json
{
  "ok": true,
  "master_id": "recXXXXXXXXXXXXXX",
  "invoice_id": 12345,
  "total": 5000.00
}
```

## Response (Error)

```json
{
  "ok": false,
  "error": "Descripción del error",
  "code": 400
}
```

## Errores Posibles

| Error | Causa |
|-------|-------|
| `master_id es requerido` | No se proporcionó `master_id` en el JSON |
| `Master record not found` | El `master_id` no existe en Airtable |
| `Master status is '...'` | El estado del Master no es "Prefacturada" |
| `Master has no linked operations` | No hay operaciones vinculadas |
| `Total tarifa calculated is 0` | Las tarifas suman 0 o son negativas |
| `Master has no Customer field` | Falta el campo "Customer" en el Master |
| `producto_mvp_id no está configurado` | Falta configurar el ID del producto en `config.php` |

## Configuración Requerida

En `src/Config/config.php`, agregar o actualizar:

```php
"producto_mvp_id" => 12345  // ID del producto de servicio en Odoo
```

## Estructura de Datos Esperada

### Airtable - Tabla "Masters"
- **campo**: `Estado` (tipo: select) → debe contener "Prefacturada"
- **campo**: `Operaciones / Órdenes de Viaje 2` (tipo: linked records) → referencias a tabla "Operaciones"
- **campo**: `Customer` (tipo: text/linked record) → nombre del cliente
- **campo**: `Odoo Invoice ID (MVP)` (tipo: number) → se actualiza con el ID de la factura

### Airtable - Tabla "Operaciones"
- **campo**: `Tarifa de Venta` (tipo: number) → tarifa a sumar

### Odoo - Modelo "account.move"
- Se crea una factura de cliente (`move_type: 'out_invoice'`)
- Con un único ítem (línea de factura)
- Vinculada al partner encontrado/creado

## Logging

Todos los eventos se registran mediante `Logger`:
- Inicio y fin del proceso
- Lectura de operaciones
- Creación del partner (si es nuevo)
- Creación de la factura
- Errores durante el proceso

## Notas

- El endpoint es **POST obligatoriamente**
- Maneja **automáticamente** la creación de partners que no existan
- Es **idempotente** a nivel de datos (revisa Airtable y Odoo)
- Registra todas las operaciones para auditoría
