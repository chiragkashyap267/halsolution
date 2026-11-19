<?php
declare(strict_types=1);

function pickupCategories(): array {
    return [
        'paper' => ['name' => 'Paper & cardboard', 'unit' => 'kg', 'icon' => 'newspaper'],
        'ewaste' => ['name' => 'E-waste', 'unit' => 'pieces', 'icon' => 'laptop'],
        'metals' => ['name' => 'Metals', 'unit' => 'kg', 'icon' => 'weight-hanging'],
        'appliances' => ['name' => 'Appliances', 'unit' => 'pieces', 'icon' => 'fan'],
        'plastics' => ['name' => 'Plastics', 'unit' => 'kg', 'icon' => 'bottle-water'],
        'clothes' => ['name' => 'Clothes', 'unit' => 'kg', 'icon' => 'shirt'],
        'vehicles' => ['name' => 'Vehicle scrap', 'unit' => 'pieces', 'icon' => 'motorcycle'],
    ];
}

function pickupSchema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS hal_phone_identities (
        phone VARCHAR(13) CHARACTER SET ascii NOT NULL PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL UNIQUE,
        verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES hal_users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hal_pickups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        reference VARCHAR(24) NOT NULL UNIQUE,
        user_id BIGINT UNSIGNED NOT NULL,
        request_key CHAR(32) CHARACTER SET ascii NOT NULL,
        phone VARCHAR(13) NOT NULL,
        customer_name VARCHAR(150) NOT NULL,
        items LONGTEXT NOT NULL,
        address VARCHAR(500) NOT NULL DEFAULT '',
        city VARCHAR(100) NOT NULL DEFAULT '',
        pincode VARCHAR(6) NOT NULL DEFAULT '',
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        accuracy_m INT UNSIGNED NULL,
        pickup_date DATE NULL,
        time_slot VARCHAR(20) NOT NULL DEFAULT 'flexible',
        notes VARCHAR(500) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY request_per_user (user_id, request_key),
        INDEX user_pickups (user_id, created_at),
        FOREIGN KEY (user_id) REFERENCES hal_users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

}

function normalizePhone(string $phone): string {
    $phone = preg_replace('/[\s()-]/', '', $phone);
    if (preg_match('/^[6-9][0-9]{9}$/D', $phone)) $phone = '+91' . $phone;
    if (!preg_match('/^\+91[6-9][0-9]{9}$/D', $phone)) respond(['error' => 'Enter a valid 10-digit Indian mobile number.'], 422);
    return $phone;
}

function verifiedPickupPhone(): ?string {
    $auth = $_SESSION['pickup_auth'] ?? null;
    if (!$auth || !isset($_SESSION['user_id']) || $auth['user_id'] !== (int)$_SESSION['user_id'] || time() - $auth['verified_at'] > 1800) return null;
    return $auth['phone'];
}

