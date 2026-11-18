<?php
declare(strict_types=1);

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

require ROOT . '/vendor/autoload.php';

function config(string $key, $default = null)
{
    static $config = null;

    if ($config === null) {
        $example = is_file(ROOT . '/config/example.php') ? require ROOT . '/config/example.php' : [];
        $local = is_file(ROOT . '/config/local.php') ? require ROOT . '/config/local.php' : [];
        $config = array_replace($example, $local);
    }

    return array_key_exists($key, $config) ? $config[$key] : $default;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = config('db_host', '127.0.0.1');
    $port = (int) config('db_port', 3306);
    $dbName = config('db_name');
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
    if (!empty($dbName)) {
        $dsn .= ';dbname=' . $dbName;
    }

    $pdo = new PDO(
        $dsn,
        (string) config('db_user', 'root'),
        (string) config('db_password', ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]
    );

    return $pdo;
}

function query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function one(string $sql, array $params = []): array
{
    $row = query($sql, $params)->fetch();
    return is_array($row) ? $row : [];
}

function all(string $sql, array $params = []): array
{
    return query($sql, $params)->fetchAll();
}

function transaction(callable $callback)
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $result = $callback();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function fail(string $message, int $code = 422): void
{
    throw new \ThesisGuard\HttpError($message, $code);
}

function enum_value($value, array $allowed, ?string $default = null): string
{
    if (!is_scalar($value) && $value !== null) {
        fail('Valor no permitido.');
    }
    $candidate = trim((string) $value);
    if ($candidate === '' && $default !== null) {
        return $default;
    }
    if (!in_array($candidate, $allowed, true)) {
        fail('Valor no permitido.');
    }
    return $candidate;
}

function field(array $data, string $key, int $maxLen = 255, bool $required = true): string
{
    $value = $data[$key] ?? null;
    if ($value !== null && !is_scalar($value)) {
        fail('El campo ' . $key . ' debe contener texto.');
    }
    $text = trim((string) $value);
    if ($text === '') {
        if ($required) {
            fail('Falta el campo requerido: ' . $key, 422);
        }
        return '';
    }

    if (mb_strlen($text) > $maxLen) {
        fail('El campo ' . $key . ' supera el tamaño máximo permitido.', 422);
    }

    return $text;
}

function input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($decoded)) {
            fail('Envía un objeto JSON válido.', 400);
        }
        return json_decode($raw, true);
    }

    parse_str($raw, $data);
    return is_array($data) ? $data : [];
}

function json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function setting(string $name, string $default = ''): string
{
    try {
        $row = one('SELECT value FROM settings WHERE name = ? LIMIT 1', [$name]);
        return is_array($row) && isset($row['value']) ? (string) $row['value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function audit(string $action, $targetId = null, ?int $userId = null): void
{
    $userId = $userId ?? ($_SESSION['uid'] ?? null);
    try {
        query('INSERT INTO audit_logs(user_id, action, target_id) VALUES (?, ?, ?)', [$userId, $action, $targetId]);
    } catch (Throwable $e) {
        // Ignore audit failures during setup or transient DB availability issues.
    }
}

function esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (is_file(ROOT . '/config/local.php')) {
    $timeZone = (require ROOT . '/config/local.php')['timezone'] ?? 'America/La_Paz';
    date_default_timezone_set((string) $timeZone);
} else {
    date_default_timezone_set('America/La_Paz');
}
