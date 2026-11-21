<?php
class Event {
    use EventPersistenceSupport;
    private const ERROR_EVENT_UNAVAILABLE = 'Мероприятие недоступно';
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function create($data) {
        $actorId = (int)($_SESSION['user_id'] ?? 0);
        if (!$actorId || !in_array(currentRole(), ['organizer', 'admin'], true)
            || (int)($data['organizer_id'] ?? 0) !== $actorId) {
            return ['success' => false, 'error' => 'Недостаточно прав для создания мероприятия'];
        }
        $validationError = $this->validateEventData($data);
        if ($validationError !== null) {
            return ['success' => false, 'error' => $validationError];
        }
        try {
            $stmt = $this->db->prepare("
                INSERT INTO events
                (organizer_id, title, event_date, start_time, end_time, location, latitude, longitude, technical_task, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['organizer_id'],
                $data['title'],
                $data['event_date'],
                $data['start_time'],
                $data['end_time'] ?? null,
                $data['location'],
                $data['latitude'] ?? null,
                $data['longitude'] ?? null,
                $data['technical_task'] ?? null,
                $data['status'] ?? 'active'
            ]);
            $eventId = $this->db->lastInsertId();
            $this->logAction($data['organizer_id'], 'event_created',
                "Создано мероприятие: {$data['title']}", 'event', $eventId);
            $this->createEventChat($eventId, $data['organizer_id']);
            AuditLog::record('event.created', 'event', (int)$eventId, null, [
                'title' => trim((string)$data['title']),
                'event_date' => $data['event_date'],
            ], ['user_id' => $actorId]);
            $result = ['success' => true, 'event_id' => $eventId];
        } catch (Exception $e) {
            $result = ['success' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Не удалось создать мероприятие'];
        }
        return $result;
    }
    private function createEventChat($eventId, $organizerId) {
        $stmt = $this->db->prepare("INSERT INTO chats (event_id, type) VALUES (?, 'event_group')");
        $stmt->execute([$eventId]);
        $chatId = $this->db->lastInsertId();
        $stmt = $this->db->prepare("INSERT INTO chat_participants (chat_id, user_id) VALUES (?, ?)");
        $stmt->execute([$chatId, $organizerId]);
        return $chatId;
    }
    public function getById($id) {
        $stmt = $this->db->prepare("
            SELECT e.*, u.first_name AS organizer_first_name, u.last_name AS organizer_last_name,
                   u.phone AS organizer_phone, o.id AS order_id, o.venue, o.address,
                   o.event_type, o.status AS order_status,
                   c.first_name AS client_first_name, c.last_name AS client_last_name
            FROM events e
            LEFT JOIN users u ON e.organizer_id = u.id
            LEFT JOIN orders o ON o.event_id = e.id
            LEFT JOIN users c ON c.id = o.client_id
            WHERE e.id = ?
        ");
        $stmt->execute([(int)$id]);
        $event = $stmt->fetch();
        if (!$event || !$this->canViewEvent($event, (int)($_SESSION['user_id'] ?? 0))) {
            return false;
        }
        return $event;
    }
    public function getByOrganizer($organizerId, $filters = []) {
        if ((int)$organizerId !== (int)($_SESSION['user_id'] ?? 0) && !isAdminRole()) {
            return [];
        }
        $sql = isAdminRole() ? "SELECT * FROM events WHERE 1=1" : "SELECT * FROM events WHERE organizer_id = ?";
        $params = isAdminRole() ? [] : [(int)$organizerId];
        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= " AND event_date >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND event_date <= ?";
            $params[] = $filters['date_to'];
        }
        $sql .= " ORDER BY event_date DESC, start_time DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function getByWorker($workerId, $filters = []) {
        if ((int)$workerId !== (int)($_SESSION['user_id'] ?? 0) && !isAdminRole()) {
            return [];
        }
        $declineReason = dbColumnExists('event_staff', 'decline_reason')
            ? 'es.decline_reason'
            : 'NULL AS decline_reason';
        $sql = "
            SELECT e.*, es.id AS assignment_id, es.status AS worker_status,
                   es.confirmed_at, es.arrived_at, {$declineReason},
                   o.id AS order_id, o.venue, o.address, o.event_type,
                   c.first_name AS client_first_name, c.last_name AS client_last_name
            FROM events e
            JOIN event_staff es ON e.id = es.event_id
            LEFT JOIN orders o ON o.event_id = e.id
            LEFT JOIN users c ON c.id = o.client_id
            WHERE es.worker_id = ?
        ";
        $params = [(int)$workerId];
        if (!empty($filters['status'])) {
            $sql .= ' AND e.status = ?';
            $params[] = $filters['status'];
        }
        $sql .= ' ORDER BY e.event_date ASC, e.start_time ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function assignWorker($eventId, $workerId, $organizerId) {
        return (new EventAssignmentService($this->db))->assign(
            (int)$eventId,
            (int)$workerId,
            (int)$organizerId
        );
    }
    public function removeWorker($eventId, $workerId, $organizerId) {
        try {
            if ((int)$organizerId !== (int)($_SESSION['user_id'] ?? 0)) {
                throw new AccessDeniedException(self::ERROR_EVENT_UNAVAILABLE);
            }
            $event = $this->getById($eventId);
            if (!$event || !$this->canManageEvent($event, (int)$organizerId)) {
                throw new AccessDeniedException(self::ERROR_EVENT_UNAVAILABLE);
            }
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("DELETE FROM event_staff WHERE event_id = ? AND worker_id = ?");
            $stmt->execute([$eventId, $workerId]);
            $stmt = $this->db->prepare("
                DELETE cp FROM chat_participants cp
                JOIN chats c ON cp.chat_id = c.id
                WHERE cp.user_id = ?
                  AND (
                    (c.event_id = ? AND c.type = 'event_group')
                    OR (c.order_id = ? AND c.type = 'order_team')
                  )
            ");
            $stmt->execute([
                $workerId,
                $eventId,
                (int)($event['order_id'] ?? 0),
            ]);
            if (!empty($event['order_id'])) {
                $removalResult = (new TelegramBridge())->scheduleMembershipRemoval(
                    (int)$event['order_id'],
                    (int)$workerId,
                    'order_team'
                );
                $this->requireSuccessfulOperation(
                    $removalResult,
                    'Не удалось запланировать отзыв Telegram-доступа'
                );
            }
            $this->createNotification($workerId, 'event_removed',
                'Изменение в расписании',
                "Вы удалены из мероприятия {$event['title']}", null);
            $userObj = new User();
            $worker = $userObj->getUserById($workerId);
            $this->logAction($organizerId, 'worker_removed',
                "Работник {$worker['first_name']} {$worker['last_name']} удалён из мероприятия",
                'event', $eventId);
            $this->db->commit();
            AuditLog::record('event.worker_removed', 'event_staff', null, [
                'event_id' => (int)$eventId, 'worker_id' => (int)$workerId,
            ], null, ['user_id' => (int)$organizerId]);
            return ['success' => true];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось удалить сотрудника')];
        }
    }
    public function getEventStaff($eventId) {
        $event = $this->eventRecord((int)$eventId);
        if (!$event || !$this->canViewEvent($event, (int)($_SESSION['user_id'] ?? 0))) {
            return [];
        }
        $declineReason = dbColumnExists('event_staff', 'decline_reason') ? 'es.decline_reason' : 'NULL AS decline_reason';
        $stmt = $this->db->prepare("
            SELECT u.id, u.first_name, u.last_name, u.avatar, u.role,
                   wp.specialty, wp.hourly_rate, es.status, es.confirmed_at, es.arrived_at, {$declineReason}
            FROM event_staff es
            JOIN users u ON es.worker_id = u.id
            LEFT JOIN worker_profiles wp ON u.id = wp.user_id
            WHERE es.event_id = ?
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }
    public function respondToAssignment($assignmentId, $workerId, $decision, $reason = '') {
        return (new EventAssignmentService($this->db))->respond(
            (int)$assignmentId,
            (int)$workerId,
            (string)$decision,
            (string)$reason
        );
    }
    private function requireSuccessfulOperation(array $result, string $fallback): void {
        if (empty($result['success'])) {
            throw new PersistenceException((string)($result['error'] ?? $fallback));
        }
    }
    public function updateWorkerStatus($eventId, $workerId, $status) {
        $result = null;
        if ((int)$workerId !== (int)($_SESSION['user_id'] ?? 0) || !isWorkerRole()) {
            $result = ['success' => false, 'error' => self::ERROR_EVENT_UNAVAILABLE];
        } elseif (!in_array($status, ['confirmed', 'on_location', 'completed'], true)) {
            $result = ['success' => false, 'error' => 'Недопустимый статус участия'];
        }
        if ($result === null) {
            $check = $this->db->prepare('SELECT status FROM event_staff WHERE event_id=? AND worker_id=? LIMIT 1');
            $check->execute([(int)$eventId, (int)$workerId]);
            $oldStatus = $check->fetchColumn();
            if ($oldStatus === false) {
                $result = ['success' => false, 'error' => 'Назначение не найдено'];
            }
        }
        $transitions = [
            'pending' => ['confirmed'],
            'confirmed' => ['on_location'],
            'on_location' => ['completed'],
            'completed' => [],
        ];
        if ($result === null && $oldStatus === $status) {
            $result = ['success' => true, 'unchanged' => true];
        } elseif ($result === null && !in_array($status, $transitions[$oldStatus] ?? [], true)) {
            $result = ['success' => false, 'error' => 'Сначала завершите предыдущий этап участия'];
        }
        if ($result === null) {
            $stmt = $this->db->prepare("
                UPDATE event_staff
                SET status = ?,
                    confirmed_at = CASE WHEN ? = 'confirmed' THEN NOW() ELSE confirmed_at END,
                    arrived_at = CASE WHEN ? = 'on_location' THEN NOW() ELSE arrived_at END
                WHERE event_id = ? AND worker_id = ?
            ");
            $stmt->execute([$status, $status, $status, $eventId, $workerId]);
            if (dbTableExists('order_team') && dbColumnExists('order_team', 'participation_status')) {
                $this->db->prepare('UPDATE order_team ot JOIN orders o ON o.id=ot.order_id SET ot.participation_status=? WHERE o.event_id=? AND ot.worker_id=?')
                    ->execute([$status, (int)$eventId, (int)$workerId]);
            }
            AuditLog::record('event.worker_status_changed', 'event_staff', null, ['status' => $oldStatus], ['status' => $status], [
                'user_id' => (int)$workerId,
            ]);
            $result = ['success' => true];
        }
        return $result;
    }
    public function update($eventId, $data) {
        try {
            $actorId = (int)($_SESSION['user_id'] ?? 0);
            $event = $this->getById((int)$eventId);
            if (!$event || !$this->canManageEvent($event, $actorId)) {
                throw new AccessDeniedException(self::ERROR_EVENT_UNAVAILABLE);
            }
            $validationError = $this->validateEventData($data);
            if ($validationError !== null) {
                throw new ValidationException($validationError);
            }
            $stmt = $this->db->prepare("
                UPDATE events
                SET title = ?, event_date = ?, start_time = ?, end_time = ?,
                    location = ?, latitude = ?, longitude = ?, technical_task = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['title'],
                $data['event_date'],
                $data['start_time'],
                $data['end_time'] ?? null,
                $data['location'],
                $data['latitude'] ?? null,
                $data['longitude'] ?? null,
                $data['technical_task'] ?? null,
                $data['status'] ?? 'active',
                $eventId
            ]);
            AuditLog::record('event.updated', 'event', (int)$eventId, [
                'title' => $event['title'], 'event_date' => $event['event_date'], 'status' => $event['status'],
            ], [
                'title' => trim((string)$data['title']), 'event_date' => $data['event_date'], 'status' => $data['status'] ?? 'active',
            ], ['user_id' => $actorId]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось обновить мероприятие')];
        }
    }
    private function canManageEvent($event, $userId) {
        return (new EventPolicy($this->db))->canManage($event, $userId);
    }
    private function canViewEvent($event, $userId) {
        return (new EventPolicy($this->db))->canView($event, $userId);
    }
    private function validateEventData($data) {
        return (new EventValidator())->validate($data);
    }
}
