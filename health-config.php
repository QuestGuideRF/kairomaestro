<?php
$startedAt = microtime(true);
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
$sessionPath = (string)session_save_path();
if (strpos($sessionPath, ';') !== false) {
    $sessionPath = substr($sessionPath, strrpos($sessionPath, ';') + 1);
}
if ($sessionPath === '') {
    $sessionPath = sys_get_temp_dir();
}
echo "config=ok\n";
echo "session_active=" . (session_status() === PHP_SESSION_ACTIVE ? 'yes' : 'no') . "\n";
echo "session_storage_writable=" . (is_dir($sessionPath) && is_writable($sessionPath) ? 'yes' : 'no') . "\n";
echo "elapsed_ms=" . round((microtime(true) - $startedAt) * 1000, 2) . "\n";
