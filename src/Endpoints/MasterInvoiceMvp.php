<?php
namespace Mercotruck\Endpoints;

use Mercotruck\Airtable\AirtableClient;
use Mercotruck\Odoo\OdooClient;
use Mercotruck\Utils\Response;
use Mercotruck\Utils\Logger;
use Exception;

class MasterInvoiceMvp
{
    private AirtableClient $airtable;
    private OdooClient $odoo;
    private Logger $logger;
    private int $productoMvpId;

    public function __construct(AirtableClient $airtable, OdooClient $odoo, int $productoMvpId)
    {
        $this->airtable = $airtable;
        $this->odoo = $odoo;
        $this->logger = new Logger();
        $this->productoMvpId = $productoMvpId;
    }

    public function run(): void
    {
        try {
            // Obtener JSON del request
            $rawInput = file_get_contents('php://input');
            error_log("MasterInvoiceMvp: raw input = " . substr($rawInput, 0, 100));
            
            $input = json_decode($rawInput, true);
            error_log("MasterInvoiceMvp: decoded input = " . json_encode($input));

            if (!$input || !isset($input['master_id'])) {
                error_log("MasterInvoiceMvp: missing master_id");
                Response::json(['ok' => false, 'error' => 'master_id es requerido'], 400);
                return;
            }

            $masterId = $input['master_id'];
            error_log("MasterInvoiceMvp: processing master_id = $masterId");

            $this->logger->logSyncStart('master_invoice_mvp', ['master_id' => $masterId]);

            // 1. Obtener el registro Master de Airtable
            error_log("MasterInvoiceMvp: getting master record from Airtable");
            $masterRecord = $this->airtable->getRecord('Masters', $masterId);
            error_log("MasterInvoiceMvp: master record obtained, empty=" . (empty($masterRecord) ? 'yes' : 'no'));

            if (empty($masterRecord)) {
                Response::json(['ok' => false, 'error' => "Master record not found: {$masterId}"], 404);
                return;
            }

            $masterFields = $masterRecord['fields'] ?? [];
            error_log("MasterInvoiceMvp: master fields count=" . count($masterFields));

            // 2. Verificar que el estado sea "Prefacturada"
            $estado = $masterFields['Estado'] ?? null;
            error_log("MasterInvoiceMvp: estado = $estado");

            if ($estado !== 'Prefacturada') {
                Response::json(['ok' => false, 'error' => "Master status is '{$estado}', expected 'Prefacturada'"], 400);
                return;
            }

            // 3. Obtener operaciones vinculadas
            $operacionesIds = $masterFields['Operaciones / Órdenes de Viaje 2'] ?? [];
            error_log("MasterInvoiceMvp: operaciones count=" . count($operacionesIds));

            if (empty($operacionesIds)) {
                Response::json(['ok' => false, 'error' => 'Master has no linked operations'], 400);
                return;
            }

            // 4. Obtener cliente real para facturación
            // La estrategia es: obtener Tarjeta Madre desde operaciones, y buscar "Cliente (from Negocios)" en Tarjeta Madre
            $clienteNombre = null;

            // Buscar en las operaciones para obtener Tarjeta Madre
            foreach ($operacionesIds as $operacionId) {
                try {
                    $operacionRecord = $this->airtable->getRecord('tblV9e6v8lhdMCqUG', $operacionId);
                    $opFields = $operacionRecord['fields'] ?? [];
                    
                    // Obtener ID de Tarjeta Madre
                    $tarjetaMadreIds = $opFields['Tarjeta Madre (from Solicitudes)'] ?? [];
                    if (!empty($tarjetaMadreIds) && is_array($tarjetaMadreIds)) {
                        $tarjetaMadreId = $tarjetaMadreIds[0];
                        
                        // Obtener Tarjeta Madre de tabla Tarjetas Madre (tblgNDyHnuG4pppWY)
                        $tarjetaMadreRecord = $this->airtable->getRecord('tblgNDyHnuG4pppWY', $tarjetaMadreId);
                        $tmFields = $tarjetaMadreRecord['fields'] ?? [];
                        
                        // Buscar cliente en "Cliente (from Negocios)"
                        $clienteData = $tmFields['Cliente (from Negocios)'] ?? null;
                        if (!empty($clienteData)) {
                            $clienteNombre = $this->extractFromLookup($clienteData);
                            if (!empty($clienteNombre)) {
                                break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Continuar con la siguiente operación
                    continue;
                }
            }

            // Si aún no hay cliente, devolver error
            if (empty($clienteNombre)) {
                Response::error("No se encontró cliente en Tarjeta Madre", 400);
                return;
            }

            // 5. Procesar operaciones y construir líneas de factura
            $invoiceLines = [];
            $totalVenta = 0;

            foreach ($operacionesIds as $operacionId) {
                try {
                    $operacionRecord = $this->airtable->getRecord('tblV9e6v8lhdMCqUG', $operacionId);
                    $opFields = $operacionRecord['fields'] ?? [];

                    // Obtener tarifas
                    $tarifaVenta = $this->normalizeTarifa($opFields['Tarifa de Venta'] ?? null);
                    $tarifaCompra = $this->normalizeTarifa($opFields['Tarifa de Compra'] ?? null);

                    // Obtener información adicional para la descripción
                    $opID = $opFields['Nombre de Operacion'] ?? $opFields['ID'] ?? 'SIN-ID';
                    $origen = $this->extractFromLookup($opFields['Origen'] ?? null);
                    $destino = $this->extractFromLookup($opFields['Destino (from Negocios) (from Tarjeta Madre) (from Solicitudes) (from Operaciones / Órdenes de Viaje 2)'] ?? null);

                    // Si no encontró destino con nombre largo, intentar con otro campo
                    if (!$destino) {
                        $destino = $this->extractFromLookup($opFields['Destino'] ?? null);
                    }

                    // Construir nombre de línea SIN Customer
                    $lineDescription = "Operacion {$opID}";
                    if ($origen) {
                        $lineDescription .= " – $origen";
                    }
                    if ($destino) {
                        $lineDescription .= " → $destino";
                    }

                    // Agregar línea de factura solo si hay tarifa de venta
                    if ($tarifaVenta > 0) {
                        $invoiceLines[] = [
                            'product_id' => $this->productoMvpId,
                            'quantity' => 1,
                            'price_unit' => $tarifaVenta,
                            'name' => $lineDescription
                        ];
                        $totalVenta += $tarifaVenta;
                    }

                    $this->logger->logRecord('master_invoice_mvp', 'read', 'Operaciones', $operacionId, [
                        'tarifa_venta' => $tarifaVenta,
                        'tarifa_compra' => $tarifaCompra,
                        'description' => $lineDescription
                    ]);

                } catch (Exception $e) {
                    $this->logger->logSyncError('master_invoice_mvp', "Error reading operation {$operacionId}: " . $e->getMessage(), []);
                }
            }

            // 6. Validar que hay líneas y total válido
            if (empty($invoiceLines) || $totalVenta <= 0) {
                Response::json(['ok' => false, 'error' => 'No valid operations with tarifa de venta found'], 400);
                return;
            }

            // 7. Buscar o crear partner en Odoo usando cliente encontrado
            $partnerId = $this->findOrCreatePartner($clienteNombre);

            // 8. Crear factura en Odoo con las líneas
            $invoiceId = $this->createInvoice($partnerId, $invoiceLines, $totalVenta);

            $this->logger->logRecord('master_invoice_mvp', 'create', 'account.move', $invoiceId, [
                'master_id' => $masterId,
                'partner_id' => $partnerId,
                'cliente' => $clienteNombre,
                'total' => $totalVenta,
                'lines_count' => count($invoiceLines)
            ]);

            $this->logger->logSyncSuccess('master_invoice_mvp', [
                'master_id' => $masterId,
                'invoice_id' => $invoiceId,
                'total' => $totalVenta
            ]);

            Response::json([
                'ok' => true,
                'master_id' => $masterId,
                'invoice_id' => $invoiceId,
                'total' => $totalVenta
            ]);

        } catch (Exception $e) {
            $errorMsg = 'Invoice creation failed: ' . $e->getMessage();
            $this->logger->logSyncError('master_invoice_mvp', $e->getMessage(), []);
            
            // Usar Response::json que ya maneja buffers correctamente
            Response::json(['ok' => false, 'error' => $errorMsg], 500);
        }
    }

    /**
     * Normaliza una tarifa a float, manejando arrays, null, strings, números
     */
    private function normalizeTarifa($rawValue): float
    {
        // Si es null o vacío, retornar 0
        if ($rawValue === null || $rawValue === '') {
            return 0.0;
        }

        // Si es array (lookup), tomar el primer elemento
        if (is_array($rawValue)) {
            if (empty($rawValue)) {
                return 0.0;
            }
            $rawValue = $rawValue[0];
        }

        // Convertir a float, si no es numérico retorna 0
        return (float) ($rawValue ?? 0);
    }

    /**
     * Extrae el primer valor de un lookup/array, o retorna null
     */
    private function extractFromLookup($rawValue): ?string
    {
        if ($rawValue === null) {
            return null;
        }

        if (is_array($rawValue)) {
            if (empty($rawValue)) {
                return null;
            }
            return (string) $rawValue[0];
        }

        $stringValue = trim((string) $rawValue);
        return empty($stringValue) ? null : $stringValue;
    }

    /**
     * Busca un partner por nombre, o lo crea si no existe
     */
    private function findOrCreatePartner(string $clienteName): int
    {
        try {
            // Buscar partner existente
            $partners = $this->odoo->search_read('res.partner', [['name', '=', $clienteName]], ['id', 'name'], 1);

            if (!empty($partners)) {
                return (int) $partners[0]['id'];
            }

            // Crear nuevo partner
            $result = $this->odoo->call('res.partner', 'create', [['name' => $clienteName]], []);

            if (!isset($result['result'])) {
                throw new Exception("Failed to create partner: " . json_encode($result));
            }

            $partnerId = (int) $result['result'];

            $this->logger->logRecord('master_invoice_mvp', 'create', 'res.partner', $partnerId, [
                'name' => $clienteName
            ]);

            return $partnerId;

        } catch (Exception $e) {
            throw new Exception("Error with partner '{$clienteName}': " . $e->getMessage());
        }
    }

    /**
     * Crea una factura en Odoo con múltiples líneas
     */
    private function createInvoice(int $partnerId, array $invoiceLines, float $totalAmount): int
    {
        try {
            // Convertir líneas al formato esperado por Odoo
            $odooLines = [];
            foreach ($invoiceLines as $line) {
                $odooLines[] = [
                    0, 0, [
                        'product_id' => $line['product_id'],
                        'quantity' => $line['quantity'],
                        'price_unit' => $line['price_unit'],
                        'name' => $line['name']
                    ]
                ];
            }

            $invoiceData = [
                'partner_id' => $partnerId,
                'move_type' => 'out_invoice',
                'invoice_line_ids' => $odooLines
            ];

            $result = $this->odoo->call('account.move', 'create', [$invoiceData], []);

            if (!isset($result['result'])) {
                throw new Exception("Failed to create invoice: " . json_encode($result));
            }

            return (int) $result['result'];

        } catch (Exception $e) {
            throw new Exception("Error creating invoice: " . $e->getMessage());
        }
    }
}
