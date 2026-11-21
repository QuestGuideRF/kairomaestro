<?php
class Chat {
    use ChatMessageSupport;
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function getEventChat($eventId) {
        if (!dbColumnExists('chats', 'event_id')) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM chats WHERE event_id = ? AND type = 'event_group'");
        $stmt->execute([$eventId]);
        return $stmt->fetch();
    }
    public function getOrderClientChat($orderId) {
        if (!dbColumnExists('chats', 'order_id')) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM chats WHERE order_id = ? AND type = 'order_client' LIMIT 1");
        $stmt->execute([$orderId]);
        return $stmt->fetch();
    }
    public function getLeadChat($leadId) {
        if (!dbColumnExists('chats', 'lead_id')) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM chats WHERE lead_id = ? AND type IN ('client','order_client') LIMIT 1");
        $stmt->execute([$leadId]);
        return $stmt->fetch();
    }
    public function getOrCreateOrganizerChat($workerId, $organizerId) {
        $eventCondition = dbColumnExists('chats', 'event_id') ? 'AND c.event_id IS NULL' : '';
        $stmt = $this->db->prepare("
            SELECT c.id FROM chats c
            JOIN chat_participants cp1 ON c.id = cp1.chat_id AND cp1.user_id = ?
            JOIN chat_participants cp2 ON c.id = cp2.chat_id AND cp2.user_id = ?
            WHERE c.type = 'personal' {$eventCondition}
        ");
        $stmt->execute([$workerId, $organizerId]);
        $chat = $stmt->fetch();
        if ($chat) {
            return ['success' => true, 'chat_id' => $chat['id']];
        }
        return $this->createPersonalChat($workerId, $organizerId);
    }
    public function createLeadChat($leadId, $clientId, $managerId = null) {
        $existing = $this->getLeadChat($leadId);
        if ($existing) {
            $result = ['success' => true, 'chat_id' => $existing['id']];
        } else {
            $participantIds = array_filter([(int)$clientId, $managerId ? (int)$managerId : null]);
            if (!$participantIds) {
                $result = ['success' => false, 'error' => 'Нет участников чата'];
            } elseif (!dbColumnExists('chats', 'lead_id')) {
                $result = ['success' => false, 'error' => 'Таблица chats требует миграцию lead_id'];
            } else {
                $result = $this->createContextChat('client', ['lead_id' => $leadId, 'title' => 'Заявка клиента'], $participantIds);
            }
        }
        return $result;
    }
    public function createOrderClientChat($orderId, $clientId, $responsibleId = null) {
        if (!dbColumnExists('chats', 'order_id')) {
            return ['success' => false, 'error' => 'Таблица chats требует миграцию order_id'];
        }
        $existing = $this->getOrderClientChat($orderId);
        if ($existing) {
            $this->addParticipant($existing['id'], (int)$clientId);
            if ($responsibleId) {
                $this->addParticipant($existing['id'], $responsibleId);
            }
            return ['success' => true, 'chat_id' => $existing['id']];
        }
        $participantIds = array_filter([(int)$clientId, $responsibleId ? (int)$responsibleId : null]);
        return $this->createContextChat('order_client', ['order_id' => $orderId, 'title' => 'Чат по заказу'], $participantIds);
    }
    public function createOrderTeamChat($orderId, $participantIds) {
        if (!dbColumnExists('chats', 'order_id')) {
            return ['success' => false, 'error' => 'Таблица chats требует миграцию order_id'];
        }
        $stmt = $this->db->prepare("SELECT id FROM chats WHERE order_id = ? AND type = 'order_team' LIMIT 1");
        $stmt->execute([$orderId]);
        $existing = $stmt->fetch();
        if ($existing) {
            foreach ($participantIds as $participantId) {
                $this->addParticipant($existing['id'], $participantId);
            }
            return ['success' => true, 'chat_id' => $existing['id']];
        }
        return $this->createContextChat('order_team', ['order_id' => $orderId, 'title' => 'Команда заказа'], $participantIds);
    }
    private function createContextChat($type, $context, $participantIds) {
        $manageTransaction = !$this->db->inTransaction();
        try {
            if ($manageTransaction) {
                $this->db->beginTransaction();
            }
            $columns = [];
            $values = [];
            foreach (['event_id', 'lead_id', 'order_id'] as $column) {
                if (dbColumnExists('chats', $column) && array_key_exists($column, $context)) {
                    $columns[] = $column;
                    $values[] = $context[$column];
                }
            }
            $columns[] = 'type';
            $values[] = $type;
            if (dbColumnExists('chats', 'title')) {
                $columns[] = 'title';
                $values[] = $context['title'] ?? null;
            }
            $stmt = $this->db->prepare($this->dynamicInsertSql('chats', $columns));
            $stmt->execute($values);
            $chatId = $this->db->lastInsertId();
            foreach (array_unique(array_filter($participantIds)) as $participantId) {
                $this->addParticipant($chatId, $participantId);
            }
            if ($manageTransaction) {
                $this->db->commit();
            }
            return ['success' => true, 'chat_id' => $chatId];
        } catch (Exception $e) {
            if ($manageTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Не удалось создать чат'];
        }
    }
    public function addParticipant($chatId, $userId) {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO chat_participants (chat_id, user_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$chatId, $userId]);
    }
    public function completeManagerHandoff($orderId, $managerId) {
        if (!dbColumnExists('chats', 'order_id') || (int)$managerId <= 0) {
            return;
        }
        $stmt = $this->db->prepare("
            DELETE cp
            FROM chat_participants cp
            JOIN chats c ON c.id = cp.chat_id
            WHERE c.order_id = ?
              AND cp.user_id = ?
              AND c.type IN ('order_client', 'order_team')
        ");
        $stmt->execute([(int)$orderId, (int)$managerId]);
    }
    public function userCanAccessChat($chatId, $userId) {
        return (new ChatAccessPolicy($this->db))->canAccess((int)$chatId, (int)$userId);
    }
    public function getUserChats($userId) {
        return (new ChatListService($this->db))->forUser((int)$userId, $this);
    }
    public function sendMessage(
        $chatId,
        $userId,
        $message,
        $filePath = null,
        $fileType = null,
        array $options = []
    ) {
        $manageTransaction = !$this->db->inTransaction();
        try {
            $replyTo = (int)($options['reply_to_message_id'] ?? 0);
            $this->assertOutgoingMessage(
                (int)$chatId,
                (int)$userId,
                (string)$message,
                $filePath,
                $replyTo
            );
            if ($manageTransaction) {
                $this->db->beginTransaction();
            }
            [$columns, $values, $fileValues] = $this->outgoingMessageData(
                (int)$chatId,
                (int)$userId,
                (string)$message,
                $filePath,
                $fileType,
                $options,
                $replyTo
            );
            $stmt = $this->db->prepare($this->dynamicInsertSql('messages', $columns));
            $stmt->execute($values);
            $messageId = (int)$this->db->lastInsertId();
            $this->finalizeOutgoingMessage(
                $messageId,
                (int)$chatId,
                (int)$userId,
                $fileValues
            );
            if ($manageTransaction) {
                $this->db->commit();
            }
            $result = ['success' => true, 'message_id' => $messageId];
        } catch (Throwable $e) {
            if ($manageTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = [
                'success' => false,
                'error' => publicExceptionMessage($e, 'Не удалось отправить сообщение'),
            ];
        }
        return $result;
    }
    public function editMessage($messageId, $userId, $message) {
        $message = trim((string)$message);
        try {
            if ($message === '' || utf8Length($message) > 10000) {
                throw new ValidationException('Введите сообщение длиной до 10 000 символов');
            }
            $this->db->beginTransaction();
            $record = $this->lockedMessage((int)$messageId);
            $this->assertMessageMutationAllowed($record, (int)$userId);
            if (!empty($record['deleted_at'])) {
                throw new ConflictException('Удалённое сообщение нельзя изменить');
            }
            $result = $this->persistMessageEdit($record, (int)$messageId, (int)$userId, $message);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = [
                'success' => false,
                'error' => publicExceptionMessage($error, 'Не удалось изменить сообщение'),
            ];
        }
        return $result;
    }
    public function deleteMessage($messageId, $userId) {
        try {
            $this->db->beginTransaction();
            $record = $this->lockedMessage((int)$messageId);
            $this->assertMessageMutationAllowed($record, (int)$userId);
            if (!empty($record['deleted_at'])) {
                $this->db->commit();
                return ['success' => true, 'unchanged' => true];
            }
            if (!dbColumnExists('messages', 'deleted_at')) {
                throw new ConfigurationException('Для удаления сообщений требуется миграция Telegram CRM');
            }
            $this->db->prepare(
                'UPDATE messages SET deleted_at=NOW(),deleted_by=?,message=? WHERE id=?'
            )->execute([(int)$userId, 'Сообщение удалено', (int)$messageId]);
            $bridgeResult = (new TelegramBridge())->scheduleDelete((int)$messageId);
            $this->assertBridgeResult($bridgeResult);
            AuditLog::record('chat.message_deleted', 'message', (int)$messageId, null, [
                'soft_deleted' => true,
            ], ['user_id' => (int)$userId]);
            $this->db->commit();
            return ['success' => true];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => publicExceptionMessage($error, 'Не удалось удалить сообщение')];
        }
    }
    public function createPersonalChat($user1Id, $user2Id) {
        $manageTransaction = !$this->db->inTransaction();
        try {
            if ($manageTransaction) {
                $this->db->beginTransaction();
            }
            $stmt = $this->db->prepare("
                SELECT c.id FROM chats c
                JOIN chat_participants cp1 ON c.id = cp1.chat_id AND cp1.user_id = ?
                JOIN chat_participants cp2 ON c.id = cp2.chat_id AND cp2.user_id = ?
                WHERE c.type = 'personal'
            ");
            $stmt->execute([$user1Id, $user2Id]);
            $existingChat = $stmt->fetch();
            if ($existingChat) {
                if ($manageTransaction) {
                    $this->db->commit();
                }
                return ['success' => true, 'chat_id' => $existingChat['id']];
            }
            $stmt = $this->db->prepare("INSERT INTO chats (type) VALUES ('personal')");
            $stmt->execute();
            $chatId = $this->db->lastInsertId();
            $stmt = $this->db->prepare("INSERT INTO chat_participants (chat_id, user_id) VALUES (?, ?)");
            $stmt->execute([$chatId, $user1Id]);
            $stmt->execute([$chatId, $user2Id]);
            if ($manageTransaction) {
                $this->db->commit();
            }
            return ['success' => true, 'chat_id' => $chatId];
        } catch (Exception $e) {
            if ($manageTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Не удалось создать чат'];
        }
    }
    public function uploadFile($file) {
        $mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'video/mp4'];
        $result = FileStorage::store($file, 'chat', $mimes, UPLOAD_LIMIT_BYTES);
        if ($result['success']) {
            $result['file_type'] = $result['mime_type'];
        }
        return $result;
    }
    private function dynamicInsertSql(string $table, array $columns): string {
        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        return 'INSERT INTO ' . $table . ' (' . $columnList . ') VALUES (' . $placeholders . ')';
    }
}
