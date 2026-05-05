<?php

class Operations
{
    private OdooHelper $helper;
    private Auth $auth;
    private array $params;

    public function __construct(OdooHelper $helper, Auth $auth, array $params)
    {
        $this->helper = $helper;
        $this->auth = $auth;
        $this->params = $params;
    }
    public function createSaleOrder(): int
    {
        $uid = $this->auth->authenticate();

        $order = $this->prepareOrder($uid);
        $orderLines = $this->prepareOrderLines($uid);
        $order['order_line'] = $orderLines;

        $newOrder = $this->helper->callMethod($uid, 'sale.order', 'create', [$order]);

        if (empty($newOrder)) {
            throw new Exception('Failed to create sale order');
        }
        if (is_array($newOrder)) {
            return (int)$newOrder[0];
        }
        return (int)$newOrder;
    }
    public function confirmSaleOrder(int $orderId): void
    {
        $uid = $this->auth->authenticate();
        $this->helper->callMethod($uid, 'sale.order', 'action_confirm', [[$orderId]]);
        echo "âœ” Sale Order {$orderId} confirmed successfully.\n";
    }
    public function getPickingIdForSale(int $saleId): int
    {
        $uid = $this->auth->authenticate();

        $pickings = $this->helper->callMethod($uid, 'stock.picking', 'search_read', [
            [['sale_id', '=', $saleId]],
        ], [
            'fields' => ['id'],
        ]);

        if (empty($pickings)) {
            throw new Exception("No pickings found for sale order {$saleId}");
        }

        return (int)$pickings[0]['id'];
    }
    public function validateAndCreateBackorderStockPicking(int $pickingId): void
    {
        $uid = $this->auth->authenticate();
        $this->preparePickingForValidation($uid, $pickingId);

        try {
            $result = $this->helper->callMethod($uid, 'stock.picking', 'button_validate', [
                [$pickingId],
            ], [
                'context' => ['skip_sms' => true],
            ]);
        } catch (Exception $e) {
            throw new Exception($this->buildPickingValidationErrorMessage($uid, $pickingId, $e->getMessage()));
        }
        if ($result === true) {
            echo "âœ” Stock Picking {$pickingId} validated successfully with no backorder.\n";
            return;
        }
        if (is_array($result) && isset($result['res_model'])) {
            if ($result['res_model'] === 'confirm.stock.sms') {
                $this->helper->callMethod($uid, 'confirm.stock.sms', 'action_cancel', [
                    [$result['res_id']],
                ]);
                echo "âœ” Stock Picking {$pickingId} validated successfully (SMS canceled).\n";
            } elseif ($result['res_model'] === 'stock.backorder.confirmation') {
                $backorder = $this->helper->callMethod($uid, 'stock.backorder.confirmation', 'create', [
                    [['pick_ids' => $result['context']['default_pick_ids'] ?? []]],
                ]);

                if (!empty($backorder)) {
                    $this->helper->callMethod($uid, 'stock.backorder.confirmation', 'process', [
                        [$backorder[0]],
                    ]);
                    echo "âœ” Stock Picking {$pickingId} validated successfully.\n";
                    echo "âœ” Backorder {$backorder[0]} confirmed successfully.\n";
                }
            }
        }
    }

    private function preparePickingForValidation(int $uid, int $pickingId): void
    {
        $this->helper->callMethod($uid, 'stock.picking', 'action_assign', [[$pickingId]]);
        try {
            $this->helper->callMethod($uid, 'stock.picking', 'action_set_quantities_to_reservation', [[$pickingId]]);
        } catch (Exception $e) {
        }

        $fieldsMap = $this->helper->callMethod($uid, 'stock.move.line', 'fields_get', [], []);
        $doneField = $this->resolveFieldName($fieldsMap, ['qty_done', 'quantity', 'quantity_done']);
        $sourceField = $this->resolveFieldName($fieldsMap, ['reserved_uom_qty', 'product_uom_qty', 'quantity']);

        if ($doneField === null || $sourceField === null) {
            return;
        }

        $readFields = array_values(array_unique(['id', $doneField, $sourceField]));
        $moveLines = $this->helper->callMethod($uid, 'stock.move.line', 'search_read', [
            [['picking_id', '=', $pickingId], ['state', 'not in', ['done', 'cancel']]],
        ], [
            'fields' => $readFields,
        ]);

        foreach ($moveLines as $line) {
            $lineId = (int)($line['id'] ?? 0);
            $doneQty = (float)($line[$doneField] ?? 0);
            $targetQty = (float)($line[$sourceField] ?? 0);

            if ($lineId > 0 && $doneQty <= 0 && $targetQty > 0) {
                $this->helper->callMethod($uid, 'stock.move.line', 'write', [
                    [$lineId],
                    [$doneField => $targetQty],
                ]);
            }
        }
    }

