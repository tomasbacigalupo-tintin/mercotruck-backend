<?php
namespace Mercotruck\Endpoints;

use Mercotruck\Airtable\AirtableClient;
use Mercotruck\Utils\Response;
use Mercotruck\Utils\Logger;
use Exception;

class EmpresaSync extends BaseOdooSync
{
    private Logger $logger;
    private AirtableClient $airtable;
    private array $companyId;

    public function run(array $config): void
    {
        $this->logger = new Logger();

        try {
            // Validar parámetro
            if (!isset($_GET['id'])) {
                Response::error('Falta parámetro id', 400);
            }

            $recordId = trim($_GET['id']);
            if (empty($recordId)) {
                Response::error('Parámetro id no puede estar vacío', 400);
            }

            // Inicializar clientes
            $this->initOdoo($config);
            $this->airtable = new AirtableClient(
                $config['airtable']['api_key'] ?? '',
                $config['airtable']['base_id'] ?? ''
            );

            $this->logger->logSyncStart('empresa', ['airtable_id' => $recordId]);

            // Resolver company_id dinámicamente
            $this->resolveCompanyId();

            // Obtener datos de Airtable
            try {
                $empresa = $this->airtable->getRecord('Empresas', $recordId);
            } catch (Exception $e) {
                $this->logger->logSyncError('empresa', 'Airtable error', ['error' => $e->getMessage()]);
                Response::error('Error al conectar con Airtable: ' . $e->getMessage(), 500);
            }

            if (!isset($empresa['fields'])) {
                $this->logger->logSyncError('empresa', 'Registro inválido en Airtable', ['record_id' => $recordId]);
                Response::error('Registro de empresa no encontrado o inválido en Airtable', 404);
            }

            $fields = $empresa['fields'];

            // Mapear campos
            $nombre = $fields['Razon_Social'] ?? $fields['Name'] ?? 'Sin Nombre';
            $email = $fields['Email'] ?? null;
            $telefono = $fields['Telefono'] ?? $fields['Phone'] ?? null;
            $cuit = $fields['CUIT'] ?? null;

            // Crear o actualizar partner
            $result = $this->createOrUpdate(
                'res.partner',
                [['name', '=', $nombre]],
                [
                    'name' => $nombre,
                    'email' => $email,
                    'phone' => $telefono,
                    'vat' => $cuit,  // Mapear CUIT al campo VAT de Odoo
                    'customer_rank' => 1,
                    'company_id' => $this->companyId['id'] ?? 1
                ],
                [
                    'email' => $email,
                    'phone' => $telefono,
                    'vat' => $cuit
                ]
            );

            $this->logger->logRecord('empresa', $result['action'], 'res.partner', $result['id'], [
                'name' => $nombre,
                'airtable_id' => $recordId
            ]);

            $this->logger->logSyncSuccess('empresa', [
                'action' => $result['action'],
                'partner_id' => $result['id'],
                'airtable_id' => $recordId
            ]);

            Response::ok([
                'action' => $result['action'],
                'partner_id' => $result['id'],
                'airtable_id' => $recordId,
                'name' => $nombre
            ]);

        } catch (Exception $e) {
            $this->logger->logSyncError('empresa', $e->getMessage(), []);
            Response::error('Error en sincronización de empresa: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Resuelve el company_id dinámicamente
     * En multicompany Odoo, busca la compañía default
     */
    private function resolveCompanyId(): void
    {
        try {
            $company = $this->searchOne('res.company', [], ['id', 'name']);
            if (!$company) {
                throw new Exception('No se encontró compañía en Odoo');
            }
            $this->companyId = $company;
        } catch (Exception $e) {
            throw new Exception('Fallo al resolver company_id: ' . $e->getMessage());
        }
    }
}
?>

