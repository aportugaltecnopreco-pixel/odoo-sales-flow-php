<?php

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

function loadEnv(string $envFile): void
{
    if (!file_exists($envFile)) {
        throw new RuntimeException("Archivo .env no encontrado en {$envFile}");
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);

        if (!empty($key)) {
            putenv("{$key}={$value}");
        }
    }
}

function loadParams(string $paramsFile): array
{
    if (!file_exists($paramsFile)) {
        throw new RuntimeException("Archivo params.json no encontrado");
    }

    $json = file_get_contents($paramsFile);
    $decoded = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Error en params.json: " . json_last_error_msg());
    }

    return $decoded;
}

class Logger
{
    public static function info(string $msg): void
    {
        echo "\n  [INFO] {$msg}\n";
    }

    public static function ok(string $msg, ?string $data = null): void
    {
        echo "\n";
        echo "  [OK] {$msg}\n";
        if ($data) {
            echo str_repeat('-', 44) . "\n";
            echo "{$data}\n";
            echo str_repeat('-', 44) . "\n";
        }
        echo "\n";
    }

    public static function error(string $msg): void
    {
        echo "  [ERROR] {$msg}\n";
    }

    public static function data(string $key, $val): void
    {
        echo "     {$key}: {$val}\n";
    }

    public static function divider(): void
    {
        echo "\n" . str_repeat('-', 50) . "\n";
    }
}

function readInput(string $prompt = ''): string
{
    if ($prompt) {
        echo $prompt;
    }
    return trim(fgets(STDIN));
}

