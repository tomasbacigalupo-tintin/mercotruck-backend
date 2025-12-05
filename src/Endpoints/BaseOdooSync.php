<?php
namespace Mercotruck\Endpoints;

use Mercotruck\Odoo\OdooClient;
use Exception;

/**
 * Clase base reutilizable para sincronizaciones con Odoo
 * Proporciona helpers comunes que se pueden usar en ProductoSync, EmpresaSync, etc.
 */
abstract class BaseOdooSync
{
    protected OdooClient $odoo;

    /**
     * Inicializa el cliente Odoo
     */
    protected function initOdoo(array $config): void
    {
        if (!isset($config['odoo'])) {
            throw new Exception('Configuración Odoo no disponible');
        }

        $this->odoo = new OdooClient(
            $config['odoo']['url'] ?? '',
            $config['odoo']['db'] ?? '',
            $config['odoo']['username'] ?? '',
            $config['odoo']['password'] ?? ''
        );
    }

    /**
     * Helper genérico: busca 1 registro en Odoo
     * Devuelve array con el registro o null si no existe
     *
     * @param string $model Modelo Odoo (ej: "product.template")
     * @param array $domain Dominio de búsqueda (ej: [['name', '=', 'Test']])
     * @param array $fields Campos a retornar (ej: ['id', 'name'])
     * @return array|null
     */
    protected function searchOne(string $model, array $domain, array $fields): ?array
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
     * Helper genérico: busca múltiples registros
     *
     * @param string $model
     * @param array $domain
     * @param array $fields
     * @param int $limit
     * @return array
     */
    protected function searchMany(string $model, array $domain, array $fields, int $limit = 100): array
    {
        $result = $this->odoo->call(
            $model,
            'search_read',
            [$domain],
            ['fields' => $fields, 'limit' => $limit]
        );

        return $result['result'] ?? [];
    }

    /**
     * Helper: busca un registro por ID exacto
     */
    protected function findById(string $model, int $id, array $fields): ?array
    {
        $result = $this->odoo->call(
            $model,
            'read',
            [[$id], $fields],
            []
        );

        $records = $result['result'] ?? [];
        return !empty($records) ? $records[0] : null;
    }

    /**
     * Helper: crea o actualiza (upsert pattern)
     * Busca por domain, si existe hace write, si no existe hace create
     *
     * @param string $model
     * @param array $searchDomain Dominio para buscar
     * @param array $createData Datos para create (incluye todo)
     * @param array $updateData Datos para write (actualiza solo esto)
     * @return array ['id' => int, 'action' => 'created'|'updated']
     */
    protected function createOrUpdate(string $model, array $searchDomain, array $createData, array $updateData): array
    {
        $existing = $this->searchOne($model, $searchDomain, ['id']);

        if ($existing) {
            // UPDATE
            $this->odoo->call($model, 'write', [[$existing['id']], $updateData]);
            return [
                'id' => $existing['id'],
                'action' => 'updated'
            ];
        } else {
            // CREATE
            $result = $this->odoo->call($model, 'create', [$createData]);
            $newId = $result['result'] ?? null;

            if (!$newId) {
                throw new Exception("Error al crear registro en {$model}");
            }

            return [
                'id' => $newId,
                'action' => 'created'
            ];
        }
    }

    /**
     * Helper: deleta un registro por ID
     */
    protected function delete(string $model, int $id): bool
    {
        $result = $this->odoo->call($model, 'unlink', [[$id]], []);
        return isset($result['result']) && $result['result'] === true;
    }

    /**
     * Helper: valida que un campo existe en un registro
     */
    protected function validateField(array $record, string $field): void
    {
        if (!isset($record[$field])) {
            throw new Exception("Campo requerido '{$field}' no encontrado en registro");
        }
    }

    /**
     * Helper: mapea un array de records a nuevos valores
     */
    protected function mapRecords(array $records, callable $mapper): array
    {
        return array_map($mapper, $records);
    }
}
?>
