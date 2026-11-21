<?php
class TeamCalendar {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function entryTypes() {
        return [
            'event' => 'Мероприятие', 'client_meeting' => 'Встреча с клиентом',
            'measurement' => 'Замер', 'purchase' => 'Закупка', 'delivery' => 'Доставка',
            'loading' => 'Погрузка', 'installation' => 'Монтаж', 'dismantling' => 'Демонтаж',
            'equipment_return' => 'Возврат оборудования', 'task' => 'Задача', 'deadline' => 'Крайний срок',
        ];
    }
    public function syncOrganizerOrders($viewerId, $viewerRole, $from, $to) {
        if (!dbTableExists('calendar_entries') || !dbTableExists('calendar_entry_participants')
            || !in_array($viewerRole, ['organizer', 'admin'], true)
            || (int)($_SESSION['user_id'] ?? 0) !== (int)$viewerId) {
            return 0;
        }
        $sql = "SELECT o.* FROM orders o WHERE o.organizer_id IS NOT NULL AND o.event_date IS NOT NULL
                AND o.event_date >= DATE(?) AND o.event_date < DATE(?)";
        $params = [$from, $to];
        if ($viewerRole === 'organizer') {
            $sql .= ' AND o.organizer_id=?';
            $params[] = (int)$viewerId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY o.id');
        $stmt->execute($params);
        $synced = 0;
        foreach ($stmt->fetchAll() as $order) {
            $manageTransaction = !$this->db->inTransaction();
            try {
                if ($manageTransaction) {
                    $this->db->beginTransaction();
                }
                (new OrderTeamService($this->db))->ensureEventForOrder($order, (int)$order['organizer_id']);
                if ($manageTransaction) {
                    $this->db->commit();
                }
                $synced++;
            } catch (Throwable $error) {
                if ($manageTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                error_log('Calendar order sync failed for order ' . (int)$order['id'] . ': ' . $error->getMessage());
            }
        }
        return $synced;
    }
    public function create($data, $participantIds, $creatorId, $allowConflict = false, $overrideReason = null) {
        try {
            $entry = $this->normalizeCreateInput($data, $participantIds, $creatorId);
            $conflicts = $this->participantConflicts($entry['participant_ids'], $entry['start'], $entry['end']);
            $overrideReason = $this->validateConflictOverride($conflicts, $allowConflict, $overrideReason);
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("INSERT INTO calendar_entries (order_id,event_id,entry_type,title,description,starts_at,ends_at,all_day,location,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,'planned',?)");
            $stmt->execute([
                $entry['order_id'],
                $entry['event_id'],
                $entry['type'],
                $entry['title'],
                $entry['description'] ?: null,
                $entry['start'],
                $entry['end'],
                $entry['all_day'],
                $entry['location'] ?: null,
                (int)$creatorId,
            ]);
            $entryId = (int)$this->db->lastInsertId();
            $stmt = $this->db->prepare("INSERT INTO calendar_entry_participants (calendar_entry_id,user_id,role_label,conflict_override,override_reason,assigned_by) VALUES (?,?,?,?,?,?)");
            foreach ($entry['participant_ids'] as $participantId) {
                $hasConflict = isset($conflicts[$participantId]);
                $stmt->execute([$entryId, $participantId, null, $hasConflict ? 1 : 0, $hasConflict ? $overrideReason : null, (int)$creatorId]);
            }
            $this->db->commit();
            AuditLog::record('calendar.entry_created', 'calendar_entry', $entryId, null, [
                'type' => $entry['type'], 'starts_at' => $entry['start'], 'ends_at' => $entry['end'],
                'participants' => $entry['participant_ids'], 'conflict_override' => (bool)$conflicts,
                'override_reason' => $conflicts ? $overrideReason : null,
            ], ['order_id' => $entry['order_id'], 'user_id' => $creatorId]);
            $this->notifyAssignment($entryId, $entry);
            $result = ['success' => true, 'entry_id' => $entryId, 'conflicts_overridden' => count($conflicts)];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось сохранить запись календаря')];
            if ($e instanceof ConflictException) {
                $result['conflict'] = true;
                $result['conflicts'] = $conflicts ?? [];
            }
        }
        return $result;
    }
    public function conflicts($userId, $start, $end, $excludeEntryId = null) {
        $sql = "SELECT ce.id,ce.title,ce.starts_at,ce.ends_at FROM calendar_entries ce JOIN calendar_entry_participants cp ON cp.calendar_entry_id=ce.id WHERE cp.user_id=? AND ce.status<>'cancelled' AND ce.starts_at<? AND ce.ends_at>?";
        $params = [(int)$userId, $end, $start];
        if ($excludeEntryId) {
            $sql .= ' AND ce.id<>?';
            $params[] = (int)$excludeEntryId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $conflicts = $stmt->fetchAll();
        if (dbTableExists('worker_availability')) {
            $stmt = $this->db->prepare("SELECT id,CONCAT('Недоступен: ',COALESCE(reason,'личный календарь')) title,CONCAT(unavailable_date,' 00:00:00') starts_at,CONCAT(unavailable_date,' 23:59:59') ends_at FROM worker_availability WHERE worker_id=? AND unavailable_date BETWEEN DATE(?) AND DATE(?)");
            $stmt->execute([(int)$userId, $start, $end]);
            $conflicts = array_merge($conflicts, $stmt->fetchAll());
        }
        return $conflicts;
    }
    public function list($from, $to, $filters = [], $viewerId = null, $viewerRole = null) {
        $sql = "
            SELECT DISTINCT ce.*, o.title AS order_title, o.event_type, o.status AS order_status,
                   o.event_date AS order_event_date, o.start_time AS order_start_time,
                   o.venue, o.address, o.client_id, o.organizer_id,
                   e.title AS event_title, e.organizer_id AS event_organizer_id,
                   client.first_name AS client_first_name, client.last_name AS client_last_name,
                   creator.first_name AS creator_first_name, creator.last_name AS creator_last_name
            FROM calendar_entries ce
            LEFT JOIN orders o ON o.id=ce.order_id
            LEFT JOIN events e ON e.id=ce.event_id
            LEFT JOIN users client ON client.id=o.client_id
            LEFT JOIN users creator ON creator.id=ce.created_by
            LEFT JOIN calendar_entry_participants cp ON cp.calendar_entry_id=ce.id
            WHERE ce.starts_at<? AND ce.ends_at>?
        ";
        $params = [$to, $from];
        if (isWorkerRole($viewerRole)) {
            $sql .= ' AND cp.user_id=?';
            $params[] = (int)$viewerId;
        } elseif ($viewerRole === 'organizer') {
            $sql .= ' AND (o.organizer_id=? OR e.organizer_id=? OR (ce.order_id IS NULL AND ce.event_id IS NULL AND ce.created_by=?))';
            array_push($params, (int)$viewerId, (int)$viewerId, (int)$viewerId);
        } elseif ($viewerRole !== 'admin') {
            return [];
        }
        $map = ['order_id' => 'ce.order_id', 'entry_type' => 'ce.entry_type', 'status' => 'ce.status'];
        foreach ($map as $key => $column) {
            if (!empty($filters[$key])) {
                $sql .= " AND {$column}=?";
                $params[] = $filters[$key];
            }
        }
        if (!empty($filters['user_id'])) {
            $sql .= ' AND cp.user_id=?';
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['organizer_id'])) {
            $sql .= ' AND o.organizer_id=?';
            $params[] = (int)$filters['organizer_id'];
        }
        if (!empty($filters['event_type'])) {
            $sql .= ' AND o.event_type=?';
            $params[] = $filters['event_type'];
        }
        if (!empty($filters['role'])) {
            $sql .= ' AND EXISTS (SELECT 1 FROM calendar_entry_participants rp JOIN users ru ON ru.id=rp.user_id WHERE rp.calendar_entry_id=ce.id AND ru.role=?)';
            $params[] = $filters['role'];
        }
        if (!empty($filters['venue'])) {
            $sql .= ' AND (ce.location LIKE ? OR o.venue LIKE ?)';
            $term = '%' . trim($filters['venue']) . '%';
            array_push($params, $term, $term);
        }
        $sql .= ' ORDER BY ce.starts_at,ce.id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function participants($entryId) {
        if (!$this->canViewEntry((int)$entryId, (int)($_SESSION['user_id'] ?? 0), currentRole())) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT cp.*,u.first_name,u.last_name,u.role FROM calendar_entry_participants cp JOIN users u ON u.id=cp.user_id WHERE cp.calendar_entry_id=? ORDER BY FIELD(u.role,'organizer','florist','installer','courier','helper','worker'),u.first_name,u.last_name");
        $stmt->execute([(int)$entryId]);
        return $stmt->fetchAll();
    }
    public function upcomingTasksForOrder($orderId, $limit = 4) {
        $orderService = new Order();
        $order = $orderService->getById((int)$orderId);
        if (!$order || !$orderService->canCollaborate($order, (int)($_SESSION['user_id'] ?? 0), currentRole())) {
            return [];
        }
        $limit = max(1, min(10, (int)$limit));
        $stmt = $this->db->prepare("
            SELECT t.id,t.title,t.status,t.due_date,u.first_name,u.last_name
            FROM order_tasks t
            LEFT JOIN users u ON u.id=t.assigned_to
            WHERE t.order_id=? AND t.status NOT IN ('done','cancelled')
            ORDER BY t.due_date IS NULL,t.due_date,t.id
            LIMIT {$limit}
        ");
        $stmt->execute([(int)$orderId]);
        return $stmt->fetchAll();
    }
    public function updateStatus($entryId, $status, $userId) {
        $stmt = $this->db->prepare("SELECT * FROM calendar_entries WHERE id=?");
        $stmt->execute([(int)$entryId]);
        $old = $stmt->fetch();
        $error = $this->statusUpdateError($old, $status, $userId);
        if ($error !== null) {
            $result = ['success' => false, 'error' => $error];
        } elseif ($old['status'] === $status) {
            $result = ['success' => true];
        } else {
            $this->db->prepare("UPDATE calendar_entries SET status=? WHERE id=?")->execute([$status, (int)$entryId]);
            AuditLog::record('calendar.status_changed', 'calendar_entry', (int)$entryId, ['status' => $old['status']], ['status' => $status], ['order_id' => $old['order_id'], 'user_id' => $userId]);
            foreach ($this->participants((int)$entryId) as $participant) {
                (new TelegramNotifier())->notifyUser(
                    (int)$participant['user_id'],
                    'calendar_status_changed',
                    'Статус записи календаря «' . $old['title'] . '» изменён: ' . $status . '.',
                    'calendar-status-' . (int)$entryId . '-' . $status . '-user-' . (int)$participant['user_id']
                );
            }
            $result = ['success' => true];
        }
        return $result;
    }
    private function normalizeDateTime($value) {
        $value = str_replace('T', ' ', trim((string)$value));
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        return null;
    }
    private function textLength($value) {
        return function_exists('mb_strlen')
            ? mb_strlen((string)$value, 'UTF-8')
            : strlen((string)$value);
    }
    private function normalizeCreateInput($data, $participantIds, $creatorId) {
        if ((int)($_SESSION['user_id'] ?? 0) !== (int)$creatorId || !can('manage_calendar')) {
            throw new AccessDeniedException('Недостаточно прав для календаря');
        }
        $entry = [
            'type' => (string)($data['entry_type'] ?? ''),
            'start' => $this->normalizeDateTime($data['starts_at'] ?? ''),
            'end' => $this->normalizeDateTime($data['ends_at'] ?? ''),
            'title' => trim((string)($data['title'] ?? '')),
            'description' => trim((string)($data['description'] ?? '')),
            'location' => trim((string)($data['location'] ?? '')),
            'order_id' => !empty($data['order_id']) ? (int)$data['order_id'] : null,
            'event_id' => !empty($data['event_id']) ? (int)$data['event_id'] : null,
            'all_day' => !empty($data['all_day']) ? 1 : 0,
            'participant_ids' => array_values(array_unique(array_filter(array_map('intval', (array)$participantIds)))),
        ];
        if (!isset($this->entryTypes()[$entry['type']]) || $entry['title'] === '' || !$entry['start'] || !$entry['end']
            || strtotime($entry['end']) <= strtotime($entry['start'])) {
            throw new ValidationException('Проверьте тип, название и время календарной записи');
        }
        if ($this->textLength($entry['title']) > 190 || $this->textLength($entry['description']) > 10000
            || $this->textLength($entry['location']) > 500) {
            throw new ValidationException('Одно из полей календаря слишком длинное');
        }
        $this->assertRelatedEntityAccess($entry, $creatorId);
        $this->assertParticipantsAvailable($entry['participant_ids']);
        return $entry;
    }
    private function assertRelatedEntityAccess($entry, $creatorId) {
        if ($entry['order_id']) {
            $order = (new Order())->getById($entry['order_id']);
            if (!(new Order())->canMutate($order, (int)$creatorId, currentRole())) {
                throw new AccessDeniedException('Заказ недоступен для изменения');
            }
        }
        if ($entry['event_id']) {
            $stmt = $this->db->prepare('SELECT organizer_id FROM events WHERE id=? LIMIT 1');
            $stmt->execute([$entry['event_id']]);
            $eventOrganizerId = $stmt->fetchColumn();
            if (!$eventOrganizerId || ((int)$eventOrganizerId !== (int)$creatorId && !isAdminRole())) {
                throw new AccessDeniedException('Мероприятие недоступно');
            }
        }
    }
    private function assertParticipantsAvailable($participantIds) {
        if ($participantIds) {
            $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
            $activeClause = dbColumnExists('users', 'is_active') ? ' AND is_active=1' : '';
            $rolePlaceholders = implode(',', array_fill(0, count(workerRoles()), '?'));
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE id IN ({$placeholders}) AND role IN ({$rolePlaceholders}){$activeClause}");
            $stmt->execute(array_merge($participantIds, workerRoles()));
            if ((int)$stmt->fetchColumn() !== count($participantIds)) {
                throw new AccessDeniedException('Один из участников недоступен');
            }
        }
    }
    private function participantConflicts($participantIds, $start, $end) {
        $conflicts = [];
        foreach ($participantIds as $participantId) {
            $items = $this->conflicts($participantId, $start, $end);
            if ($items) {
                $conflicts[$participantId] = $items;
            }
        }
        return $conflicts;
    }
    private function validateConflictOverride($conflicts, $allowConflict, $overrideReason) {
        $overrideReason = trim((string)$overrideReason);
        if ($conflicts && !$allowConflict) {
            throw new ConflictException('У сотрудников есть пересечения по времени');
        }
        if ($conflicts && !can('override_calendar_conflict')) {
            throw new AccessDeniedException('Недостаточно прав для подтверждения конфликта');
        }
        if ($conflicts && $overrideReason === '') {
            throw new ValidationException('Для подтверждения конфликта укажите причину');
        }
        if ($this->textLength($overrideReason) > 1000) {
            throw new ValidationException('Причина подтверждения слишком длинная');
        }
        return $overrideReason;
    }
    private function notifyAssignment($entryId, $entry) {
        foreach ($entry['participant_ids'] as $participantId) {
            (new TelegramNotifier())->notifyUser(
                $participantId,
                'calendar_assignment',
                'Новая запись в календаре: ' . $entry['title'] . ', ' . formatRussianDateTime($entry['start']),
                'calendar-' . $entryId . '-user-' . $participantId
            );
        }
    }
    private function canViewEntry($entryId, $viewerId, $viewerRole) {
        $allowed = false;
        $validViewer = $viewerId > 0
            && (int)($_SESSION['user_id'] ?? 0) === (int)$viewerId
            && currentRole() === $viewerRole;
        if ($validViewer) {
            $stmt = $this->db->prepare(
                "SELECT ce.created_by,o.organizer_id,e.organizer_id AS event_organizer_id,
                        EXISTS(
                            SELECT 1 FROM calendar_entry_participants cp
                            WHERE cp.calendar_entry_id=ce.id AND cp.user_id=?
                        ) AS is_participant
                 FROM calendar_entries ce
                 LEFT JOIN orders o ON o.id=ce.order_id
                 LEFT JOIN events e ON e.id=ce.event_id
                 WHERE ce.id=? LIMIT 1"
            );
            $stmt->execute([$viewerId, $entryId]);
            $entry = $stmt->fetch();
            if ($entry) {
                $allowed = match ($viewerRole) {
                    'admin' => true,
                    'organizer' => (int)($entry['organizer_id'] ?? 0) === $viewerId
                        || (int)($entry['event_organizer_id'] ?? 0) === $viewerId
                        || (int)($entry['created_by'] ?? 0) === $viewerId,
                    default => isWorkerRole($viewerRole) && !empty($entry['is_participant']),
                };
            }
        }
        return $allowed;
    }
    private function statusUpdateError($entry, $status, $userId) {
        if ((int)($_SESSION['user_id'] ?? 0) !== (int)$userId || !can('manage_calendar')) {
            $error = 'Недостаточно прав для календаря';
        } elseif (!in_array($status, ['planned', 'in_progress', 'completed', 'cancelled'], true)) {
            $error = 'Недопустимый статус';
        } elseif (!$entry) {
            $error = 'Запись не найдена';
        } elseif (!empty($entry['order_id'])) {
            $order = (new Order())->getById((int)$entry['order_id']);
            $error = (new Order())->canMutate($order, (int)$userId, currentRole()) ? null : 'Запись недоступна';
        } elseif ((int)$entry['created_by'] !== (int)$userId && !isAdminRole()) {
            $error = 'Запись недоступна';
        } else {
            $error = null;
        }
        return $error;
    }
}
