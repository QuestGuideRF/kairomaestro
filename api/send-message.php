<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code($_SERVER['REQUEST_METHOD'] === 'POST' ? 419 : 405);
    echo json_encode(['success' => false, 'error' => 'Bad request']);
    exit;
}
$chatObj = new Chat();
$chatId = (int)($_POST['chat_id'] ?? 0);
$message = $_POST['message'] ?? '';
$replyToMessageId = (int)($_POST['reply_to_message_id'] ?? 0);
if (!$chatId || !$chatObj->userCanAccessChat($chatId, (int)$_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Чат недоступен'], JSON_UNESCAPED_UNICODE);
    exit;
}
$upload = null;
if (!empty($_FILES['file']['name'])) {
    $uploadResult = $chatObj->uploadFile($_FILES['file']);
    if (!$uploadResult['success']) {
        echo json_encode($uploadResult, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $upload = $uploadResult;
}
$result = $chatObj->sendMessage(
    $chatId,
    $_SESSION['user_id'],
    $message,
    $upload,
    $upload['file_type'] ?? null,
    ['reply_to_message_id' => $replyToMessageId]
);
if (!$result['success']) {
    if (is_array($upload) && !empty($upload['file_path'])) {
        FileStorage::deleteOwnedFile(
            (string)$upload['file_path'],
            'chat',
            'message_upload'
        );
    }
    http_response_code(422);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}
$messages = $chatObj->getNewMessages($chatId, $_SESSION['user_id'], (int)$result['message_id'] - 1);
echo json_encode([
    'success' => true,
    'message' => $messages[0] ?? null,
], JSON_UNESCAPED_UNICODE);
