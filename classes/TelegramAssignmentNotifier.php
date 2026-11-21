<?php
class TelegramAssignmentNotifier {
    private PDO $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function queue(int $assignmentId, int $workerId): array {
        $stmt = $this->db->prepare(
            "SELECT es.id,e.title,e.event_date,e.start_time,
                    COALESCE(NULLIF(o.venue,''),NULLIF(o.address,''),
                             NULLIF(e.location,''),'Адрес уточняется') AS location
             FROM event_staff es
             JOIN events e ON e.id=es.event_id
             LEFT JOIN orders o ON o.event_id=e.id
             WHERE es.id=? AND es.worker_id=? AND es.status='pending'
             LIMIT 1"
        );
        $stmt->execute([$assignmentId, $workerId]);
        $assignment = $stmt->fetch();
        if (!$assignment) {
            return ['success' => false, 'queued' => false, 'error' => 'Приглашение недоступно'];
        }
        return (new NotificationQueue())->enqueue(
            'event_assignment_invite',
            [
                'assignment_id' => $assignmentId,
                'worker_id' => $workerId,
                'title' => (string)$assignment['title'],
                'event_date' => $assignment['event_date'],
                'start_time' => $assignment['start_time'],
                'location' => (string)$assignment['location'],
            ],
            [
                'recipient_type' => 'user',
                'recipient_id' => $workerId,
                'idempotency_key' => 'event-assignment-invite-' . $assignmentId . '-worker-' . $workerId,
                'priority' => 20,
            ]
        );
    }
}
