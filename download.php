<?php
require_once __DIR__ . '/config/config.php';
requireLogin();
$type = (string)($_GET['type'] ?? '');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$secureFile = new SecureFile();
$variant = (string)($_GET['variant'] ?? 'original');
if (!in_array($variant, ['original', 'thumb'], true)) {
    $variant = 'original';
}
$delivered = $id
    ? $secureFile->deliver($type, (int)$id, (int)$_SESSION['user_id'], (string)currentRole(), $variant)
    : false;
if (!$delivered) {
    $status = $id ? $secureFile->failureStatus() : 404;
    http_response_code($status);
    if ($status === 403) {
        require_once __DIR__ . '/403.php';
    } else {
        require_once __DIR__ . '/404.php';
    }
    exit;
}
exit;
