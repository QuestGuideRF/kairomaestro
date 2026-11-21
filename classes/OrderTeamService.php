<?php
class OrderTeamService {
    private const ASSIGNABLE_ROLES = ['florist', 'courier', 'installer', 'helper'];
    private const ROLE_LABELS = [
        'florist' => 'Флорист',
        'courier' => 'Курьер',
        'installer' => 'Монтажник',
        'helper' => 'Помощник',
    ];
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function assign($orderId, $workerId, $userId, $data) {
        try {
            $assignment = $this->normalize($orderId, $workerId, $userId, $data);
            $this->db->beginTransaction();
            $schedule = $this->schedule($assignment['order'], $userId);
            $conflict = $this->detectConflict($assignment['worker_id'], $schedule);
            $this->validateConflict($conflict, $data);
            $eventStaffId = $this->attachEventStaff($schedule['event_id'], $assignment['worker_id']);
            $profile = $this->workerProfile($assignment['worker_id'], $assignment['worker']['role']);
            $this->upsertTeamMember($orderId, $assignment, $eventStaffId, $profile);
            $this->publishTeam($orderId, $assignment, $schedule);
            $this->syncCalendar($orderId, $userId, $assignment['worker_id'], $profile['public_role'], $schedule, $conflict, $data);
            $inviteResult = (new TelegramAssignmentNotifier())->queue(
                $eventStaffId,
                (int)$assignment['worker_id']
            );
            $this->requireSuccess($inviteResult, 'Не удалось поставить приглашение сотруднику в очередь');
            AuditLog::record('order.team_member_added', 'order_team', null, null, [
                'worker_id' => $assignment['worker_id'],
                'worker_role' => $assignment['worker']['role'],
                'planned_hours' => $assignment['planned_hours'],
                'hourly_rate' => $profile['hourly_rate'],
            ], ['order_id' => (int)$orderId, 'user_id' => $userId]);
            $this->db->commit();
            $result = ['success' => true, 'event_id' => $schedule['event_id']];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $message = publicExceptionMessage($error, 'Не удалось изменить состав команды');
            $conflict = str_starts_with($message, 'CONFLICT:');
            $result = ['success' => false, 'error' => $conflict ? substr($message, 9) : $message];
            if ($conflict) {
                $result['conflict'] = true;
            }
        }
        return $result;
    }
    public function ensureEventForOrder($order, $userId) {
        if (!$order || empty($order['id'])) {
            throw new EntityNotFoundException('Заказ не найден');
        }
        $eventId = $this->ensureEvent($order, $userId);
        $schedule = $this->buildSchedule($order, $eventId);
        $organizerId = (int)($order['organizer_id'] ?: $userId);
        $this->ensureCalendarEntry((int)$order['id'], $organizerId, $schedule);
        return $eventId;
    }
    public function setClientVisibility($teamMemberId, $userId, $visible) {
        try {
            $teamMemberId = (int)$teamMemberId;
            $userId = (int)$userId;
            $stmt = $this->db->prepare(
                'SELECT team.id,team.order_id,team.worker_id,team.show_to_client '
                . 'FROM order_team team WHERE team.id=? LIMIT 1'
            );
            $stmt->execute([$teamMemberId]);
            $member = $stmt->fetch();
            $order = $member ? (new Order())->getById((int)$member['order_id']) : null;
            if (!$member || $userId !== (int)($_SESSION['user_id'] ?? 0)
                || !(new Order())->canMutate($order, $userId, currentRole())
                || !can('assign_workers')) {
                throw new AccessDeniedException('Недостаточно прав для изменения видимости команды');
            }
            $visible = (bool)$visible;
            $this->db->prepare('UPDATE order_team SET show_to_client=? WHERE id=?')
                ->execute([$visible ? 1 : 0, $teamMemberId]);
            AuditLog::record(
                'order.team_visibility_changed',
                'order_team',
                $teamMemberId,
                ['show_to_client' => (bool)$member['show_to_client']],
                ['show_to_client' => $visible],
                [
                    'order_id' => (int)$member['order_id'],
                    'user_id' => $userId,
                    'metadata' => ['worker_id' => (int)$member['worker_id']],
                ]
            );
            return ['success' => true];
        } catch (Throwable $error) {
            return [
                'success' => false,
                'error' => publicExceptionMessage($error, 'Не удалось изменить видимость участника'),
            ];
        }
    }
    private function normalize($orderId, $workerId, $userId, $data) {
        $orderService = new Order();
        $order = $orderService->getById($orderId);
        if (!$order) {
            throw new EntityNotFoundException('Заказ не найден');
        }
        if (!$orderService->canMutate($order, (int)$userId, currentRole()) || !can('assign_workers')) {
            throw new AccessDeniedException('Недостаточно прав для изменения команды');
        }
        $plannedHoursRaw = str_replace(',', '.', trim((string)($data['planned_hours'] ?? '0'))) ?: '0';
        if (!is_numeric($plannedHoursRaw) || (float)$plannedHoursRaw < 0 || (float)$plannedHoursRaw > 1000) {
            throw new ValidationException('Проверьте плановые часы');
        }
        $tasksSummary = trim((string)($data['tasks_summary'] ?? ''));
        $internalComment = trim((string)($data['internal_comment'] ?? ''));
        if (utf8Length($tasksSummary) > 10000 || utf8Length($internalComment) > 10000) {
            throw new ValidationException('Комментарий или задачи слишком длинные');
        }
        $workerId = (int)$workerId;
        $worker = (new User())->getUserById($workerId);
        if (!$worker || !in_array($worker['role'] ?? '', self::ASSIGNABLE_ROLES, true)
            || (int)($worker['is_active'] ?? 1) !== 1) {
            throw new EntityNotFoundException('Выбранный сотрудник недоступен');
        }
        return [
            'order' => $order,
            'worker' => $worker,
            'worker_id' => $workerId,
            'planned_hours' => round((float)$plannedHoursRaw, 2),
            'tasks_summary' => $tasksSummary,
            'internal_comment' => $internalComment,
        ];
    }
    private function schedule($order, $userId) {
        $eventId = $this->ensureEvent($order, $userId);
        $schedule = $this->buildSchedule($order, $eventId);
        $organizerId = (int)($order['organizer_id'] ?: $userId);
        $schedule['calendar_entry_id'] = $this->ensureCalendarEntry((int)$order['id'], $organizerId, $schedule);
        return $schedule;
    }
    private function buildSchedule($order, $eventId) {
        $eventDate = $order['event_date'] ?: date('Y-m-d');
        $startTime = $order['start_time'] ?: '10:00:00';
        $endTime = $order['end_time'] ?: '23:59:59';
        $startAt = $eventDate . ' ' . $startTime;
        $endAt = $eventDate . ' ' . $endTime;
        if (strtotime($endAt) <= strtotime($startAt)) {
            $endAt = date('Y-m-d H:i:s', strtotime($endAt . ' +1 day'));
        }
        return [
            'event_id' => (int)$eventId,
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'calendar_entry_id' => 0,
            'title' => $order['title'],
            'location' => $this->orderLocation($order),
        ];
    }
    private function detectConflict($workerId, $schedule) {
        $conflict = !(new User())->isWorkerAvailable(
            $workerId,
            $schedule['event_date'],
            $schedule['start_time'],
            $schedule['end_time'],
            $schedule['event_id']
        );
        if (dbTableExists('calendar_entries') && dbTableExists('calendar_entry_participants')) {
            $conflict = $conflict || (bool)(new TeamCalendar())->conflicts(
                $workerId,
                $schedule['start_at'],
                $schedule['end_at'],
                $schedule['calendar_entry_id'] ?: null
            );
        }
        return $conflict;
    }
    private function validateConflict($hasConflict, $data) {
        $allowConflict = !empty($data['allow_conflict']);
        if ($allowConflict && !can('override_calendar_conflict')) {
            throw new AccessDeniedException('Недостаточно прав для подтверждения пересечения');
        }
        if ($hasConflict && !$allowConflict) {
            throw new ConflictException('CONFLICT:Сотрудник уже занят или отметил это время недоступным');
        }
        if ($hasConflict && trim((string)($data['conflict_reason'] ?? '')) === '') {
            throw new ValidationException('Для подтверждения пересечения укажите причину');
        }
    }
    private function attachEventStaff($eventId, $workerId) {
        $reset = "status='pending', confirmed_at=NULL, arrived_at=NULL";
        if (dbColumnExists('event_staff', 'decline_reason')) {
            $reset .= ', decline_reason=NULL';
        }
        $this->db->prepare(
            "INSERT INTO event_staff (event_id, worker_id, status) VALUES (?, ?, 'pending')
             ON DUPLICATE KEY UPDATE {$reset}"
        )->execute([(int)$eventId, (int)$workerId]);
        $stmt = $this->db->prepare('SELECT id FROM event_staff WHERE event_id=? AND worker_id=? LIMIT 1');
        $stmt->execute([(int)$eventId, (int)$workerId]);
        $eventStaffId = (int)$stmt->fetchColumn();
        if ($eventStaffId <= 0) {
            throw new PersistenceException('Не удалось сохранить назначение сотрудника');
        }
        return $eventStaffId;
    }
    private function workerProfile($workerId, $role) {
        $profile = [];
        if (dbTableExists('worker_profiles')) {
            $stmt = $this->db->prepare('SELECT specialty, hourly_rate FROM worker_profiles WHERE user_id=? LIMIT 1');
            $stmt->execute([(int)$workerId]);
            $profile = $stmt->fetch() ?: [];
        }
        return [
            'public_role' => self::ROLE_LABELS[$role] ?? ($profile['specialty'] ?? 'Сотрудник'),
            'hourly_rate' => (float)($profile['hourly_rate'] ?? 0),
        ];
    }
    private function upsertTeamMember($orderId, $assignment, $eventStaffId, $profile) {
        $stmt = $this->db->prepare("
            INSERT INTO order_team (
                order_id, worker_id, event_staff_id, public_role, internal_role, hourly_rate,
                planned_hours, transport_compensation, participation_status, tasks_summary, internal_comment, show_to_client
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'pending', ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                event_staff_id=VALUES(event_staff_id), public_role=VALUES(public_role),
                hourly_rate=VALUES(hourly_rate), planned_hours=VALUES(planned_hours),
                tasks_summary=VALUES(tasks_summary), internal_comment=VALUES(internal_comment),
                participation_status='pending', show_to_client=1
        ");
        $stmt->execute([
            (int)$orderId,
            $assignment['worker_id'],
            $eventStaffId ?: null,
            $profile['public_role'],
            null,
            $profile['hourly_rate'],
            $assignment['planned_hours'],
            $assignment['tasks_summary'] ?: null,
            $assignment['internal_comment'] ?: null,
        ]);
        if (dbColumnExists('orders', 'show_team_to_client')) {
            $this->db->prepare('UPDATE orders SET show_team_to_client=1 WHERE id=?')->execute([(int)$orderId]);
        }
    }
    private function publishTeam($orderId, $assignment, $schedule) {
        $chat = new Chat();
        $chatResult = $chat->createOrderTeamChat($orderId, array_filter([
            $assignment['order']['organizer_id'],
        ]));
        $this->requireSuccess($chatResult, 'Не удалось создать командный чат');
        $notificationResult = (new Notification())->create(
            $assignment['worker_id'],
            'event_assigned',
            'Приглашение на мероприятие',
            'Подтвердите участие в заказе: ' . $assignment['order']['title'],
            '/worker/event.php?id=' . $schedule['event_id']
        );
        $this->requireSuccess($notificationResult, 'Не удалось создать уведомление сотрудника');
    }
    private function syncCalendar($orderId, $userId, $workerId, $publicRole, $schedule, $hasConflict, $data) {
        if (!dbTableExists('calendar_entries') || !dbTableExists('calendar_entry_participants')) {
            return;
        }
        $calendarEntryId = $schedule['calendar_entry_id'] ?: $this->ensureCalendarEntry((int)$orderId, (int)$userId, $schedule);
        $reason = trim((string)($data['conflict_reason'] ?? ''));
        $stmt = $this->db->prepare("
            INSERT INTO calendar_entry_participants
                (calendar_entry_id,user_id,role_label,conflict_override,override_reason,assigned_by)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE role_label=VALUES(role_label),
                conflict_override=VALUES(conflict_override),override_reason=VALUES(override_reason),
                assigned_by=VALUES(assigned_by)
        ");
        $stmt->execute([
            $calendarEntryId,
            (int)$workerId,
            $publicRole,
            $hasConflict ? 1 : 0,
            $hasConflict ? $reason : null,
            (int)$userId,
        ]);
    }
    private function ensureCalendarEntry($orderId, $organizerId, $schedule) {
        if (!dbTableExists('calendar_entries') || !dbTableExists('calendar_entry_participants')) {
            return 0;
        }
        $stmt = $this->db->prepare("SELECT id FROM calendar_entries WHERE order_id=? AND entry_type='event' ORDER BY id LIMIT 1");
        $stmt->execute([(int)$orderId]);
        $calendarEntryId = (int)$stmt->fetchColumn();
        if ($calendarEntryId) {
            $this->db->prepare('UPDATE calendar_entries SET event_id=?,title=?,starts_at=?,ends_at=?,location=? WHERE id=?')
                ->execute([
                    $schedule['event_id'],
                    $schedule['title'],
                    $schedule['start_at'],
                    $schedule['end_at'],
                    $schedule['location'],
                    $calendarEntryId,
                ]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO calendar_entries
                    (order_id,event_id,entry_type,title,starts_at,ends_at,location,status,created_by)
                VALUES (?,?,'event',?,?,?,?, 'planned',?)
            ");
            $stmt->execute([
                (int)$orderId,
                $schedule['event_id'],
                $schedule['title'],
                $schedule['start_at'],
                $schedule['end_at'],
                $schedule['location'],
                $organizerId ?: null,
            ]);
            $calendarEntryId = (int)$this->db->lastInsertId();
        }
        if ($organizerId > 0) {
            $stmt = $this->db->prepare("
                INSERT INTO calendar_entry_participants
                    (calendar_entry_id,user_id,role_label,conflict_override,override_reason,assigned_by)
                VALUES (?,?,'Организатор',0,NULL,?)
                ON DUPLICATE KEY UPDATE role_label='Организатор',assigned_by=VALUES(assigned_by)
            ");
            $stmt->execute([$calendarEntryId, $organizerId, $organizerId]);
        }
        return $calendarEntryId;
    }
    private function ensureEvent($order, $userId) {
        $organizerId = (int)($order['organizer_id'] ?: $userId);
        if ($organizerId <= 0) {
            throw new ValidationException('Сначала назначьте организатора');
        }
        $event = null;
        if (!empty($order['event_id'])) {
            $stmt = $this->db->prepare('SELECT * FROM events WHERE id=? LIMIT 1');
            $stmt->execute([(int)$order['event_id']]);
            $event = $stmt->fetch() ?: null;
        }
        $eventDate = $order['event_date'] ?: ($event['event_date'] ?? date('Y-m-d'));
        $startTime = $order['start_time'] ?: ($event['start_time'] ?? '10:00:00');
        $endTime = $order['end_time'] ?: ($event['end_time'] ?? null);
        $location = $this->orderLocation($order);
        if ($event) {
            $this->db->prepare('UPDATE events SET organizer_id=?,title=?,event_date=?,start_time=?,end_time=?,location=? WHERE id=?')
                ->execute([$organizerId, $order['title'], $eventDate, $startTime, $endTime, $location, (int)$event['id']]);
            $eventId = (int)$event['id'];
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO events
                    (organizer_id,title,event_date,start_time,end_time,location,technical_task,status)
                VALUES (?,?,?,?,?,?,?,'active')
            ");
            $stmt->execute([
                $organizerId,
                $order['title'],
                $eventDate,
                $startTime,
                $endTime,
                $location,
                $order['client_comment'] ?? null,
            ]);
            $eventId = (int)$this->db->lastInsertId();
            $this->db->prepare('UPDATE orders SET event_id=? WHERE id=?')->execute([$eventId, (int)$order['id']]);
        }
        $this->createEventChat($eventId, $order, $organizerId);
        return $eventId;
    }
    private function createEventChat($eventId, $order, $organizerId) {
        if (!dbColumnExists('chats', 'event_id')) {
            return;
        }
        $stmt = $this->db->prepare("SELECT id FROM chats WHERE event_id=? AND type='event_group' LIMIT 1");
        $stmt->execute([(int)$eventId]);
        $chatId = (int)$stmt->fetchColumn();
        if (!$chatId) {
            $columns = ['event_id', 'type'];
            $values = [(int)$eventId, 'event_group'];
            foreach (['order_id' => $order['id'], 'title' => 'Команда: ' . $order['title']] as $column => $value) {
                if (dbColumnExists('chats', $column)) {
                    $columns[] = $column;
                    $values[] = $value;
                }
            }
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $this->db->prepare('INSERT INTO chats (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
            $stmt->execute($values);
            $chatId = (int)$this->db->lastInsertId();
        }
        (new Chat())->addParticipant($chatId, $organizerId);
    }
    private function orderLocation($order) {
        $address = trim((string)($order['address'] ?? ''));
        $venue = trim((string)($order['venue'] ?? ''));
        if ($address !== '') {
            return $address;
        }
        if ($venue !== '') {
            return $venue;
        }
        return 'Адрес уточняется';
    }
    private function requireSuccess(array $result, string $fallback): void {
        if (empty($result['success'])) {
            throw new PersistenceException((string)($result['error'] ?? $fallback));
        }
    }
}
