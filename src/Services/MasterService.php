<?php
/**
 * MasterService - Lógica de negocio para Masters
 * Gestiona sincronización, facturación y triangulación
 */

namespace Mercotruck\Services;

use Mercotruck\Airtable\AirtableClient;
use Mercotruck\Odoo\OdooManager;
use Mercotruck\Odoo\PartnerService;
use Mercotruck\Odoo\AnalyticService;
use Mercotruck\Odoo\InvoiceService;
use Mercotruck\Utils\Logger;
use Exception;

class MasterService
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
     * Procesa un Master: crea analítica y prepara para facturación
     */
    public function processMaster(string $masterId): array
    {
        $this->logger->logSyncStart('master_process', ['master_id' => $masterId]);
        
        // 1. Obtener Master de Airtable
        $masterRecord = $this->airtable->getRecord('Masters', $masterId);
        
        if (empty($masterRecord)) {
            throw new Exception("Master no encontrado: {$masterId}");
        }
        
        $fields = $masterRecord['fields'] ?? [];
        $masterName = $fields['Master'] ?? $masterId;
        
        // 2. Determinar país de venta (triangulación)
        $companyVenta = $this->determineCompanyVenta($fields);
        
        // 3. Obtener/crear cliente en Odoo
        $clienteData = $this->extractClienteData($fields);
        $partnerResult = $this->partnerService->findOrCreate($companyVenta, $clienteData);
        
        // 4. Crear cuenta analítica para el Master
        $analyticResult = $this->analyticService->createForMaster(
            $companyVenta,
            $masterName,
            $partnerResult['id']
        );
        
        // 5. Actualizar Airtable con IDs de Odoo
        $updateFields = [];
        if ($companyVenta === 'ar') {
            $updateFields['odoo_analytic_id_master_ar'] = (string) $analyticResult['id'];
            $updateFields['odoo_partner_id_ar'] = (string) $partnerResult['id'];
        } else {
            $updateFields['odoo_analytic_id_master_cl'] = (string) $analyticResult['id'];
            $updateFields['odoo_partner_id_cl'] = (string) $partnerResult['id'];
        }
        $updateFields['company_venta'] = strtoupper($companyVenta);
        
        try {
            $this->airtable->updateRecord('Masters', $masterId, $updateFields);
        } catch (Exception $e) {
            $this->logger->log('WARNING', 'master_process', 'No se pudo actualizar Airtable: ' . $e->getMessage(), []);
        }
        
        $result = [
            'master_id' => $masterId,
            'master_name' => $masterName,
            'company_venta' => $companyVenta,
            'partner' => $partnerResult,
            'analytic' => $analyticResult
        ];
        
        $this->logger->logSyncSuccess('master_process', $result);
        
        return $result;
    }

    /**
     * Factura un Master (crea factura de cliente)
     */
    public function invoiceMaster(string $masterId): array
    {
        $this->logger->logSyncStart('master_invoice', ['master_id' => $masterId]);
        
        // 1. Obtener Master
        $masterRecord = $this->airtable->getRecord('Masters', $masterId);
        $fields = $masterRecord['fields'] ?? [];
        $masterName = $fields['Master'] ?? $masterId;
        
        // 2. Verificar estado
        $estado = $fields['Estado'] ?? '';
        // Permitir facturación si está en estados avanzados
        
        // 3. Determinar país
        $companyVenta = $fields['company_venta'] ?? $this->determineCompanyVenta($fields);
        $companyVenta = strtolower($companyVenta);
        
        // 4. Obtener partner (cliente)
        $clienteData = $this->extractClienteData($fields);
        $partnerResult = $this->partnerService->findOrCreate($companyVenta, $clienteData);
        
        // 5. Obtener cuenta analítica del Master
        $analyticResult = $this->analyticService->createForMaster(
            $companyVenta,
            $masterName,
            $partnerResult['id']
        );
        
        // 6. Obtener operaciones y calcular totales
        $operacionesIds = $fields['Operaciones / Órdenes de Viaje 2'] ?? [];
        $lines = [];
        $totalVenta = 0;
        
        foreach ($operacionesIds as $opId) {
            try {
                $opRecord = $this->airtable->getRecord('tblV9e6v8lhdMCqUG', $opId);
                $opFields = $opRecord['fields'] ?? [];
                
                $tarifaVenta = $this->normalizeTarifa($opFields['Tarifa de Venta'] ?? null);
                
                if ($tarifaVenta > 0) {
                    $opName = $opFields['Nombre de Operación'] ?? $opFields['Nombre de Operacion'] ?? $opId;
                    $origen = $this->extractLookup($opFields['Origen'] ?? null);
                    $destino = $this->extractLookup($opFields['Destino'] ?? null);
                    
                    $description = "Transporte {$opName}";
                    if ($origen) $description .= " - {$origen}";
                    if ($destino) $description .= " → {$destino}";
                    
                    $lines[] = [
                        'product_id' => $this->config['productos']['transporte'][$companyVenta] ?? 2,
                        'name' => $description,
                        'quantity' => 1,
                        'price_unit' => $tarifaVenta,
                        'analytic_account_id' => $analyticResult['id']
                    ];
                    
                    $totalVenta += $tarifaVenta;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        if (empty($lines) || $totalVenta <= 0) {
            throw new Exception("No hay operaciones con tarifa de venta válida");
        }
        
        // 7. Crear factura
        $invoiceResult = $this->invoiceService->createCustomerInvoice($companyVenta, [
            'partner_id' => $partnerResult['id'],
            'ref' => "Master {$masterName}",
            'lines' => $lines
        ]);
        
        // 8. Actualizar Airtable
        $updateFields = [
            'estado_contable_master' => 'facturado',
            'numero_factura_master' => $invoiceResult['name'] ?? ''
        ];
        
        if ($companyVenta === 'ar') {
            $updateFields['odoo_invoice_id_ar'] = (string) $invoiceResult['id'];
        } else {
            $updateFields['odoo_invoice_id_cl'] = (string) $invoiceResult['id'];
        }
        
        try {
            $this->airtable->updateRecord('Masters', $masterId, $updateFields);
        } catch (Exception $e) {
            $this->logger->log('WARNING', 'master_invoice', 'No se pudo actualizar Airtable', []);
        }
        
        $result = [
            'master_id' => $masterId,
            'master_name' => $masterName,
            'company' => $companyVenta,
            'invoice' => $invoiceResult,
            'total' => $totalVenta,
            'lines_count' => count($lines)
        ];
        
        $this->logger->logSyncSuccess('master_invoice', $result);
        
        return $result;
    }

    /**
     * Determina el país para facturación (triangulación)
     */
    private function determineCompanyVenta(array $fields): string
    {
        // Si ya está definido, usarlo
        if (!empty($fields['company_venta'])) {
            return strtolower($fields['company_venta']);
        }
        
        // Inferir del cliente o destino
        $cliente = $fields['Cliente'] ?? $fields['Nombre del Cliente'] ?? '';
        $destino = $fields['Destino'] ?? [];
        
        // Si destino incluye Chile, facturar en CL
        if (is_array($destino)) {
            foreach ($destino as $d) {
                if (stripos($d, 'Chile') !== false || stripos($d, 'CL') !== false) {
                    return 'cl';
                }
            }
        }
        
        // Default: Argentina
        return 'ar';
    }

    /**
     * Extrae datos del cliente desde el Master
     */
    private function extractClienteData(array $fields): array
    {
        $nombre = $fields['Nombre del Cliente'] ?? 
                  $fields['Cliente'] ?? 
                  $fields['Nombre del Cliente Formateado'] ?? 
                  'Cliente sin nombre';
        
        return [
            'name' => $nombre,
            'tipo' => 'cliente'
        ];
    }

    /**
     * Normaliza tarifa a float
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
