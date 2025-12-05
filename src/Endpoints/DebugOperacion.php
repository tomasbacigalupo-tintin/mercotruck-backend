<?php
namespace Mercotruck\Endpoints;

use Mercotruck\Airtable\AirtableClient;
use Mercotruck\Utils\Response;
use Exception;

class DebugOperacion
{
    private AirtableClient $airtable;

    public function __construct(AirtableClient $airtable)
    {
        $this->airtable = $airtable;
    }

    public function run(): void
    {
        try {
            $id = $_GET['id'] ?? null;

            if (!$id) {
                Response::json(['ok' => false, 'error' => 'id es requerido'], 400);
                return;
            }

            $record = $this->airtable->getRecord('tblV9e6v8lhdMCqUG', $id);

            $fields = $record['fields'] ?? [];

            // Ordenar campos alfabéticamente
            ksort($fields);

            Response::json(['ok' => true, 'fields' => $fields]);

        } catch (Exception $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
