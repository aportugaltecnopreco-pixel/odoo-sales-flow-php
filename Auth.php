<?php

class Auth
{
    private string $odooUrl;
    private string $db;
    private string $username;
    private string $password;
    
    private function __construct(string $odooUrl, string $db, string $username, string $password)
    {
        $this->odooUrl = $odooUrl;
        $this->db = $db;
        $this->username = $username;
        $this->password = $password;
    }

    public static function fromEnvironment(): self
    {
        $odooUrl = getenv('ODOO_URL');
        $odooPort = getenv('ODOO_PORT');
        $db = getenv('ODOO_DB');
        $username = getenv('ODOO_USER');
        $password = getenv('ODOO_PASS');

        if (!$odooUrl || !$db || !$username || !$password) {
            throw new RuntimeException('Variables de entorno faltantes (.env incompleto)');
        }

        // Agregar puerto si existe (al igual que en Node.js)
        if (!empty($odooPort)) {
            $odooUrl .= ':' . $odooPort;
        }

        return new self($odooUrl, $db, $username, $password);
    }

    public function authenticate(): int
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'id' => rand(0, 1000),
            'params' => [
                'service' => 'common',
                'method' => 'login',
                'args' => [$this->db, $this->username, $this->password],
            ],
        ];

        $response = $this->makeRequest('/jsonrpc', $payload);

        // Validar respuesta
        if (isset($response['error']) && $response['error'] !== null) {
            $errorMsg = $response['error']['data']['message'] ?? $response['error']['message'] ?? 'Error desconocido';
            throw new Exception("Odoo authentication error: {$errorMsg}");
        }

        if (!isset($response['result']) || $response['result'] === null) {
            throw new Exception('Authentication Failed - No UID returned');
        }

        return (int)$response['result'];
    }

    protected function makeRequest(string $endpoint, array $payload): array
    {
        $ch = curl_init();

        $options = [
            CURLOPT_URL => $this->odooUrl . $endpoint,
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
    public function getOdooUrl(): string
    {
        return $this->odooUrl;
    }
}
