<?php

namespace Mercotruck\Odoo;

use GuzzleHttp\Client;

class OdooClient {

    private $url;
    private $db;
    private $username;
    private $password;
    private $http;
    private $uid;

    public function __construct($config)
    {
        $this->url      = $config['url'];
        $this->db       = $config['db'];
        $this->username = $config['username'];
        $this->password = $config['password'];

        $this->http = new Client([
            'base_uri' => $this->url . '/jsonrpc',
            'timeout'  => 10,
        ]);

        $this->uid = $this->authenticate();
    }

    private function call($method, $params)
    {
        $payload = [
            'jsonrpc' => "2.0",
            'method'  => $method,
            'params'  => $params,
            'id'      => time()
        ];

        $res = $this->http->post('', ['json' => $payload]);
        $json = json_decode($res->getBody()->getContents(), true);

        if (isset($json['error'])) {
            throw new \Exception("Odoo error: " . json_encode($json['error']));
        }

        return $json['result'];
    }

    private function authenticate()
    {
        return $this->call('call', [
            'service' => 'common',
            'method'  => 'login',
            'args'    => [$this->db, $this->username, $this->password]
        ]);
    }

    public function search_read($model, $fields = [], $domain = [])
    {
        return $this->call('call', [
            'service' => 'object',
            'method'  => 'execute_kw',
            'args' => [
                $this->db,
                $this->uid,
                $this->password,
                $model,
                'search_read',
                [$domain],
                ['fields' => $fields]
            ]
        ]);
    }
}
