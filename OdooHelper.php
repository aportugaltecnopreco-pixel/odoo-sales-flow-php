<?php

class OdooHelper
{
    private Auth $auth;
    public function __construct(Auth $auth)
    {
        $this->auth = $auth;
    }
    public function callMethod(int $uid, string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        $password = getenv('ODOO_PASS');
        $db = getenv('ODOO_DB');

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'id' => rand(0, 1000),
            'params' => [
                'service' => 'object',
                'method' => 'execute_kw',
                'args' => [$db, $uid, $password, $model, $method, $args, $kwargs],
            ],
        ];

        $response = $this->makeRequest('/jsonrpc', $payload);

        if (isset($response['error']) && $response['error'] !== null) {
            $errorMsg = $response['error']['data']['message'] ?? $response['error']['message'] ?? 'Error desconocido';
            throw new Exception("Odoo error [{$model}.{$method}]: {$errorMsg}");
        }

        return $response['result'] ?? null;
    }

    public function searchProductByCode(int $uid, string $code): ?array
    {
        $code = trim((string)$code);
        if ($code === '') {
            return null;
        }

        // Buscar por default_code esto a traves del search_read
        $products = $this->callMethod($uid, 'product.product', 'search_read', [
            [['default_code', '=', $code]],
        ], [
            'fields' => ['id', 'name', 'default_code'],
            'limit' => 1,
        ]);

        if (!empty($products) && isset($products[0])) {
            return $products[0];
        }

        // Buscar por barcode si default_code no encontró
        $products = $this->callMethod($uid, 'product.product', 'search_read', [
            [['barcode', '=', $code]],
        ], [
            'fields' => ['id', 'name', 'default_code'],
            'limit' => 1,
        ]);

        return !empty($products) && isset($products[0]) ? $products[0] : null;
    }

    public function searchOrCreatePartner(int $uid, string $partnerName, string $vat = ''): int
    {
        $partnerName = trim((string)$partnerName);
        $vat = trim((string)$vat);

        if ($vat !== '') {
            $partners = $this->callMethod($uid, 'res.partner', 'search_read', [
                [['vat', '=', $vat]],
            ], [
                'fields' => ['id'],
                'limit' => 1,
            ]);

            if (!empty($partners) && isset($partners[0]['id'])) {
                return (int)$partners[0]['id'];
            }
        }

        if ($partnerName !== '') {
            $partners = $this->callMethod($uid, 'res.partner', 'search_read', [
                [['name', '=ilike', $partnerName]],
            ], [
                'fields' => ['id'],
                'limit' => 1,
            ]);

            if (!empty($partners) && isset($partners[0]['id'])) {
                return (int)$partners[0]['id'];
            }
        }

        // Crear nuevo partner
        $partnerValues = [
            'name' => $partnerName !== '' ? $partnerName : 'Cliente ' . date('Ymd-His'),
            'customer_rank' => 1,
        ];

        if ($vat !== '') {
            $partnerValues['vat'] = $vat;
        }

        $partnerId = $this->callMethod($uid, 'res.partner', 'create', [[$partnerValues]]);
        return (int)(is_array($partnerId) ? $partnerId[0] : $partnerId);
    }

    private function makeRequest(string $endpoint, array $payload): array
    {
        $ch = curl_init();

        $options = [
            CURLOPT_URL => $this->auth->getOdooUrl() . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new Exception("CURL Error: {$error}");
        }

        if ($httpCode !== 200) {
            throw new Exception("HTTP Error {$httpCode}: {$response}");
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        return $decoded;
    }
}
