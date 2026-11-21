<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
$chatId = $_GET['chat_id'] ?? 0;
$lastId = $_GET['last_id'] ?? 0;
if (!$chatId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Chat ID required']);
    exit;
}
$chatObj = new Chat();
if (!$chatObj->userCanAccessChat((int)$chatId, (int)$_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Чат недоступен'], JSON_UNESCAPED_UNICODE);
    exit;
}
$messages = $chatObj->getNewMessages((int)$chatId, $_SESSION['user_id'], (int)$lastId);
$changed = $chatObj->getChangedMessages(
    (int)$chatId,
    (int)$_SESSION['user_id'],
    (string)($_GET['since'] ?? '')
);
echo json_encode([
    'success' => true,
    'messages' => $messages,
    'changed' => $changed,
    'server_time' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
