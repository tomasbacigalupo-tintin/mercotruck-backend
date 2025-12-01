<?php

header('Content-Type: application/json');

require_once __DIR__ . '/vendor/autoload.php';

use Mercotruck\Odoo\OdooClient;

$config = require __DIR__ . '/src/Config/config.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/mercotruck-backend', '', $path);

try {

    if ($path === '/health') {
        echo json_encode(['health' => 'ok', 'timestamp' => time()]);
        exit;
    }

    if ($path === '/odoo/clients') {

        $client = new OdooClient($config['odoo']);

        $customers = $client->search_read(
            'res.partner',
            ['name', 'email', 'phone', 'vat'],
            [['customer_rank', '>', 0]]
        );

        echo json_encode($customers);
        exit;
    }

    echo json_encode(['error' => 'Ruta no encontrada', 'path' => $path]);
    exit;

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
