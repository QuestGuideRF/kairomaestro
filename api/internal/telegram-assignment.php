<?php
require_once __DIR__ . '/bootstrap.php';
internalApiRun(function (): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new AccessDeniedException('Метод не поддерживается');
    }
    $payload = (new BotInternalAuth())->jsonPayload();
    if (($payload['action'] ?? '') !== 'respond_assignment') {
        throw new ValidationException('Неизвестная операция');
    }
    $telegramUserId = (int)($payload['telegram_user_id'] ?? 0);
    $assignmentId = (int)($payload['assignment_id'] ?? 0);
    $decision = (string)($payload['decision'] ?? '');
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare(
        "SELECT id,role,first_name,last_name FROM users
         WHERE telegram_user_id=? AND is_active=1
           AND role IN ('florist','courier','installer','helper','worker')
         LIMIT 1"
    );
    $stmt->execute([$telegramUserId]);
    $user = $stmt->fetch();
    if (!$user || $assignmentId <= 0) {
        throw new AccessDeniedException('Назначение недоступно');
    }
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_role'] = (string)$user['role'];
    $_SESSION['role'] = (string)$user['role'];
    $_SESSION['user_name'] = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);
    $result = (new Event())->respondToAssignment(
        $assignmentId,
        (int)$user['id'],
        $decision,
        trim((string)($payload['reason'] ?? ''))
    );
    if (empty($result['success'])) {
        throw new ValidationException((string)($result['error'] ?? 'Не удалось сохранить ответ'));
    }
    return $result;
});
