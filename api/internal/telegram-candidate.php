<?php
require_once __DIR__ . '/bootstrap.php';
internalApiRun(function (): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new AccessDeniedException('Метод не поддерживается');
    }
    $payload = (new BotInternalAuth())->jsonPayload();
    if (($payload['action'] ?? '') !== 'submit_candidate') {
        throw new ValidationException('Неизвестная операция');
    }
    $telegramUserId = (int)($payload['telegram_user_id'] ?? 0);
    $telegramChatId = (int)($payload['telegram_chat_id'] ?? 0);
    if ($telegramUserId <= 0 || $telegramChatId === 0) {
        throw new ValidationException('Telegram identity is required');
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $data['privacy_consent'] = true;
    if (empty($data['telegram']) && !empty($payload['telegram_username'])) {
        $data['telegram'] = ltrim((string)$payload['telegram_username'], '@');
    }
    $result = (new CandidateApplication())->submit(
        $data,
        trim((string)($payload['submission_key'] ?? '')),
        [
            'source' => 'telegram_bot',
            'telegram_user_id' => $telegramUserId,
            'telegram_chat_id' => $telegramChatId,
        ]
    );
    if (empty($result['success'])) {
        throw new ValidationException((string)($result['error'] ?? 'Не удалось создать заявку'));
    }
    return $result;
});
