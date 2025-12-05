<?php
/**
 * Test de Odoo Client
 * 
 * Verifica conexión y operaciones básicas con Odoo
 * Ejecución: php test/test-odoo.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../src/Config/config.php';
require __DIR__ . '/../src/Odoo/OdooClient.php';

echo "=== TEST ODOO CLIENT ===\n\n";

try {
    echo "[1] Inicializando cliente Odoo...\n";
    $odoo = new \Mercotruck\Odoo\OdooClient(
        $config['odoo']['url'],
        $config['odoo']['db'],
        $config['odoo']['username'],
        $config['odoo']['password']
    );

    echo "[2] Autenticación...\n";
    $uid = $odoo->authenticate();
    echo "✓ UID autenticado: {$uid}\n\n";

    echo "[3] Obteniendo compañía...\n";
    $company = $odoo->search_read('res.company', [], ['id', 'name'], 1);
    if (!empty($company)) {
        echo "✓ Compañía: " . $company[0]['name'] . " (ID: " . $company[0]['id'] . ")\n\n";
    } else {
        echo "⚠ No se encontró compañía\n\n";
    }

    echo "[4] Buscando partners...\n";
    $partners = $odoo->search_read('res.partner', [], ['id', 'name', 'email'], 5);
    echo "✓ Se encontraron " . count($partners) . " partners (mostrando los primeros 5):\n";
    foreach ($partners as $p) {
        echo "  - {$p['name']} ({$p['id']}) | {$p['email']}\n";
    }

    echo "\n✓ TODOS LOS TESTS PASARON\n";

} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
