<?php
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn() || !in_array(currentRole(), ['manager','admin'], true) || !can('manage_leads')) {
    jsonResponse(['success' => false, 'error' => 'Доступ запрещён'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Метод не поддерживается'], 405);
}
$payload = json_decode(file_get_contents('php://input'), true);
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '');
if (!verifyCsrfToken($token)) {
    jsonResponse(['success' => false, 'error' => 'Сессия устарела'], 419);
}
$leadId = (int)($payload['lead_id'] ?? 0);
$status = trim((string)($payload['status'] ?? ''));
$leadObj = new Lead();
$lead = $leadObj->getById($leadId);
if (!$lead || (currentRole() === 'manager' && $lead['manager_id'] && (int)$lead['manager_id'] !== (int)$_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'error' => 'Заявка недоступна'], 403);
}
$result = $leadObj->updateStatus($leadId, $status, (int)$_SESSION['user_id'], 'Перемещение на Kanban-доске');
jsonResponse($result, $result['success'] ? 200 : 422);
