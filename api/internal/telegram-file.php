<?php
require_once __DIR__ . '/bootstrap.php';
internalApiRun(function (): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new AccessDeniedException('Метод не поддерживается');
    }
    $metadata = (new BotInternalAuth())->multipartMetadata();

    return (new TelegramFileUploadService())->store(
        $metadata,
        isset($_FILES['file']) && is_array($_FILES['file'])
            ? $_FILES['file']
            : null
    );
});
