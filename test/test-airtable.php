<?php
/**
 * Test de Airtable Client
 * 
 * Verifica conexión y operaciones básicas con Airtable API
 * Ejecución: php test/test-airtable.php <recordId>
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../src/Config/config.php';
require __DIR__ . '/../src/Airtable/AirtableClient.php';

echo "=== TEST AIRTABLE CLIENT ===\n\n";

if ($argc < 2) {
    echo "Uso: php test/test-airtable.php <recordId>\n";
    echo "Ejemplo: php test/test-airtable.php rec123456789\n";
    exit(1);
}

$recordId = $argv[1];

try {
    echo "[1] Inicializando cliente Airtable...\n";
    $airtable = new \Mercotruck\Airtable\AirtableClient(
        $config['airtable']['api_key'],
        $config['airtable']['base_id']
    );

    echo "[2] Obteniendo registro: {$recordId}...\n";
    $record = $airtable->getRecord('Empresas', $recordId);

    if (isset($record['fields'])) {
        echo "✓ Registro obtenido:\n";
        echo "  ID: " . $record['id'] . "\n";
        echo "  Campos: " . json_encode($record['fields'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "⚠ Registro no encontrado o no tiene campos\n";
        echo "Respuesta: " . json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }

    echo "\n✓ TEST COMPLETADO\n";

} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
