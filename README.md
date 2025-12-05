# Mercotruck Backend - API REST

Backend PHP para integración con Odoo y Airtable.

## Estructura del Proyecto

```
mercotruck-backend/
├── src/
│   ├── Config/
│   │   └── config.php          # Configuración de Odoo y Airtable
│   ├── Odoo/
│   │   └── OdooClient.php      # Cliente JSON-RPC para Odoo
│   ├── Airtable/
│   │   └── AirtableClient.php  # Cliente REST para Airtable API
│   ├── Endpoints/
│   │   ├── EmpresaSync.php     # Sincroniza empresas Airtable → Odoo
│   │   └── ProductoSync.php    # Crea productos en Odoo
│   └── Utils/
│       └── Response.php        # Helpers para respuestas JSON
├── test/
│   ├── test-odoo.php          # Prueba conexión Odoo
│   ├── test-airtable.php      # Prueba conexión Airtable
│   └── test-router.php        # Instrucciones de testing
├── index.php                  # Router principal
├── composer.json              # Dependencias y autoload PSR-4
└── vendor/                    # Dependencias instaladas
```

## Rutas Disponibles

### Health Check
```
GET /
GET /health
```
Respuesta: `{ "status": "ok", "version": "1.0.0", "uptime": ... }`

### Test Odoo
```
GET /test-odoo
```
Valida conexión con Odoo y obtiene datos básicos.
Respuesta: `{ "status": "ok", "test": "odoo", "uid": ..., "company": {...} }`

### Sincronizar Empresa
```
GET /sync/empresa?id=<airtable_record_id>
```
Sincroniza empresa desde Airtable a Odoo (crea o actualiza partner).

Parámetros:
- `id`: ID del registro en Airtable (obligatorio)

Respuesta:
```json
{
  "status": "ok",
  "action": "created|updated",
  "partner_id": 123,
  "airtable_id": "rec...",
  "name": "Empresa S.A."
}
```

### Sincronizar Productos
```
POST /sync/productos
```
Crea productos de servicio en Odoo (Transporte, Flete, Estadía).

Respuesta:
```json
{
  "status": "ok",
  "creados": [
    { "name": "Transporte", "id": 456, "status": "created" },
    { "name": "Flete", "id": 457, "status": "created" },
    { "name": "Estadía", "id": 458, "status": "created" }
  ],
  "total": 3,
  "errores": null
}
```

## Instalación y Setup

### 1. Dependencias
```bash
composer install
```

### 2. Configuración
Edita `src/Config/config.php` con tus credenciales:
```php
[
    "odoo" => [
        "url" => "https://tu-instancia.odoo.com",
        "db" => "tu_db",
        "username" => "tu_usuario@email.com",
        "password" => "tu_password"
    ],
    "airtable" => [
        "api_key" => "patXXXXXXXXXXXXXX...",
        "base_id" => "appXXXXXXXXXXXXXX..."
    ]
]
```

### 3. Testing Local
```bash
php -S localhost:8000
```

En otra terminal, prueba las rutas:
```bash
# Health check
curl http://localhost:8000/health

# Test Odoo
curl http://localhost:8000/test-odoo

# Test Airtable (requiere ID real)
php test/test-airtable.php rec123456789
```

## Características Principales

✓ **Clases con Namespace PSR-4**: Todas las clases están en namespaces correctos
✓ **Type Hints**: Métodos con tipos de parámetros y retorno
✓ **Manejo de Errores**: Try-catch global y respuestas JSON consistentes
✓ **JSON-RPC**: Cliente Odoo con soporte completo para JSON-RPC 2.0
✓ **REST API**: Cliente Airtable con autenticación Bearer
✓ **Responses Unificadas**: Helper `Response::ok()` y `Response::error()`
✓ **UTF-8**: Todas las respuestas con encoding correcto
✓ **Headers Seguros**: CORS, X-Frame-Options, X-Content-Type-Options
✓ **Sin Logs Externos**: Logging desactivado en respuestas HTTP
✓ **Deprecados Removidos**: Sin `curl_close()`, JSON encode mejorado

## Clases Principales

### OdooClient
```php
$odoo = new \Mercotruck\Odoo\OdooClient($url, $db, $user, $pass);
$uid = $odoo->authenticate();
$result = $odoo->call('model', 'method', [$args], ['kwargs']);
$records = $odoo->search_read('res.partner', [], ['id', 'name']);
```

### AirtableClient
```php
$airtable = new \Mercotruck\Airtable\AirtableClient($apiKey, $baseId);
$record = $airtable->getRecord('TableName', 'recordId');
```

### Response (Helpers)
```php
\Mercotruck\Utils\Response::ok(['data' => $data]);
\Mercotruck\Utils\Response::error('Mensaje de error', 400);
\Mercotruck\Utils\Response::notFound();
\Mercotruck\Utils\Response::internalError('Error 500');
```

## Cambios Realizados (Auditoría)

1. **src/Utils/Response.php**: CREADO
   - Helpers unificados para respuestas JSON
   - Métodos: ok(), error(), notFound(), internalError()

2. **src/Odoo/OdooClient.php**: MEJORADO
   - Añadidos type hints en métodos
   - Mejorado manejo de errores (HTTP codes, JSON validation)
   - Removido `unset($ch)` (deprecado)
   - Añadido timeout de 30 segundos
   - Mejor validación de respuesta JSON

3. **src/Airtable/AirtableClient.php**: MEJORADO
   - Añadidos type hints
   - Removido `curl_close()` (PHP 8.5+ lo hace automático)
   - Mejorada validación de errores
   - URL encoding correcto para table names y record IDs

4. **src/Endpoints/ProductoSync.php**: REESCRITO
   - Estructura completa con docblocks
   - Manejo de errores try-catch
   - Respuesta JSON consistente
   - Método privado `getProductosTemplate()`
   - Return type void en `run()`

5. **src/Endpoints/EmpresaSync.php**: REFACTORIZADO
   - Retirados headers duplicados
   - Manejo de errores centralizado
   - Validación mejorada de parámetros
   - Respuesta unificada con `Response::ok()`
   - Type hints en métodos

6. **index.php**: COMPLETAMENTE REESCRITO
   - Error handling global con try-catch
   - Headers seguros (CORS, X-Frame-Options)
   - Buffer output limpio sin duplicados
   - Ruta elegante (/) y fallback (/health)
   - Soporte CORS preflight (OPTIONS)
   - Todas las clases cargadas correctamente
   - Uso de Response helper en todas las rutas

7. **test/**: CREADOS 3 ARCHIVOS
   - test-odoo.php: Valida conexión Odoo
   - test-airtable.php: Valida conexión Airtable
   - test-router.php: Instrucciones de testing

## Notas de Producción

- Cambiar `CURLOPT_SSL_VERIFYPEER` y `CURLOPT_SSL_VERIFYHOST` a true en producción
- Guardar credenciales en variables de entorno
- Implementar rate limiting
- Agregar logging (file o syslog)
- Validar entrada GET/POST params
- Usar HTTPS obligatoriamente

## Autor
Tech Lead Senior - Análisis completo y refactorización 2025
