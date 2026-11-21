<?php
require_once __DIR__ . '/config/config.php';
$healthToken = trim((string)env('HEALTH_CHECK_TOKEN', ''));
$providedToken = trim((string)($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ''));
$allowed = isLoggedIn() && isAdminRole();
if (!$allowed && ($healthToken === '' || $providedToken === '' || !hash_equals($healthToken, $providedToken))) {
    http_response_code(404);
    exit;
}
header('Content-Type: text/plain; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$startedAt = microtime(true);
echo "ok\n";
echo "php=" . PHP_VERSION . "\n";
echo "time=" . date('c') . "\n";
echo "elapsed_ms=" . round((microtime(true) - $startedAt) * 1000, 2) . "\n";
