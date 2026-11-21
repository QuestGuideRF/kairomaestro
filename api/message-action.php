<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется вход'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code($_SERVER['REQUEST_METHOD'] === 'POST' ? 419 : 405);
    echo json_encode(['success' => false, 'error' => 'Сессия устарела'], JSON_UNESCAPED_UNICODE);
    exit;
}
$action = (string)($_POST['action'] ?? '');
$messageId = (int)($_POST['message_id'] ?? 0);
$chat = new Chat();
$result = match ($action) {
    'edit' => $chat->editMessage($messageId, (int)$_SESSION['user_id'], (string)($_POST['message'] ?? '')),
    'delete' => $chat->deleteMessage($messageId, (int)$_SESSION['user_id']),
    default => ['success' => false, 'error' => 'Неизвестное действие'],
};
if (empty($result['success'])) {
    $error = (string)($result['error'] ?? '');
    if (str_contains($error, 'недоступ') || str_contains($error, 'только свои')) {
        http_response_code(403);
    } elseif (str_contains($error, 'не найден')) {
        http_response_code(404);
    } elseif (str_contains($error, 'нельзя')) {
        http_response_code(409);
    } else {
        http_response_code(422);
    }
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