    private function resolveFieldName(array $fieldsMap, array $candidates): ?string
    {
        foreach ($candidates as $name) {
            if (isset($fieldsMap[$name])) {
                return $name;
            }
        }
        return null;
    }

    private function buildPickingValidationErrorMessage(int $uid, int $pickingId, string $rawMessage): string
    {
        $diagnostics = $this->getPickingDiagnostics($uid, $pickingId);
        $reserved = $diagnostics['reserved_total'];
        $done = $diagnostics['done_total'];
        $hasTrackedProduct = $diagnostics['has_tracked_product'];
        $state = $diagnostics['picking_state'];

        if (stripos($rawMessage, 'No puedes validar un traslado') !== false) {
            if ($hasTrackedProduct && $done <= 0.0) {
                return "No se pudo validar el picking {$pickingId}: Falta lote/serie en al menos una linea de operacion. " .
                    "Diagnostico: state={$state}, reserved_total={$reserved}, done_total={$done}.";
            }

            if (in_array($state, ['waiting', 'confirmed'], true) && $reserved <= 0.0) {
                return "No se pudo validar el picking {$pickingId}: Picking no asignado. " .
                    "Primero debes reservar productos (asignar). " .
                    "Diagnostico: state={$state}, reserved_total={$reserved}, done_total={$done}.";
            }

            if ($reserved <= 0.0 && $done <= 0.0) {
                return "No se pudo validar el picking {$pickingId}: Sin stock disponible para reservar. " .
                    "Diagnostico: state={$state}, reserved_total={$reserved}, done_total={$done}.";
            }

            return "No se pudo validar el picking {$pickingId}: validacion bloqueada por reglas de inventario. " .
                "Diagnostico: state={$state}, reserved_total={$reserved}, done_total={$done}.";
        }

        return "Error validando picking {$pickingId}: {$rawMessage}";
    }

    private function getPickingDiagnostics(int $uid, int $pickingId): array
    {
        $result = [
            'reserved_total' => 0.0,
            'done_total' => 0.0,
            'has_tracked_product' => false,
            'picking_state' => 'unknown',
        ];

        $pickings = $this->helper->callMethod($uid, 'stock.picking', 'search_read', [
            [['id', '=', $pickingId]],
        ], [
            'fields' => ['state'],
            'limit' => 1,
        ]);
        if (!empty($pickings) && isset($pickings[0]['state'])) {
            $result['picking_state'] = (string)$pickings[0]['state'];
        }

        $lineFieldsMap = $this->helper->callMethod($uid, 'stock.move.line', 'fields_get', [], []);
        $doneField = $this->resolveFieldName($lineFieldsMap, ['qty_done', 'quantity', 'quantity_done']);
        $reservedField = $this->resolveFieldName($lineFieldsMap, ['reserved_uom_qty', 'reserved_qty', 'product_uom_qty']);

        if ($doneField !== null || $reservedField !== null) {
            $readFields = ['id'];
            if ($doneField !== null) {
                $readFields[] = $doneField;
            }
            if ($reservedField !== null) {
                $readFields[] = $reservedField;
            }

            $moveLines = $this->helper->callMethod($uid, 'stock.move.line', 'search_read', [
                [['picking_id', '=', $pickingId], ['state', 'not in', ['cancel']]],
            ], [
                'fields' => array_values(array_unique($readFields)),
            ]);

            foreach ($moveLines as $line) {
                if ($reservedField !== null) {
                    $result['reserved_total'] += (float)($line[$reservedField] ?? 0);
                }
                if ($doneField !== null) {
                    $result['done_total'] += (float)($line[$doneField] ?? 0);
                }
            }
        }

        $moves = $this->helper->callMethod($uid, 'stock.move', 'search_read', [
            [['picking_id', '=', $pickingId], ['state', 'not in', ['cancel']]],
        ], [
            'fields' => ['has_tracking'],
        ]);
        foreach ($moves as $move) {
            if (!empty($move['has_tracking']) && $move['has_tracking'] !== 'none') {
                $result['has_tracked_product'] = true;
                break;
            }
        }

        return $result;
    }
    public function createInvoiceFromSale(int $saleId): int
    {
        $uid = $this->auth->authenticate();
        $errors = [];

        try {
            $result = $this->helper->callMethod($uid, 'sale.order', 'action_create_invoice', [
                [$saleId],
            ]);
            $invoiceId = $this->extractIdFromRpcResult($result);
            if ($invoiceId > 0) {
                return $invoiceId;
            }
        } catch (Exception $e) {
            $errors[] = 'action_create_invoice: ' . $e->getMessage();
        }

        try {
            $context = [
                'active_model' => 'sale.order',
                'active_id' => $saleId,
                'active_ids' => [$saleId],
            ];

            $wizard = $this->helper->callMethod($uid, 'sale.advance.payment.inv', 'create', [[
                'sale_order_ids' => [[6, 0, [$saleId]]],
                'advance_payment_method' => 'delivered',
            ]], [
                'context' => $context,
            ]);

            $wizardId = $this->extractIdFromRpcResult($wizard);
            if ($wizardId <= 0) {
                throw new Exception("No se pudo crear el wizard de facturacion para la venta {$saleId}");
            }

            $result = $this->helper->callMethod($uid, 'sale.advance.payment.inv', 'create_invoices', [
                [$wizardId],
            ], [
                'context' => $context,
            ]);

            $invoiceId = $this->extractIdFromRpcResult($result);
            if ($invoiceId > 0) {
                return $invoiceId;
            }

            throw new Exception("El wizard no devolvio ID de factura para la venta {$saleId}");
        } catch (Exception $e) {
            $errors[] = 'sale.advance.payment.inv: ' . $e->getMessage();
        }

        throw new Exception("No se pudo crear la factura para la venta {$saleId}. " . implode(' | ', $errors));
    }

