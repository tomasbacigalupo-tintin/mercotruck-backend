<?php
/**
 * AnalyticService - Gestión de cuentas analíticas en Odoo
 * Crea cuentas para Masters (padre) y Operaciones (hijo)
 */

namespace Mercotruck\Odoo;

use Exception;

class AnalyticService
{
    private OdooManager $manager;
    private array $config;

    public function __construct(OdooManager $manager, array $config)
    {
        $this->manager = $manager;
        $this->config = $config;
    }

    /**
     * Busca cuenta analítica por nombre
     */
    public function findByName(string $country, string $name): ?array
    {
        $client = $this->manager->getClient($country);
        
        $result = $client->search_read(
            'account.analytic.account',
            [['name', '=', $name]],
            ['id', 'name', 'partner_id', 'plan_id'],
            1
        );
        
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Crea cuenta analítica
     */
    public function create(string $country, array $data): int
    {
        $client = $this->manager->getClient($country);
        
        $analyticData = [
            'name' => $data['name']
        ];
        
        // Partner asociado
        if (!empty($data['partner_id'])) {
            $analyticData['partner_id'] = $data['partner_id'];
        }
        
        // Plan analítico - obligatorio en Odoo 17+
        $planId = $data['plan_id'] ?? $this->getDefaultPlan($country);
        if ($planId) {
            $analyticData['plan_id'] = $planId;
        }
        
        // Código
        if (!empty($data['code'])) {
            $analyticData['code'] = $data['code'];
        }

        try {
            $result = $client->call('account.analytic.account', 'create', [$analyticData], []);
            
            if (!isset($result['result'])) {
                throw new Exception("Error creando cuenta analítica: " . json_encode($result));
            }
            
            return (int) $result['result'];
        } catch (Exception $e) {
            // Si falla, devolver un ID ficticio para continuar sin analítica
            // Esto permite que el flujo continúe aunque no se puedan crear cuentas analíticas
            return 0;
        }
    }

    /**
     * Busca o crea cuenta analítica
     */
    public function findOrCreate(string $country, array $data): array
    {
        $name = $data['name'] ?? '';
        
        $existing = $this->findByName($country, $name);
        
        if ($existing) {
            return [
                'id' => $existing['id'],
                'action' => 'existing',
                'name' => $existing['name']
            ];
        }
        
        $newId = $this->create($country, $data);
        
        return [
            'id' => $newId,
            'action' => 'created',
            'name' => $name
        ];
    }

    /**
     * Crea cuenta analítica para un Master
     * Formato: MASTER-{id}
     */
    public function createForMaster(string $country, string $masterId, ?int $partnerId = null): array
    {
        $name = "MASTER-{$masterId}";
        
        return $this->findOrCreate($country, [
            'name' => $name,
            'code' => $masterId,
            'partner_id' => $partnerId
        ]);
    }

    /**
     * Crea cuenta analítica para una Operación/Camión
     * Formato: OP-{id} (hija del Master)
     */
    public function createForOperacion(string $country, string $operacionId, ?string $masterId = null, ?int $partnerId = null): array
    {
        $name = "OP-{$operacionId}";
        
        // Si hay Master, indicarlo en el código
        $code = $masterId ? "{$masterId}/{$operacionId}" : $operacionId;
        
        return $this->findOrCreate($country, [
            'name' => $name,
            'code' => $code,
            'partner_id' => $partnerId
        ]);
    }

    /**
     * Obtiene el plan analítico por defecto (si existe)
     */
    public function getDefaultPlan(string $country): ?int
    {
        try {
            $client = $this->manager->getClient($country);
            
            // Buscar plan "Mercotruck" o el primero disponible
            $result = $client->search_read(
                'account.analytic.plan',
                [['name', 'ilike', 'Mercotruck']],
                ['id'],
                1
            );
            
            if (!empty($result)) {
                return $result[0]['id'];
            }
            
            // Fallback: primer plan activo
            $result = $client->search_read(
                'account.analytic.plan',
                [],
                ['id'],
                1
            );
            
            return !empty($result) ? $result[0]['id'] : null;
            
        } catch (Exception $e) {
            // El modelo puede no existir en versiones antiguas de Odoo
            return null;
        }
    }
}
