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

function database(): PDO {
    $privateFile = dirname($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)) . '/hal-account-config.php';
    $file = is_file($privateFile) ? $privateFile : __DIR__ . '/config.local.php';
    $config = is_file($file) ? require $file : [];
    $config = array_merge([
        'host' => getenv('HAL_DB_HOST') ?: 'localhost',
        'port' => getenv('HAL_DB_PORT') ?: 3306,
        'database' => getenv('HAL_DB_NAME') ?: '',
        'username' => getenv('HAL_DB_USER') ?: '',
        'password' => getenv('HAL_DB_PASSWORD') ?: '',
    ], is_array($config) ? $config : []);
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
        email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
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

try {
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
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? 'session';
    if (!is_string($action) || !in_array($action, ['session', 'register', 'login', 'profile', 'logout'], true)) respond(['error' => 'Not found.'], 404);
    if (($action === 'session' && $method !== 'GET') || ($action !== 'session' && $method !== 'POST')) {
        header('Allow: ' . ($action === 'session' ? 'GET' : 'POST'));
        respond(['error' => 'Method not allowed.'], 405);
    }
    $data = [];
    if ($method === 'POST') {
        if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) respond(['error' => 'Your session changed. Refresh the page and try again.'], 403);
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') respond(['error' => 'JSON request required.'], 415);
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if ($raw === false || strlen($raw) > 16384) respond(['error' => 'Request is too large.'], 413);
        $data = json_decode($raw, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) respond(['error' => 'Invalid request.'], 400);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        respond(['user' => null, 'csrfToken' => $_SESSION['csrf']]);
    }

    $db = database();
    if ($action === 'session') {
        $user = isset($_SESSION['user_id']) ? profile($db, (int)$_SESSION['user_id']) : null;
        if ($user) $_SESSION['last_active'] = time();
        else unset($_SESSION['user_id']);
        respond(['user' => $user, 'csrfToken' => $_SESSION['csrf']]);
    }
    if ($action === 'register' || $action === 'login') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rateLimit($db, $action . ':ip:' . $ip, $action === 'register' ? 10 : 60, 900);
        $email = strtolower(textField($data, 'email', 254, true));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid email address.'], 422);
        $password = $data['password'] ?? null;
        if (!is_string($password) || strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) respond(['error' => 'Use a password between 10 and 72 characters.'], 422);
        rateLimit($db, $action . ':account:' . $email, 10, 900);
        if ($action === 'register') {
            $name = textField($data, 'name', 150, true);
            $q = $db->prepare('INSERT INTO hal_users (name, email, password_hash) VALUES (?, ?, ?)');
            try { $q->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]); }
            catch (PDOException $e) {
                if ($e->getCode() === '23000') respond(['error' => 'An account with this email already exists. Please sign in.'], 409);
                throw $e;
            }
            $id = (int)$db->lastInsertId();
        } else {
            $q = $db->prepare('SELECT id, password_hash FROM hal_users WHERE email = ?');
            $q->execute([$email]);
            $row = $q->fetch();
            $valid = password_verify($password, $row['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
            if (!$row || !$valid) respond(['error' => 'Email or password is incorrect.'], 401);
            $id = (int)$row['id'];
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                $q = $db->prepare('UPDATE hal_users SET password_hash = ? WHERE id = ?');
                $q->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            }
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        $_SESSION['last_active'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        respond(['user' => profile($db, $id), 'csrfToken' => $_SESSION['csrf']], $action === 'register' ? 201 : 200);
    }
    if ($action === 'profile') {
        if (!isset($_SESSION['user_id']) || !profile($db, (int)$_SESSION['user_id'])) respond(['error' => 'Please sign in to continue.'], 401);
        rateLimit($db, 'profile:' . $_SESSION['user_id'], 30, 900);
        $name = textField($data, 'name', 150, true);
        $phone = preg_replace('/[\s()-]/', '', textField($data, 'phone', 30));
        if (preg_match('/^[6-9][0-9]{9}$/D', $phone)) $phone = '+91' . $phone;
        if ($phone !== '' && !preg_match('/^\+91[6-9][0-9]{9}$/D', $phone)) respond(['error' => 'Enter a valid Indian mobile number.'], 422);
        $address = textField($data, 'address', 500);
        $city = textField($data, 'city', 100);
        $pincode = textField($data, 'pincode', 6);
        if ($pincode !== '' && !preg_match('/^[1-9][0-9]{5}$/D', $pincode)) respond(['error' => 'Enter a valid 6-digit pincode.'], 422);
        $q = $db->prepare('UPDATE hal_users SET name = ?, phone = ?, address = ?, city = ?, pincode = ? WHERE id = ?');
        $q->execute([$name, $phone, $address, $city, $pincode, (int)$_SESSION['user_id']]);
        $_SESSION['last_active'] = time();
        respond(['user' => profile($db, (int)$_SESSION['user_id']), 'csrfToken' => $_SESSION['csrf'], 'message' => 'Your details have been saved.']);
    }
} catch (Throwable $e) {
    error_log('HAL account service error (' . get_class($e) . ').');
    respond(['error' => 'Account service is temporarily unavailable. Please try again shortly.'], 503);
}
