<?php
require_once __DIR__ . '/../config/config.php';
$draft = new QuizDraft();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim((string)($_GET['token'] ?? ''));
    $data = $draft->load($token);
    if ($data === null) {
        jsonResponse(['success' => false, 'error' => 'Черновик не найден или устарел'], 404);
    }
    jsonResponse(['success' => true, 'data' => $data]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Метод не поддерживается'], 405);
}
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || !verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? ''))) {
    jsonResponse(['success' => false, 'error' => 'Сессия устарела'], 419);
}
$result = $draft->save(is_array($payload['data'] ?? null) ? $payload['data'] : [], trim((string)($payload['token'] ?? '')) ?: null);
if (random_int(1, 100) === 1) {
    $draft->cleanupExpired();
}
jsonResponse($result, $result['success'] ? 200 : 422);
