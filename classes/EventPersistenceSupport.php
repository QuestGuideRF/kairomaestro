<?php
trait EventPersistenceSupport {
    private function createNotification($userId, $type, $title, $message, $link) {
        $stmt = $this->db->prepare(
            'INSERT INTO notifications (user_id, type, title, message, link) '
            . 'VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, $title, $message, $link]);
    }
    private function logAction($userId, $actionType, $description, $entityType, $entityId) {
        $stmt = $this->db->prepare(
            'INSERT INTO action_logs (user_id, action_type, description, entity_type, entity_id) '
            . 'VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $actionType, $description, $entityType, $entityId]);
    }
    public function getWorkerCalendar($workerId, $year, $month) {
        if ((int)$workerId !== (int)($_SESSION['user_id'] ?? 0) && !isAdminRole()) {
            return ['work_days' => [], 'unavailable_days' => []];
        }
        $year = max(2000, min(2100, (int)$year));
        $month = max(1, min(12, (int)$month));
        $stmt = $this->db->prepare(
            "SELECT e.event_date, e.id, e.title, es.status
             FROM events e
             JOIN event_staff es ON e.id = es.event_id
             WHERE es.worker_id = ?
               AND YEAR(e.event_date) = ?
               AND MONTH(e.event_date) = ?
               AND e.status != 'cancelled'
               AND es.status IN ('confirmed','on_location','completed')"
        );
        $stmt->execute([$workerId, $year, $month]);
        $workDays = $stmt->fetchAll(PDO::FETCH_GROUP);
        $stmt = $this->db->prepare(
            'SELECT unavailable_date FROM worker_availability '
            . 'WHERE worker_id = ? AND YEAR(unavailable_date) = ? AND MONTH(unavailable_date) = ?'
        );
        $stmt->execute([$workerId, $year, $month]);
        return [
            'work_days' => $workDays,
            'unavailable_days' => $stmt->fetchAll(PDO::FETCH_COLUMN),
        ];
    }
    private function eventRecord($eventId) {
        $stmt = $this->db->prepare('SELECT * FROM events WHERE id=? LIMIT 1');
        $stmt->execute([(int)$eventId]);
        return $stmt->fetch();
    }
}
