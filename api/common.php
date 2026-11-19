<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Vary: Cookie');
header('X-Content-Type-Options: nosniff');

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function configuration(): array {
    $privateFile = dirname(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__)) . '/hal-account-config.php';
    $file = is_file($privateFile) ? $privateFile : __DIR__ . '/config.local.php';
    $config = is_file($file) ? require $file : [];
    $config = array_merge([
        'host' => getenv('HAL_DB_HOST') ?: 'localhost',
        'port' => getenv('HAL_DB_PORT') ?: 3306,
        'database' => getenv('HAL_DB_NAME') ?: '',
        'username' => getenv('HAL_DB_USER') ?: '',
        'password' => getenv('HAL_DB_PASSWORD') ?: '',
        'otp_authkey' => getenv('HAL_OTP_AUTHKEY') ?: '',
        'otp_template_id' => getenv('HAL_OTP_TEMPLATE_ID') ?: '',
        'otp_daily_limit' => getenv('HAL_OTP_DAILY_LIMIT') ?: 100,
    ], is_array($config) ? $config : []);
    return $config;
}

function database(): PDO {
    $config = configuration();
    if (!$config['database'] || !$config['username'] || str_starts_with($config['database'], 'YOUR_')) {
        respond(['error' => 'Account service is being set up. Please try again shortly.'], 503);
    }
    if (!preg_match('/^[a-zA-Z0-9_.:-]+$/D', (string)$config['host']) || !preg_match('/^[a-zA-Z0-9_]+$/D', (string)$config['database'])) {
        throw new RuntimeException('Invalid database configuration.');
    }
    $port = (int)$config['port'];
    if ($port < 1 || $port > 65535) throw new RuntimeException('Invalid database port.');
    $db = new PDO('mysql:host=' . $config['host'] . ';port=' . $port . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec("CREATE TABLE IF NOT EXISTS hal_users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NULL UNIQUE,
        password_hash VARCHAR(255) NULL,
        phone VARCHAR(20) NOT NULL DEFAULT '',
        address VARCHAR(500) NOT NULL DEFAULT '',
        city VARCHAR(100) NOT NULL DEFAULT '',
        pincode VARCHAR(6) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hal_auth_limits (
        bucket CHAR(64) NOT NULL PRIMARY KEY,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        expires_at BIGINT UNSIGNED NOT NULL,
        INDEX (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $db;
}

function rateLimit(PDO $db, string $key, int $maximum, int $window): void {
    $bucket = hash('sha256', $key . ':' . (string)floor(time() / $window));
    $expires = (int)((floor(time() / $window) + 1) * $window);
    $q = $db->prepare('INSERT IGNORE INTO hal_auth_limits (bucket, attempts, expires_at) VALUES (?, 0, ?)');
    $q->execute([$bucket, $expires]);
    $q = $db->prepare('UPDATE hal_auth_limits SET attempts = attempts + 1 WHERE bucket = ?');
    $q->execute([$bucket]);
    $q = $db->prepare('SELECT attempts FROM hal_auth_limits WHERE bucket = ?');
    $q->execute([$bucket]);
    if ((int)$q->fetchColumn() > $maximum) {
        header('Retry-After: ' . max(1, $expires - time()));
        respond(['error' => 'Too many attempts. Please wait a few minutes and try again.'], 429);
    }
    if (random_int(1, 100) === 1) $db->exec('DELETE FROM hal_auth_limits WHERE expires_at < ' . time());
}

function profile(PDO $db, int $id): ?array {
    $q = $db->prepare('SELECT id, name, email, phone, address, city, pincode FROM hal_users WHERE id = ?');
    $q->execute([$id]);
    $user = $q->fetch();
    if (!$user) return null;
    $user['id'] = (int)$user['id'];
    return $user;
}

function textField(array $data, string $key, int $limit, bool $required = false): string {
    if (isset($data[$key]) && !is_string($data[$key])) respond(['error' => 'Please check your ' . $key . '.'], 422);
    $value = trim($data[$key] ?? '');
    if (($required && $value === '') || strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
        respond(['error' => 'Please enter a valid ' . $key . '.'], 422);
    }
    return $value;
}

function startAccountSession(): void {
    $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '604800');
    session_name('HAL_SESSION');
    session_set_cookie_params([
        'lifetime' => 604800, 'path' => '/',
        'secure' => !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!session_start()) throw new RuntimeException('Session unavailable.');
    if (isset($_SESSION['last_active']) && time() - (int)$_SESSION['last_active'] > 604800) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
