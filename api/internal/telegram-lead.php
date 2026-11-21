<?php
require_once __DIR__ . '/bootstrap.php';
internalApiRun(function (): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new AccessDeniedException('Метод не поддерживается');
    }
    $payload = (new BotInternalAuth())->jsonPayload();
    if (($payload['action'] ?? '') !== 'submit_quiz') {
        throw new ValidationException('Неизвестная операция');
    }
    $identity = is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
    $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
    $telegramUserId = (int)($identity['telegram_user_id'] ?? 0);
    $telegramChatId = (int)($identity['telegram_chat_id'] ?? 0);
    $username = ltrim(trim((string)($identity['telegram_username'] ?? '')), '@');
    if ($telegramUserId <= 0 || $telegramChatId === 0) {
        throw new ValidationException('Telegram identity is missing');
    }
    $answers['contact_method'] = 'telegram';
    $answers['source'] = 'telegram_bot';
    $answers['telegram_username'] = $username;
    $answers['privacy_consent'] = true;
    $answers['_telegram_identity_verified'] = true;
    $answers['submission_key'] = trim((string)($payload['submission_key'] ?? ''));
    $answers['referer_url'] = appUrl('/?source=telegram_bot');
    $result = (new LeadQuizService(Database::getInstance()->getConnection()))->submit(
        $answers,
        [],
        [
            'channel' => 'telegram',
            'telegram_user_id' => $telegramUserId,
            'telegram_chat_id' => $telegramChatId,
            'telegram_username' => $username,
        ]
    );
    if (!$result['success']) {
        throw new ValidationException((string)($result['error'] ?? 'Не удалось создать заявку'));
    }
    if (!empty($result['password_setup_token'])) {
        $result['password_setup_url'] = appUrl(
            '/set-password.php?token=' . rawurlencode((string)$result['password_setup_token'])
        );
        unset($result['password_setup_token']);
    }
    return $result;
});
