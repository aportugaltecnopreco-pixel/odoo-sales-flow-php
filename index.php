<?php

// Autoload simple de clases
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
            continue; // Ignorar comentarios
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
        echo "\n  ℹ  {$msg}\n";
    }

    public static function ok(string $msg, ?string $data = null): void
    {
        echo "\n";
        echo "  ✔  {$msg}\n";
        if ($data) {
            echo "────────────────────────────────────────────\n";
            echo "{$data}\n";
            echo "────────────────────────────────────────────\n";
        }
        echo "\n";
    }

    public static function error(string $msg): void
    {
        echo "  ✖  {$msg}\n";
    }

    public static function data(string $key, $val): void
    {
        echo "     {$key}: {$val}\n";
    }

    public static function divider(): void
    {
        echo "\n" . str_repeat('─', 50) . "\n";
    }
}

// Función para leer entrada del usuario
function readInput(string $prompt = ''): string
{
    if ($prompt) {
        echo $prompt;
    }
    return trim(fgets(STDIN));
}

// ============ PROGRAMA PRINCIPAL ============

try {
    // Cargar configuración
    loadEnv(__DIR__ . '/.env');
    $params = loadParams(__DIR__ . '/params.json');

    // Inicializar dependencias (inyección)
    $auth = Auth::fromEnvironment();
    $helper = new OdooHelper($auth);
    $operations = new Operations($helper, $auth, $params);

    // Menú interactivo
    $running = true;

    while ($running) {
        echo "\n?‍ ¿Qué querés ejecutar?\n";
        echo "── Pasos individuales ──────────────\n";
        echo "1. Autenticar\n";
        echo "2. Crear venta\n";
        echo "3. Confirmar venta\n";
        echo "4. Validar salida de productos\n";
        echo "5. Crear, confirmar y crear factura\n";
        echo "6. Flujo completo (Crear, confirmar, facturar)\n";
        echo "───────────────────────────────────\n";
        echo "7. Salir\n";
        echo "\n";

        $choice = readInput("Ingresa opción [1-7]: ");

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
                    stepCreateInvoice($operations);
                    break;
                case '6':
                    stepConfirmInvoice($operations);
                    break;
                case '7':
                    Logger::ok('¡Hasta luego!');
                    $running = false;
                    break;
                default:
                    Logger::error('Opción no válida. Intenta de nuevo.');
            }
        } catch (Exception $e) {
            Logger::error("Error: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    Logger::error("Error crítico: " . $e->getMessage());
    exit(1);
}

// ============ FUNCIONES DE PASOS ============

function stepAuthenticate(Auth $auth, Operations $operations): void
{
    try {
        $uid = $auth->authenticate();
        Logger::ok('Autenticación exitosa', "User ID: {$uid}");
    } catch (Exception $e) {
        throw $e;
    }
}

function stepCreateSale(Operations $operations): void
{
    try {
        $saleId = $operations->createSaleOrder();
        Logger::ok('Venta creada con éxito', "ID de la venta: {$saleId}");
    } catch (Exception $e) {
        throw $e;
    }
}

function stepConfirmSale(Operations $operations): void
{
    try {
        $saleId = $operations->createSaleOrder();
        $operations->confirmSaleOrder($saleId);
        Logger::ok('Venta creada y confirmada con éxito', "ID de la venta: {$saleId}");
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
            'Venta creada, confirmada y salida de productos validada con éxito',
            "ID de la venta: {$saleId}, ID del picking: {$pickingId}"
        );
    } catch (Exception $e) {
        throw $e;
    }
}

function stepCreateInvoice(Operations $operations): void
{
    try {
        $saleId = $operations->createSaleOrder();
        $operations->confirmSaleOrder($saleId);
        $invoiceId = $operations->createInvoiceFromSale($saleId);
        Logger::ok(
            'Venta creada, confirmada y factura creada con éxito',
            "ID de la venta: {$saleId}, ID de la factura: {$invoiceId}"
        );
    } catch (Exception $e) {
        throw $e;
    }
}

function stepConfirmInvoice(Operations $operations): void
{
    try {
        Logger::info('Iniciando flujo completo...');

        $saleId = $operations->createSaleOrder();
        Logger::ok('✓ Venta creada', "ID: {$saleId}");

        $operations->confirmSaleOrder($saleId);
        Logger::ok('✓ Venta confirmada');

        $invoiceId = $operations->createInvoiceFromSale($saleId);
        Logger::ok('✓ Factura creada', "ID: {$invoiceId}");

        $operations->confirmInvoice($invoiceId);
        Logger::ok('✓ Factura confirmada');

        Logger::ok(
            '✔ FLUJO COMPLETO EXITOSO',
            "Venta: {$saleId} | Factura Confirmada: {$invoiceId}"
        );
    } catch (Exception $e) {
        throw $e;
    }
}
