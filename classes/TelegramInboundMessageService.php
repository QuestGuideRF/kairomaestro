<?php
class TelegramInboundMessageService {
    private const MISSING_MESSAGE_ID = 'Telegram message id is missing';

    private PDO $db;
    private TelegramBindingContext $contextResolver;
    private TelegramMessageStorage $storage;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->contextResolver = new TelegramBindingContext($db);
        $this->storage = new TelegramMessageStorage($db);
    }

    public function receive(array $payload, ?array $file = null): array {
        $context = $this->contextResolver->resolve($payload);
        $content = $this->validatedContent($payload, $file);
        $existing = $this->storage->remoteMap(
            (int)$context['telegram_chat_id'],
            $content['telegram_message_id']
        );
        if ($existing) {
            $result = [
                'success' => true,
                'duplicate' => true,
                'message_id' => (int)$existing['site_message_id'],
            ];
        } else {
            $result = $this->persist($payload, $file, $context, $content);
        }

        return $result;
    }

    private function validatedContent(array $payload, ?array $file): array {
        $telegramMessageId = (int)($payload['telegram_message_id'] ?? 0);
        $text = trim((string)($payload['text'] ?? ''));
        $unsupported = trim(
            (string)($payload['unsupported_media_type'] ?? '')
        );
        if ($telegramMessageId <= 0) {
            throw new ValidationException(self::MISSING_MESSAGE_ID);
        }
        if ($text === '' && $file === null && $unsupported === '') {
            throw new ValidationException('Пустое сообщение');
        }
        if (utf8Length($text) > 10000 || utf8Length($unsupported) > 50) {
            throw new ValidationException('Сообщение слишком длинное');
        }

        return [
            'telegram_message_id' => $telegramMessageId,
            'text' => $text,
            'unsupported' => $unsupported,
        ];
    }

    private function persist(
        array $payload,
        ?array $file,
        array $context,
        array $content
    ): array {
        $stored = null;
        $this->db->beginTransaction();
        try {
            $fields = $this->messageFields(
                $payload,
                $file,
                $context,
                $content
            );
            if ($file !== null) {
                $stored = $this->storage->storeAttachment($file);
                $fields = $this->withAttachmentFields($fields, $stored);
            }
            $messageId = $this->insertMessage($fields);
            if ($stored !== null && dbTableExists('message_attachments')) {
                $this->storage->insertAttachment(
                    $messageId,
                    $stored,
                    $payload
                );
            }
            $this->insertRemoteMap($messageId, $context, $content);
            $this->storage->createSiteNotifications(
                (int)$context['site_chat_id'],
                (int)$context['user_id'],
                $messageId
            );
            $this->recordAudit($messageId, $context, $content, $file);
            $this->db->commit();
            $result = [
                'success' => true,
                'message_id' => $messageId,
                'duplicate' => false,
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->storage->deleteStoredAttachment($stored);
            throw $error;
        }

        return $result;
    }

    private function messageFields(
        array $payload,
        ?array $file,
        array $context,
        array $content
    ): array {
        $message = $content['text'] !== ''
            ? $content['text']
            : $this->storage->attachmentFallback(
                $file,
                $content['unsupported']
            );
        $fields = [
            'chat_id' => (int)$context['site_chat_id'],
            'user_id' => (int)$context['user_id'],
            'message' => $message,
        ];
        $replyTo = $this->storage->replySiteMessage(
            (int)$context['id'],
            (int)($payload['reply_to_telegram_message_id'] ?? 0)
        );
        $optional = [
            'message_type' => 'user',
            'reply_to_message_id' => $replyTo ?: null,
            'source' => 'telegram',
            'telegram_deep_link' => $this->storage->deepLink(
                (int)$context['telegram_chat_id'],
                $content['telegram_message_id']
            ),
            'media_group_id' => substr(
                (string)($payload['media_group_id'] ?? ''),
                0,
                100
            ) ?: null,
            'forwarded' => !empty($payload['forwarded']) ? 1 : 0,
            'unsupported_media_type' => $content['unsupported'] ?: null,
        ];

        return $this->existingColumns($fields, $optional);
    }

    private function withAttachmentFields(array $fields, array $stored): array {
        return $this->existingColumns($fields, [
            'file_path' => $stored['file_path'],
            'file_name' => $stored['file_name'],
            'file_type' => $stored['mime_type'],
            'file_size' => $stored['file_size'],
        ]);
    }

    private function existingColumns(array $fields, array $candidates): array {
        foreach ($candidates as $column => $value) {
            if (dbColumnExists('messages', $column)) {
                $fields[$column] = $value;
            }
        }

        return $fields;
    }

    private function insertMessage(array $fields): int {
        $stmt = $this->db->prepare(
            'INSERT INTO messages (`'
            . implode('`,`', array_keys($fields))
            . '`) VALUES ('
            . implode(',', array_fill(0, count($fields), '?'))
            . ')'
        );
        $stmt->execute(array_values($fields));

        return (int)$this->db->lastInsertId();
    }

    private function insertRemoteMap(
        int $messageId,
        array $context,
        array $content
    ): void {
        $this->db->prepare(
            "INSERT INTO telegram_message_map
                (site_message_id,site_chat_id,binding_id,telegram_chat_id,
                 telegram_message_id,direction,revision,sync_status)
             VALUES (?,?,?,?,?,'telegram_to_site',1,'mapped')"
        )->execute([
            $messageId,
            (int)$context['site_chat_id'],
            (int)$context['id'],
            (int)$context['telegram_chat_id'],
            $content['telegram_message_id'],
        ]);
    }

    private function recordAudit(
        int $messageId,
        array $context,
        array $content,
        ?array $file
    ): void {
        AuditLog::record(
            'telegram.message_received',
            'message',
            $messageId,
            null,
            [
                'binding_id' => (int)$context['id'],
                'has_file' => $file !== null,
                'text_length' => utf8Length($content['text']),
            ],
            ['user_id' => (int)$context['user_id']]
        );
    }
}