function sendSmsOtp(string $phone, string $code): void {
    $config = configuration();
    if (!$config['otp_authkey'] || !$config['otp_template_id']) {
        respond(['error' => 'SMS verification is not available yet. Please contact us to arrange your pickup.'], 503);
    }
    if (!function_exists('curl_init')) throw new RuntimeException('SMS transport unavailable.');
    $query = http_build_query([
        'template_id' => $config['otp_template_id'], 'mobile' => ltrim($phone, '+'),
        'otp' => $code, 'otp_length' => 6, 'otp_expiry' => 5,
    ]);
    $curl = curl_init('https://control.msg91.com/api/v5/otp?' . $query);
    curl_setopt_array($curl, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['authkey: ' . $config['otp_authkey'], 'Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $result = is_string($raw) ? json_decode($raw, true) : null;
    if ($status !== 200 || !is_array($result) || ($result['type'] ?? '') !== 'success') {
        respond(['error' => 'We could not send the code. Please try again shortly.'], 503);
    }
}

function verifyChallenge(string $code): array {
    $challenge = $_SESSION['otp_challenge'] ?? null;
    if (!$challenge || time() > $challenge['expires_at']) {
        unset($_SESSION['otp_challenge']);
        respond(['error' => 'This code has expired. Please request a new OTP.'], 422);
    }
    $_SESSION['otp_challenge']['attempts']++;
    if ($challenge['attempts'] >= 5) {
        unset($_SESSION['otp_challenge']);
        respond(['error' => 'Too many incorrect codes. Please request a new OTP.'], 429);
    }
    if (!hash_equals($challenge['hash'], hash_hmac('sha256', $code, $challenge['secret']))) {
        if ($_SESSION['otp_challenge']['attempts'] >= 5) unset($_SESSION['otp_challenge']);
        respond(['error' => 'That code is incorrect. Check the SMS and try again.'], 422);
    }
    return $challenge;
}

function signInByPhone(PDO $db, string $phone): array {
    // Permit phone-only accounts without inventing an email address or password.
    $nullable = $db->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hal_users' AND COLUMN_NAME = 'email'")->fetchColumn();
    if ($nullable === 'NO') {
        $db->exec('ALTER TABLE hal_users MODIFY email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NULL, MODIFY password_hash VARCHAR(255) NULL');
    }
    $db->beginTransaction();
    try {
        $currentId = isset($_SESSION['user_id']) && profile($db, (int)$_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $q = $db->prepare('SELECT user_id FROM hal_phone_identities WHERE phone = ? FOR UPDATE');
        $q->execute([$phone]);
        $owner = $q->fetchColumn();
        if ($owner && $currentId && (int)$owner !== $currentId) {
            $db->rollBack();
            respond(['error' => 'This mobile belongs to another account. Sign out before continuing with it.'], 409);
        }
        if ($owner) $id = (int)$owner;
        elseif ($currentId) $id = $currentId;
        else {
            $q = $db->prepare('INSERT INTO hal_users (name, email, password_hash, phone) VALUES (?, NULL, NULL, ?)');
            $q->execute(['Customer', $phone]);
            $id = (int)$db->lastInsertId();
        }
        if (!$owner) {
            $q = $db->prepare('SELECT phone FROM hal_phone_identities WHERE user_id = ? FOR UPDATE');
            $q->execute([$id]);
            if ($q->fetchColumn()) {
                $q = $db->prepare('UPDATE hal_phone_identities SET phone = ?, verified_at = CURRENT_TIMESTAMP WHERE user_id = ?');
                $q->execute([$phone, $id]);
            } else {
                $q = $db->prepare('INSERT INTO hal_phone_identities (phone, user_id) VALUES (?, ?)');
                $q->execute([$phone, $id]);
            }
        }
        $q = $db->prepare('UPDATE hal_users SET phone = ? WHERE id = ?');
        $q->execute([$phone, $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    session_regenerate_id(true);
    unset($_SESSION['otp_challenge']);
    $_SESSION['user_id'] = $id;
    $_SESSION['last_active'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['pickup_auth'] = ['phone' => $phone, 'user_id' => $id, 'verified_at' => time()];
    return profile($db, $id);
}

function validatePickup(array $data): array {
    $name = textField($data, 'name', 150, true);
    $address = textField($data, 'address', 500);
    $city = textField($data, 'city', 100);
    $pincode = textField($data, 'pincode', 6);
    $notes = textField($data, 'notes', 500);
    $lat = $data['latitude'] ?? null;
    $lon = $data['longitude'] ?? null;
    if ($lat !== null || $lon !== null) {
        if (!is_numeric($lat) || !is_numeric($lon) || !is_finite((float)$lat) || !is_finite((float)$lon) || abs((float)$lat) > 90 || abs((float)$lon) > 180) respond(['error' => 'Your location pin is invalid. Detect your location again or enter an address.'], 422);
        $lat = (float)$lat; $lon = (float)$lon;
    } elseif ($address === '' || $city === '' || $pincode === '') {
        respond(['error' => 'Enter your address, city and pincode, or detect your current location.'], 422);
    }
    if ($pincode !== '' && !preg_match('/^[1-9][0-9]{5}$/D', $pincode)) respond(['error' => 'Enter a valid 6-digit pincode.'], 422);
    $accuracy = $data['accuracy'] ?? null;
    if ($accuracy !== null && (!is_numeric($accuracy) || !is_finite((float)$accuracy) || (float)$accuracy < 0 || (float)$accuracy > 100000)) respond(['error' => 'Invalid location accuracy.'], 422);
    $items = $data['items'] ?? null;
    if (!is_array($items) || count($items) < 1 || count($items) > 7) respond(['error' => 'Select at least one scrap category.'], 422);
    $catalog = pickupCategories(); $selected = [];
    foreach ($items as $item) {
        if (!is_array($item) || !is_string($item['category'] ?? null) || !isset($catalog[$item['category']]) || isset($selected[$item['category']])) respond(['error' => 'Please check your scrap selection.'], 422);
        $id = $item['category']; $quantity = $item['quantity'] ?? null;
        if ($quantity !== null && (!is_numeric($quantity) || !is_finite((float)$quantity) || (float)$quantity <= 0 || (float)$quantity > 100000 || ($catalog[$id]['unit'] === 'pieces' && (float)$quantity !== floor((float)$quantity)))) respond(['error' => 'Enter a valid weight or quantity for ' . $catalog[$id]['name'] . '.'], 422);
        $selected[$id] = ['category' => $id, 'name' => $catalog[$id]['name'], 'unit' => $catalog[$id]['unit'], 'quantity' => $quantity === null ? null : (float)$quantity];
    }
    $date = textField($data, 'date', 10);
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'));
    if ($date !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Kolkata'));
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $parsed < $today || $parsed > $today->modify('+30 days')) respond(['error' => 'Choose a pickup date within the next 30 days.'], 422);
    }
    $slot = textField($data, 'slot', 20);
    if (!in_array($slot, ['flexible', 'morning', 'afternoon'], true)) respond(['error' => 'Choose a valid pickup time.'], 422);
    $key = textField($data, 'request_key', 32, true);
    if (!preg_match('/^[a-f0-9]{32}$/D', $key)) respond(['error' => 'Please refresh the booking page and try again.'], 422);
    return ['name' => $name, 'address' => $address, 'city' => $city, 'pincode' => $pincode, 'notes' => $notes,
        'latitude' => $lat, 'longitude' => $lon, 'accuracy' => $accuracy === null ? null : (int)round((float)$accuracy),
        'items' => array_values($selected), 'date' => $date ?: null, 'slot' => $slot, 'request_key' => $key];
}

function pickupResult(PDO $db, array $row): array {
    return ['reference' => $row['reference'], 'name' => $row['customer_name'], 'phone' => $row['phone'],
        'items' => json_decode($row['items'], true), 'address' => $row['address'], 'city' => $row['city'],
        'pincode' => $row['pincode'], 'latitude' => $row['latitude'], 'longitude' => $row['longitude'],
        'date' => $row['pickup_date'], 'slot' => $row['time_slot'],
        'status' => $row['status'], 'notes' => $row['notes'], 'created_at' => $row['created_at']];
}
