<?php
class TelegramMessageStorage {
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function remoteMap(int $chatId, int $messageId): ?array {
        $stmt = $this->db->prepare(
            'SELECT site_message_id FROM telegram_message_map '
            . 'WHERE telegram_chat_id=? AND telegram_message_id=? LIMIT 1'
        );
        $stmt->execute([$chatId, $messageId]);
        $map = $stmt->fetch();

        return is_array($map) ? $map : null;
    }

    public function replySiteMessage(
        int $bindingId,
        int $telegramMessageId
    ): int {
        $siteMessageId = 0;
        if ($telegramMessageId > 0) {
            $stmt = $this->db->prepare(
                'SELECT site_message_id FROM telegram_message_map '
                . 'WHERE binding_id=? AND telegram_message_id=? LIMIT 1'
            );
            $stmt->execute([$bindingId, $telegramMessageId]);
            $siteMessageId = (int)$stmt->fetchColumn();
        }

        return $siteMessageId;
    }

    public function storeAttachment(array $file): array {
        $tmpPath = (string)($file['tmp_name'] ?? '');
        $detectedMime = class_exists('finfo') && $tmpPath !== ''
            ? (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmpPath)
            : '';
        $limitMb = str_starts_with($detectedMime, 'image/')
            ? (int)env('TELEGRAM_MAX_PHOTO_MB', 15)
            : (int)env('TELEGRAM_MAX_DOCUMENT_MB', 30);
        $stored = FileStorage::store(
            $file,
            'chat',
            self::ALLOWED_MIME_TYPES,
            max(1, min(50, $limitMb)) * 1024 * 1024
        );
        if (empty($stored['success'])) {
            throw new FileStorageException(
                (string)($stored['error'] ?? 'Не удалось сохранить файл')
            );
        }

        return $stored;
    }

    public function insertAttachment(
        int $messageId,
        array $stored,
        array $payload
    ): void {
        $mime = (string)$stored['mime_type'];
        $this->db->prepare(
            'INSERT INTO message_attachments '
            . '(message_id,attachment_type,storage_path,original_name,'
            . 'mime_type,file_size,telegram_file_id,telegram_file_unique_id,'
            . 'width,height) VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $messageId,
            str_starts_with($mime, 'image/') ? 'photo' : 'document',
            $stored['file_path'],
            $stored['file_name'],
            $mime,
            (int)$stored['file_size'],
            substr(
                (string)($payload['telegram_file_id'] ?? ''),
                0,
                255
            ) ?: null,
            substr(
                (string)($payload['telegram_file_unique_id'] ?? ''),
                0,
                255
            ) ?: null,
            !empty($payload['width']) ? (int)$payload['width'] : null,
            !empty($payload['height']) ? (int)$payload['height'] : null,
        ]);
    }

    public function createSiteNotifications(
        int $chatId,
        int $senderId,
        int $messageId
    ): void {
        $sender = $this->db->prepare(
            'SELECT first_name,last_name FROM users WHERE id=?'
        );
        $sender->execute([$senderId]);
        $senderRow = $sender->fetch() ?: [];
        $name = trim(
            (string)($senderRow['first_name'] ?? '')
            . ' '
            . (string)($senderRow['last_name'] ?? '')
        );
        $name = $name !== '' ? $name : 'Участник';
        $stmt = $this->db->prepare(
            "INSERT INTO notifications (user_id,type,title,message,link)
             SELECT cp.user_id,'new_message','Новое сообщение',?,?
             FROM chat_participants cp
             WHERE cp.chat_id=? AND cp.user_id<>?"
        );
        $stmt->execute([
            'Новое сообщение от ' . $name,
            '/chat.php?id=' . $chatId . '#message-' . $messageId,
            $chatId,
            $senderId,
        ]);
    }

    public function attachmentFallback(
        ?array $file,
        string $unsupported
    ): string {
        if ($unsupported === 'attachment_pending') {
            $fallback = 'Вложение загружается из Telegram.';
        } elseif ($unsupported === 'file_unavailable') {
            $fallback = 'Файл временно недоступен. '
                . 'Откройте исходное сообщение в Telegram.';
        } elseif ($unsupported !== '') {
            $labels = [
                'voice' => 'Голосовое сообщение',
                'video' => 'Видео',
                'video_note' => 'Видеосообщение',
                'sticker' => 'Стикер',
                'animation' => 'GIF-анимация',
                'audio' => 'Аудиозапись',
                'poll' => 'Опрос',
                'location' => 'Геопозиция',
                'venue' => 'Место',
                'contact' => 'Контакт',
            ];
            $label = $labels[$unsupported] ?? 'Медиа';
            $fallback = $label . ' отправлено в Telegram.';
        } else {
            $fallback = $file !== null ? 'Вложение' : '';
        }

        return $fallback;
    }

    public function deepLink(int $chatId, int $messageId): ?string {
        $internalId = str_starts_with((string)$chatId, '-100')
            ? substr((string)$chatId, 4)
            : null;

        return $internalId
            ? 'https://t.me/c/' . $internalId . '/' . $messageId
            : null;
    }

    public function deleteStoredAttachment(?array $stored): void {
        if ($stored !== null) {
            FileStorage::deleteOwnedFile(
                (string)$stored['file_path'],
                'chat',
                'telegram_message'
            );
        }
    }
}
