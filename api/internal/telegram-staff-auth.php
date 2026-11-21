<?php
require_once __DIR__ . '/bootstrap.php';
internalApiRun(function (): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new AccessDeniedException('Метод не поддерживается');
    }
    $payload = (new BotInternalAuth())->jsonPayload();
    if (($payload['action'] ?? '') !== 'staff_auth') {
        throw new ValidationException('Неизвестная операция');
    }
    return (new TelegramStaffAuthService())->authenticate($payload);
});
