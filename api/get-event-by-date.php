<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn() || !isWorkerRole()) {
    jsonResponse(['success' => false, 'error' => 'Доступ запрещён'], 403);
}
$date = trim((string)($_GET['date'] ?? ''));
$workerId = $_SESSION['user_id'];
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
    jsonResponse(['success' => false, 'error' => 'Некорректная дата'], 422);
}
$eventObj = new Event();
$events = $eventObj->getByWorker($workerId);
foreach ($events as $event) {
    if ($event['event_date'] === $date) {
        jsonResponse([
            'success' => true,
            'event' => $event
        ]);
    }
}
jsonResponse(['success' => false, 'error' => 'Мероприятие не найдено'], 404);
