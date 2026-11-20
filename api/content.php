<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require __DIR__ . '/content-store.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); respond(['error' => 'Method not allowed.'], 405); }
    $content = readSiteContent();
    $content['products'] = array_values(array_filter($content['products'], fn($p) => $p['active']));
    header('Cache-Control: no-cache, must-revalidate');
    header_remove('Vary');
    respond($content);
} catch (Throwable $e) { error_log('HAL content: ' . $e->getMessage()); respond(['error' => 'Website settings temporarily unavailable.'], 503); }
