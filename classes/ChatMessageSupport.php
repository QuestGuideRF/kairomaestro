<?php
trait ChatMessageSupport {
    public function getMessages($chatId, $userId, $limit = 50, $offset = 0) {
        $messageTypeSelect = $this->messageTypeSelect();
        if (!$this->userCanAccessChat($chatId, $userId)) {
            return [];
        }
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);
        $stmt = $this->db->prepare(
            'UPDATE messages SET is_read = 1 '
            . 'WHERE chat_id = ? AND (user_id IS NULL OR user_id != ?) AND is_read = 0'
        );
        $stmt->execute([$chatId, $userId]);
        $stmt = $this->db->prepare(
            "SELECT m.*, u.first_name, u.last_name, u.role AS user_role, {$messageTypeSelect},
                    rm.message AS reply_message,
                    ru.first_name AS reply_first_name,
                    ru.last_name AS reply_last_name,
                    ru.role AS reply_user_role
             FROM messages m
             LEFT JOIN users u ON m.user_id = u.id
             LEFT JOIN messages rm ON rm.id=m.reply_to_message_id AND rm.chat_id=m.chat_id
             LEFT JOIN users ru ON ru.id=rm.user_id
             WHERE m.chat_id = ?
             ORDER BY m.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, (int)$chatId, PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(3, (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        return $this->withAttachments(array_reverse($stmt->fetchAll()));
    }
    public function getNewMessages($chatId, $userId, $lastId = 0) {
        $messageTypeSelect = $this->messageTypeSelect();
        if (!$this->userCanAccessChat($chatId, $userId)) {
            return [];
        }
        $lastId = max(0, (int)$lastId);
        $stmt = $this->db->prepare(
            "SELECT m.*, u.first_name, u.last_name, u.role AS user_role, {$messageTypeSelect},
                    rm.message AS reply_message,
                    ru.first_name AS reply_first_name,
                    ru.last_name AS reply_last_name,
                    ru.role AS reply_user_role
             FROM messages m
             LEFT JOIN users u ON m.user_id = u.id
             LEFT JOIN messages rm ON rm.id=m.reply_to_message_id AND rm.chat_id=m.chat_id
             LEFT JOIN users ru ON ru.id=rm.user_id
             WHERE m.chat_id = ? AND m.id > ?
             ORDER BY m.created_at ASC
             LIMIT 100"
        );
        $stmt->execute([(int)$chatId, $lastId]);
        $messages = $stmt->fetchAll();
        if ($messages) {
            $this->db->prepare(
                'UPDATE messages SET is_read=1 '
                . 'WHERE chat_id=? AND (user_id IS NULL OR user_id<>?) AND id>?'
            )->execute([(int)$chatId, (int)$userId, $lastId]);
        }
        return $this->withAttachments($messages);
    }
    public function getChangedMessages($chatId, $userId, $since) {
        if (!$this->userCanAccessChat($chatId, $userId)
            || !dbColumnExists('messages', 'edited_at')
            || !dbColumnExists('messages', 'deleted_at')) {
            return [];
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)$since);
        if (!$parsed) {
            $parsed = new DateTimeImmutable('-10 minutes');
        }
        $messageTypeSelect = $this->messageTypeSelect();
        $stmt = $this->db->prepare(
            "SELECT m.*,u.first_name,u.last_name,u.role AS user_role,{$messageTypeSelect},
                    rm.message AS reply_message,
                    ru.first_name AS reply_first_name,
                    ru.last_name AS reply_last_name,
                    ru.role AS reply_user_role
             FROM messages m
             LEFT JOIN users u ON u.id=m.user_id
             LEFT JOIN messages rm ON rm.id=m.reply_to_message_id AND rm.chat_id=m.chat_id
             LEFT JOIN users ru ON ru.id=rm.user_id
             WHERE m.chat_id=?
               AND (
                 (m.edited_at IS NOT NULL AND m.edited_at>=?)
                 OR (m.deleted_at IS NOT NULL AND m.deleted_at>=?)
               )
             ORDER BY m.id
             LIMIT 100"
        );
        $time = $parsed->format('Y-m-d H:i:s');
        $stmt->execute([(int)$chatId, $time, $time]);
        return $this->withAttachments($stmt->fetchAll());
    }
    public function sendSystemMessage($chatId, $message, bool $syncToTelegram = true) {
        $manageTransaction = !$this->db->inTransaction();
        try {
            $message = trim((string)$message);
            $this->assertSystemMessage((int)$chatId, $message);
            if ($manageTransaction) {
                $this->db->beginTransaction();
            }
            $userId = $this->systemMessageUserId((int)$chatId);
            [$columns, $values] = $this->systemMessageData(
                (int)$chatId,
                $userId,
                $message
            );
            $stmt = $this->db->prepare($this->dynamicInsertSql('messages', $columns));
            $stmt->execute($values);
            $messageId = (int)$this->db->lastInsertId();
            if ($syncToTelegram) {
                $this->assertBridgeResult((new TelegramBridge())->scheduleMessage($messageId));
            }
            AuditLog::record('chat.system_message_created', 'message', $messageId, null, [
                'chat_id' => (int)$chatId,
            ]);
            if ($manageTransaction) {
                $this->db->commit();
            }
            $result = ['success' => true, 'message_id' => $messageId];
        } catch (Throwable $error) {
            if ($manageTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = [
                'success' => false,
                'error' => publicExceptionMessage($error, 'Не удалось создать системное сообщение'),
            ];
        }
        return $result;
    }
    private function assertOutgoingMessage(
        int $chatId,
        int $userId,
        string $message,
        mixed $filePath,
        int $replyTo
    ): void {
        if (!$this->userCanAccessChat($chatId, $userId)) {
            throw new AccessDeniedException('Нет доступа к чату');
        }
        if (trim($message) === '' && !$filePath) {
            throw new ValidationException('Сообщение пустое');
        }
        if (utf8Length($message) > 10000) {
            throw new ValidationException('Сообщение слишком длинное');
        }
        if ($replyTo > 0 && !$this->replyBelongsToChat($replyTo, $chatId)) {
            throw new ValidationException('Сообщение для ответа недоступно');
        }
    }
    private function outgoingMessageData(
        int $chatId,
        int $userId,
        string $message,
        mixed $filePath,
        mixed $fileType,
        array $options,
        int $replyTo
    ): array {
        $columns = ['chat_id', 'user_id', 'message'];
        $values = [$chatId, $userId, trim($message)];
        $requestedType = $options['message_type'] ?? 'user';
        $optionalValues = [
            'message_type' => in_array($requestedType, ['user', 'system'], true)
                ? $requestedType
                : 'user',
            'reply_to_message_id' => $replyTo ?: null,
            'source' => 'site',
            'forwarded' => !empty($options['forwarded']) ? 1 : 0,
        ];
        foreach ($optionalValues as $column => $value) {
            if (dbColumnExists('messages', $column)) {
                $columns[] = $column;
                $values[] = $value;
            }
        }
        $fileValues = $this->messageFileValues($filePath, $fileType);
        foreach ($fileValues as $column => $value) {
            if (dbColumnExists('messages', $column)) {
                $columns[] = $column;
                $values[] = $value;
            }
        }
        return [$columns, $values, $fileValues];
    }
    private function messageFileValues(mixed $filePath, mixed $fileType): array {
        if (is_array($filePath)) {
            return [
                'file_path' => $filePath['file_path'] ?? null,
                'file_name' => $filePath['file_name'] ?? null,
                'file_type' => $filePath['file_type'] ?? $filePath['mime_type'] ?? $fileType,
                'file_size' => $filePath['file_size'] ?? null,
            ];
        }
        return [
            'file_path' => $filePath,
            'file_name' => null,
            'file_type' => $fileType,
            'file_size' => null,
        ];
    }
    private function finalizeOutgoingMessage(
        int $messageId,
        int $chatId,
        int $userId,
        array $fileValues
    ): void {
        $this->storeLegacyAttachment($messageId, $fileValues);
        $this->notifyChatParticipants($chatId, $userId, $messageId);
        $this->assertBridgeResult((new TelegramBridge())->scheduleMessage($messageId));
        AuditLog::record('chat.message_created', 'message', $messageId, null, [
            'chat_id' => $chatId,
            'has_file' => !empty($fileValues['file_path']),
        ]);
    }
    private function notifyChatParticipants(
        int $chatId,
        int $senderId,
        ?int $messageId = null
    ): void {
        $eventSelect = dbColumnExists('chats', 'event_id')
            ? 'c.event_id'
            : 'NULL AS event_id';
        $orderSelect = dbColumnExists('chats', 'order_id')
            ? ',c.order_id,c.type AS chat_type'
            : ',NULL AS order_id,c.type AS chat_type';
        $stmt = $this->db->prepare(
            "SELECT cp.user_id, {$eventSelect} {$orderSelect}
             FROM chat_participants cp
             JOIN chats c ON cp.chat_id = c.id
             WHERE cp.chat_id = ? AND cp.user_id != ?"
        );
        $stmt->execute([$chatId, $senderId]);
        $participants = $stmt->fetchAll();
        $sender = (new User())->getUserById($senderId);
        $senderName = $sender
            ? trim(
                (string)$sender['first_name']
                . ' '
                . (string)$sender['last_name']
            )
            : 'Команда проекта';
        foreach ($participants as $participant) {
            $this->db->prepare(
                "INSERT INTO notifications
                 (user_id, type, title, message, link)
                 VALUES (?, 'new_message', 'Новое сообщение', ?, ?)"
            )->execute([
                (int)$participant['user_id'],
                'Новое сообщение от ' . $senderName,
                '/chat.php?id=' . $chatId,
            ]);
            if (!in_array(
                $participant['chat_type'] ?? '',
                ['order_client', 'order_team'],
                true
            )) {
                (new TelegramNotifier())->notifyUser(
                    (int)$participant['user_id'],
                    'new_message',
                    'Новое сообщение от '
                        . $senderName
                        . ': '
                        . appUrl('/chat.php?id=' . $chatId),
                    'chat-message-'
                        . (int)$messageId
                        . '-user-'
                        . (int)$participant['user_id'],
                    40
                );
            }
        }
    }
    private function persistMessageEdit(
        array $record,
        int $messageId,
        int $userId,
        string $message
    ): array {
        $revision = max(1, (int)($record['revision'] ?? 1));
        if ((string)$record['message'] === $message) {
            return ['success' => true, 'unchanged' => true, 'revision' => $revision];
        }
        if (dbTableExists('message_revisions')) {
            $this->db->prepare(
                'INSERT IGNORE INTO message_revisions '
                . '(message_id,revision,message_text,source,edited_by) VALUES (?,?,?,?,?)'
            )->execute([
                $messageId,
                $revision,
                (string)$record['message'],
                (string)($record['source'] ?? 'site'),
                $userId,
            ]);
        }
        $nextRevision = $revision + 1;
        $sets = ['message=?'];
        $params = [$message];
        if (dbColumnExists('messages', 'edited_at')) {
            $sets[] = 'edited_at=NOW()';
        }
        if (dbColumnExists('messages', 'revision')) {
            $sets[] = 'revision=?';
            $params[] = $nextRevision;
        }
        $params[] = $messageId;
        $this->db->prepare(
            'UPDATE messages SET ' . implode(',', $sets) . ' WHERE id=?'
        )->execute($params);
        $this->assertBridgeResult(
            (new TelegramBridge())->scheduleEdit($messageId, $nextRevision)
        );
        AuditLog::record('chat.message_edited', 'message', $messageId, [
            'revision' => $revision,
        ], [
            'revision' => $nextRevision,
        ], ['user_id' => $userId]);
        return ['success' => true, 'revision' => $nextRevision];
    }
    private function assertSystemMessage(int $chatId, string $message): void {
        if ($message === '' || utf8Length($message) > 10000) {
            throw new ValidationException('Некорректное системное сообщение');
        }
        $check = $this->db->prepare('SELECT 1 FROM chats WHERE id=?');
        $check->execute([$chatId]);
        if (!$check->fetchColumn()) {
            throw new EntityNotFoundException('Чат не найден');
        }
    }
    private function systemMessageUserId(int $chatId): ?int {
        $userId = null;
        if (!$this->messageUserCanBeNull()) {
            $participant = $this->db->prepare(
                'SELECT user_id FROM chat_participants WHERE chat_id=? ORDER BY id LIMIT 1'
            );
            $participant->execute([$chatId]);
            $candidate = (int)$participant->fetchColumn();
            if ($candidate <= 0) {
                throw new EntityNotFoundException('В чате нет участников');
            }
            $userId = $candidate;
        }
        return $userId;
    }
    private function systemMessageData(int $chatId, ?int $userId, string $message): array {
        $columns = ['chat_id', 'user_id', 'message'];
        $values = [$chatId, $userId, $message];
        foreach (['message_type', 'source'] as $column) {
            if (dbColumnExists('messages', $column)) {
                $columns[] = $column;
                $values[] = 'system';
            }
        }
        return [$columns, $values];
    }
    private function assertBridgeResult(array $result): void {
        if (empty($result['success'])) {
            throw new NotificationException(
                (string)($result['error'] ?? 'Не удалось поставить Telegram-операцию в очередь')
            );
        }
    }
    private function messageTypeSelect() {
        if (dbColumnExists('messages', 'message_type')) {
            return 'm.message_type AS message_type';
        }
        return "CASE WHEN m.message LIKE 'Здравствуйте! Мы получили вашу заявку%' "
            . "THEN 'system' ELSE 'user' END AS message_type";
    }
    private function replyBelongsToChat(int $messageId, int $chatId): bool {
        $stmt = $this->db->prepare('SELECT 1 FROM messages WHERE id=? AND chat_id=? LIMIT 1');
        $stmt->execute([$messageId, $chatId]);
        return (bool)$stmt->fetchColumn();
    }
    private function storeLegacyAttachment(int $messageId, array $fileValues): void {
        if (empty($fileValues['file_path']) || !dbTableExists('message_attachments')) {
            return;
        }
        $mime = trim((string)($fileValues['file_type'] ?? ''));
        $type = str_starts_with($mime, 'image/') ? 'photo' : 'document';
        $this->db->prepare(
            'INSERT INTO message_attachments '
            . '(message_id,attachment_type,storage_path,original_name,mime_type,file_size) '
            . 'VALUES (?,?,?,?,?,?)'
        )->execute([
            $messageId,
            $type,
            $fileValues['file_path'],
            $fileValues['file_name'] ?: null,
            $mime ?: null,
            !empty($fileValues['file_size']) ? (int)$fileValues['file_size'] : null,
        ]);
    }
    private function withAttachments(array $messages): array {
        if (!$messages || !dbTableExists('message_attachments')) {
            return $messages;
        }
        $messageIds = array_values(array_filter(array_map(
            static fn($message) => (int)($message['id'] ?? 0),
            $messages
        )));
        if (!$messageIds) {
            return $messages;
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->db->prepare(
            'SELECT * FROM message_attachments WHERE message_id IN ('
            . $placeholders . ') ORDER BY id'
        );
        $stmt->execute($messageIds);
        $byMessage = [];
        foreach ($stmt->fetchAll() as $attachment) {
            $byMessage[(int)$attachment['message_id']][] = $attachment;
        }
        foreach ($messages as &$message) {
            $message['attachments'] = $byMessage[(int)$message['id']] ?? [];
        }
        unset($message);
        return $messages;
    }
    private function lockedMessage(int $messageId): array {
        $stmt = $this->db->prepare(
            'SELECT m.*,c.type AS chat_type,c.order_id '
            . 'FROM messages m JOIN chats c ON c.id=m.chat_id '
            . 'WHERE m.id=? FOR UPDATE'
        );
        $stmt->execute([$messageId]);
        $record = $stmt->fetch();
        if (!$record) {
            throw new EntityNotFoundException('Сообщение не найдено');
        }
        return $record;
    }
    private function assertMessageMutationAllowed(array $record, int $userId): void {
        if (!$this->userCanAccessChat((int)$record['chat_id'], $userId)) {
            throw new AccessDeniedException('Сообщение недоступно');
        }
        $isAuthor = (int)($record['user_id'] ?? 0) === $userId;
        if (!$isAuthor && !isAdminRole()) {
            throw new AccessDeniedException('Можно изменять только свои сообщения');
        }
        if (($record['source'] ?? 'site') === 'telegram' && !isAdminRole()) {
            throw new AccessDeniedException('Сообщение из Telegram изменяется в Telegram');
        }
    }
    private function messageUserCanBeNull() {
        $stmt = $this->db->prepare(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages' AND COLUMN_NAME='user_id'"
        );
        $stmt->execute();
        return $stmt->fetchColumn() === 'YES';
    }
}
