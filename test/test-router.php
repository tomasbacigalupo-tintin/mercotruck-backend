<?php
/**
 * Test del Router
 * 
 * Verifica las rutas disponibles del backend
 * Ejecución: php -S localhost:8000 (y luego curl)
 * O: php test/test-router.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

echo "=== TEST ROUTER ===\n\n";

$baseUrl = 'http://localhost:8000';

// Si se ejecuta como script CLI, verificar disponibilidad
if (php_sapi_name() === 'cli') {
    echo "INSTRUCCIONES PARA TESTING:\n\n";
    echo "1. Abre una terminal y ejecuta:\n";
    echo "   cd " . __DIR__ . "/..\n";
    echo "   php -S localhost:8000\n\n";

    echo "2. En otra terminal, ejecuta los siguientes curl commands:\n\n";

    echo "# Health check\n";
    echo "curl -X GET http://localhost:8000/health\n";
    echo "curl -X GET http://localhost:8000/\n\n";

    echo "# Test Odoo\n";
    echo "curl -X GET http://localhost:8000/test-odoo\n\n";

    echo "# Sync Empresa (requiere ID válido de Airtable)\n";
    echo "curl -X GET 'http://localhost:8000/sync/empresa?id=recXXXXXXXXXXXXXX'\n\n";

    echo "# Sync Productos\n";
    echo "curl -X POST http://localhost:8000/sync/productos\n\n";

    echo "# 404 (debe devolver error)\n";
    echo "curl -X GET http://localhost:8000/ruta-inexistente\n\n";

    exit(0);
}

// Si se ejecuta como request HTTP
echo "Router CLI test mode. Run 'php -S localhost:8000' to start the server.\n";
?>
