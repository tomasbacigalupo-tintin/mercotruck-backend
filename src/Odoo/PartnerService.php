<?php
/**
 * PartnerService - Gestión de contactos/empresas en Odoo
 * Sincroniza empresas de Airtable a Odoo AR/CL
 */

namespace Mercotruck\Odoo;

use Exception;

class PartnerService
{
    private OdooManager $manager;
    private array $config;

    public function __construct(OdooManager $manager, array $config)
    {
        $this->manager = $manager;
        $this->config = $config;
    }

    /**
     * Busca un partner por nombre o VAT
     */
    public function findPartner(string $country, string $name, ?string $vat = null): ?array
    {
        $client = $this->manager->getClient($country);
        
        $domain = [];
        
        if ($vat) {
            // Buscar primero por VAT (CUIT/RUT)
            $domain = [['vat', '=', $vat]];
            $result = $client->search_read('res.partner', $domain, ['id', 'name', 'vat'], 1);
            if (!empty($result)) {
                return $result[0];
            }
        }
        
        // Si no hay VAT o no se encontró, buscar por nombre exacto
        $domain = [['name', '=', $name]];
        $result = $client->search_read('res.partner', $domain, ['id', 'name', 'vat'], 1);
        
        if (!empty($result)) {
            return $result[0];
        }
        
        // Buscar por nombre similar
        $domain = [['name', 'ilike', $name]];
        $result = $client->search_read('res.partner', $domain, ['id', 'name', 'vat'], 1);
        
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Crea un partner en Odoo
     */
    public function createPartner(string $country, array $data): int
    {
        $client = $this->manager->getClient($country);
        
        $partnerData = [
            'name' => $data['name'] ?? 'Sin nombre',
            'is_company' => true,
            'customer_rank' => ($data['tipo'] ?? '') === 'cliente' ? 1 : 0,
            'supplier_rank' => in_array($data['tipo'] ?? '', ['fletero', 'proveedor']) ? 1 : 0,
        ];
        
        // VAT (CUIT/RUT)
        if (!empty($data['vat'])) {
            $partnerData['vat'] = $data['vat'];
        }
        
        // Email
        if (!empty($data['email'])) {
            $partnerData['email'] = $data['email'];
        }
        
        // Teléfono
        if (!empty($data['phone'])) {
            $partnerData['phone'] = $data['phone'];
        }
        
        // País
        if (!empty($data['country_code'])) {
            $countryId = $this->resolveCountryId($client, $data['country_code']);
            if ($countryId) {
                $partnerData['country_id'] = $countryId;
            }
        }
        
        $result = $client->call('res.partner', 'create', [$partnerData], []);
        
        if (!isset($result['result'])) {
            throw new Exception("Error creando partner: " . json_encode($result));
        }
        
        return (int) $result['result'];
    }

    /**
     * Actualiza un partner existente
     */
    public function updatePartner(string $country, int $partnerId, array $data): bool
    {
        $client = $this->manager->getClient($country);
        
        $updateData = [];
        
        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }
        if (isset($data['vat'])) {
            $updateData['vat'] = $data['vat'];
        }
        if (isset($data['email'])) {
            $updateData['email'] = $data['email'];
        }
        if (isset($data['phone'])) {
            $updateData['phone'] = $data['phone'];
        }
        
        if (empty($updateData)) {
            return true; // Nada que actualizar
        }
        
        $result = $client->call('res.partner', 'write', [[$partnerId], $updateData], []);
        
        return isset($result['result']) && $result['result'] === true;
    }

    /**
     * Busca o crea un partner (upsert)
     */
    public function findOrCreate(string $country, array $data): array
    {
        $name = $data['name'] ?? '';
        $vat = $data['vat'] ?? null;
        
        // Buscar existente
        $existing = $this->findPartner($country, $name, $vat);
        
        if ($existing) {
            // Actualizar si hay cambios
            $this->updatePartner($country, $existing['id'], $data);
            return [
                'id' => $existing['id'],
                'action' => 'updated',
                'name' => $existing['name']
            ];
        }
        
        // Crear nuevo
        $newId = $this->createPartner($country, $data);
        
        return [
            'id' => $newId,
            'action' => 'created',
            'name' => $name
        ];
    }

    /**
     * Sincroniza una empresa desde Airtable a Odoo
     * Decide AR/CL según el país de la empresa
     */
    public function syncFromAirtable(array $airtableRecord): array
    {
        $fields = $airtableRecord['fields'] ?? [];
        
        // Obtener datos
        $name = $fields['Nombre'] ?? $fields['Razón Social'] ?? $fields['Name'] ?? 'Sin nombre';
        $vat = $fields['CUIT/RUT'] ?? $fields['CUIT'] ?? $fields['RUT'] ?? null;
        $pais = $fields['País'] ?? $fields['Pais'] ?? 'AR';
        $tipo = strtolower($fields['Tipo'] ?? 'cliente');
        $email = $fields['Email'] ?? $fields['Mail'] ?? null;
        
        // Determinar país destino
        $country = OdooManager::determineCountry($pais, null, 'venta');
        
        $data = [
            'name' => $name,
            'vat' => $vat,
            'tipo' => $tipo,
            'email' => $email,
            'country_code' => $pais
        ];
        
        $result = $this->findOrCreate($country, $data);
        $result['country'] = $country;
        $result['airtable_id'] = $airtableRecord['id'] ?? null;
        
        return $result;
    }

    /**
     * Resuelve ID del país en Odoo
     */
    private function resolveCountryId(OdooClient $client, string $countryCode): ?int
    {
        $code = strtoupper($countryCode);
        
        $result = $client->search_read('res.country', [['code', '=', $code]], ['id'], 1);
        
        return !empty($result) ? $result[0]['id'] : null;
    }
}
