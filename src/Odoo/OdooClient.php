<?php

namespace Mercotruck\Odoo;

use Exception;

class OdooClient
{
    private $url;
    private $db;
    private $username;
    private $password;
    private $uid;
    private $companyId;

    public function __construct($url, $db, $username, $password, $companyId = null)
    {
        $this->url = rtrim($url, "/");
        $this->db = $db;
        $this->username = $username;
        $this->password = $password;
        $this->companyId = $companyId;

        $this->uid = $this->login();
    }

    private function login()
    {
        $payload = [
            "jsonrpc" => "2.0",
            "method"  => "call",
            "params"  => [
                "service" => "common",
                "method"  => "login",
                "args"    => [
                    $this->db,
                    $this->username,
                    $this->password
                ]
            ],
            "id" => uniqid()
        ];

        $res = $this->request($payload);

        if (!isset($res["result"])) {
            throw new Exception("Login failed: " . json_encode($res));
        }

        return $res["result"];
    }

    public function call($model, $method, $args = [], $kwargs = [])
    {
        // Add company context if set
        if ($this->companyId && !isset($kwargs['context'])) {
            $kwargs['context'] = [
                'allowed_company_ids' => [$this->companyId],
                'company_id' => $this->companyId
            ];
        } elseif ($this->companyId && isset($kwargs['context'])) {
            $kwargs['context']['allowed_company_ids'] = [$this->companyId];
            $kwargs['context']['company_id'] = $this->companyId;
        }

        $payload = [
            "jsonrpc" => "2.0",
            "method"  => "call",
            "params"  => [
                "service" => "object",
                "method"  => "execute_kw",
                "args"    => [
                    $this->db,
                    $this->uid,
                    $this->password,
                    $model,
                    $method,
                    $args,
                    $kwargs
                ]
            ],
            "id" => uniqid()
        ];

        return $this->request($payload);
    }

    private function request($payload)
    {
        $ch = curl_init($this->url . "/jsonrpc");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        // FIX LOCAL: desactivar verificación SSL (ajustar en producción)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            throw new Exception("cURL error: " . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            throw new Exception("HTTP error {$httpCode}: " . $response);
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        return $decoded;
    }

    public function getUid(): int
    {
        return $this->uid;
    }

    public function authenticate(): int
    {
        return $this->uid;
    }

    public function search_read($model, $domain = [], $fields = [], $limit = 0)
    {
        $kwargs = [];
        if (!empty($fields)) { $kwargs["fields"] = $fields; }
        if ($limit > 0) { $kwargs["limit"] = $limit; }

        $res = $this->call(
            $model,
            "search_read",
            [$domain],
            $kwargs
        );
        return $res["result"] ?? [];
    }
} ?>