try {
    loadEnv(__DIR__ . '/.env');
    $params = loadParams(__DIR__ . '/params.json');

    $auth = Auth::fromEnvironment();
    $helper = new OdooHelper($auth);
    $operations = new Operations($helper, $auth, $params);

    $running = true;

    while ($running) {
        echo "\nQue quieres ejecutar?\n";
        echo "-- Pasos individuales ----------------------\n";
        echo "1. Autenticar\n";
        echo "2. Crear cotizacion\n";
        echo "3. Crear Cotizacion, Confirmar Orden de venta\n";
        echo "4. Crear Cotizacion, Confirmar Orden de venta y Validar disponibilidad de productos\n";
        echo "5. Validar disponibilidad de productos por ID de venta\n";
        echo "6. Crear factura por id\n";
        echo "7. Crear, confirmar validar y crear factura\n";
        echo "8. Flujo completo (Crear, confirmar, facturar)\n";
        echo "--------------------------------------------\n";
        echo "9. Salir\n\n";

        $choice = readInput("Ingresa opcion [1-9]: ");

        Logger::divider();

        try {
            switch ($choice) {
                case '1':
                    stepAuthenticate($auth, $operations);
                    break;
                case '2':
                    stepCreateSale($operations);
                    break;
                case '3':
                    stepConfirmSale($operations);
                    break;
                case '4':
                    stepValidateStock($operations);
                    break;
                case '5':
                    stepValidateStock1($operations);
                    break;
                case '6':
                    stepCreateInvoice($operations);
                    break;
                case '7':
                    stepCreateInvoice1($operations);
                    break;
                case '8':
                    stepConfirmInvoice($operations);
                    break;
                case '9':
                    Logger::ok('Hasta luego!');
                    $running = false;
                    break;
                default:
                    Logger::error('Opcion no valida. Intenta de nuevo.');
            }
        } catch (Exception $e) {
            Logger::error("Error: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    Logger::error("Error critico: " . $e->getMessage());
    exit(1);
}

function stepAuthenticate(Auth $auth, Operations $operations): void
{
    try {
        $uid = $auth->authenticate();
        Logger::ok('Autenticacion exitosa', "User ID: {$uid}");
    } catch (Exception $e) {
        throw $e;
    }
}

function stepCreateSale(Operations $operations): void
{
    try {
        $saleId = $operations->createSaleOrder();
        Logger::ok('Venta creada con exito', "ID de la venta: {$saleId}");
    } catch (Exception $e) {
        throw $e;
    }
}

function stepConfirmSale(Operations $operations): void
{
    try {
        $saleId = $operations->createSaleOrder();
        $operations->confirmSaleOrder($saleId);
        Logger::ok('Venta creada y confirmada con exito', "ID de la venta: {$saleId}");
    } catch (Exception $e) {
        throw $e;
    }
}

function stepValidateStock(Operations $operations): void
{
    try {
        $saleId = $operations->createSaleOrder();
        $operations->confirmSaleOrder($saleId);
        $pickingId = $operations->getPickingIdForSale($saleId);
        $operations->validateAndCreateBackorderStockPicking($pickingId);
        Logger::ok(
            'Venta creada, confirmada y salida de productos validada con exito',
            "ID de la venta: {$saleId}, ID del picking: {$pickingId}"
        );
    } catch (Exception $e) {
        throw $e;
    }
}

function stepValidateStock1(Operations $operations): void
{
    try {
        $saleIdInput = readInput("Ingresa el ID de la venta a consultar: ");
        if (!ctype_digit($saleIdInput) || (int)$saleIdInput <= 0) {
            throw new Exception('ID de venta no valido. Debe ser un numero entero positivo.');
        }

        $saleId = (int)$saleIdInput;
        $availability = $operations->checkSaleAvailability($saleId);

        Logger::ok('Consulta de disponibilidad completada', "ID de la venta: {$saleId}");

        foreach ($availability['pickings'] as $picking) {
            $pickingStatus = !empty($picking['all_available']) ? 'DISPONIBLE' : 'PENDIENTE';
            Logger::info(
                "Picking {$picking['name']} (ID {$picking['id']}) | Estado: {$picking['state']} | {$pickingStatus}"
            );

            $totals = $picking['totals'] ?? [];
            Logger::data('Total demand', (string)($totals['demand_qty'] ?? 0));
            Logger::data('Total reserved', (string)($totals['reserved_qty'] ?? 0));
            Logger::data('Total done', (string)($totals['done_qty'] ?? 0));

            foreach ($picking['lines'] as $line) {
                $lineStatus = !empty($line['available']) ? 'OK' : 'FALTA STOCK';
                Logger::data('Producto', "{$line['product_name']} (ID {$line['product_id']})");
                Logger::data(
                    'Cantidades',
                    "demand={$line['demand_qty']} | reserved={$line['reserved_qty']} | done={$line['done_qty']} | {$lineStatus}"
                );
            }
            Logger::divider();
        }
    } catch (Exception $e) {
        throw $e;
    }
}

function stepCreateInvoice(Operations $operations): void
{
    try {
        $saleIdInput = readInput("Ingresa el ID de la venta para crear factura: ");
        if (!ctype_digit($saleIdInput) || (int)$saleIdInput <= 0) {
            throw new Exception('ID de venta no valido. Debe ser un numero entero positivo.');
        }

        $saleId = (int)$saleIdInput;
        $invoiceId = $operations->createInvoiceFromSale($saleId);
        Logger::ok(
            'Factura creada con exito',
            "ID de la venta: {$saleId}, ID de la factura: {$invoiceId}"
        );
    } catch (Exception $e) {
        throw $e;
    }
}
function stepCreateInvoice1(Operations $operations): void
{
    try {
        $saleIdInput = readInput("Ingresa el ID de la venta para crear factura: ");
        if (!ctype_digit($saleIdInput) || (int)$saleIdInput <= 0) {
            throw new Exception('ID de venta no valido. Debe ser un numero entero positivo.');
        }

        $saleId = (int)$saleIdInput;
    } catch (Exception $e) {
        throw $e;
    }
}

function stepConfirmInvoice(Operations $operations): void
{
    try {
        Logger::info('Iniciando flujo completo...');

        $saleId = $operations->createSaleOrder();
        Logger::ok('Venta creada', "ID: {$saleId}");

        $operations->confirmSaleOrder($saleId);
        Logger::ok('Venta confirmada');

        $invoiceId = $operations->createInvoiceFromSale($saleId);
        Logger::ok('Factura creada', "ID: {$invoiceId}");

        $operations->confirmInvoice($invoiceId);
        Logger::ok('Factura confirmada');

        Logger::ok(
            'FLUJO COMPLETO EXITOSO',
            "Venta: {$saleId} | Factura Confirmada: {$invoiceId}"
        );
    } catch (Exception $e) {
        throw $e;
    }
}
