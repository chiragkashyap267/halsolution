<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require __DIR__ . '/content-store.php';

function adminSession(): void {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('HAL_ADMIN');
    $host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true), 'samesite' => 'Strict']);
    session_start();
    if (isset($_SESSION['admin_at']) && (time() - $_SESSION['admin_at'] > 1800 || time() - ($_SESSION['admin_started'] ?? 0) > 28800 || ($_SESSION['admin_version'] ?? 0) !== adminIdentity()['version'])) $_SESSION = [];
    $_SESSION['admin_csrf'] ??= bin2hex(random_bytes(32));
    if (isset($_SESSION['admin_at'])) $_SESSION['admin_at'] = time();
}
function requireAdmin(): void {
    if (!isset($_SESSION['admin_at'])) respond(['error' => 'Please sign in to the admin panel.'], 401);
}
function loginAttempt(bool $reset = false): void {
    $file = contentDirectory() . '/login-' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.json';
    $handle = fopen($file, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Login service unavailable.');
    try {
        $state = json_decode(stream_get_contents($handle) ?: '{}', true) ?: [];
        if (($state['until'] ?? 0) < time() || $reset) $state = ['until' => time() + 900, 'count' => 0];
        if (!$reset && $state['count'] >= 5) { header('Retry-After: ' . ($state['until'] - time())); respond(['error' => 'Too many attempts. Try again in 15 minutes.'], 429); }
        if (!$reset) $state['count']++;
        rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($state));
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}
try {
    adminSession();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? 'session';
    if ($method === 'GET' && $action === 'session') respond(['authenticated' => isset($_SESSION['admin_at']), 'csrfToken' => $_SESSION['admin_csrf'], 'email' => 'admin@gmail.com']);
    if ($method === 'GET' && $action === 'content') { requireAdmin(); respond(readSiteContent()); }
    if ($method !== 'POST') { header('Allow: GET, POST'); respond(['error' => 'Method not allowed.'], 405); }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && strcasecmp((string)parse_url($origin, PHP_URL_HOST), (string)parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST)) !== 0) respond(['error' => 'Invalid request origin.'], 403);
    if (!hash_equals($_SESSION['admin_csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) respond(['error' => 'Session changed. Refresh this page.'], 403);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4 * 1024 * 1024) respond(['error' => 'Image must be smaller than 3 MB.'], 413);
    $data = $action === 'upload' ? [] : json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data)) respond(['error' => 'Invalid request.'], 400);
    if ($action === 'login') {
        loginAttempt();
        $password = textField($data, 'password', 256, true);
        $identity = adminIdentity();
        if (!password_verify($password, $identity['hash'])) respond(['error' => 'Incorrect password.'], 401);
        loginAttempt(true); session_regenerate_id(true);
        $_SESSION['admin_at'] = $_SESSION['admin_started'] = time();
        $_SESSION['admin_version'] = $identity['version'];
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
        respond(['authenticated' => true, 'csrfToken' => $_SESSION['admin_csrf']]);
    }
    requireAdmin();
    if ($action === 'logout') { $_SESSION = []; session_destroy(); respond(['ok' => true]); }
    if ($action === 'password') {
        $identity = adminIdentity();
        if (!password_verify(textField($data, 'currentPassword', 256, true), $identity['hash'])) respond(['error' => 'Current password is incorrect.'], 422);
        $password = textField($data, 'newPassword', 256, true);
        if (strlen($password) < 10) respond(['error' => 'Use at least 10 characters for the new password.'], 422);
        $identity['hash'] = password_hash($password, PASSWORD_DEFAULT); $identity['version']++;
        privateWrite(contentDirectory() . '/admin.json', $identity);
        $_SESSION['admin_version'] = $identity['version'];
        session_regenerate_id(true); respond(['ok' => true]);
    }
    if ($action === 'upload') {
        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 3 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) respond(['error' => 'Choose a JPG, PNG or WebP image smaller than 3 MB.'], 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $size = @getimagesize($file['tmp_name']);
        if (!isset($types[$mime]) || !$size || $size[0] > 6000 || $size[1] > 6000 || $size[0] * $size[1] > 20000000) respond(['error' => 'Use a valid JPG, PNG or WebP image up to 6000 pixels.'], 422);
        $dir = dirname(__DIR__) . '/images/uploads';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Upload folder unavailable.');
        $url = 'images/uploads/' . bin2hex(random_bytes(16)) . '.' . $types[$mime];
        if (!move_uploaded_file($file['tmp_name'], dirname(__DIR__) . '/' . $url)) throw new RuntimeException('Could not save image.');
        respond(['url' => $url]);
    }
    if (!isset($data['revision']) || !is_int($data['revision'])) respond(['error' => 'Reload content before saving.'], 422);
    if ($action === 'product') {
        $item = $data['product'] ?? null;
        if (!is_array($item)) respond(['error' => 'Invalid product.'], 422);
        $product = ['id' => textField($item, 'id', 80), 'name' => textField($item, 'name', 100, true), 'description' => textField($item, 'description', 300), 'badge' => textField($item, 'badge', 40), 'category' => textField($item, 'category', 20, true), 'unit' => textField($item, 'unit', 15, true), 'img' => textField($item, 'img', 1500, true)];
        if (!in_array($product['category'], ['paper','clothes','metals','appliances','ewaste','plastics','vehicles'], true) || !in_array($product['unit'], ['kg','unit','estimate'], true) || !contentImage($product['img'])) respond(['error' => 'Check the category, unit and image URL.'], 422);
        if (!is_numeric($item['price'] ?? null) || !is_finite((float)$item['price']) || (float)$item['price'] < 0 || (float)$item['price'] > 10000000) respond(['error' => 'Enter a price between ₹0 and ₹1 crore.'], 422);
        $product['price'] = round((float)$item['price'], 2); $product['active'] = ($item['active'] ?? true) === true;
        $content = mutateContent($data['revision'], function($content) use ($product) {
            foreach ($content['products'] as $existing) if (strcasecmp($existing['name'], $product['name']) === 0 && $existing['id'] !== $product['id']) respond(['error' => 'A product with this name already exists.'], 422);
            if ($product['id'] === '') {
                if (count($content['products']) >= 300) respond(['error' => 'Product limit reached.'], 422);
                $product['id'] = 'scrap-' . bin2hex(random_bytes(8)); $content['products'][] = $product;
            } else {
                $found = false;
                foreach ($content['products'] as &$existing) if ($existing['id'] === $product['id']) { $existing = $product; $found = true; break; }
                unset($existing);
                if (!$found) respond(['error' => 'Product not found.'], 404);
            }
            if (!array_filter($content['products'], fn($p) => $p['active'])) respond(['error' => 'Keep at least one product visible on the website.'], 422);
            return $content;
        }); respond($content);
    }
    if ($action === 'settings') {
        $settings = $data['settings'] ?? [];
        if (!is_array($settings)) respond(['error' => 'Invalid settings.'], 422);
        $clean = [];
        foreach (['companyName'=>150,'email'=>254,'phone'=>20,'address'=>500,'ownerName'=>100,'ownerRole'=>100,'supportHours'=>120,'heroTitle'=>120,'heroSubtitle'=>200,'footerTitle'=>200,'footerButton'=>50,'footerNote'=>250] as $key => $limit) $clean[$key] = textField($settings, $key, $limit, true);
        if (!filter_var($clean['email'], FILTER_VALIDATE_EMAIL) || !preg_match('/^\+[1-9][0-9]{7,14}$/D', $clean['phone'])) respond(['error' => 'Check the email and phone number (include country code).'], 422);
        respond(mutateContent($data['revision'], function($content) use ($clean) { $content['settings'] = $clean; return $content; }));
    }
    respond(['error' => 'Action not found.'], 404);
} catch (JsonException $e) { respond(['error' => 'Invalid request data.'], 400); }
catch (Throwable $e) { error_log('HAL admin: ' . $e->getMessage()); respond(['error' => 'Could not save changes. Please try again or check hosting file permissions.'], 503); }
