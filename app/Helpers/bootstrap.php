<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$configFile = $projectRoot . '/config/config.php';
$composerAutoload = $projectRoot . '/vendor/autoload.php';

if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuratiebestand ontbreekt.');
}

if (!is_file($composerAutoload)) {
    http_response_code(500);
    exit('Composer-autoload ontbreekt.');
}

require $composerAutoload;

$config = require $configFile;

date_default_timezone_set(
    (string) ($config['app']['timezone'] ?? 'Europe/Brussels')
);

session_name(
    (string) ($config['app']['session_name'] ?? 'att_portaal_session')
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' =>
            !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

spl_autoload_register(
    static function (string $class) use ($projectRoot): void {
        $prefix = 'App\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));

        $file = $projectRoot
            . '/app/'
            . str_replace('\\', '/', $relativeClass)
            . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
);

function app_config(
    ?string $key = null,
    mixed $default = null
): mixed {
    global $config;

    if ($key === null) {
        return $config;
    }

    $value = $config;

    foreach (explode('.', $key) as $segment) {
        if (
            !is_array($value)
            || !array_key_exists($segment, $value)
        ) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = app_config('db');

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    $pdo = new PDO(
        $dsn,
        $db['user'],
        $db['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals(
            (string) $_SESSION['csrf_token'],
            $token
        );
}

function flash(
    string $type,
    string $message
): void {
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }

    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function pull_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];

    unset($_SESSION['flash']);

    return is_array($flashes)
        ? $flashes
        : [];
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function log_activity(
    ?int $userId,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    array $details = []
): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_log (
                user_id,
                action,
                entity_type,
                entity_id,
                details,
                ip_address
            ) VALUES (
                :user_id,
                :action,
                :entity_type,
                :entity_id,
                :details,
                :ip_address
            )'
        );

        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details
                ? json_encode(
                    $details,
                    JSON_UNESCAPED_UNICODE
                )
                : null,
            'ip_address' => client_ip(),
        ]);
    } catch (Throwable $exception) {
        error_log(
            'ATT activity-log fout: '
            . $exception->getMessage()
        );
    }
}
