<?php
trait AdminTelegramRepository {
    public function groupPool(): array {
        if (!dbTableExists('telegram_group_pool')) {
            return [];
        }
        return $this->db->query(
            'SELECT p.*,b.id binding_id,b.site_chat_id,b.chat_kind,b.status binding_status '
            . 'FROM telegram_group_pool p '
            . 'LEFT JOIN telegram_chat_bindings b ON b.group_pool_id=p.id '
            . 'ORDER BY p.kind,p.status,p.id'
        )->fetchAll();
    }
    public function requestGroupRegistration(
        string $telegramChatId,
        string $kind,
        int $actorId
    ): array {
        $telegramChatId = trim($telegramChatId);
        $kind = trim(strtolower($kind));
        $validationError = $this->groupRegistrationValidationError(
            $telegramChatId,
            $kind
        );
        if ($validationError !== null) {
            return ['success' => false, 'error' => $validationError];
        }
        $key = hash(
            'sha256',
            'telegram-group-register:' . $telegramChatId . ':' . $kind . ':'
            . bin2hex(random_bytes(16))
        );
        try {
            $this->db->beginTransaction();
            $this->db->prepare(
                "INSERT INTO telegram_sync_operations "
                . "(operation_type,idempotency_key,status) "
                . "VALUES ('group_register',?,'pending')"
            )->execute([$key]);
            $operationId = (int)$this->db->lastInsertId();
            $payload = json_encode([
                'operation_id' => $operationId,
                'telegram_chat_id' => (int)$telegramChatId,
                'kind' => $kind,
                'requested_by' => $actorId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->db->prepare(
                "INSERT INTO notification_queue "
                . "(recipient_type,event_type,payload_json,status,priority,"
                . "next_attempt_at,idempotency_key) "
                . "VALUES ('admin','telegram.group.register',?,'pending',5,NOW(),?)"
            )->execute([$payload, $key]);
            $this->db->commit();
            AuditLog::record(
                'admin.telegram_group_registration_requested',
                'telegram_sync_operation',
                $operationId,
                null,
                ['kind' => $kind],
                ['user_id' => $actorId, 'role' => 'admin']
            );
            return [
                'success' => true,
                'message' => 'Проверка группы поставлена в очередь',
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log(
                'Telegram group registration enqueue failed: '
                . get_class($error) . ': ' . $error->getMessage()
            );
            return ['success' => false, 'error' => 'Не удалось поставить проверку группы в очередь'];
        }
    }
    private function groupRegistrationValidationError(
        string $telegramChatId,
        string $kind
    ): ?string {
        $error = null;
        $validGroup = preg_match('/^-\d{6,20}$/', $telegramChatId) === 1
            && in_array($kind, ['client', 'team'], true);
        if (!$validGroup) {
            $error = 'Проверьте ID supergroup и тип группы';
        } elseif (!dbTableExists('telegram_sync_operations')
            || !dbTableExists('notification_queue')) {
            $error = 'Сначала примените Telegram-миграцию';
        }

        return $error;
    }
    public function telegramBindings(): array {
        if (!dbTableExists('telegram_chat_bindings')) {
            return [];
        }
        return $this->db->query(
            'SELECT b.*,o.title order_title,'
            . '(SELECT COUNT(*) FROM telegram_group_memberships m '
            . 'WHERE m.binding_id=b.id AND m.status=\'joined\') joined_count '
            . 'FROM telegram_chat_bindings b JOIN orders o ON o.id=b.order_id '
            . 'ORDER BY b.id DESC LIMIT 300'
        )->fetchAll();
    }
    public function syncOperations(int $limit = 300): array {
        if (!dbTableExists('telegram_sync_operations')) {
            return [];
        }
        $stmt = $this->db->query(
            'SELECT * FROM telegram_sync_operations ORDER BY id DESC LIMIT '
            . max(1, min(500, $limit))
        );
        return $stmt->fetchAll();
    }
    public function inboundEvents(int $limit = 300): array {
        if (!dbTableExists('telegram_inbound_events')) {
            return [];
        }
        $stmt = $this->db->query(
            'SELECT id,event_type,telegram_chat_id,telegram_message_id,telegram_user_id,'
            . 'status,attempts,max_attempts,next_attempt_at,last_error,site_message_id,'
            . 'created_at,completed_at FROM telegram_inbound_events '
            . 'ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))
        );
        return $stmt->fetchAll();
    }
    public function retryInboundEvent(int $eventId, int $actorId): array {
        if ($eventId <= 0 || !dbTableExists('telegram_inbound_events')) {
            return ['success' => false, 'error' => 'Входящее событие не найдено'];
        }
        $stmt = $this->db->prepare(
            "UPDATE telegram_inbound_events SET status='pending',attempts=0,"
            . "next_attempt_at=NOW(),locked_at=NULL,locked_by=NULL,last_error=NULL "
            . "WHERE id=? AND status IN ('failed','retry')"
        );
        $stmt->execute([$eventId]);
        if ($stmt->rowCount() !== 1) {
            return ['success' => false, 'error' => 'Событие уже обработано или выполняется'];
        }
        AuditLog::record(
            'admin.telegram_inbound_retried',
            'telegram_inbound_event',
            $eventId,
            null,
            null,
            ['user_id' => $actorId, 'role' => 'admin']
        );
        return ['success' => true, 'message' => 'Событие возвращено в обработку'];
    }
}
