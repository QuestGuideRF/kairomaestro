<?php
class EventAssignmentService {
    private const ERROR_EVENT_UNAVAILABLE = 'Мероприятие недоступно';

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function assign(int $eventId, int $workerId, int $organizerId): array {
        try {
            $context = $this->assignmentContext(
                $eventId,
                $workerId,
                $organizerId
            );
            $this->db->beginTransaction();
            $assignment = $this->performAssignment(
                $eventId,
                $workerId,
                $organizerId,
                $context
            );
            $this->db->commit();
            $result = ['success' => true];
            if ($assignment['unchanged']) {
                $result['unchanged'] = true;
            } else {
                AuditLog::record(
                    'event.worker_assigned',
                    'event_staff',
                    null,
                    null,
                    ['event_id' => $eventId, 'worker_id' => $workerId],
                    ['user_id' => $organizerId]
                );
            }
        } catch (Throwable $error) {
            $this->rollBack();
            $result = [
                'success' => false,
                'error' => publicExceptionMessage(
                    $error,
                    'Не удалось назначить сотрудника'
                ),
            ];
        }

        return $result;
    }

    private function assignmentContext(
        int $eventId,
        int $workerId,
        int $organizerId
    ): array {
        if ($organizerId !== (int)($_SESSION['user_id'] ?? 0)) {
            throw new AccessDeniedException(self::ERROR_EVENT_UNAVAILABLE);
        }
        $event = $this->eventRecord($eventId);
        if (!$event
            || !(new EventPolicy($this->db))->canManage($event, $organizerId)) {
            throw new AccessDeniedException(self::ERROR_EVENT_UNAVAILABLE);
        }
        $worker = (new User())->getUserById($workerId);
        $allowedRoles = ['florist', 'courier', 'installer', 'helper'];
        $workerAvailable = $worker
            && in_array($worker['role'] ?? '', $allowedRoles, true)
            && (int)($worker['is_active'] ?? 1) === 1;
        if (!$workerAvailable) {
            throw new EntityNotFoundException('Сотрудник недоступен');
        }

        return ['event' => $event, 'worker' => $worker];
    }

    private function performAssignment(
        int $eventId,
        int $workerId,
        int $organizerId,
        array $context
    ): array {
        $existing = $this->existingAssignment($eventId, $workerId);
        $unchanged = $existing && $existing['status'] !== 'declined';
        if (!$unchanged) {
            $this->assertWorkerAvailable(
                $workerId,
                $eventId,
                (array)$context['event']
            );
            $assignmentId = $this->upsertAssignment(
                $eventId,
                $workerId,
                $existing
            );
            $this->notifyAssignedWorker(
                $assignmentId,
                $eventId,
                $workerId,
                $organizerId,
                $context
            );
        }

        return ['unchanged' => (bool)$unchanged];
    }

