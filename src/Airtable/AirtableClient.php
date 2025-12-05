<?php

namespace Mercotruck\Airtable;

use Exception;

class AirtableClient
{
    private $apiKey;
    private $baseId;

    public function __construct(string $apiKey, string $baseId)
    {
        $this->apiKey = $apiKey;
        $this->baseId = $baseId;
    }

    public function getRecord(string $table, string $recordId): array
    {
        $url = "https://api.airtable.com/v0/{$this->baseId}/" . urlencode($table) . "/" . urlencode($recordId);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->apiKey}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        // Desactivar SSL local (ajustar en producción)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception("Airtable cURL error: " . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            throw new Exception("Airtable HTTP error {$httpCode}: " . $response);
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Airtable JSON decode error: " . json_last_error_msg());
        }

        return $decoded ?? [];
    }

    /**
     * Obtiene todos los registros de una tabla
     * Soporta paginación automática
     */
    public function searchRecords(string $table, array $filterByFormula = []): array
    {
        $allRecords = [];
        $offset = null;

        do {
            $url = "https://api.airtable.com/v0/{$this->baseId}/" . urlencode($table);

            $params = [];
            if (!empty($filterByFormula)) {
                $params['filterByFormula'] = $filterByFormula;
            }
            if ($offset) {
                $params['offset'] = $offset;
            }

            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$this->apiKey}",
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                throw new Exception("Airtable cURL error: " . curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode >= 400) {
                throw new Exception("Airtable HTTP error {$httpCode}: " . $response);
            }

            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Airtable JSON decode error: " . json_last_error_msg());
            }

            if (!isset($decoded['records'])) {
                break;
            }

            $allRecords = array_merge($allRecords, $decoded['records']);
            $offset = $decoded['offset'] ?? null;

        } while ($offset);

        return $allRecords;
    }

    /**
     * Actualiza un registro en Airtable
     */
    public function updateRecord(string $table, string $recordId, array $fields): array
    {
        $url = "https://api.airtable.com/v0/{$this->baseId}/" . urlencode($table) . "/" . urlencode($recordId);

        $payload = ['fields' => $fields];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->apiKey}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception("Airtable cURL error: " . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            throw new Exception("Airtable HTTP error {$httpCode}: " . $response);
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Airtable JSON decode error: " . json_last_error_msg());
        }

        return $decoded ?? [];
    }

    /**
     * Crea un registro en Airtable
     */
    public function createRecord(string $table, array $fields): array
    {
        $url = "https://api.airtable.com/v0/{$this->baseId}/" . urlencode($table);

        $payload = ['fields' => $fields];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->apiKey}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception("Airtable cURL error: " . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            throw new Exception("Airtable HTTP error {$httpCode}: " . $response);
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Airtable JSON decode error: " . json_last_error_msg());
        }

        return $decoded ?? [];
    }
}
?>
