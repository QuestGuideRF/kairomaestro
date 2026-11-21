<?php
require_once __DIR__ . '/bootstrap.php';
internalApiRun(function (): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new AccessDeniedException('Метод не поддерживается');
    }
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $service = new TelegramChatSyncService();
    if (str_starts_with($contentType, 'multipart/form-data')) {
        $payload = (new BotInternalAuth())->multipartMetadata();
        return match ((string)($payload['action'] ?? '')) {
            'receive_message' => $service->receive($payload, $_FILES['file'] ?? null),
            'attach_message' => $service->attach($payload, $_FILES['file'] ?? null),
            default => throw new ValidationException('Неизвестная операция'),
        };
    }
    $payload = (new BotInternalAuth())->jsonPayload();
    return match ((string)($payload['action'] ?? '')) {
        'receive_message' => $service->receive($payload),
        'edit_message' => $service->edit($payload),
        'attachment_unavailable' => $service->attachmentUnavailable($payload),
        'membership_event' => $service->membershipEvent($payload),
        default => throw new ValidationException('Неизвестная операция'),
    };
});
