<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
if (!isAdminRole()) {
    AuditLog::record('admin.access_denied', 'admin_panel', null, null, null, [
        'result' => 'denied',
    ]);
    http_response_code(403);
    require_once __DIR__ . '/../403.php';
    exit;
}
$adminRepository = new AdminRepository();
$adminFlash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
function adminRedirect(string $path, array $result): never {
    $_SESSION['admin_flash'] = [
        'success' => !empty($result['success']),
        'message' => !empty($result['success'])
            ? 'Изменения сохранены.'
            : (string)($result['error'] ?? 'Не удалось выполнить действие.'),
    ];
    redirect('/admin/' . ltrim($path, '/'));
}
