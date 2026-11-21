<?php
class NotificationQueue {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function enqueue($eventType, $payload, $options = []) {
        $options = $this->normalizeOptions($options, func_get_args());
        $recipientType = (string)$options['recipient_type'];
        $recipientId = $options['recipient_id'];
        $chatId = $options['chat_id'];
        $idempotencyKey = $options['idempotency_key'];
        $priority = (int)$options['priority'];
        $context = (array)$options['context'];
        if (!dbTableExists('notification_queue')) {
            error_log('notification_queue migration is not installed');
            return ['success' => false, 'queued' => false, 'error' => 'Notification queue is unavailable'];
        }
        try {
            if ($idempotencyKey) {
                $stmt = $this->db->prepare('SELECT id FROM notification_queue WHERE idempotency_key=? LIMIT 1');
                $stmt->execute([$idempotencyKey]);
                $existingId = (int)$stmt->fetchColumn();
                if ($existingId > 0) {
                    return ['success' => true, 'queued' => false, 'queue_id' => $existingId];
                }
            }
            $idempotencyKey = $idempotencyKey ?: hash('sha256', $eventType . '|' . $recipientType . '|' . (string)$recipientId . '|' . json_encode($payload) . '|' . microtime(true));
            $columns = [
                'channel', 'recipient_type', 'recipient_id', 'telegram_chat_id', 'event_type',
                'payload_json', 'status', 'priority', 'next_attempt_at', 'idempotency_key',
            ];
            $values = [
                'telegram',
                $recipientType,
                $recipientId ?: null,
                $chatId ?: null,
                $eventType,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'pending',
                (int)$priority,
                date('Y-m-d H:i:s'),
                $idempotencyKey,
            ];
            foreach (['order_id', 'site_chat_id', 'site_message_id'] as $column) {
                if (dbColumnExists('notification_queue', $column)) {
                    $columns[] = $column;
                    $values[] = !empty($context[$column]) ? (int)$context[$column] : null;
                }
            }
            $stmt = $this->db->prepare(
                'INSERT INTO notification_queue (`' . implode('`,`', $columns) . '`) VALUES ('
                . implode(',', array_fill(0, count($columns), '?')) . ') '
                . 'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
            );
            $stmt->execute($values);
            $id = (int)$this->db->lastInsertId();
            $queued = $stmt->rowCount() > 0;
            if ($queued) {
                AuditLog::record('notification.queued', 'notification_queue', $id, null, [
                    'event_type' => $eventType,
                    'recipient_type' => $recipientType,
                    'recipient_id' => $recipientId,
                ]);
            }
            $result = ['success' => true, 'queued' => $queued, 'queue_id' => $id];
        } catch (Throwable $e) {
            error_log('Notification queue failure: ' . $e->getMessage());
            AuditLog::record('notification.queue_failed', 'notification_queue', null, null, [
                'event_type' => $eventType,
            ], ['result' => 'failure', 'metadata' => ['error' => $e->getMessage()]]);
            $result = [
                'success' => false,
                'queued' => false,
                'error' => 'Не удалось поставить уведомление в очередь',
            ];
        }
        return $result;
    }
    private function normalizeOptions($options, array $arguments): array {
        if (!is_array($options)) {
            $options = [
                'recipient_type' => $options ?: 'manager',
                'recipient_id' => $arguments[3] ?? null,
                'chat_id' => $arguments[4] ?? null,
                'idempotency_key' => $arguments[5] ?? null,
                'priority' => $arguments[6] ?? 100,
                'context' => $arguments[7] ?? [],
            ];
        }
        return array_merge([
            'recipient_type' => 'manager',
            'recipient_id' => null,
            'chat_id' => null,
            'idempotency_key' => null,
            'priority' => 100,
            'context' => [],
        ], $options);
    }
    public function retry($queueId, $userId) {
        if ((int)$userId !== (int)($_SESSION['user_id'] ?? 0) || !can('retry_notifications')) {
            return ['success' => false, 'error' => 'Недостаточно прав'];
        }
        $permanentClause = dbColumnExists('notification_queue', 'permanent_failure')
            ? ' AND permanent_failure=0'
            : '';
        $stmt = $this->db->prepare(
            "UPDATE notification_queue
             SET status='retry',attempts=0,next_attempt_at=NOW(),locked_at=NULL,
                 locked_by=NULL,failed_at=NULL,last_error=NULL
             WHERE id=? AND status IN ('failed','cancelled')
               AND event_type NOT IN ('privileged_login_code','telegram.invite.expired')"
            . $permanentClause
        );
        $stmt->execute([(int)$queueId]);
        $success = $stmt->rowCount() > 0;
        AuditLog::record('notification.retried', 'notification_queue', (int)$queueId, null, ['status' => $success ? 'retry' : 'unchanged'], ['user_id' => $userId, 'result' => $success ? 'success' : 'denied']);
        return ['success' => $success, 'error' => $success ? null : 'Запись недоступна для повторной отправки'];
    }
    public function recent($status = null, $limit = 100) {
        if (!can('retry_notifications')) {
            return [];
        }
        $allowedStatuses = ['pending','processing','sent','retry','failed','cancelled'];
        if ($status && !in_array($status, $allowedStatuses, true)) {
            $status = null;
        }
        $sql = 'SELECT * FROM notification_queue';
        $params = [];
        if ($status) {
            $sql .= ' WHERE status=?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(300, (int)$limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
