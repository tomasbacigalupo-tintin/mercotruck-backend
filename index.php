<?php
/**
 * Mercotruck Backend - API Router
 * 
 * Punto de entrada para todas las rutas del backend.
 * Integración multi-empresa con Odoo AR/CL y Airtable.
 */

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Handler para errores fatales
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error', 
            'error' => 'Fatal error: ' . $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ], JSON_UNESCAPED_UNICODE);
    }
});

// Headers seguros
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Output buffering
ob_start();

try {
    // Cargar autoload y configuración
    require __DIR__ . '/vendor/autoload.php';
    $config = require __DIR__ . '/src/Config/config.php';

    // Cargar clases
    require __DIR__ . '/src/Utils/Response.php';
    require __DIR__ . '/src/Utils/Logger.php';
    require __DIR__ . '/src/Odoo/OdooClient.php';
    require __DIR__ . '/src/Odoo/OdooManager.php';
    require __DIR__ . '/src/Odoo/PartnerService.php';
    require __DIR__ . '/src/Odoo/AnalyticService.php';
    require __DIR__ . '/src/Odoo/InvoiceService.php';
    require __DIR__ . '/src/Airtable/AirtableClient.php';
    require __DIR__ . '/src/Services/MasterService.php';
    require __DIR__ . '/src/Services/OperacionService.php';
    require __DIR__ . '/src/Endpoints/BaseOdooSync.php';
    require __DIR__ . '/src/Endpoints/EmpresaSync.php';
    require __DIR__ . '/src/Endpoints/ProductoSync.php';
    require __DIR__ . '/src/Endpoints/TarifaSync.php';
    require __DIR__ . '/src/Endpoints/MasterInvoiceMvp.php';
    require __DIR__ . '/src/Endpoints/DebugOperacion.php';

    // Obtener ruta
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = str_replace('/index.php', '', $path);
    $path = empty($path) ? '/' : $path;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // OPTIONS request (CORS preflight)
    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    // Helper para obtener JSON del body
    $getJsonInput = function(): array {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    };

    // ========================================
    // RUTAS
    // ========================================

    // Health check
    if ($path === '/health' || $path === '/') {
        \Mercotruck\Utils\Response::ok([
            'version' => '2.0.0',
            'uptime' => time(),
            'features' => ['multi-company', 'ar', 'cl', 'triangulation']
        ]);
    }

    // ----------------------------------------
    // TEST ENDPOINTS
    // ----------------------------------------

    // Test Odoo AR
    if ($path === '/test-odoo' || $path === '/test-odoo-ar') {
        $manager = \Mercotruck\Odoo\OdooManager::getInstance($config);
        $client = $manager->getAR();
        $uid = $client->getUid();
        $company = $client->search_read('res.company', [], ['id', 'name'], 1);

        \Mercotruck\Utils\Response::ok([
            'test' => 'odoo-ar',
            'uid' => $uid,
            'company' => $company[0] ?? null
        ]);
    }

    // Test Odoo CL
    if ($path === '/test-odoo-cl') {
        try {
            $manager = \Mercotruck\Odoo\OdooManager::getInstance($config);
            $client = $manager->getCL();
            $uid = $client->getUid();
            $company = $client->search_read('res.company', [], ['id', 'name'], 1);

            \Mercotruck\Utils\Response::ok([
                'test' => 'odoo-cl',
                'uid' => $uid,
                'company' => $company[0] ?? null
            ]);
        } catch (Exception $e) {
            \Mercotruck\Utils\Response::error('Odoo CL no disponible: ' . $e->getMessage(), 503);
        }
    }

    // Test ambos Odoo
    if ($path === '/test-connections') {
        $manager = \Mercotruck\Odoo\OdooManager::getInstance($config);
        $results = $manager->testConnections();
        \Mercotruck\Utils\Response::ok(['connections' => $results]);
    }

    // ----------------------------------------
    // SYNC ENDPOINTS
    // ----------------------------------------

    // Sync Empresa (POST con ID o GET con ?id=)
    if ($path === '/sync/empresa') {
        $input = $method === 'POST' ? $getJsonInput() : $_GET;
        $empresaId = $input['id'] ?? $input['empresa_id'] ?? null;

        if (!$empresaId) {
            \Mercotruck\Utils\Response::error('id o empresa_id es requerido', 400);
        }

        $airtable = new \Mercotruck\Airtable\AirtableClient(
            $config['airtable']['api_key'],
            $config['airtable']['base_id']
        );
        
        $manager = \Mercotruck\Odoo\OdooManager::getInstance($config);
        $partnerService = new \Mercotruck\Odoo\PartnerService($manager, $config);
        
        $record = $airtable->getRecord('Empresas', $empresaId);
        $result = $partnerService->syncFromAirtable($record);
        
        // Actualizar Airtable con el partner_id
        $updateField = $result['country'] === 'ar' ? 'odoo_partner_id_ar' : 'odoo_partner_id_cl';
        try {
            $airtable->updateRecord('Empresas', $empresaId, [
                $updateField => (string) $result['id']
            ]);
        } catch (Exception $e) {}
        
        \Mercotruck\Utils\Response::ok($result);
    }

    // Sync Productos
    if ($path === '/sync/productos') {
        $sync = new \Mercotruck\Endpoints\ProductoSync();
        $sync->run($config);
    }

    // Sync Tarifas
    if ($path === '/sync/tarifas') {
        $sync = new \Mercotruck\Endpoints\TarifaSync();
        $sync->run($config);
    }

    // ----------------------------------------
    // MASTER ENDPOINTS
    // ----------------------------------------

    // Procesar Master (crear analítica)
    if ($path === '/sync/master-analytic' || $path === '/masters/process') {
        $input = $method === 'POST' ? $getJsonInput() : $_GET;
        $masterId = $input['id'] ?? $input['master_id'] ?? null;

        if (!$masterId) {
            \Mercotruck\Utils\Response::error('master_id es requerido', 400);
        }

        $airtable = new \Mercotruck\Airtable\AirtableClient(
            $config['airtable']['api_key'],
            $config['airtable']['base_id']
        );
        $manager = \Mercotruck\Odoo\OdooManager::getInstance($config);
        $masterService = new \Mercotruck\Services\MasterService($airtable, $manager, $config);
        
        $result = $masterService->processMaster($masterId);
        \Mercotruck\Utils\Response::ok($result);
    }

    // Facturar Master (crear factura cliente)
    if ($path === '/invoices/master' || $path === '/masters/invoice') {
        $input = $method === 'POST' ? $getJsonInput() : $_GET;
        $masterId = $input['id'] ?? $input['master_id'] ?? null;

        if (!$masterId) {
            \Mercotruck\Utils\Response::error('master_id es requerido', 400);
        }

        $airtable = new \Mercotruck\Airtable\AirtableClient(
            $config['airtable']['api_key'],
            $config['airtable']['base_id']
        );
        $manager = \Mercotruck\Odoo\OdooManager::getInstance($config);
        $masterService = new \Mercotruck\Services\MasterService($airtable, $manager, $config);
        
        $result = $masterService->invoiceMaster($masterId);
        \Mercotruck\Utils\Response::ok($result);
    }

    // MVP: Master Invoice (legacy endpoint)
    if ($path === '/sync/master-invoice-mvp') {
        $airtable = new \Mercotruck\Airtable\AirtableClient(
            $config['airtable']['api_key'],
            $config['airtable']['base_id']
        );
        
        // Usar config antiguo para compatibilidad
        $odooConfig = $config['odoo_ar'] ?? $config['odoo'] ?? [];
        $odoo = new \Mercotruck\Odoo\OdooClient(
            $odooConfig['url'],
            $odooConfig['db'],
            $odooConfig['username'],
            $odooConfig['password']
        );

        $productoMvpId = $config['productos']['transporte']['ar'] ?? $config['producto_mvp_id'] ?? 2;

        $sync = new \Mercotruck\Endpoints\MasterInvoiceMvp($airtable, $odoo, $productoMvpId);
        $sync->run();
    }

    // ----------------------------------------
    // OPERACION ENDPOINTS
    // ----------------------------------------

    // Procesar Operación (crear analítica)
    if ($path === '/sync/operacion-analytic' || $path === '/operaciones/process') {
        $input = $method === 'POST' ? $getJsonInput() : $_GET;
        $opId = $input['id'] ?? $input['operacion_id'] ?? null;

        if (!$opId) {
            \Mercotruck\Utils\Response::error('operacion_id es requerido', 400);
        }

        $airtable = new \Mercotruck\Airtable\AirtableClient(
            $config['airtable']['api_key'],
            $config['airtable']['base_id']
        );
        $manager = \Mercotruck\Odoo\OdooManager::getInstance($config);
        $opService = new \Mercotruck\Services\OperacionService($airtable, $manager, $config);
        
        $result = $opService->processOperacion($opId);
        \Mercotruck\Utils\Response::ok($result);
    }

    // Facturar Fletero (crear factura proveedor)
    if ($path === '/invoices/fletero' || $path === '/operaciones/invoice-fletero') {
        $input = $method === 'POST' ? $getJsonInput() : $_GET;
        $opId = $input['id'] ?? $input['operacion_id'] ?? null;

        if (!$opId) {
            \Mercotruck\Utils\Response::error('operacion_id es requerido', 400);
        }

        $airtable = new \Mercotruck\Airtable\AirtableClient(
            $config['airtable']['api_key'],
            $config['airtable']['base_id']
        );
        $manager = \Mercotruck\Odoo\OdooManager::getInstance($config);
        $opService = new \Mercotruck\Services\OperacionService($airtable, $manager, $config);
        
        $result = $opService->invoiceFletero($opId);
        \Mercotruck\Utils\Response::ok($result);
    }

    // ----------------------------------------
    // DEBUG ENDPOINTS
    // ----------------------------------------

    // Debug Master
    if ($path === '/debug/master') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            \Mercotruck\Utils\Response::error('id es requerido', 400);
        }

        $airtable = new \Mercotruck\Airtable\AirtableClient(
            $config['airtable']['api_key'],
            $config['airtable']['base_id']
        );

        $record = $airtable->getRecord('Masters', $id);
        \Mercotruck\Utils\Response::ok($record['fields'] ?? []);
    }

    // Debug Operacion
    if ($path === '/debug/operacion') {
        $airtable = new \Mercotruck\Airtable\AirtableClient(
            $config['airtable']['api_key'],
            $config['airtable']['base_id']
        );

        $debug = new \Mercotruck\Endpoints\DebugOperacion($airtable);
        $debug->run();
    }

    // ----------------------------------------
    // WEBHOOK ENDPOINTS (para Airtable automations)
    // ----------------------------------------

    // Webhook: Master creado/actualizado
    if ($path === '/webhooks/airtable/master') {
        $input = $getJsonInput();
        $masterId = $input['record_id'] ?? $input['master_id'] ?? $input['id'] ?? null;

        if (!$masterId) {
            \Mercotruck\Utils\Response::error('record_id es requerido', 400);
        }

        $airtable = new \Mercotruck\Airtable\AirtableClient(
            $config['airtable']['api_key'],
            $config['airtable']['base_id']
        );
        $manager = \Mercotruck\Odoo\OdooManager::getInstance($config);
        $masterService = new \Mercotruck\Services\MasterService($airtable, $manager, $config);
        
        $result = $masterService->processMaster($masterId);
        \Mercotruck\Utils\Response::ok(['webhook' => 'master', 'result' => $result]);
    }

    // Webhook: Operación creada/actualizada
    if ($path === '/webhooks/airtable/operacion') {
        $input = $getJsonInput();
        $opId = $input['record_id'] ?? $input['operacion_id'] ?? $input['id'] ?? null;

        if (!$opId) {
            \Mercotruck\Utils\Response::error('record_id es requerido', 400);
        }

        $airtable = new \Mercotruck\Airtable\AirtableClient(
            $config['airtable']['api_key'],
            $config['airtable']['base_id']
        );
        $manager = \Mercotruck\Odoo\OdooManager::getInstance($config);
        $opService = new \Mercotruck\Services\OperacionService($airtable, $manager, $config);
        
        $result = $opService->processOperacion($opId);
        \Mercotruck\Utils\Response::ok(['webhook' => 'operacion', 'result' => $result]);
    }

    // Webhook: Master listo para facturar
    if ($path === '/webhooks/airtable/master-ready-to-invoice') {
        $input = $getJsonInput();
        $masterId = $input['record_id'] ?? $input['master_id'] ?? $input['id'] ?? null;

        if (!$masterId) {
            \Mercotruck\Utils\Response::error('record_id es requerido', 400);
        }

        $airtable = new \Mercotruck\Airtable\AirtableClient(
            $config['airtable']['api_key'],
            $config['airtable']['base_id']
        );
        $manager = \Mercotruck\Odoo\OdooManager::getInstance($config);
        $masterService = new \Mercotruck\Services\MasterService($airtable, $manager, $config);
        
        $result = $masterService->invoiceMaster($masterId);
        \Mercotruck\Utils\Response::ok(['webhook' => 'master-invoice', 'result' => $result]);
    }

    // Webhook: Factura fletero lista
    if ($path === '/webhooks/airtable/fletero-invoice') {
        $input = $getJsonInput();
        $opId = $input['record_id'] ?? $input['operacion_id'] ?? $input['id'] ?? null;

        if (!$opId) {
            \Mercotruck\Utils\Response::error('record_id es requerido', 400);
        }

        $airtable = new \Mercotruck\Airtable\AirtableClient(
            $config['airtable']['api_key'],
            $config['airtable']['base_id']
        );
        $manager = \Mercotruck\Odoo\OdooManager::getInstance($config);
        $opService = new \Mercotruck\Services\OperacionService($airtable, $manager, $config);
        
        $result = $opService->invoiceFletero($opId);
        \Mercotruck\Utils\Response::ok(['webhook' => 'fletero-invoice', 'result' => $result]);
    }

    // 404 - Ruta no encontrada
    \Mercotruck\Utils\Response::notFound();

} catch (Exception $e) {
    // Error no manejado
    \Mercotruck\Utils\Response::internalError('Error interno: ' . $e->getMessage());
} finally {
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}
