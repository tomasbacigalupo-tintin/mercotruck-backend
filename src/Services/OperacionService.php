<?php
/**
 * OperacionService - Lógica de negocio para Operaciones/Camiones
 * Gestiona sincronización y facturación a fleteros
 */

namespace Mercotruck\Services;

use Mercotruck\Airtable\AirtableClient;
use Mercotruck\Odoo\OdooManager;
use Mercotruck\Odoo\PartnerService;
use Mercotruck\Odoo\AnalyticService;
use Mercotruck\Odoo\InvoiceService;
use Mercotruck\Utils\Logger;
use Exception;

class OperacionService
{
    private AirtableClient $airtable;
    private OdooManager $odooManager;
    private PartnerService $partnerService;
    private AnalyticService $analyticService;
    private InvoiceService $invoiceService;
    private Logger $logger;
    private array $config;

    public function __construct(
        AirtableClient $airtable,
        OdooManager $odooManager,
        array $config
    ) {
        $this->airtable = $airtable;
        $this->odooManager = $odooManager;
        $this->config = $config;
        $this->logger = new Logger();
        
        $this->partnerService = new PartnerService($odooManager, $config);
        $this->analyticService = new AnalyticService($odooManager, $config);
        $this->invoiceService = new InvoiceService($odooManager, $config);
    }

    /**
     * Procesa una Operación: crea analítica
     */
    public function processOperacion(string $operacionId): array
    {
        $this->logger->logSyncStart('operacion_process', ['operacion_id' => $operacionId]);
        
        // 1. Obtener Operación de Airtable
        $opRecord = $this->airtable->getRecord('tblV9e6v8lhdMCqUG', $operacionId);
        
        if (empty($opRecord)) {
            throw new Exception("Operación no encontrada: {$operacionId}");
        }
        
        $fields = $opRecord['fields'] ?? [];
        $opName = $fields['Nombre de Operación'] ?? $fields['Nombre de Operacion'] ?? $operacionId;
        
        // 2. Obtener Master padre (si existe)
        $masterIds = $fields['Master'] ?? [];
        $masterId = !empty($masterIds) ? $masterIds[0] : null;
        $masterName = null;
        
        if ($masterId) {
            try {
                $masterRecord = $this->airtable->getRecord('Masters', $masterId);
                $masterName = $masterRecord['fields']['Master'] ?? null;
            } catch (Exception $e) {
                // Continuar sin Master
            }
        }
        
        // 3. Determinar país de costo (donde está el fletero)
        $companyCost = $this->determineCompanyCost($fields);
        
        // 4. Obtener/crear fletero en Odoo
        $fleteroData = $this->extractFleteroData($fields);
        $partnerResult = null;
        
        if (!empty($fleteroData['name'])) {
            $partnerResult = $this->partnerService->findOrCreate($companyCost, $fleteroData);
        }
        
        // 5. Crear cuenta analítica para la Operación
        $analyticResult = $this->analyticService->createForOperacion(
            $companyCost,
            $opName,
            $masterName,
            $partnerResult ? $partnerResult['id'] : null
        );
        
        // 6. Actualizar Airtable
        $updateFields = [];
        if ($companyCost === 'ar') {
            $updateFields['odoo_analytic_id_operacion_ar'] = (string) $analyticResult['id'];
        } else {
            $updateFields['odoo_analytic_id_operacion_cl'] = (string) $analyticResult['id'];
        }
        $updateFields['company_cost'] = strtoupper($companyCost);
        
        try {
            $this->airtable->updateRecord('tblV9e6v8lhdMCqUG', $operacionId, $updateFields);
        } catch (Exception $e) {
            $this->logger->log('WARNING', 'operacion_process', 'No se pudo actualizar Airtable', []);
        }
        
        $result = [
            'operacion_id' => $operacionId,
            'operacion_name' => $opName,
            'master_name' => $masterName,
            'company_cost' => $companyCost,
            'partner' => $partnerResult,
            'analytic' => $analyticResult
        ];
        
        $this->logger->logSyncSuccess('operacion_process', $result);
        
        return $result;
    }

