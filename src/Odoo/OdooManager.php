<?php
/**
 * OdooManager - Gestiona conexiones a múltiples instancias de Odoo
 * Soporta AR (Argentina) y CL (Chile)
 */

namespace Mercotruck\Odoo;

use Exception;

class OdooManager
{
    private static ?OdooManager $instance = null;
    private array $clients = [];
    private array $config;

    private function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Singleton - obtiene instancia del manager
     */
    public static function getInstance(array $config): OdooManager
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Obtiene cliente Odoo para un país específico
     * @param string $country 'ar' o 'cl'
     */
    public function getClient(string $country): OdooClient
    {
        $country = strtolower($country);
        
        if (!in_array($country, ['ar', 'cl'])) {
            throw new Exception("País no soportado: {$country}. Usar 'ar' o 'cl'");
        }

        // Lazy loading - solo conectar cuando se necesite
        if (!isset($this->clients[$country])) {
            $configKey = "odoo_{$country}";
            
            if (!isset($this->config[$configKey])) {
                throw new Exception("Configuración no encontrada para Odoo {$country}");
            }

            $cfg = $this->config[$configKey];
            
            try {
                $this->clients[$country] = new OdooClient(
                    $cfg['url'],
                    $cfg['db'],
                    $cfg['username'],
                    $cfg['password'],
                    $cfg['company_id'] ?? null  // Pass company_id for multi-company
                );
            } catch (Exception $e) {
                throw new Exception("Error conectando a Odoo {$country}: " . $e->getMessage());
            }
        }

        return $this->clients[$country];
    }

    /**
     * Obtiene cliente AR
     */
    public function getAR(): OdooClient
    {
        return $this->getClient('ar');
    }

    /**
     * Obtiene cliente CL
     */
    public function getCL(): OdooClient
    {
        return $this->getClient('cl');
    }

    /**
     * Obtiene configuración de un país
     */
    public function getCountryConfig(string $country): array
    {
        $country = strtolower($country);
        $configKey = "odoo_{$country}";
        
        if (!isset($this->config[$configKey])) {
            throw new Exception("Configuración no encontrada para: {$country}");
        }
        
        return $this->config[$configKey];
    }

    /**
     * Determina el país según lógica de negocio
     * Triangulación: cliente AR + fletero CL = factura en AR, costo en CL
     */
    public static function determineCountry(?string $paisCliente, ?string $paisFletero = null, string $tipo = 'venta'): string
    {
        // Para ventas: usar país del cliente
        if ($tipo === 'venta') {
            if (empty($paisCliente)) {
                return 'ar'; // default
            }
            return strtolower($paisCliente) === 'cl' ? 'cl' : 'ar';
        }

        // Para compras/costos: usar país del fletero
        if ($tipo === 'compra' || $tipo === 'costo') {
            if (empty($paisFletero)) {
                return 'ar'; // default
            }
            return strtolower($paisFletero) === 'cl' ? 'cl' : 'ar';
        }

        return 'ar';
    }

    /**
     * Verifica conexión a ambos Odoo
     */
    public function testConnections(): array
    {
        $results = [];
        
        foreach (['ar', 'cl'] as $country) {
            try {
                $client = $this->getClient($country);
                $uid = $client->getUid();
                $results[$country] = [
                    'status' => 'ok',
                    'uid' => $uid
                ];
            } catch (Exception $e) {
                $results[$country] = [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
}
