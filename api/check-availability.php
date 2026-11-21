<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn() || !in_array(currentRole(), ['organizer', 'admin'], true) || !can('assign_workers')) {
    jsonResponse(['success' => false, 'error' => 'Доступ запрещён'], 403);
}
$date = trim((string)($_GET['date'] ?? ''));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
    jsonResponse(['success' => false, 'error' => 'Некорректная дата'], 422);
}
$userObj = new User();
$workers = $userObj->getAllWorkers();
$availability = [];
foreach ($workers as $worker) {
    $isAvailable = $userObj->isWorkerAvailable($worker['id'], $date);
    $availability[$worker['id']] = $isAvailable;
}
jsonResponse([
    'success' => true,
    'availability' => $availability
]);
