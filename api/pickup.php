<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require __DIR__ . '/pickup-functions.php';

try {
    startAccountSession();
    $action = $_GET['action'] ?? 'session';
    if (!is_string($action) || !in_array($action, ['session', 'send-otp', 'verify-otp', 'create', 'orders'], true)) respond(['error' => 'Not found.'], 404);
    $get = in_array($action, ['session', 'orders'], true);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== ($get ? 'GET' : 'POST')) {
        header('Allow: ' . ($get ? 'GET' : 'POST')); respond(['error' => 'Method not allowed.'], 405);
    }
    $data = [];
    if (!$get) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf'], $token)) respond(['error' => 'Your session changed. Refresh the page and try again.'], 403);
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') respond(['error' => 'JSON request required.'], 415);
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if ($raw === false || strlen($raw) > 16384) respond(['error' => 'Request is too large.'], 413);
        $data = json_decode($raw, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) respond(['error' => 'Invalid request.'], 400);
    }
    $db = database(); pickupSchema($db);
    $config = configuration();
    $user = isset($_SESSION['user_id']) ? profile($db, (int)$_SESSION['user_id']) : null;
    if ($action === 'session') respond(['user' => $user, 'verifiedPhone' => verifiedPickupPhone(),
        'csrfToken' => $_SESSION['csrf'], 'categories' => pickupCategories(),
        'otpReady' => (bool)($config['otp_authkey'] && $config['otp_template_id'])]);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if ($action === 'send-otp') {
        $phone = normalizePhone(textField($data, 'phone', 30, true));
        $config = configuration();
        if (!$config['otp_authkey'] || !$config['otp_template_id']) respond(['error' => 'SMS verification is not available yet. Please contact us to arrange your pickup.'], 503);
        rateLimit($db, 'otp:ip:' . $ip, 10, 900);
        rateLimit($db, 'otp:phone:cooldown:' . $phone, 1, 60);
        rateLimit($db, 'otp:phone:day:' . $phone, 10, 86400);
        rateLimit($db, 'otp:global:day', max(1, min(10000, (int)$config['otp_daily_limit'])), 86400);
        if (isset($_SESSION['otp_sent_at']) && time() - $_SESSION['otp_sent_at'] < 60) respond(['error' => 'Wait 60 seconds before requesting another code.'], 429);
        unset($_SESSION['otp_challenge']);
        $code = (string)random_int(100000, 999999); $secret = bin2hex(random_bytes(32));
        sendSmsOtp($phone, $code);
        $_SESSION['otp_sent_at'] = time();
        $_SESSION['otp_challenge'] = ['phone' => $phone, 'hash' => hash_hmac('sha256', $code, $secret),
            'secret' => $secret, 'attempts' => 0, 'expires_at' => time() + 300];
        respond(['message' => 'A 6-digit OTP has been sent by SMS.', 'retryAfter' => 60]);
    }
    if ($action === 'verify-otp') {
        rateLimit($db, 'otp:verify:ip:' . $ip, 30, 900);
        $code = textField($data, 'code', 6, true);
        if (!preg_match('/^[0-9]{6}$/D', $code)) respond(['error' => 'Enter the 6-digit OTP.'], 422);
        $challenge = verifyChallenge($code);
        $user = signInByPhone($db, $challenge['phone']);
        respond(['user' => $user, 'verifiedPhone' => verifiedPickupPhone(), 'csrfToken' => $_SESSION['csrf']]);
    }
    if (!$user) respond(['error' => 'Verify your mobile number to continue.'], 401);
    if ($action === 'orders') {
        $q = $db->prepare('SELECT * FROM hal_pickups WHERE user_id = ? ORDER BY id DESC LIMIT 50'); $q->execute([$user['id']]);
        respond(['orders' => array_map(fn($row) => pickupResult($db, $row), $q->fetchAll())]);
    }
    $phone = verifiedPickupPhone();
    if (!$phone) respond(['error' => 'Verify your mobile number again before placing this order.'], 401);
    $pickup = validatePickup($data);
    // Replaying the same request never creates a second order.
    $q = $db->prepare('SELECT * FROM hal_pickups WHERE user_id = ? AND request_key = ?');
    $q->execute([$user['id'], $pickup['request_key']]);
    if ($row = $q->fetch()) respond(['order' => pickupResult($db, $row)]);
    rateLimit($db, 'pickup:user:' . $user['id'], 10, 3600);
    $reference = 'HAL-' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $db->beginTransaction();
    try {
        $q = $db->prepare('INSERT INTO hal_pickups (reference, user_id, request_key, phone, customer_name, items, address, city, pincode, latitude, longitude, accuracy_m, pickup_date, time_slot, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $q->execute([$reference, $user['id'], $pickup['request_key'], $phone, $pickup['name'], json_encode($pickup['items'], JSON_THROW_ON_ERROR),
            $pickup['address'], $pickup['city'], $pickup['pincode'], $pickup['latitude'], $pickup['longitude'], $pickup['accuracy'], $pickup['date'], $pickup['slot'], $pickup['notes']]);
        $id = (int)$db->lastInsertId();
        $q = $db->prepare('UPDATE hal_users SET name = ?, address = ?, city = ?, pincode = ? WHERE id = ?');
        $q->execute([$pickup['name'], $pickup['address'], $pickup['city'], $pickup['pincode'], $user['id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            $q = $db->prepare('SELECT * FROM hal_pickups WHERE user_id = ? AND request_key = ?'); $q->execute([$user['id'], $pickup['request_key']]);
            if ($row = $q->fetch()) respond(['order' => pickupResult($db, $row)]);
        }
        throw $e;
    }
    session_write_close();
    $q = $db->prepare('SELECT * FROM hal_pickups WHERE id = ?'); $q->execute([$id]);
    respond(['order' => pickupResult($db, $q->fetch())], 201);
} catch (Throwable $e) {
    error_log('HAL pickup service error (' . get_class($e) . ').');
    respond(['error' => 'Pickup service is temporarily unavailable. Please try again shortly.'], 503);
}
