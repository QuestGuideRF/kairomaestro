<?php
class TelegramChatSyncService {
    private const MESSAGE_UNAVAILABLE = 'Сообщение недоступно';
    private const MESSAGE_MAP_JOIN = 'JOIN messages m ON m.id=tm.site_message_id ';
    private PDO $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function receive(array $payload, ?array $file = null): array {
        return (new TelegramInboundMessageService($this->db))->receive(
            $payload,
            $file
        );
    }
    public function edit(array $payload): array {
        $context = $this->authorizedContext($payload);
        $telegramMessageId = (int)($payload['telegram_message_id'] ?? 0);
        $text = trim((string)($payload['text'] ?? ''));
        if ($telegramMessageId <= 0 || $text === '' || utf8Length($text) > 10000) {
            throw new ValidationException('Некорректное изменение сообщения');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT m.*,tm.revision AS mapped_revision '
                . 'FROM telegram_message_map tm '
                . self::MESSAGE_MAP_JOIN
                . 'WHERE tm.binding_id=? AND tm.telegram_message_id=? FOR UPDATE'
            );
            $stmt->execute([(int)$context['id'], $telegramMessageId]);
            $message = $stmt->fetch();
            if (!$message || (int)$message['user_id'] !== (int)$context['user_id']) {
                throw new AccessDeniedException(self::MESSAGE_UNAVAILABLE);
            }
            if (!empty($message['deleted_at'])) {
                throw new ConflictException('Удалённое сообщение нельзя изменить');
            }
            if ((string)$message['message'] === $text) {
                $this->db->commit();
                return ['success' => true, 'unchanged' => true];
            }
            $revision = max(1, (int)($message['revision'] ?? 1));
            if (dbTableExists('message_revisions')) {
                $this->db->prepare(
                    'INSERT IGNORE INTO message_revisions '
                    . '(message_id,revision,message_text,source,edited_by) VALUES (?,?,?,?,?)'
                )->execute([
                    (int)$message['id'],
                    $revision,
                    (string)$message['message'],
                    'telegram',
                    (int)$context['user_id'],
                ]);
            }
            $nextRevision = $revision + 1;
            $this->db->prepare(
                'UPDATE messages SET message=?,edited_at=NOW(),revision=? WHERE id=?'
            )->execute([$text, $nextRevision, (int)$message['id']]);
            $this->db->prepare(
                "UPDATE telegram_message_map
                 SET revision=?,sync_status='mapped',last_error=NULL
                 WHERE binding_id=? AND telegram_message_id=?"
            )->execute([$nextRevision, (int)$context['id'], $telegramMessageId]);
            AuditLog::record(
                'telegram.message_edited',
                'message',
                (int)$message['id'],
                ['revision' => $revision],
                ['revision' => $nextRevision],
                ['user_id' => (int)$context['user_id']]
            );
            $this->db->commit();
            return ['success' => true, 'message_id' => (int)$message['id'], 'revision' => $nextRevision];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
    public function attach(array $payload, ?array $file): array {
        return (new TelegramAttachmentSyncService($this->db))->attach(
            $payload,
            $file
        );
    }
    public function attachmentUnavailable(array $payload): array {
        return (new TelegramAttachmentSyncService($this->db))->markUnavailable(
            $payload
        );
    }
    public function membershipEvent(array $payload): array {
        $telegramChatId = (int)($payload['telegram_chat_id'] ?? 0);
        $telegramUserId = (int)($payload['telegram_user_id'] ?? 0);
        $event = (string)($payload['membership_event'] ?? '');
        if ($telegramChatId === 0
            || $telegramUserId <= 0
            || !in_array($event, ['joined', 'left'], true)) {
            throw new ValidationException('Некорректное событие участника группы');
        }
        $stmt = $this->db->prepare(
            'SELECT b.site_chat_id,b.order_id,b.chat_kind,m.status,u.id user_id,'
            . 'u.role,u.first_name,u.last_name '
            . 'FROM telegram_chat_bindings b '
            . 'JOIN telegram_group_memberships m ON m.binding_id=b.id '
            . 'JOIN users u ON u.id=m.user_id AND u.telegram_user_id=m.telegram_user_id '
            . 'WHERE b.telegram_chat_id=? AND m.telegram_user_id=? LIMIT 1'
        );
        $stmt->execute([$telegramChatId, $telegramUserId]);
        $context = $stmt->fetch();
        if (!$context) {
            throw new AccessDeniedException('Участник не связан с группой заказа');
        }
        $this->assertRoleMatchesChat($context);
        $allowedStatuses = $event === 'joined'
            ? ['joined']
            : ['left', 'removed'];
        if (!in_array((string)$context['status'], $allowedStatuses, true)) {
            throw new ConflictException('Статус участника ещё не синхронизирован');
        }
        $name = trim((string)$context['first_name'] . ' ' . (string)$context['last_name']);
        $name = $name !== '' ? $name : 'Участник';
        $text = $event === 'joined'
            ? $name . ' присоединился к группе проекта в Telegram.'
            : $name . ' покинул группу проекта в Telegram.';
        $result = (new Chat())->sendSystemMessage(
            (int)$context['site_chat_id'],
            $text,
            false
        );
        if (empty($result['success'])) {
            throw new IntegrationException((string)($result['error'] ?? 'Не удалось сохранить событие группы'));
        }
        AuditLog::record(
            'telegram.membership_event',
            'order',
            (int)$context['order_id'],
            null,
            [
                'membership_event' => $event,
                'site_chat_id' => (int)$context['site_chat_id'],
                'user_id' => (int)$context['user_id'],
            ],
            ['user_id' => (int)$context['user_id']]
        );
        return ['success' => true, 'message_id' => (int)$result['message_id']];
    }
    private function authorizedContext(array $payload): array {
        return (new TelegramBindingContext($this->db))->resolve($payload);
    }
}