    public function checkSaleAvailability(int $saleId): array
    {
        $uid = $this->auth->authenticate();
        return $this->helper->getSaleAvailabilityBySaleId($uid, $saleId);
    }

    private function extractIdFromRpcResult(mixed $result): int
    {
        if (is_int($result)) {
            return $result > 0 ? $result : 0;
        }

        if (is_string($result) && ctype_digit($result)) {
            $id = (int)$result;
            return $id > 0 ? $id : 0;
        }

        if (is_array($result)) {
            if (isset($result['res_id']) && is_numeric($result['res_id'])) {
                $id = (int)$result['res_id'];
                return $id > 0 ? $id : 0;
            }

            if (isset($result[0]) && is_numeric($result[0])) {
                $id = (int)$result[0];
                return $id > 0 ? $id : 0;
            }
        }

        return 0;
    }

    public function confirmInvoice(int $invoiceId): void
    {
        $uid = $this->auth->authenticate();

        $this->helper->callMethod($uid, 'account.move', 'action_post', [[$invoiceId]]);
        echo "âœ” Invoice {$invoiceId} confirmed successfully.\n";
    }
    private function prepareOrder(int $uid): array
    {
        $orderParams = $this->params['order'] ?? [];
        $partnerName = $orderParams['partner_name'] ?? 'Cliente Demo';
        $partnerVat = $orderParams['partner_vat'] ?? '';

        $partnerId = $this->helper->searchOrCreatePartner($uid, $partnerName, $partnerVat);

        return [
            'partner_id' => $partnerId,
            'date_order' => $orderParams['date_order'] ?? date('Y-m-d'),
        ];
    }
    private function prepareOrderLines(int $uid): array
    {
        $orderLines = [];
        $orderParams = $this->params['order']['order_line'] ?? [];

        foreach ($orderParams as $param) {
            $productCode = trim((string)($param['product_code'] ?? ''));
            if ($productCode === '') {
                throw new Exception('El parÃ¡metro product_code es obligatorio en cada lÃ­nea');
            }

            $product = $this->helper->searchProductByCode($uid, $productCode);
            if ($product === null) {
                throw new Exception("No se encontrÃ³ el producto con cÃ³digo: {$productCode}");
            }

            $productId = (int)$product['id'];
            $quantity = (float)($param['product_uom_qty'] ?? 1);
            $priceUnit = (float)($param['price_unit'] ?? 0);

            if ($quantity <= 0) {
                throw new Exception("La cantidad debe ser mayor a cero para {$productCode}");
            }

            if ($priceUnit <= 0) {
                throw new Exception("El precio unitario debe ser mayor a cero para {$productCode}");
            }
            $orderLines[] = [
                0,
                0,
                [
                    'product_id' => $productId,
                    'product_uom_qty' => $quantity,
                    'price_unit' => $priceUnit,
                ],
            ];
        }

        return $orderLines;
    }
}


