<?php
/**
 * InvoiceService - Gestión de facturas en Odoo
 * Soporta facturas de cliente (out_invoice) y proveedor (in_invoice)
 */

namespace Mercotruck\Odoo;

use Exception;

class InvoiceService
{
    private OdooManager $manager;
    private array $config;

    public function __construct(OdooManager $manager, array $config)
    {
        $this->manager = $manager;
        $this->config = $config;
    }

    /**
     * Crea factura de cliente (out_invoice)
     */
    public function createCustomerInvoice(string $country, array $data): array
    {
        $client = $this->manager->getClient($country);
        
        // Preparar líneas de factura
        $lines = [];
        foreach ($data['lines'] ?? [] as $line) {
            $lineData = [
                'product_id' => $line['product_id'] ?? $this->getDefaultProductId($country),
                'name' => $line['name'] ?? 'Servicio de transporte',
                'quantity' => $line['quantity'] ?? 1,
                'price_unit' => $line['price_unit'] ?? 0,
            ];
            
            // Nota: analytic_account_id se maneja a nivel de factura completa, no por línea
            // en esta versión de Odoo
            
            $lines[] = [0, 0, $lineData];
        }
        
        // Datos de factura
        $invoiceData = [
            'move_type' => 'out_invoice',
            'partner_id' => $data['partner_id'],
            'invoice_line_ids' => $lines
        ];
        
        // Journal (diario de ventas)
        if (!empty($data['journal_id'])) {
            $invoiceData['journal_id'] = $data['journal_id'];
        }
        
        // Moneda
        if (!empty($data['currency_id'])) {
            $invoiceData['currency_id'] = $data['currency_id'];
        }
        
        // Referencia
        if (!empty($data['ref'])) {
            $invoiceData['ref'] = $data['ref'];
        }
        
        // Narración/notas
        if (!empty($data['narration'])) {
            $invoiceData['narration'] = $data['narration'];
        }
        
        $result = $client->call('account.move', 'create', [$invoiceData], []);
        
        if (!isset($result['result'])) {
            throw new Exception("Error creando factura cliente: " . json_encode($result));
        }
        
        $invoiceId = (int) $result['result'];
        
        // Obtener datos de la factura creada
        $invoice = $client->search_read(
            'account.move',
            [['id', '=', $invoiceId]],
            ['id', 'name', 'state', 'amount_total', 'amount_untaxed'],
            1
        );
        
        return [
            'id' => $invoiceId,
            'name' => $invoice[0]['name'] ?? 'Borrador',
            'state' => $invoice[0]['state'] ?? 'draft',
            'amount_total' => $invoice[0]['amount_total'] ?? 0,
            'amount_untaxed' => $invoice[0]['amount_untaxed'] ?? 0,
            'country' => $country
        ];
    }

    /**
     * Crea factura de proveedor/fletero (in_invoice)
     */
    public function createVendorInvoice(string $country, array $data): array
    {
        $client = $this->manager->getClient($country);
        
        // Preparar líneas
        $lines = [];
        foreach ($data['lines'] ?? [] as $line) {
            $lineData = [
                'product_id' => $line['product_id'] ?? $this->getFleteProductId($country),
                'name' => $line['name'] ?? 'Servicio de flete',
                'quantity' => $line['quantity'] ?? 1,
                'price_unit' => $line['price_unit'] ?? 0,
            ];
            
            // Nota: analytic se maneja a nivel de factura completa
            
            $lines[] = [0, 0, $lineData];
        }
        
        // Datos de factura proveedor
        $invoiceData = [
            'move_type' => 'in_invoice',
            'partner_id' => $data['partner_id'],
            'invoice_line_ids' => $lines
        ];
        
        if (!empty($data['journal_id'])) {
            $invoiceData['journal_id'] = $data['journal_id'];
        }
        
        if (!empty($data['currency_id'])) {
            $invoiceData['currency_id'] = $data['currency_id'];
        }
        
        if (!empty($data['ref'])) {
            $invoiceData['ref'] = $data['ref'];
        }
        
        // Referencia del proveedor (número de factura del fletero)
        if (!empty($data['supplier_invoice_number'])) {
            $invoiceData['ref'] = $data['supplier_invoice_number'];
        }
        
        $result = $client->call('account.move', 'create', [$invoiceData], []);
        
        if (!isset($result['result'])) {
            throw new Exception("Error creando factura proveedor: " . json_encode($result));
        }
        
        $invoiceId = (int) $result['result'];
        
        $invoice = $client->search_read(
            'account.move',
            [['id', '=', $invoiceId]],
            ['id', 'name', 'state', 'amount_total'],
            1
        );
        
        return [
            'id' => $invoiceId,
            'name' => $invoice[0]['name'] ?? 'Borrador',
            'state' => $invoice[0]['state'] ?? 'draft',
            'amount_total' => $invoice[0]['amount_total'] ?? 0,
            'country' => $country
        ];
    }

    /**
     * Confirma/valida una factura
     */
    public function confirmInvoice(string $country, int $invoiceId): bool
    {
        $client = $this->manager->getClient($country);
        
        try {
            $result = $client->call('account.move', 'action_post', [[$invoiceId]], []);
            return true;
        } catch (Exception $e) {
            throw new Exception("Error confirmando factura: " . $e->getMessage());
        }
    }

    /**
     * Obtiene factura por ID
     */
    public function getInvoice(string $country, int $invoiceId): ?array
    {
        $client = $this->manager->getClient($country);
        
        $result = $client->search_read(
            'account.move',
            [['id', '=', $invoiceId]],
            ['id', 'name', 'state', 'amount_total', 'amount_untaxed', 'partner_id', 'move_type'],
            1
        );
        
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Busca facturas de un partner
     */
    public function findByPartner(string $country, int $partnerId, string $type = 'out_invoice'): array
    {
        $client = $this->manager->getClient($country);
        
        return $client->search_read(
            'account.move',
            [['partner_id', '=', $partnerId], ['move_type', '=', $type]],
            ['id', 'name', 'state', 'amount_total', 'invoice_date'],
            50
        );
    }

    /**
     * Obtiene ID de producto de transporte
     */
    private function getDefaultProductId(string $country): int
    {
        return $this->config['productos']['transporte'][$country] ?? 2;
    }

    /**
     * Obtiene ID de producto de flete subcontratado
     */
    private function getFleteProductId(string $country): int
    {
        return $this->config['productos']['flete_subcontratado'][$country] ?? 3;
    }

    /**
     * Obtiene ID de producto de estadía
     */
    private function getEstadiaProductId(string $country): int
    {
        return $this->config['productos']['estadia'][$country] ?? 4;
    }

    /**
     * Resuelve ID de moneda
     */
    public function resolveCurrency(string $country, string $currencyCode): ?int
    {
        $client = $this->manager->getClient($country);
        
        $result = $client->search_read(
            'res.currency',
            [['name', '=', strtoupper($currencyCode)]],
            ['id'],
            1
        );
        
        return !empty($result) ? $result[0]['id'] : null;
    }
}
