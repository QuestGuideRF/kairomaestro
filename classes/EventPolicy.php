<?php
class EventPolicy {
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function canManage($event, $userId) {
        $identityMatches = $this->identityMatches($event, $userId);
        return $identityMatches
            && (isAdminRole() || (currentRole() === 'organizer' && (int)$event['organizer_id'] === (int)$userId));
    }
    public function canView($event, $userId) {
        if (!$this->identityMatches($event, $userId)) {
            $allowed = false;
        } elseif ($this->canManage($event, $userId)) {
            $allowed = true;
        } elseif (isWorkerRole()) {
            $allowed = $this->workerIsAssigned((int)$event['id'], (int)$userId);
        } else {
            $allowed = false;
        }
        return $allowed;
    }
    private function identityMatches($event, $userId) {
        return $event && $userId && (int)$userId === (int)($_SESSION['user_id'] ?? 0);
    }
    private function workerIsAssigned($eventId, $userId) {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM event_staff
             WHERE event_id=? AND worker_id=?
               AND status IN ('confirmed','on_location','completed')
             LIMIT 1"
        );
        $stmt->execute([$eventId, $userId]);
        return (bool)$stmt->fetchColumn();
    }
}
