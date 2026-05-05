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
        $products = $this->callMethod($uid, 'product.product', 'search_read', [
            [['default_code', '=', $code]],
        ], [
            'fields' => ['id', 'name', 'default_code'],
            'limit' => 1,
        ]);

        if (!empty($products) && isset($products[0])) {
            return $products[0];
        }
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

    public function getSaleAvailabilityBySaleId(int $uid, int $saleId): array
    {
        $pickings = $this->callMethod($uid, 'stock.picking', 'search_read', [
            [['sale_id', '=', $saleId], ['state', 'not in', ['cancel']]],
        ], [
            'fields' => ['id', 'name', 'state', 'origin'],
            'order' => 'id asc',
        ]);

        if (empty($pickings)) {
            throw new Exception("No se encontraron pickings para la venta {$saleId}");
        }

        $moveFieldsMap = $this->callMethod($uid, 'stock.move', 'fields_get', [], []);
        $demandField = $this->resolveFieldName($moveFieldsMap, ['product_uom_qty', 'quantity']);
        $reservedField = $this->resolveFieldName($moveFieldsMap, ['reserved_availability', 'availability', 'quantity']);
        $doneField = $this->resolveFieldName($moveFieldsMap, ['quantity_done', 'picked', 'quantity']);

        $fields = ['id', 'product_id', 'state'];
        if ($demandField !== null) {
            $fields[] = $demandField;
        }
        if ($reservedField !== null) {
            $fields[] = $reservedField;
        }
        if ($doneField !== null) {
            $fields[] = $doneField;
        }
        $fields = array_values(array_unique($fields));

        $result = [
            'sale_id' => $saleId,
            'pickings' => [],
        ];

        foreach ($pickings as $picking) {
            $pickingId = (int)($picking['id'] ?? 0);
            if ($pickingId <= 0) {
                continue;
            }

            try {
                $this->callMethod($uid, 'stock.picking', 'action_assign', [[$pickingId]]);
            } catch (Exception $e) {
            }

            $moves = $this->callMethod($uid, 'stock.move', 'search_read', [
                [['picking_id', '=', $pickingId], ['state', 'not in', ['cancel']]],
            ], [
                'fields' => $fields,
                'order' => 'id asc',
            ]);

            $lines = [];
            $totalDemand = 0.0;
            $totalReserved = 0.0;
            $totalDone = 0.0;
            $allAvailable = true;

            foreach ($moves as $move) {
                $productId = 0;
                $productName = 'N/A';
                if (isset($move['product_id']) && is_array($move['product_id'])) {
                    $productId = (int)($move['product_id'][0] ?? 0);
                    $productName = (string)($move['product_id'][1] ?? 'N/A');
                }

                $demandQty = $demandField !== null ? (float)($move[$demandField] ?? 0) : 0.0;
                $reservedQty = $reservedField !== null ? (float)($move[$reservedField] ?? 0) : 0.0;
                $doneQty = $doneField !== null ? (float)($move[$doneField] ?? 0) : 0.0;
                $available = $demandQty <= 0.0 || $reservedQty >= $demandQty;

                if (!$available) {
                    $allAvailable = false;
                }

                $totalDemand += $demandQty;
                $totalReserved += $reservedQty;
                $totalDone += $doneQty;

                $lines[] = [
                    'move_id' => (int)($move['id'] ?? 0),
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'state' => (string)($move['state'] ?? 'unknown'),
                    'demand_qty' => $demandQty,
                    'reserved_qty' => $reservedQty,
                    'done_qty' => $doneQty,
                    'available' => $available,
                ];
            }

            $result['pickings'][] = [
                'id' => $pickingId,
                'name' => (string)($picking['name'] ?? ''),
                'state' => (string)($picking['state'] ?? 'unknown'),
                'origin' => (string)($picking['origin'] ?? ''),
                'all_available' => $allAvailable,
                'totals' => [
                    'demand_qty' => $totalDemand,
                    'reserved_qty' => $totalReserved,
                    'done_qty' => $totalDone,
                ],
                'lines' => $lines,
            ];
        }

        return $result;
    }

    private function resolveFieldName(array $fieldsMap, array $candidates): ?string
    {
        foreach ($candidates as $field) {
            if (isset($fieldsMap[$field])) {
                return $field;
            }
        }
        return null;
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
