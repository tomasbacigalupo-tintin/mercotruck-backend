<?php
namespace Mercotruck\Endpoints;

use Mercotruck\Odoo\OdooClient;
use Mercotruck\Utils\Response;
use Mercotruck\Utils\Logger;
use Exception;

class ProductoSync
{
    private OdooClient $odoo;
    private Logger $logger;
    private array $resolvedIds = [];

    public function run(array $config): void
    {
        $this->logger = new Logger();

        try {
            if (!isset($config['odoo'])) {
                Response::error('Configuración Odoo no disponible', 400);
            }

            $this->logger->logSyncStart('producto', []);

            $this->odoo = new OdooClient(
                $config['odoo']['url'] ?? '',
                $config['odoo']['db'] ?? '',
                $config['odoo']['username'] ?? '',
                $config['odoo']['password'] ?? ''
            );

            // Resolver IDs dinámicamente una sola vez
            $this->resolveRequiredIds();

            $productos = $this->getProductosTemplate();
            $details = [];
            $summary = ['created' => 0, 'updated' => 0, 'errors' => 0];

            foreach ($productos as $producto) {
                try {
                    $result = $this->createOrUpdateProduct($producto);
                    $details[] = $result;
                    $summary[$result['action']]++;

                    $this->logger->logRecord('producto', $result['action'], 'product.template', $result['id'], [
                        'name' => $producto['name']
                    ]);
                } catch (Exception $e) {
                    $details[] = [
                        'name' => $producto['name'],
                        'action' => 'error',
                        'error' => $e->getMessage()
                    ];
                    $summary['errors']++;
                    $this->logger->logSyncError('producto', $e->getMessage(), ['product' => $producto['name']]);
                }
            }

            $this->logger->logSyncSuccess('producto', $summary);

            Response::ok([
                'summary' => $summary,
                'details' => $details
            ]);

        } catch (Exception $e) {
            $this->logger->logSyncError('producto', $e->getMessage(), []);
            Response::error('Sync error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Resuelve los IDs dinámicamente desde Odoo
     * Si alguno falta, lanza excepción
     */
    private function resolveRequiredIds(): void
    {
        try {
            // Buscar categoría "Servicios" o "Services"
            $categResult = $this->searchOne('product.category', [['name', 'ilike', 'Servi']], ['id']);
            if (!$categResult) {
                throw new Exception('No se encontró categoría de Servicios en Odoo');
            }
            $this->resolvedIds['categ_id'] = $categResult['id'];

            // Buscar UoM "Unidades" o "Units"
            $uomResult = $this->searchOne('uom.uom', [['name', 'ilike', 'Unit']], ['id']);
            if (!$uomResult) {
                throw new Exception('No se encontró unidad de medida en Odoo');
            }
            $this->resolvedIds['uom_id'] = $uomResult['id'];

            // Buscar impuesto de venta (IVA 21% típico Argentina)
            $taxResult = $this->searchOne('account.tax', [
                ['type_tax_use', '=', 'sale'],
                ['amount', '=', 21.0]
            ], ['id']);
            if (!$taxResult) {
                // Fallback: buscar cualquier tax de venta
                $taxResult = $this->searchOne('account.tax', [
                    ['type_tax_use', '=', 'sale']
                ], ['id']);
            }
            if (!$taxResult) {
                throw new Exception('No se encontró impuesto de venta en Odoo');
            }
            $this->resolvedIds['tax_id'] = $taxResult['id'];

            // Buscar cuenta de ingresos por defecto - múltiples estrategias (OPCIONAL)
            $incomeResult = null;
            
            // Patrones de código comunes en planes contables argentinos
            $codePatterns = ['4.1', '41', '70', '400', '4000'];
            foreach ($codePatterns as $pattern) {
                $incomeResult = $this->searchOne('account.account', [
                    ['code', 'ilike', $pattern . '%'],
                    ['deprecated', '=', false]
                ], ['id']);
                if ($incomeResult) break;
            }
            
            // Si no encuentra por código, buscar por nombre
            if (!$incomeResult) {
                $namePatterns = ['Ingreso', 'Venta', 'Revenue', 'Income', 'Sales'];
                foreach ($namePatterns as $pattern) {
                    $incomeResult = $this->searchOne('account.account', [
                        ['name', 'ilike', '%' . $pattern . '%'],
                        ['deprecated', '=', false]
                    ], ['id']);
                    if ($incomeResult) break;
                }
            }
            
            // Último fallback: cualquier cuenta de tipo income
            if (!$incomeResult) {
                $incomeResult = $this->searchOne('account.account', [
                    ['account_type', '=', 'income'],
                    ['deprecated', '=', false]
                ], ['id']);
            }
            
            // La cuenta de ingresos es opcional - si no existe, no se usa
            if ($incomeResult) {
                $this->resolvedIds['income_account_id'] = $incomeResult['id'];
            } else {
                $this->resolvedIds['income_account_id'] = null;
            }

        } catch (Exception $e) {
            throw new Exception('Fallo al resolver IDs requeridos: ' . $e->getMessage());
        }
    }

    /**
     * Helper genérico: busca 1 registro, devuelve array o null
     */
    private function searchOne(string $model, array $domain, array $fields): ?array
    {
        $result = $this->odoo->call(
            $model,
            'search_read',
            [$domain],
            ['fields' => $fields, 'limit' => 1]
        );

        $records = $result['result'] ?? [];
        return !empty($records) ? $records[0] : null;
    }

    /**
     * Helper: busca producto por nombre, devuelve ID o null
     */
    private function searchProductByName(string $name): ?int
    {
        $result = $this->searchOne('product.template', [['name', '=', $name]], ['id']);
        return $result ? $result['id'] : null;
    }

    /**
     * Crea o actualiza un producto
     */
    private function createOrUpdateProduct(array $producto): array
    {
        $productName = $producto['name'] ?? '';
        $existingId = $this->searchProductByName($productName);

        if ($existingId) {
            // UPDATE
            $updateData = [
                'list_price' => $producto['list_price'] ?? 0.0,
                'standard_price' => $producto['standard_price'] ?? 0.0,
                'taxes_id' => [[6, 0, [$this->resolvedIds['tax_id']]]],
                'categ_id' => $this->resolvedIds['categ_id']
            ];
            
            // Solo agregar cuenta si existe
            if ($this->resolvedIds['income_account_id']) {
                $updateData['property_account_income_id'] = $this->resolvedIds['income_account_id'];
            }

            $this->odoo->call('product.template', 'write', [[$existingId], $updateData]);

            return [
                'name' => $productName,
                'action' => 'updated',
                'id' => $existingId
            ];
        } else {
            // CREATE
            $createData = [
                'name' => $productName,
                'type' => $producto['type'] ?? 'service',
                'detailed_type' => $producto['detailed_type'] ?? 'service',
                'categ_id' => $this->resolvedIds['categ_id'],
                'uom_id' => $this->resolvedIds['uom_id'],
                'uom_po_id' => $this->resolvedIds['uom_id'],
                'list_price' => $producto['list_price'] ?? 0.0,
                'standard_price' => $producto['standard_price'] ?? 0.0,
                'sale_ok' => $producto['sale_ok'] ?? true,
                'purchase_ok' => $producto['purchase_ok'] ?? true,
                'taxes_id' => [[6, 0, [$this->resolvedIds['tax_id']]]],
                'tracking' => $producto['tracking'] ?? 'none'
            ];
            
            // Solo agregar cuenta si existe
            if ($this->resolvedIds['income_account_id']) {
                $createData['property_account_income_id'] = $this->resolvedIds['income_account_id'];
            }

            $result = $this->odoo->call('product.template', 'create', [$createData]);
            $newId = $result['result'] ?? null;

            if (!$newId) {
                throw new Exception('No se obtuvo ID del producto creado');
            }

            return [
                'name' => $productName,
                'action' => 'created',
                'id' => $newId
            ];
        }
    }

    /**
     * Define los productos base a sincronizar
     * Los IDs se resuelven dinámicamente en resolveRequiredIds()
     */
    private function getProductosTemplate(): array
    {
        return [
            [
                'name' => 'Transporte',
                'type' => 'service',
                'detailed_type' => 'service',
                'list_price' => 0.0,
                'standard_price' => 0.0,
                'sale_ok' => true,
                'purchase_ok' => true,
                'tracking' => 'none'
            ],
            [
                'name' => 'Flete',
                'type' => 'service',
                'detailed_type' => 'service',
                'list_price' => 0.0,
                'standard_price' => 0.0,
                'sale_ok' => true,
                'purchase_ok' => true,
                'tracking' => 'none'
            ],
            [
                'name' => 'Estadía',
                'type' => 'service',
                'detailed_type' => 'service',
                'list_price' => 0.0,
                'standard_price' => 0.0,
                'sale_ok' => true,
                'purchase_ok' => true,
                'tracking' => 'none'
            ]
        ];
    }
}
?>
