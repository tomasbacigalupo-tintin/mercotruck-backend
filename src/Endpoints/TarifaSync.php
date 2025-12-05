<?php
namespace Mercotruck\Endpoints;

use Mercotruck\Airtable\AirtableClient;
use Mercotruck\Utils\Response;
use Mercotruck\Utils\Logger;
use Exception;

/**
 * Sincroniza tarifas de flete desde Airtable a Odoo
 * Crea registros en sale.pricelist y sale.pricelist.item
 */
class TarifaSync extends BaseOdooSync
{
    private Logger $logger;
    private AirtableClient $airtable;
    private int $pricelistId;

    public function run(array $config): void
    {
        $this->logger = new Logger();

        try {
            // Inicializar clientes
            $this->initOdoo($config);
            $this->airtable = new AirtableClient(
                $config['airtable']['api_key'] ?? '',
                $config['airtable']['base_id'] ?? ''
            );

            $this->logger->logSyncStart('tarifa', ['source' => 'airtable']);

            // Crear o usar pricelist base
            $this->ensurePricelistExists();

            // Obtener tarifas desde Airtable
            $tarifas = $this->getTarifasFromAirtable();

            $details = [];
            $summary = ['created' => 0, 'updated' => 0, 'errors' => 0];

            foreach ($tarifas as $tarifa) {
                try {
                    $result = $this->syncTarifa($tarifa);
                    $details[] = $result;
                    $summary[$result['action']]++;

                    $this->logger->logRecord('tarifa', $result['action'], 'sale.pricelist.item', $result['id']);
                } catch (Exception $e) {
                    $details[] = [
                        'origin' => $tarifa['origin'] ?? 'unknown',
                        'destination' => $tarifa['destination'] ?? 'unknown',
                        'action' => 'error',
                        'error' => $e->getMessage()
                    ];
                    $summary['errors']++;
                    $this->logger->logSyncError('tarifa', $e->getMessage(), $tarifa);
                }
            }

            $this->logger->logSyncSuccess('tarifa', $summary);

            Response::ok([
                'summary' => $summary,
                'details' => $details
            ]);

        } catch (Exception $e) {
            $this->logger->logSyncError('tarifa', $e->getMessage(), []);
            Response::error('Error en sincronización de tarifas: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Crea o usa la pricelist "Tarifas Flete"
     */
    private function ensurePricelistExists(): void
    {
        try {
            // Primero intentar buscar una existente
            $existing = $this->searchOne('product.pricelist', [['name', '=', 'Tarifas Flete']], ['id']);
            
            if ($existing) {
                $this->pricelistId = $existing['id'];
                return;
            }
            
            // Intentar crear una nueva
            $currencyId = $this->resolveCurrency();
            
            $createResult = $this->odoo->call('product.pricelist', 'create', [[
                'name' => 'Tarifas Flete',
                'active' => true,
                'currency_id' => $currencyId
            ]], []);
            
            if (isset($createResult['result'])) {
                $this->pricelistId = $createResult['result'];
                return;
            }
            
            // Si no puede crear, buscar cualquier pricelist existente
            $anyPricelist = $this->searchOne('product.pricelist', [['active', '=', true]], ['id']);
            if ($anyPricelist) {
                $this->pricelistId = $anyPricelist['id'];
                return;
            }
            
            throw new Exception('No se pudo crear ni encontrar una pricelist');
            
        } catch (Exception $e) {
            // Último intento: buscar cualquier pricelist
            $anyPricelist = $this->searchOne('product.pricelist', [], ['id']);
            if ($anyPricelist) {
                $this->pricelistId = $anyPricelist['id'];
                return;
            }
            throw new Exception('Error con pricelist: ' . $e->getMessage());
        }
    }

    /**
     * Obtiene tarifas desde Airtable
     * Esperado: tabla "Tarifas" con campos: origin, destination, price, min_weight, max_weight
     */
    private function getTarifasFromAirtable(): array
    {
        try {
            $records = $this->airtable->searchRecords('Tarifas', []);
        } catch (Exception $e) {
            // Si la tabla no existe o hay error, retornar vacío
            return [];
        }

        $tarifas = [];
        foreach ($records as $record) {
            if (isset($record['fields'])) {
                $tarifas[] = [
                    'airtable_id' => $record['id'],
                    'origin' => $record['fields']['Origin'] ?? $record['fields']['Origen'] ?? 'Unknown',
                    'destination' => $record['fields']['Destination'] ?? $record['fields']['Destino'] ?? 'Unknown',
                    'price' => floatval($record['fields']['Price'] ?? $record['fields']['Precio'] ?? 0),
                    'min_weight' => floatval($record['fields']['MinWeight'] ?? $record['fields']['PesoMin'] ?? 0),
                    'max_weight' => floatval($record['fields']['MaxWeight'] ?? $record['fields']['PesoMax'] ?? 999),
                ];
            }
        }

        return $tarifas;
    }

    /**
     * Sincroniza una tarifa individual (create or update)
     */
    private function syncTarifa(array $tarifa): array
    {
        $domainKey = "{$tarifa['origin']} - {$tarifa['destination']}";

        $result = $this->createOrUpdate(
            'sale.pricelist.item',
            [['name', '=', $domainKey]],
            [
                'name' => $domainKey,
                'pricelist_id' => $this->pricelistId,
                'applied_on' => '3_global',
                'compute_price' => 'fixed',
                'fixed_price' => $tarifa['price'],
                'min_quantity' => 1
            ],
            [
                'fixed_price' => $tarifa['price'],
                'name' => $domainKey
            ]
        );

        return [
            'origin' => $tarifa['origin'],
            'destination' => $tarifa['destination'],
            'price' => $tarifa['price'],
            'action' => $result['action'],
            'id' => $result['id']
        ];
    }

    /**
     * Resuelve la currency (ARS para Argentina)
     */
    private function resolveCurrency(): int
    {
        $currency = $this->searchOne('res.currency', [['name', '=', 'ARS']], ['id']);
        if (!$currency) {
            $currency = $this->searchOne('res.currency', [['name', '=', 'USD']], ['id']);
        }
        if (!$currency) {
            throw new Exception('No se encontró currency ARS o USD en Odoo');
        }
        return $currency['id'];
    }
}
?>
