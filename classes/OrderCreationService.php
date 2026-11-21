<?php
class OrderCreationService {
    private const ERROR_ORDER_EXISTS = 'По этой заявке заказ уже создан';
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function createFromLead($leadId, $data, $creatorId) {
        try {
            $context = $this->prepareContext($leadId, $data, $creatorId);
            $this->db->beginTransaction();
            $context = $this->lockContext($context, $creatorId);
            $orderId = $this->insertOrder($context);
            $this->markLeadConverted($context, $orderId, $creatorId);
            $this->attachChats($context, $orderId);
            $this->queueOrderAvailable($context, $orderId);
            $this->db->commit();
            $this->auditCreatedOrder($context, $orderId, $creatorId);
            $result = ['success' => true, 'order_id' => $orderId];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = ['success' => false, 'error' => publicExceptionMessage($error, 'Не удалось создать заказ')];
        }
        return $result;
    }
    private function prepareContext($leadId, $data, $creatorId) {
        if (!in_array(currentRole(), ['manager', 'admin'], true)
            || (int)$creatorId !== (int)($_SESSION['user_id'] ?? 0)) {
            throw new AccessDeniedException('Недостаточно прав для создания заказа');
        }
        $lead = (new Lead())->getById($leadId);
        $this->assertLeadAvailable($lead, $creatorId);
        $this->assertClientTelegramConfirmed((int)$lead['client_id']);
        $context = [
            'lead_id' => (int)$leadId,
            'lead' => $lead,
            'title' => trim((string)($data['title'] ?? '')) ?: $this->buildTitle($lead),
            'event_date' => trim((string)($data['event_date'] ?? $lead['event_date'] ?? '')),
            'start_time' => trim((string)($data['start_time'] ?? '')),
            'end_time' => trim((string)($data['end_time'] ?? '')),
            'address' => trim((string)($data['address'] ?? $lead['location_details'] ?? '')),
            'venue' => trim((string)($data['venue'] ?? $lead['venue'] ?? '')),
            'internal_notes' => $this->buildInternalNotes($lead, $data),
            'organizer_id' => currentRole() === 'admin' && !empty($data['organizer_id']) ? (int)$data['organizer_id'] : null,
            'status' => 'discussion',
        ];
        $this->validateFields($context);
        return $context;
    }
    private function lockContext($context, $creatorId) {
        $stmt = $this->db->prepare('SELECT order_id,manager_id,status FROM leads WHERE id=? FOR UPDATE');
        $stmt->execute([$context['lead_id']]);
        $lockedLead = $stmt->fetch();
        $this->assertLeadAvailable($lockedLead, $creatorId);
        $context['lead']['manager_id'] = $lockedLead['manager_id'];
        $context['lead']['status'] = $lockedLead['status'];
        $context['manager_id'] = $lockedLead['manager_id'] ?: (int)$creatorId;
        $this->assertOrganizerAvailable($context['organizer_id']);
        return $context;
    }
    private function assertLeadAvailable($lead, $creatorId) {
        if (!$lead) {
            throw new EntityNotFoundException('Заявка не найдена');
        }
        if (!empty($lead['order_id'])) {
            throw new ConflictException(self::ERROR_ORDER_EXISTS);
        }
        if (currentRole() === 'manager' && !empty($lead['manager_id'])
            && (int)$lead['manager_id'] !== (int)$creatorId) {
            throw new AccessDeniedException('Заявка назначена другому менеджеру');
        }
    }
    private function assertOrganizerAvailable($organizerId) {
        if ($organizerId) {
            $activeClause = dbColumnExists('users', 'is_active') ? ' AND is_active=1' : '';
            $stmt = $this->db->prepare("SELECT 1 FROM users WHERE id=? AND role IN ('organizer','admin'){$activeClause} LIMIT 1");
            $stmt->execute([(int)$organizerId]);
            if (!$stmt->fetchColumn()) {
                throw new EntityNotFoundException('Выбранный организатор недоступен');
            }
        }
    }
    private function assertClientTelegramConfirmed(int $clientId): void {
        $stmt = $this->db->prepare(
            'SELECT telegram_user_id FROM users WHERE id=? AND is_active=1 LIMIT 1'
        );
        $stmt->execute([$clientId]);
        if ((int)$stmt->fetchColumn() <= 0) {
            throw new ConflictException(
                'Клиент ещё не подтвердил Telegram. '
                . 'Заказ можно создать после завершения регистрации.'
            );
        }
    }
    private function validateFields($context) {
        if (utf8Length($context['title']) > 255 || utf8Length($context['address']) > 500
            || utf8Length($context['venue']) > 255 || utf8Length($context['internal_notes']) > 50000) {
            throw new ValidationException('Одно из полей заказа слишком длинное');
        }
        if ($context['event_date'] !== '' && !$this->validDate($context['event_date'])) {
            throw new ValidationException('Проверьте дату мероприятия');
        }
        if (($context['start_time'] !== '' && !$this->validTime($context['start_time']))
            || ($context['end_time'] !== '' && !$this->validTime($context['end_time']))) {
            throw new ValidationException('Проверьте время мероприятия');
        }
    }
    private function insertOrder($context) {
        $lead = $context['lead'];
        $services = $lead['services_needed'] ?: json_encode([], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare("
            INSERT INTO orders (
                lead_id, client_id, manager_id, organizer_id, title, event_type, status,
                event_date, start_time, end_time, address, venue, guests_count, style,
                budget_range, services_needed, client_comment, internal_notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $context['lead_id'],
            $lead['client_id'],
            $context['manager_id'] ?: null,
            $context['organizer_id'] ?: null,
            $context['title'],
            $lead['event_type'],
            $context['status'],
            $context['event_date'] ?: null,
            $context['start_time'] ?: null,
            $context['end_time'] ?: null,
            $context['address'] ?: null,
            $context['venue'] ?: null,
            $lead['guests_count'],
            $lead['style'],
            $lead['budget_range'],
            $services,
            $this->buildClientComment($lead),
            $context['internal_notes'] ?: null,
        ]);
        return (int)$this->db->lastInsertId();
    }
    private function markLeadConverted($context, $orderId, $creatorId) {
        $stmt = $this->db->prepare("UPDATE leads SET status='converted',order_id=?,manager_id=? WHERE id=? AND order_id IS NULL");
        $stmt->execute([$orderId, $context['manager_id'] ?: null, $context['lead_id']]);
        if ($stmt->rowCount() !== 1) {
            throw new ConflictException(self::ERROR_ORDER_EXISTS);
        }
        if (dbTableExists('lead_status_history')) {
            $stmt = $this->db->prepare("INSERT INTO lead_status_history (lead_id, old_status, new_status, changed_by, comment) VALUES (?, ?, 'converted', ?, 'Менеджер создал заказ')");
            $stmt->execute([$context['lead_id'], $context['lead']['status'], (int)$creatorId]);
        }
        $stmt = $this->db->prepare("INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, comment) VALUES (?, NULL, ?, ?, 'Заказ создан из заявки')");
        $stmt->execute([$orderId, $context['status'], (int)$creatorId]);
    }
    private function attachChats($context, $orderId) {
        $chatService = new Chat();
        $leadChat = $chatService->getLeadChat($context['lead_id']);
        if ($leadChat && dbColumnExists('chats', 'order_id')) {
            $this->db->prepare("UPDATE chats SET order_id=?,type='order_client',title='Чат по заказу' WHERE id=?")
                ->execute([$orderId, (int)$leadChat['id']]);
            if ($context['manager_id']) {
                $chatService->addParticipant((int)$leadChat['id'], (int)$context['manager_id']);
            }
        }
        if ($context['organizer_id']) {
            $chatResult = $chatService->createOrderClientChat($orderId, $context['lead']['client_id'], $context['organizer_id']);
            $this->requireSuccess($chatResult, 'Не удалось создать клиентский чат');
            $messageResult = $chatService->sendMessage(
                $chatResult['chat_id'],
                $context['organizer_id'],
                'Мы оформили заявку в заказ. Здесь будут материалы, сметы, договор и план подготовки.'
            );
            $this->requireSuccess($messageResult, 'Не удалось создать приветственное сообщение');
            $teamChatResult = $chatService->createOrderTeamChat($orderId, [$context['organizer_id']]);
            $this->requireSuccess($teamChatResult, 'Не удалось создать командный чат');
            if ($context['manager_id']) {
                $chatService->completeManagerHandoff($orderId, (int)$context['manager_id']);
            }
            $bridge = new TelegramBridge();
            foreach ($bridge->scheduleOrderProvision((int)$orderId) as $provisionResult) {
                $this->requireSuccess($provisionResult, 'Не удалось запланировать Telegram-группу');
            }
            $this->requireSuccess(
                $bridge->scheduleInvite((int)$orderId, (int)$context['lead']['client_id'], 'order_client'),
                'Не удалось запланировать приглашение клиента'
            );
            $this->requireSuccess(
                $bridge->scheduleInvite((int)$orderId, (int)$context['organizer_id'], 'order_client'),
                'Не удалось запланировать приглашение организатора'
            );
            $this->requireSuccess(
                $bridge->scheduleInvite((int)$orderId, (int)$context['organizer_id'], 'order_team'),
                'Не удалось запланировать приглашение организатора в команду'
            );
        }
    }
    private function auditCreatedOrder($context, $orderId, $creatorId) {
        AuditLog::record('order.created_from_lead', 'order', $orderId, null, [
            'lead_id' => $context['lead_id'],
            'client_id' => (int)$context['lead']['client_id'],
            'manager_id' => $context['manager_id'],
            'organizer_id' => $context['organizer_id'],
        ], ['order_id' => $orderId, 'lead_id' => $context['lead_id'], 'user_id' => $creatorId]);
    }
    private function queueOrderAvailable($context, $orderId) {
        $result = (new NotificationQueue())->enqueue('order_available', [
            'text' => 'Доступен новый заказ #' . $orderId . ': ' . ($context['lead']['event_type'] ?: 'событие')
                . ($context['event_date'] !== '' ? ', ' . formatRussianDate($context['event_date'], 'date_only') : '')
                . '. ' . appUrl('/organizer/order.php?id=' . $orderId),
            'order_id' => $orderId,
        ], [
            'recipient_type' => 'organizer',
            'recipient_id' => $context['organizer_id'],
            'idempotency_key' => 'order-available-' . $orderId,
            'priority' => 40,
        ]);
        $this->requireSuccess($result, 'Не удалось поставить новый заказ в очередь уведомлений');
    }
    private function requireSuccess(array $result, string $fallback): void {
        if (empty($result['success'])) {
            throw new PersistenceException((string)($result['error'] ?? $fallback));
        }
    }
    private function buildClientComment($lead) {
        $parts = [];
        foreach ([
            'Комментарий из квиза' => $lead['comment'] ?? null,
            'Дополнительные комментарии клиента' => $lead['client_additional_comments'] ?? null,
            'Пожелания' => $lead['client_wishes'] ?? null,
            'Что нравится' => $lead['client_likes'] ?? null,
            'Что не нравится' => $lead['client_dislikes'] ?? null,
        ] as $label => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $parts[] = $label . ":\n" . $value;
            }
        }
        return $parts ? implode("\n\n", $parts) : null;
    }
    private function buildInternalNotes($lead, $data) {
        $parts = [];
        foreach ([
            'Итог разговора' => $lead['conversation_summary'] ?? null,
            'Вопросы клиента' => $lead['client_questions'] ?? null,
            'Ответы менеджера' => $lead['manager_answers'] ?? null,
            'Дополнение при создании заказа' => $data['internal_notes'] ?? null,
        ] as $label => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $parts[] = $label . ":\n" . $value;
            }
        }
        return implode("\n\n", $parts);
    }    private function buildTitle($lead) {
        return ($lead['event_type'] ?: 'Событие') . ' для ' . ($lead['client_name'] ?: 'клиента');
    }
    private function validDate($value) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
        return $date && $date->format('Y-m-d') === $value;
    }
    private function validTime($value) {
        $valid = false;
        foreach (['H:i', 'H:i:s'] as $format) {
            $time = DateTimeImmutable::createFromFormat('!' . $format, (string)$value);
            if ($time && $time->format($format) === $value) {
                $valid = true;
            }
        }
        return $valid;
    }
}
