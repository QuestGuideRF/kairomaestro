<?php
class Payment {
    private const ERROR_PAYMENT_UNAVAILABLE = 'Выплата недоступна';
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function create($data) {
        try {
            if (!$this->canSubmit((int)$data['event_id'], (int)$data['worker_id'])) {
                throw new AccessDeniedException(self::ERROR_PAYMENT_UNAVAILABLE);
            }
            $stmt = $this->db->prepare("SELECT hourly_rate FROM worker_profiles WHERE user_id = ?");
            $stmt->execute([$data['worker_id']]);
            $profile = $stmt->fetch() ?: [];
            $hourlyRate = max(0, (float)($profile['hourly_rate'] ?? 0));
            $hoursWorked = min(72, max(0, (float)($data['hours_worked'] ?? 0)));
            $workPayment = $hoursWorked * $hourlyRate;
            $requestedTravelType = (string)($data['travel_type'] ?? 'none');
            $travelType = in_array($requestedTravelType, ['none', 'own_car', 'taxi'], true)
                ? $requestedTravelType
                : 'none';
            $travelCost = 0;
            if ($travelType !== 'none') {
                if (isWorkerRole() && !$this->hasArrived((int)$data['event_id'], (int)$data['worker_id'])) {
                    throw new ValidationException('Транспорт можно указать после отметки прибытия');
                }
                $travelCost = min(100000, max(0, (float)($data['travel_cost'] ?? 0)));
                if ($travelCost > 0 && empty($data['travel_receipt'])) {
                    throw new ValidationException('Для компенсации транспорта приложите чек или скриншот');
                }
            }
            $totalAmount = $workPayment + $travelCost;
            $stmt = $this->db->prepare("
                INSERT INTO payments
                (event_id, worker_id, hours_worked, hourly_rate, work_payment,
                 travel_type, travel_distance, travel_cost, travel_receipt, total_amount, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $data['event_id'],
                $data['worker_id'],
                $hoursWorked,
                $hourlyRate,
                $workPayment,
                $travelType,
                $data['travel_distance'] ?? null,
                $travelCost,
                $data['travel_receipt'] ?? null,
                $totalAmount
            ]);
            $paymentId = $this->db->lastInsertId();
            $this->updateWorkerStatistics($data['worker_id']);
            AuditLog::record('worker_payment.created', 'payment', (int)$paymentId, null, [
                'event_id' => (int)$data['event_id'], 'worker_id' => (int)$data['worker_id'],
                'hours_worked' => (float)$hoursWorked, 'hourly_rate' => (float)$hourlyRate,
                'travel_cost' => (float)$travelCost, 'total_amount' => (float)$totalAmount,
            ], ['user_id' => (int)($_SESSION['user_id'] ?? 0)]);
            return ['success' => true, 'payment_id' => $paymentId, 'total_amount' => $totalAmount];
        } catch (Exception $e) {
            return ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось сохранить выплату')];
        }
    }
    public function update($paymentId, $data) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM payments WHERE id = ?");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();
            if (!$payment) {
                throw new EntityNotFoundException('Платёж не найден');
            }
            if (!$this->canSubmit((int)$payment['event_id'], (int)$payment['worker_id'])) {
                throw new AccessDeniedException(self::ERROR_PAYMENT_UNAVAILABLE);
            }
            if (isWorkerRole() && $payment['status'] !== 'pending') {
                throw new AccessDeniedException('Подтверждённую выплату может изменить только организатор');
            }
            $stmt = $this->db->prepare("SELECT hourly_rate FROM worker_profiles WHERE user_id=? LIMIT 1");
            $stmt->execute([(int)$payment['worker_id']]);
            $hourlyRate = max(0, (float)($stmt->fetchColumn() ?: 0));
            $hoursWorked = min(72, max(0, (float)($data['hours_worked'] ?? $payment['hours_worked'])));
            $workPayment = $hoursWorked * $hourlyRate;
            $travelType = in_array(($data['travel_type'] ?? $payment['travel_type']), ['none', 'own_car', 'taxi'], true)
                ? ($data['travel_type'] ?? $payment['travel_type'])
                : 'none';
            $travelCost = 0;
            if (in_array($travelType, ['own_car', 'taxi'], true)) {
                if (isWorkerRole() && !$this->hasArrived((int)$payment['event_id'], (int)$payment['worker_id'])) {
                    throw new ValidationException('Транспорт можно указать после отметки прибытия');
                }
                $travelCost = min(100000, max(0, (float)($data['travel_cost'] ?? $payment['travel_cost'])));
                $receipt = $data['travel_receipt'] ?? $payment['travel_receipt'];
                if ($travelCost > 0 && empty($receipt)) {
                    throw new ValidationException('Для компенсации транспорта приложите чек или скриншот');
                }
            }
            $totalAmount = $workPayment + $travelCost;
            $stmt = $this->db->prepare("
                UPDATE payments
                SET hours_worked = ?, hourly_rate = ?, work_payment = ?,
                    travel_type = ?, travel_distance = ?, travel_cost = ?,
                    travel_receipt = ?, total_amount = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $hoursWorked,
                $hourlyRate,
                $workPayment,
                $travelType,
                $data['travel_distance'] ?? $payment['travel_distance'],
                $travelCost,
                $data['travel_receipt'] ?? $payment['travel_receipt'],
                $totalAmount,
                $paymentId
            ]);
            $this->updateWorkerStatistics($payment['worker_id']);
            AuditLog::record('worker_payment.updated', 'payment', (int)$paymentId, $payment, [
                'hours_worked' => (float)$hoursWorked, 'hourly_rate' => (float)$hourlyRate,
                'travel_cost' => (float)$travelCost, 'total_amount' => (float)$totalAmount,
            ], ['user_id' => (int)($_SESSION['user_id'] ?? 0)]);
            return ['success' => true, 'total_amount' => $totalAmount];
        } catch (Exception $e) {
            return ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось изменить выплату')];
        }
    }
    public function getByEvent($eventId) {
        $event = $this->eventAccess((int)$eventId);
        if (!$event['allowed']) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT p.*, u.first_name, u.last_name, wp.specialty
            FROM payments p
            JOIN users u ON p.worker_id = u.id
            LEFT JOIN worker_profiles wp ON u.id = wp.user_id
            WHERE p.event_id = ?
            " . ($event['worker_only'] ? ' AND p.worker_id = ?' : '') . "
        ");
        $params = [(int)$eventId];
        if ($event['worker_only']) {
            $params[] = (int)$_SESSION['user_id'];
        }
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function getByWorker($workerId, $filters = []) {
        if ((int)$workerId !== (int)($_SESSION['user_id'] ?? 0) && !isAdminRole()) {
            return [];
        }
        $sql = "
            SELECT p.*, e.title as event_title, e.event_date
            FROM payments p
            JOIN events e ON p.event_id = e.id
            WHERE p.worker_id = ?
        ";
        $params = [$workerId];
        if (!empty($filters['status'])) {
            $sql .= " AND p.status = ?";
            $params[] = $filters['status'];
        }
        $sql .= " ORDER BY e.event_date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function updateStatus($paymentId, $status) {
        if (!in_array($status, ['pending','approved','paid'], true)) {
            $error = 'Недопустимый статус выплаты';
        }
        $stmt = $this->db->prepare("SELECT p.*,e.organizer_id FROM payments p JOIN events e ON e.id=p.event_id WHERE p.id=?");
        $stmt->execute([(int)$paymentId]);
        $old = $stmt->fetch();
        if (!isset($error) && (!$old || !(isAdminRole() || (currentRole() === 'organizer' && (int)$old['organizer_id'] === (int)($_SESSION['user_id'] ?? 0))))) {
            $error = self::ERROR_PAYMENT_UNAVAILABLE;
        }
        $transitions = ['pending' => ['approved'], 'approved' => ['paid'], 'paid' => []];
        if (!isset($error) && $old['status'] !== $status && !isAdminRole() && !in_array($status, $transitions[$old['status']] ?? [], true)) {
            $error = 'Недопустимый переход статуса выплаты';
        }
        if (isset($error)) {
            $result = ['success' => false, 'error' => $error];
        } elseif ($old['status'] === $status) {
            $result = ['success' => true, 'unchanged' => true];
        } else {
            $stmt = $this->db->prepare("
                UPDATE payments
                SET status = ?,
                    paid_at = CASE WHEN ? = 'paid' THEN NOW() ELSE paid_at END
                WHERE id = ?
            ");
            $stmt->execute([$status, $status, $paymentId]);
            AuditLog::record('worker_payment.status_changed', 'payment', (int)$paymentId, ['status' => $old['status']], ['status' => $status], ['user_id' => (int)$_SESSION['user_id']]);
            $result = ['success' => true];
        }
        return $result;
    }
    private function updateWorkerStatistics($workerId) {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(DISTINCT p.event_id) as total_events,
                COALESCE(SUM(p.total_amount), 0) as total_earnings,
                COALESCE(SUM(p.hours_worked), 0) as total_hours
            FROM payments p
            WHERE p.worker_id = ? AND p.status IN ('approved', 'paid')
        ");
        $stmt->execute([$workerId]);
        $stats = $stmt->fetch();
        $stmt = $this->db->prepare("
            INSERT INTO worker_statistics (worker_id, total_events, total_earnings, total_hours)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_events = VALUES(total_events),
                total_earnings = VALUES(total_earnings),
                total_hours = VALUES(total_hours)
        ");
        $stmt->execute([
            $workerId,
            $stats['total_events'],
            $stats['total_earnings'],
            $stats['total_hours']
        ]);
    }
    public function getWorkerStatistics($workerId) {
        if ((int)$workerId !== (int)($_SESSION['user_id'] ?? 0) && !isAdminRole()) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT * FROM worker_statistics WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        return $stmt->fetch();
    }
    public function uploadReceipt($file) {
        return FileStorage::store(
            $file,
            'receipts',
            ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'],
            min(UPLOAD_LIMIT_BYTES, 5 * 1024 * 1024)
        );
    }
    private function canSubmit($eventId, $workerId) {
        $role = currentRole();
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $allowed = isAdminRole($role);
        if (!$allowed && isWorkerRole($role) && $workerId === $userId) {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM event_staff
                 WHERE event_id=? AND worker_id=?
                   AND status IN ('confirmed','on_location','completed')
                 LIMIT 1"
            );
            $stmt->execute([$eventId, $workerId]);
            $allowed = (bool)$stmt->fetchColumn();
        }
        if (!$allowed && $role === 'organizer') {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM events e
                 JOIN event_staff es
                   ON es.event_id=e.id
                  AND es.worker_id=?
                  AND es.status IN ('confirmed','on_location','completed')
                 WHERE e.id=? AND e.organizer_id=?
                 LIMIT 1"
            );
            $stmt->execute([$workerId, $eventId, $userId]);
            $allowed = (bool)$stmt->fetchColumn();
        }
        return $allowed;
    }
    private function hasArrived($eventId, $workerId) {
        $stmt = $this->db->prepare('SELECT 1 FROM event_staff WHERE event_id=? AND worker_id=? AND arrived_at IS NOT NULL LIMIT 1');
        $stmt->execute([(int)$eventId, (int)$workerId]);
        return (bool)$stmt->fetchColumn();
    }
    private function eventAccess($eventId) {
        $role = currentRole();
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $result = ['allowed' => $userId > 0 && isAdminRole($role), 'worker_only' => false];
        if ($userId && !$result['allowed'] && $role === 'organizer') {
            $stmt = $this->db->prepare('SELECT 1 FROM events WHERE id=? AND organizer_id=? LIMIT 1');
            $stmt->execute([(int)$eventId, $userId]);
            $result['allowed'] = (bool)$stmt->fetchColumn();
        }
        if ($userId && !$result['allowed'] && isWorkerRole($role)) {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM event_staff
                 WHERE event_id=? AND worker_id=?
                   AND status IN ('confirmed','on_location','completed')
                 LIMIT 1"
            );
            $stmt->execute([(int)$eventId, $userId]);
            $result = ['allowed' => (bool)$stmt->fetchColumn(), 'worker_only' => true];
        }
        return $result;
    }
}