    private function existingAssignment(int $eventId, int $workerId): ?array {
        $stmt = $this->db->prepare(
            'SELECT id,status FROM event_staff '
            . 'WHERE event_id=? AND worker_id=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$eventId, $workerId]);
        $assignment = $stmt->fetch();

        return is_array($assignment) ? $assignment : null;
    }

    private function assertWorkerAvailable(
        int $workerId,
        int $eventId,
        array $event
    ): void {
        $available = (new User())->isWorkerAvailable(
            $workerId,
            $event['event_date'],
            $event['start_time'] ?: null,
            $event['end_time'] ?: null,
            $eventId
        );
        if (!$available) {
            throw new ConflictException(
                'Этот сотрудник уже работает в это время'
            );
        }
    }

    private function upsertAssignment(
        int $eventId,
        int $workerId,
        ?array $existing
    ): int {
        if ($existing) {
            $sets = ["status='pending'", 'confirmed_at=NULL', 'arrived_at=NULL'];
            if (dbColumnExists('event_staff', 'decline_reason')) {
                $sets[] = 'decline_reason=NULL';
            }
            $stmt = $this->db->prepare(
                'UPDATE event_staff SET ' . implode(',', $sets)
                . " WHERE id=? AND status='declined'"
            );
            $stmt->execute([(int)$existing['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new ConflictException(
                    'Назначение уже изменено в другой сессии'
                );
            }
            $assignmentId = (int)$existing['id'];
        } else {
            $this->db->prepare(
                "INSERT INTO event_staff (event_id,worker_id,status)
                 VALUES (?,?,'pending')"
            )->execute([$eventId, $workerId]);
            $assignmentId = (int)$this->db->lastInsertId();
        }

        return $assignmentId;
    }

    private function notifyAssignedWorker(
        int $assignmentId,
        int $eventId,
        int $workerId,
        int $organizerId,
        array $context
    ): void {
        $event = (array)$context['event'];
        $worker = (array)$context['worker'];
        $this->db->prepare(
            'INSERT INTO notifications (user_id,type,title,message,link) '
            . 'VALUES (?,?,?,?,?)'
        )->execute([
            $workerId,
            'event_assigned',
            'Новое мероприятие',
            "Вы назначены на мероприятие {$event['title']}",
            "/worker/event.php?id={$eventId}",
        ]);
        $this->db->prepare(
            'INSERT INTO action_logs '
            . '(user_id,action_type,description,entity_type,entity_id) '
            . 'VALUES (?,?,?,?,?)'
        )->execute([
            $organizerId,
            'worker_assigned',
            "Работник {$worker['first_name']} {$worker['last_name']} "
                . 'назначен на мероприятие',
            'event',
            $eventId,
        ]);
        $queued = (new TelegramAssignmentNotifier())->queue(
            $assignmentId,
            $workerId
        );
        $this->requireSuccess(
            $queued,
            'Не удалось поставить приглашение сотруднику в очередь'
        );
    }

    public function respond(
        int $assignmentId,
        int $workerId,
        string $decision,
        string $reason = ''
    ): array {
        try {
            $request = $this->validatedResponse(
                $assignmentId,
                $workerId,
                $decision,
                $reason
            );
            $this->db->beginTransaction();
            $assignment = $this->lockedAssignment(
                $request['assignment_id'],
                $request['worker_id']
            );
            $result = $this->performResponse($request, $assignment);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->rollBack();
            $result = [
                'success' => false,
                'error' => publicExceptionMessage(
                    $error,
                    'Не удалось сохранить ответ'
                ),
            ];
        }

        return $result;
    }

    private function validatedResponse(
        int $assignmentId,
        int $workerId,
        string $decision,
        string $reason
    ): array {
        $reason = trim($reason);
        if ($workerId !== (int)($_SESSION['user_id'] ?? 0) || !isWorkerRole()) {
            throw new AccessDeniedException(self::ERROR_EVENT_UNAVAILABLE);
        }
        if (!in_array($decision, ['confirmed', 'declined'], true)) {
            throw new ValidationException('Недопустимый ответ на приглашение');
        }
        if (utf8Length($reason) > 1000) {
            throw new ValidationException('Причина отказа слишком длинная');
        }

        return [
            'assignment_id' => $assignmentId,
            'worker_id' => $workerId,
            'decision' => $decision,
            'reason' => $reason,
        ];
    }

    private function lockedAssignment(
        int $assignmentId,
        int $workerId
    ): array {
        $stmt = $this->db->prepare(
            'SELECT es.*,e.organizer_id,e.title AS event_title,'
            . 'o.id AS order_id FROM event_staff es '
            . 'JOIN events e ON e.id=es.event_id '
            . 'LEFT JOIN orders o ON o.event_id=e.id '
            . 'WHERE es.id=? FOR UPDATE'
        );
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch();
        if (!$assignment || (int)$assignment['worker_id'] !== $workerId) {
            throw new AccessDeniedException(self::ERROR_EVENT_UNAVAILABLE);
        }

        return $assignment;
    }

    private function performResponse(array $request, array $assignment): array {
        $decision = (string)$request['decision'];
        if ($assignment['status'] === $decision) {
            $result = ['success' => true, 'unchanged' => true];
        } else {
            if ($assignment['status'] !== 'pending') {
                throw new ConflictException(
                    'Ответ на это приглашение уже сохранён'
                );
            }
            $this->persistResponse($request);
            $this->syncOrderTeam($request, $assignment);
            (new EventAssignmentAccess($this->db))->apply(
                $assignment,
                (int)$request['worker_id'],
                $decision
            );
            $this->notifyOrganizer($request, $assignment);
            AuditLog::record(
                'event.assignment_response',
                'event_staff',
                (int)$request['assignment_id'],
                ['status' => 'pending'],
                [
                    'status' => $decision,
                    'has_reason' => $request['reason'] !== '',
                ],
                ['user_id' => (int)$request['worker_id']]
            );
            $result = ['success' => true];
        }

        return $result;
    }

    private function persistResponse(array $request): void {
        $sets = [
            'status=?',
            "confirmed_at=CASE WHEN ?='confirmed' THEN NOW() "
                . 'ELSE confirmed_at END',
        ];
        $params = [$request['decision'], $request['decision']];
        if (dbColumnExists('event_staff', 'decline_reason')) {
            $sets[] = 'decline_reason=?';
            $declineReason = $request['decision'] === 'declined'
                && $request['reason'] !== ''
                ? $request['reason']
                : null;
            $params[] = $declineReason;
        }
        $params[] = $request['assignment_id'];
        $params[] = $request['worker_id'];
        $stmt = $this->db->prepare(
            'UPDATE event_staff SET ' . implode(',', $sets)
            . " WHERE id=? AND worker_id=? AND status='pending'"
        );
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            throw new ConflictException(
                'Ответ уже сохранён в другой сессии'
            );
        }
    }

    private function syncOrderTeam(array $request, array $assignment): void {
        $canSync = dbTableExists('order_team')
            && dbColumnExists('order_team', 'participation_status');
        if ($canSync && !empty($assignment['order_id'])) {
            $this->db->prepare(
                'UPDATE order_team SET participation_status=? '
                . 'WHERE order_id=? AND worker_id=?'
            )->execute([
                $request['decision'],
                (int)$assignment['order_id'],
                $request['worker_id'],
            ]);
        } elseif ($canSync && dbColumnExists('order_team', 'event_staff_id')) {
            $this->db->prepare(
                'UPDATE order_team SET participation_status=? '
                . 'WHERE event_staff_id=?'
            )->execute([
                $request['decision'],
                $request['assignment_id'],
            ]);
        }
    }

    private function notifyOrganizer(array $request, array $assignment): void {
        $worker = (new User())->getUserById((int)$request['worker_id']);
        $workerName = trim(
            (string)($worker['first_name'] ?? '')
            . ' '
            . (string)($worker['last_name'] ?? '')
        );
        $workerName = $workerName !== '' ? $workerName : 'Сотрудник';
        $statusText = $request['decision'] === 'confirmed'
            ? 'подтвердил участие'
            : 'отклонил приглашение';
        $message = $workerName . ' ' . $statusText . ' в мероприятии «'
            . $assignment['event_title'] . '».';
        if ($request['decision'] === 'declined' && $request['reason'] !== '') {
            $message .= ' Причина: ' . $request['reason'];
        }
        $notification = (new Notification())->create(
            (int)$assignment['organizer_id'],
            'assignment_response',
            'Ответ сотрудника',
            $message,
            '/organizer/event.php?id=' . (int)$assignment['event_id']
        );
        $this->requireSuccess(
            $notification,
            'Не удалось уведомить организатора'
        );
        $telegram = (new TelegramNotifier())->notifyUser(
            (int)$assignment['organizer_id'],
            'assignment_response',
            $message,
            'assignment-response-' . $request['assignment_id']
                . '-' . $request['decision'],
            30
        );
        $this->requireSuccess(
            $telegram,
            'Не удалось поставить ответ сотрудника в очередь'
        );
    }

    private function eventRecord(int $eventId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM events WHERE id=? LIMIT 1');
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();

        return is_array($event) ? $event : null;
    }

    private function requireSuccess(array $result, string $fallback): void {
        if (empty($result['success'])) {
            throw new PersistenceException(
                (string)($result['error'] ?? $fallback)
            );
        }
    }

    private function rollBack(): void {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