    /**
     * Crea factura de proveedor para una Operación (pago a fletero)
     */
    public function invoiceFletero(string $operacionId): array
    {
        $this->logger->logSyncStart('fletero_invoice', ['operacion_id' => $operacionId]);
        
        // 1. Obtener Operación
        $opRecord = $this->airtable->getRecord('tblV9e6v8lhdMCqUG', $operacionId);
        $fields = $opRecord['fields'] ?? [];
        $opName = $fields['Nombre de Operación'] ?? $fields['Nombre de Operacion'] ?? $operacionId;
        
        // 2. Determinar país
        $companyCost = $fields['company_cost'] ?? $this->determineCompanyCost($fields);
        $companyCost = strtolower($companyCost);
        
        // 3. Obtener/crear fletero
        $fleteroData = $this->extractFleteroData($fields);
        
        if (empty($fleteroData['name'])) {
            throw new Exception("No hay fletero asociado a la operación");
        }
        
        $partnerResult = $this->partnerService->findOrCreate($companyCost, $fleteroData);
        
        // 4. Obtener cuenta analítica
        $masterIds = $fields['Master'] ?? [];
        $masterName = null;
        if (!empty($masterIds)) {
            try {
                $masterRecord = $this->airtable->getRecord('Masters', $masterIds[0]);
                $masterName = $masterRecord['fields']['Master'] ?? null;
            } catch (Exception $e) {}
        }
        
        $analyticResult = $this->analyticService->createForOperacion(
            $companyCost,
            $opName,
            $masterName,
            $partnerResult['id']
        );
        
        // 5. Calcular montos
        $tarifaCompra = $this->normalizeTarifa($fields['Tarifa de Compra'] ?? null);
        
        if ($tarifaCompra <= 0) {
            throw new Exception("No hay tarifa de compra válida");
        }
        
        // 6. Preparar líneas de factura
        $lines = [];
        
        // Línea principal: flete
        $origen = $this->extractLookup($fields['Origen'] ?? null);
        $destino = $this->extractLookup($fields['Destino'] ?? null);
        
        $description = "Flete {$opName}";
        if ($origen) $description .= " - {$origen}";
        if ($destino) $description .= " → {$destino}";
        
        $lines[] = [
            'product_id' => $this->config['productos']['flete_subcontratado'][$companyCost] ?? 3,
            'name' => $description,
            'quantity' => 1,
            'price_unit' => $tarifaCompra,
            'analytic_account_id' => $analyticResult['id']
        ];
        
        // Línea de estadía (si aplica)
        // TODO: agregar cuando tengamos campos de estadía en Airtable
        
        $totalCompra = $tarifaCompra;
        
        // 7. Crear factura de proveedor
        $invoiceResult = $this->invoiceService->createVendorInvoice($companyCost, [
            'partner_id' => $partnerResult['id'],
            'ref' => "Operación {$opName}",
            'lines' => $lines
        ]);
        
        // 8. Actualizar Airtable
        $updateFields = [
            'estado_contable_operacion' => 'facturado',
            'odoo_purchase_invoice_id' => (string) $invoiceResult['id']
        ];
        
        try {
            $this->airtable->updateRecord('tblV9e6v8lhdMCqUG', $operacionId, $updateFields);
        } catch (Exception $e) {
            $this->logger->log('WARNING', 'fletero_invoice', 'No se pudo actualizar Airtable', []);
        }
        
        $result = [
            'operacion_id' => $operacionId,
            'operacion_name' => $opName,
            'company' => $companyCost,
            'fletero' => $partnerResult,
            'invoice' => $invoiceResult,
            'total' => $totalCompra
        ];
        
        $this->logger->logSyncSuccess('fletero_invoice', $result);
        
        return $result;
    }

    /**
     * Determina el país para costos (donde está el fletero)
     */
    private function determineCompanyCost(array $fields): string
    {
        // Si ya está definido
        if (!empty($fields['company_cost'])) {
            return strtolower($fields['company_cost']);
        }
        
        // Inferir del país del fletero
        $paisFletero = $this->extractLookup($fields['País'] ?? null);
        
        if ($paisFletero && (stripos($paisFletero, 'Chile') !== false || strtoupper($paisFletero) === 'CL')) {
            return 'cl';
        }
        
        // Inferir del origen
        $origen = $this->extractLookup($fields['Origen'] ?? null);
        if ($origen && stripos($origen, 'Chile') !== false) {
            return 'cl';
        }
        
        return 'ar';
    }

    /**
     * Extrae datos del fletero desde la Operación
     */
    private function extractFleteroData(array $fields): array
    {
        // El fletero puede venir de varios campos
        $chofer = $this->extractLookup($fields['Chofer'] ?? null);
        $transportista = $this->extractLookup($fields['Transportista'] ?? null);
        $cuit = $this->extractLookup($fields['CUIT'] ?? null);
        
        $nombre = $transportista ?? $chofer ?? '';
        
        return [
            'name' => $nombre,
            'vat' => $cuit,
            'tipo' => 'fletero'
        ];
    }

    /**
     * Normaliza tarifa
     */
    private function normalizeTarifa($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        
        if (is_array($value)) {
            return !empty($value) ? (float) $value[0] : 0.0;
        }
        
        return (float) $value;
    }

    /**
     * Extrae valor de lookup
     */
    private function extractLookup($value): ?string
    {
        if ($value === null) {
            return null;
        }
        
        if (is_array($value)) {
            return !empty($value) ? (string) $value[0] : null;
        }
        
        return (string) $value;
    }
}
