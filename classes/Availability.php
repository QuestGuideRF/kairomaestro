<?php
class Availability {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function markUnavailable($workerId, $date, $reason = null) {
        $result = null;
        if ((int)$workerId !== (int)($_SESSION['user_id'] ?? 0) || !isWorkerRole()) {
            $result = ['success' => false, 'error' => 'Календарь недоступен'];
        } elseif (!$this->validDate($date)) {
            $result = ['success' => false, 'error' => 'Некорректная дата'];
        }
        $reason = trim((string)$reason);
        $length = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
        if ($result === null && $length > 500) {
            $result = ['success' => false, 'error' => 'Причина слишком длинная'];
        }
        if ($result === null) {
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO worker_availability (worker_id, unavailable_date, reason)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE reason = VALUES(reason)
                ");
                $stmt->execute([(int)$workerId, $date, $reason !== '' ? $reason : null]);
                AuditLog::record('availability.unavailable_marked', 'worker_availability', null, null, [
                    'date' => $date, 'reason' => $reason !== '' ? $reason : null,
                ], ['user_id' => (int)$workerId]);
                $result = ['success' => true];
            } catch (Exception $e) {
                $result = ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось сохранить доступность')];
            }
        }
        return $result;
    }
    public function markAvailable($workerId, $date) {
        if ((int)$workerId !== (int)($_SESSION['user_id'] ?? 0) || !isWorkerRole()) {
            return ['success' => false, 'error' => 'Календарь недоступен'];
        }
        if (!$this->validDate($date)) {
            return ['success' => false, 'error' => 'Некорректная дата'];
        }
        $stmt = $this->db->prepare("
            DELETE FROM worker_availability
            WHERE worker_id = ? AND unavailable_date = ?
        ");
        $stmt->execute([$workerId, $date]);
        AuditLog::record('availability.available_marked', 'worker_availability', null, ['date' => $date], null, [
            'user_id' => (int)$workerId,
        ]);
        return ['success' => true];
    }
    public function getUnavailableDays($workerId, $dateFrom = null, $dateTo = null) {
        if ((int)$workerId !== (int)($_SESSION['user_id'] ?? 0) && !isCrmRole()) {
            return [];
        }
        $sql = "SELECT * FROM worker_availability WHERE worker_id = ?";
        $params = [$workerId];
        if ($dateFrom) {
            $sql .= " AND unavailable_date >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND unavailable_date <= ?";
            $params[] = $dateTo;
        }
        $sql .= " ORDER BY unavailable_date";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function isAvailable($workerId, $date) {
        $result = null;
        if (((int)$workerId !== (int)($_SESSION['user_id'] ?? 0) && !isCrmRole()) || !$this->validDate($date)) {
            $result = ['available' => false, 'reason' => 'unavailable'];
        }
        if ($result === null) {
            $stmt = $this->db->prepare("
                SELECT id FROM worker_availability
                WHERE worker_id = ? AND unavailable_date = ?
            ");
            $stmt->execute([$workerId, $date]);
            if ($stmt->fetch()) {
                $result = ['available' => false, 'reason' => 'personal'];
            }
        }
        if ($result === null) {
            $stmt = $this->db->prepare("
                SELECT e.id, e.title FROM events e
                JOIN event_staff es ON e.id = es.event_id
                WHERE es.worker_id = ? AND e.event_date = ? AND e.status != 'cancelled'
            ");
            $stmt->execute([$workerId, $date]);
            $event = $stmt->fetch();
            $result = $event
                ? [
                    'available' => false,
                    'reason' => 'busy',
                    'event_id' => $event['id'],
                    'event_title' => $event['title']
                ]
                : ['available' => true];
        }
        return $result;
    }
    private function validDate($value) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
        return $date && $date->format('Y-m-d') === $value;
    }
}
