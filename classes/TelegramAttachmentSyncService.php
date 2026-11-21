<?php
class TelegramAttachmentSyncService {
    private const MISSING_MESSAGE_ID = 'Telegram message id is missing';
    private const MESSAGE_UNAVAILABLE = 'Сообщение недоступно';
    private const MESSAGE_MAP_JOIN = 'JOIN messages m ON m.id=tm.site_message_id ';

    private PDO $db;
    private TelegramBindingContext $contextResolver;
    private TelegramMessageStorage $storage;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->contextResolver = new TelegramBindingContext($db);
        $this->storage = new TelegramMessageStorage($db);
    }

    public function attach(array $payload, ?array $file): array {
        if ($file === null || empty($file['tmp_name'])) {
            throw new ValidationException('Файл Telegram не передан');
        }
        $context = $this->contextResolver->resolve($payload);
        $telegramMessageId = $this->telegramMessageId($payload);

        return $this->persistAttachment(
            $payload,
            $file,
            $context,
            $telegramMessageId
        );
    }

    private function persistAttachment(
        array $payload,
        array $file,
        array $context,
        int $telegramMessageId
    ): array {
        $stored = null;
        $this->db->beginTransaction();
        try {
            $message = $this->lockedMessage(
                (int)$context['id'],
                $telegramMessageId,
                (int)$context['user_id']
            );
            if ($this->hasAttachment((int)$message['id'])) {
                $result = [
                    'success' => true,
                    'duplicate' => true,
                    'message_id' => (int)$message['id'],
                ];
            } else {
                $stored = $this->storage->storeAttachment($file);
                $this->updateMessage($message, $stored);
                if (dbTableExists('message_attachments')) {
                    $this->storage->insertAttachment(
                        (int)$message['id'],
                        $stored,
                        $payload
                    );
                }
                $this->recordReceivedAudit($message, $stored, $context);
                $result = [
                    'success' => true,
                    'duplicate' => false,
                    'message_id' => (int)$message['id'],
                ];
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->storage->deleteStoredAttachment($stored);
            throw $error;
        }

        return $result;
    }

    public function markUnavailable(array $payload): array {
        $context = $this->contextResolver->resolve($payload);
        $telegramMessageId = $this->telegramMessageId($payload);
        $message = $this->message(
            (int)$context['id'],
            $telegramMessageId,
            (int)$context['user_id']
        );
        if (!$this->hasAttachment((int)$message['id'])) {
            $this->updateUnavailableMessage($message);
            AuditLog::record(
                'telegram.attachment_unavailable',
                'message',
                (int)$message['id'],
                null,
                ['binding_id' => (int)$context['id']],
                ['user_id' => (int)$context['user_id']]
            );
        }

        return [
            'success' => true,
            'message_id' => (int)$message['id'],
        ];
    }

    private function telegramMessageId(array $payload): int {
        $messageId = (int)($payload['telegram_message_id'] ?? 0);
        if ($messageId <= 0) {
            throw new ValidationException(self::MISSING_MESSAGE_ID);
        }

        return $messageId;
    }

    private function lockedMessage(
        int $bindingId,
        int $telegramMessageId,
        int $userId
    ): array {
        return $this->findMessage(
            $bindingId,
            $telegramMessageId,
            $userId,
            true
        );
    }

    private function message(
        int $bindingId,
        int $telegramMessageId,
        int $userId
    ): array {
        return $this->findMessage(
            $bindingId,
            $telegramMessageId,
            $userId,
            false
        );
    }

    private function findMessage(
        int $bindingId,
        int $telegramMessageId,
        int $userId,
        bool $lock
    ): array {
        $suffix = $lock ? ' FOR UPDATE' : ' LIMIT 1';
        $stmt = $this->db->prepare(
            'SELECT m.*,tm.site_message_id '
            . 'FROM telegram_message_map tm '
            . self::MESSAGE_MAP_JOIN
            . 'WHERE tm.binding_id=? AND tm.telegram_message_id=?'
            . $suffix
        );
        $stmt->execute([$bindingId, $telegramMessageId]);
        $message = $stmt->fetch();
        if (!$message || (int)$message['user_id'] !== $userId) {
            throw new AccessDeniedException(self::MESSAGE_UNAVAILABLE);
        }

        return $message;
    }

    private function hasAttachment(int $messageId): bool {
        $exists = false;
        if (dbTableExists('message_attachments')) {
            $stmt = $this->db->prepare(
                'SELECT id FROM message_attachments '
                . 'WHERE message_id=? LIMIT 1'
            );
            $stmt->execute([$messageId]);
            $exists = (bool)$stmt->fetchColumn();
        }

        return $exists;
    }

    private function updateMessage(array $message, array $stored): void {
        $updates = [];
        $values = [];
        foreach ([
            'file_path' => $stored['file_path'],
            'file_name' => $stored['file_name'],
            'file_type' => $stored['mime_type'],
            'file_size' => $stored['file_size'],
        ] as $column => $value) {
            if (dbColumnExists('messages', $column)) {
                $updates[] = '`' . $column . '`=?';
                $values[] = $value;
            }
        }
        if (dbColumnExists('messages', 'unsupported_media_type')) {
            $updates[] = '`unsupported_media_type`=NULL';
        }
        $pendingText = $this->storage->attachmentFallback(
            null,
            'attachment_pending'
        );
        if ((string)$message['message'] === $pendingText) {
            $updates[] = '`message`=?';
            $values[] = 'Вложение';
        }
        if ($updates !== []) {
            $values[] = (int)$message['id'];
            $this->db->prepare(
                'UPDATE messages SET ' . implode(',', $updates) . ' WHERE id=?'
            )->execute($values);
        }
    }

    private function updateUnavailableMessage(array $message): void {
        $updates = [];
        $values = [];
        if (dbColumnExists('messages', 'unsupported_media_type')) {
            $updates[] = '`unsupported_media_type`=?';
            $values[] = 'file_unavailable';
        }
        $pendingText = $this->storage->attachmentFallback(
            null,
            'attachment_pending'
        );
        if ((string)$message['message'] === $pendingText) {
            $updates[] = '`message`=?';
            $values[] = $this->storage->attachmentFallback(
                null,
                'file_unavailable'
            );
        }
        if ($updates !== []) {
            $values[] = (int)$message['id'];
            $this->db->prepare(
                'UPDATE messages SET ' . implode(',', $updates) . ' WHERE id=?'
            )->execute($values);
        }
    }

    private function recordReceivedAudit(
        array $message,
        array $stored,
        array $context
    ): void {
        AuditLog::record(
            'telegram.attachment_received',
            'message',
            (int)$message['id'],
            null,
            [
                'binding_id' => (int)$context['id'],
                'mime_type' => (string)$stored['mime_type'],
                'file_size' => (int)$stored['file_size'],
            ],
            ['user_id' => (int)$context['user_id']]
        );
    }
}
