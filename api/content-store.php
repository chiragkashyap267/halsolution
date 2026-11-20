<?php
declare(strict_types=1);

// Runtime content lives outside public_html, so a Git deployment cannot overwrite edits.
function contentDirectory(): string {
    $path = getenv('HAL_CONTENT_DIR') ?: dirname(dirname(__DIR__)) . '/hal-site-data';
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Content storage unavailable.');
    return $path;
}
function contentDefaults(): array {
    return json_decode(file_get_contents(dirname(__DIR__) . '/content-defaults.json'), true, 32, JSON_THROW_ON_ERROR);
}
function readSiteContent(): array {
    $path = contentDirectory() . '/content.json';
    if (!is_file($path)) return contentDefaults();
    return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
}
function privateWrite(string $file, array $data): void {
    $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
    try {
        if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Could not save changes.');
        chmod($tmp, 0600);
        if (!rename($tmp, $file)) throw new RuntimeException('Could not publish changes.');
    } finally { if (is_file($tmp)) unlink($tmp); }
}
function mutateContent(int $revision, callable $change): array {
    $lock = fopen(contentDirectory() . '/content.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Content storage busy.');
    try {
        $content = readSiteContent();
        if ($revision !== $content['revision']) respond(['error' => 'Another session saved changes. Reload this page before editing again.'], 409);
        $content = $change($content);
        $content['revision']++;
        $content['updatedAt'] = gmdate('c');
        privateWrite(contentDirectory() . '/content.json', $content);
        return $content;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function adminIdentity(): array {
    $path = contentDirectory() . '/admin.json';
    if (is_file($path)) return json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    // Initial credential requested by the site owner. Replaced by a private hash on password change.
    return ['email' => 'admin@gmail.com', 'hash' => '$2y$10$MS7IoWwozZZ9wguqxudNUOvLVVhqTngJPZ.0kYL7Im61OgIfJ8jAC', 'version' => 1];
}
function contentImage(string $url): bool {
    return (bool)(preg_match('~^images/[a-zA-Z0-9_./-]+\.(?:jpe?g|png|webp|svg)$~iD', $url) && !str_contains($url, '..'))
        || (bool)(filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://') && !parse_url($url, PHP_URL_USER) && !parse_url($url, PHP_URL_PASS));
}
