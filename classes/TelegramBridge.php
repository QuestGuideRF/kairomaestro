<?php
class TelegramBridge {
    private PDO $db;
    private NotificationQueue $queue;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->queue = new NotificationQueue();
    }
    public function scheduleOrderProvision(int $orderId): array {
        $results = [];
        foreach (['order_client', 'order_team'] as $kind) {
            $key = 'provision:order:' . $orderId . ':' . $kind;
            $results[$kind] = $this->schedule(
                'telegram.chat.provision',
                $key,
                ['order_id' => $orderId, 'chat_kind' => $kind],
                ['order_id' => $orderId],
                15
            );
        }
        return $results;
    }
    public function scheduleInvite(int $orderId, int $userId, string $chatKind): array {
        if (!in_array($chatKind, ['order_client', 'order_team'], true)) {
            return ['success' => false, 'error' => 'Недопустимый тип Telegram-чата'];
        }
        $version = 'initial';
        if (dbTableExists('telegram_group_memberships')
            && dbTableExists('telegram_chat_bindings')
            && dbTableExists('telegram_invites')) {
            $stmt = $this->db->prepare(
                'SELECT m.id,m.status,UNIX_TIMESTAMP(m.updated_at) AS membership_changed_at,'
                . 'i.id AS invite_id,i.status AS invite_status,'
                . 'UNIX_TIMESTAMP(i.updated_at) AS invite_changed_at '
                . 'FROM telegram_group_memberships m '
                . 'JOIN telegram_chat_bindings b ON b.id=m.binding_id '
                . 'LEFT JOIN telegram_invites i ON i.id=('
                . 'SELECT latest.id FROM telegram_invites latest '
                . 'WHERE latest.binding_id=m.binding_id AND latest.user_id=m.user_id '
                . 'ORDER BY latest.id DESC LIMIT 1'
                . ') '
                . 'WHERE b.order_id=? AND b.chat_kind=? AND m.user_id=? '
                . 'ORDER BY m.id DESC LIMIT 1'
            );
            $stmt->execute([$orderId, $chatKind, $userId]);
            $membership = $stmt->fetch();
            if ($membership) {
                $version = implode(':', [
                    (int)$membership['id'],
                    (string)$membership['status'],
                    (int)$membership['membership_changed_at'],
                    (int)($membership['invite_id'] ?? 0),
                    (string)($membership['invite_status'] ?? 'none'),
                    (int)($membership['invite_changed_at'] ?? 0),
                ]);
            }
        }
        $key = 'invite:order:' . $orderId . ':user:' . $userId . ':'
            . $chatKind . ':version:' . $version;
        return $this->schedule(
            'telegram.invite.create',
            $key,
            ['order_id' => $orderId, 'user_id' => $userId, 'chat_kind' => $chatKind],
            ['order_id' => $orderId],
            20
        );
    }
    public function scheduleMessage(int $messageId): array {
        $message = $this->messageContext($messageId);
        if (!$message || $message['source'] === 'telegram'
            || empty($message['order_id'])
            || !in_array($message['chat_type'], ['order_client', 'order_team'], true)) {
            return ['success' => true, 'queued' => false];
        }
        $key = 'message:site:' . $messageId . ':telegram';
        return $this->schedule(
            'telegram.message.send',
            $key,
            [
                'site_message_id' => $messageId,
                'site_chat_id' => (int)$message['chat_id'],
                'order_id' => (int)($message['order_id'] ?? 0),
            ],
            [
                'order_id' => (int)($message['order_id'] ?? 0),
                'site_chat_id' => (int)$message['chat_id'],
                'site_message_id' => $messageId,
            ],
            25
        );
    }
    public function scheduleEdit(int $messageId, int $revision): array {
        $message = $this->messageContext($messageId);
        if (!$message || $message['source'] === 'telegram'
            || empty($message['order_id'])
            || !in_array($message['chat_type'], ['order_client', 'order_team'], true)) {
            return ['success' => true, 'queued' => false];
        }
        $key = 'edit:site:' . $messageId . ':rev:' . max(1, $revision);
        return $this->schedule(
            'telegram.message.edit',
            $key,
            ['site_message_id' => $messageId, 'revision' => max(1, $revision)],
            [
                'order_id' => (int)($message['order_id'] ?? 0),
                'site_chat_id' => (int)$message['chat_id'],
                'site_message_id' => $messageId,
            ],
            25
        );
    }
    public function scheduleDelete(int $messageId): array {
        $message = $this->messageContext($messageId);
        if (!$message || empty($message['order_id'])
            || !in_array($message['chat_type'], ['order_client', 'order_team'], true)) {
            return ['success' => true, 'queued' => false];
        }
        $key = 'delete:site:' . $messageId . ':telegram';
        return $this->schedule(
            'telegram.message.delete',
            $key,
            ['site_message_id' => $messageId],
            [
                'order_id' => (int)($message['order_id'] ?? 0),
                'site_chat_id' => (int)$message['chat_id'],
                'site_message_id' => $messageId,
            ],
            10
        );
    }
    public function scheduleMembershipRemoval(int $orderId, int $userId, string $chatKind = 'order_team'): array {
        $version = 'legacy';
        if (dbTableExists('telegram_group_memberships')
            && dbTableExists('telegram_chat_bindings')) {
            $stmt = $this->db->prepare(
                'SELECT m.id,UNIX_TIMESTAMP(m.updated_at) AS changed_at '
                . 'FROM telegram_group_memberships m '
                . 'JOIN telegram_chat_bindings b ON b.id=m.binding_id '
                . 'WHERE b.order_id=? AND b.chat_kind=? AND m.user_id=? '
                . 'ORDER BY m.id DESC LIMIT 1'
            );
            $stmt->execute([$orderId, $chatKind, $userId]);
            $membership = $stmt->fetch();
            if ($membership) {
                $version = (int)$membership['id'] . ':' . (int)$membership['changed_at'];
            }
        }
        $key = 'membership:remove:order:' . $orderId . ':user:' . $userId
            . ':' . $chatKind . ':version:' . $version;
        return $this->schedule(
            'telegram.membership.remove',
            $key,
            ['order_id' => $orderId, 'user_id' => $userId, 'chat_kind' => $chatKind],
            ['order_id' => $orderId],
            10
        );
    }
    private function schedule(
        string $eventType,
        string $idempotencyKey,
        array $payload,
        array $context,
        int $priority
    ): array {
        if (!dbTableExists('telegram_sync_operations')) {
            return ['success' => false, 'error' => 'Telegram CRM migration is not installed'];
        }
        $stmt = $this->db->prepare(
            "INSERT INTO telegram_sync_operations
                (idempotency_key,operation_type,order_id,site_chat_id,site_message_id,status)
             VALUES (?,?,?,?,?,'pending')
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        );
        $stmt->execute([
            $idempotencyKey,
            $eventType,
            !empty($context['order_id']) ? (int)$context['order_id'] : null,
            !empty($context['site_chat_id']) ? (int)$context['site_chat_id'] : null,
            !empty($context['site_message_id']) ? (int)$context['site_message_id'] : null,
        ]);
        $payload['operation_id'] = (int)$this->db->lastInsertId();
        return $this->queue->enqueue(
            $eventType,
            $payload,
            [
                'recipient_type' => 'telegram_operation',
                'idempotency_key' => $idempotencyKey,
                'priority' => $priority,
                'context' => $context,
            ]
        );
    }
    private function messageContext(int $messageId): ?array {
        if (!dbColumnExists('messages', 'source')) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT m.id,m.chat_id,m.source,c.order_id,c.type AS chat_type '
            . 'FROM messages m JOIN chats c ON c.id=m.chat_id WHERE m.id=? LIMIT 1'
        );
        $stmt->execute([$messageId]);
        return $stmt->fetch() ?: null;
    }
}
