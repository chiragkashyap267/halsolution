<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

try {
    startAccountSession();
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
        unset($_SESSION['pickup_auth'], $_SESSION['otp_challenge']);
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
        // A phone identity is a sign-in credential; editing a profile cannot replace it.
        $q = $db->query("SHOW TABLES LIKE 'hal_phone_identities'");
        if ($q->fetchColumn()) {
            $q = $db->prepare('SELECT phone FROM hal_phone_identities WHERE user_id = ?');
            $q->execute([(int)$_SESSION['user_id']]);
            $identity = $q->fetchColumn();
            if ($identity && $phone !== $identity) respond(['error' => 'Verify a new mobile number on the booking page to change your account number.'], 422);
        }
        $address = textField($data, 'address', 500);
        $city = textField($data, 'city', 100);
        $pincode = textField($data, 'pincode', 6);
        if ($pincode !== '' && !preg_match('/^[1-9][0-9]{5}$/D', $pincode)) respond(['error' => 'Enter a valid 6-digit pincode.'], 422);
        $q = $db->prepare('UPDATE hal_users SET name = ?, phone = ?, address = ?, city = ?, pincode = ? WHERE id = ?');
        $q->execute([$name, $phone, $address, $city, $pincode, (int)$_SESSION['user_id']]);
        if (isset($_SESSION['pickup_auth']) && $_SESSION['pickup_auth']['phone'] !== $phone) unset($_SESSION['pickup_auth']);
        $_SESSION['last_active'] = time();
        respond(['user' => profile($db, (int)$_SESSION['user_id']), 'csrfToken' => $_SESSION['csrf'], 'message' => 'Your details have been saved.']);
    }
} catch (Throwable $e) {
    error_log('HAL account service error (' . get_class($e) . ').');
    respond(['error' => 'Account service is temporarily unavailable. Please try again shortly.'], 503);
}
