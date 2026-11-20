<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require __DIR__ . '/pickup-functions.php';
require __DIR__ . '/content-store.php';

try {
    startAccountSession();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); respond(['error' => 'Method not allowed.'], 405); }
    if (!isset($_SESSION['user_id'])) respond(['error' => 'Sign in to download your receipt.'], 401);
    $reference = $_GET['reference'] ?? '';
    if (!is_string($reference) || !preg_match('/^HAL-[0-9]{6}-[A-F0-9]{8}$/D', $reference)) respond(['error' => 'Receipt not found.'], 404);
    $db = database();
    $q = $db->prepare('SELECT * FROM hal_pickups WHERE reference = ? AND user_id = ?');
    $q->execute([$reference, (int)$_SESSION['user_id']]);
    $order = $q->fetch();
    if (!$order) respond(['error' => 'Receipt not found.'], 404);
    $contact = readSiteContent()['settings'];
    $logo = base64_encode(file_get_contents(dirname(__DIR__) . '/images/logo.png'));
    $escape = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $reference . '-receipt.html"');
    header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    $items = json_decode($order['items'], true, 16, JSON_THROW_ON_ERROR);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $escape($reference) ?> | Pickup receipt</title>
<style>body{font:16px/1.6 system-ui,sans-serif;color:#172d42;max-width:760px;margin:40px auto;padding:0 24px}header{border-bottom:3px solid #0891b2;padding-bottom:20px}h1{font-size:28px;margin-bottom:0}h2{font-size:20px}small{color:#475569}table{width:100%;border-collapse:collapse;margin:20px 0}th,td{text-align:left;border-bottom:1px solid #cbd5e1;padding:12px 4px;overflow-wrap:anywhere}p{overflow-wrap:anywhere}.notice{padding:16px;background:#ecfeff;border:1px solid #a5f3fc;border-radius:8px}@media print{body{margin:0;padding:12px}.notice{background:white}}</style></head>
<body><header><img src="data:image/png;base64,<?= $logo ?>" alt="HAL Solution Pvt. Ltd." width="180"><h1>Pickup order receipt</h1><p><?= $escape($reference) ?></p><small><?= $escape($contact['companyName']) ?><br><?= $escape($contact['address']) ?><br><?= $escape($contact['phone']) ?> &middot; <?= $escape($contact['email']) ?></small></header>
<h2>Customer &amp; pickup details</h2><p><?= $escape($order['customer_name']) ?><br><?= $escape($order['phone']) ?></p>
<p><?= nl2br($escape(implode(', ', array_filter([$order['address'], $order['city'], $order['pincode']])))) ?>
<?php if ($order['latitude'] !== null): ?><br>Location pin: <?= $escape($order['latitude']) ?>, <?= $escape($order['longitude']) ?><?php endif; ?></p>
<p>Preferred date: <?= $escape($order['pickup_date'] ?? 'Next available day') ?><br>Preferred time: <?= $escape($order['time_slot']) ?><br>Request status: <?= $escape($order['status']) ?></p>
<table><thead><tr><th>Scrap item</th><th>Approximate quantity</th></tr></thead><tbody>
<?php foreach ($items as $item): ?><tr><td><?= $escape($item['name']) ?></td><td><?= $escape($item['quantity'] === null ? 'To be weighed at pickup' : $item['quantity'] . ' ' . $item['unit']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php if ($order['notes'] !== ''): ?><h2>Notes</h2><p><?= nl2br($escape($order['notes'])) ?></p><?php endif; ?>
<p class="notice">This acknowledges your pickup request. Final weight, rates and payment will be confirmed after inspection and weighing. This receipt is not a tax invoice or proof of payment. Collection time is subject to availability.</p>
<p><small>Keep this receipt for your records. Open this file in a browser and use Print / Save as PDF if needed.</small></p></body></html>
<?php
} catch (Throwable $e) {
    error_log('HAL receipt error (' . get_class($e) . ').');
    respond(['error' => 'Receipt is temporarily unavailable. Please try again shortly.'], 503);
}
