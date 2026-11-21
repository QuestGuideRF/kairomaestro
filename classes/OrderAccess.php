<?php
trait OrderAccess {
    public function getById($orderId) {
        $clientEmailSelect = $this->clientEmailSelect();
        $stmt = $this->db->prepare("
            SELECT o.*,
                   c.first_name as client_first_name, c.last_name as client_last_name,
                   c.phone as client_phone, {$clientEmailSelect},
                   m.first_name as manager_first_name, m.last_name as manager_last_name,
                   org.first_name as organizer_first_name, org.last_name as organizer_last_name,
                   org.avatar as organizer_avatar
            FROM orders o
            JOIN users c ON o.client_id = c.id
            LEFT JOIN users m ON o.manager_id = m.id
            LEFT JOIN users org ON o.organizer_id = org.id
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        return $stmt->fetch();
    }
    public function canAccess($order, $userId, $role) {
        if (!$order || (int)($_SESSION['user_id'] ?? 0) !== (int)$userId || currentRole() !== $role) {
            return false;
        }
        $allowed = match ($role) {
            'admin' => true,
            'client' => (int)$order['client_id'] === (int)$userId,
            'manager' => true,
            'organizer' => !$order['organizer_id'] || (int)$order['organizer_id'] === (int)$userId,
            default => false,
        };
        if (!$allowed && isWorkerRole($role)) {
            $stmt = $this->db->prepare(
                "SELECT id FROM order_team
                 WHERE order_id=? AND worker_id=?
                   AND participation_status IN ('confirmed','on_location','completed')
                 LIMIT 1"
            );
            $stmt->execute([$order['id'], $userId]);
            $allowed = (bool)$stmt->fetch();
        }
        return $allowed;
    }
    public function canMutate($order, $userId, $role) {
        $validSession = $order
            && (int)($_SESSION['user_id'] ?? 0) === (int)$userId
            && currentRole() === $role;
        return $validSession && match ($role) {
            'admin' => true,
            'organizer' => !empty($order['organizer_id']) && (int)$order['organizer_id'] === (int)$userId,
            default => false,
        };
    }
    public function canCollaborate($order, $userId, $role) {
        if (!$order || (int)($_SESSION['user_id'] ?? 0) !== (int)$userId || currentRole() !== $role) {
            return false;
        }
        $allowed = match ($role) {
            'admin' => true,
            'client' => (int)$order['client_id'] === (int)$userId,
            'manager' => !empty($order['manager_id']) && (int)$order['manager_id'] === (int)$userId,
            'organizer' => !empty($order['organizer_id']) && (int)$order['organizer_id'] === (int)$userId,
            default => false,
        };
        if (!$allowed && isWorkerRole($role)) {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM order_team
                 WHERE order_id=? AND worker_id=?
                   AND participation_status IN ('confirmed','on_location','completed')
                 LIMIT 1"
            );
            $stmt->execute([(int)$order['id'], (int)$userId]);
            $allowed = (bool)$stmt->fetchColumn();
        }
        return $allowed;
    }
}
