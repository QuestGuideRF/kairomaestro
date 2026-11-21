<?php
class ChatAccessPolicy {
    private PDO $db;
    public function __construct(PDO $db) {
        $this->db = $db;
    }
    public function canAccess(int $chatId, int $userId): bool {
        $allowed = false;
        $sessionMatches = $chatId > 0
            && $userId > 0
            && $userId === (int)($_SESSION['user_id'] ?? 0);
        if ($sessionMatches) {
            if (isAdminRole()) {
                $allowed = $this->chatExists($chatId);
            } else {
                $context = $this->participantContext($chatId, $userId);
                $allowed = $context
                    ? $this->roleAllows($context, $userId, currentRole())
                    : false;
            }
        }
        return $allowed;
    }
    private function chatExists(int $chatId): bool {
        $check = $this->db->prepare('SELECT 1 FROM chats WHERE id=? LIMIT 1');
        $check->execute([$chatId]);
        return (bool)$check->fetchColumn();
    }
    private function participantContext(int $chatId, int $userId): ?array {
        $hasOrder = dbColumnExists('chats', 'order_id') && dbTableExists('orders');
        $hasEvent = dbColumnExists('chats', 'event_id') && dbTableExists('events');
        $hasLead = dbColumnExists('chats', 'lead_id') && dbTableExists('leads');
        $orderSelect = $hasOrder
            ? ',c.order_id,o.client_id AS order_client_id,o.manager_id AS order_manager_id,'
                . 'o.organizer_id AS order_organizer_id'
            : ',NULL AS order_id,NULL AS order_client_id,NULL AS order_manager_id,'
                . 'NULL AS order_organizer_id';
        $eventSelect = $hasEvent
            ? ',c.event_id,e.organizer_id AS event_organizer_id'
            : ',NULL AS event_id,NULL AS event_organizer_id';
        $leadSelect = $hasLead
            ? ',c.lead_id,l.client_id AS lead_client_id,l.manager_id AS lead_manager_id'
            : ',NULL AS lead_id,NULL AS lead_client_id,NULL AS lead_manager_id';
        $orderJoin = $hasOrder ? 'LEFT JOIN orders o ON o.id=c.order_id' : '';
        $eventJoin = $hasEvent ? 'LEFT JOIN events e ON e.id=c.event_id' : '';
        $leadJoin = $hasLead ? 'LEFT JOIN leads l ON l.id=c.lead_id' : '';
        $stmt = $this->db->prepare(
            "SELECT cp.id,c.type {$orderSelect} {$eventSelect} {$leadSelect}
             FROM chat_participants cp
             JOIN chats c ON c.id=cp.chat_id
             {$orderJoin}
             {$eventJoin}
             {$leadJoin}
             WHERE cp.chat_id=? AND cp.user_id=?
             LIMIT 1"
        );
        $stmt->execute([$chatId, $userId]);
        $context = $stmt->fetch();
        return $context ?: null;
    }
    private function roleAllows(array $context, int $userId, string $role): bool {
        return match (true) {
            $role === 'client' => $this->clientAllows($context, $userId),
            $role === 'manager' => $this->managerAllows($context, $userId),
            $role === 'organizer' => $this->organizerAllows($context, $userId),
            isWorkerRole($role) => $this->workerAllows($context, $userId),
            default => false,
        };
    }
    private function clientAllows(array $context, int $userId): bool {
        return match ((string)$context['type']) {
            'order_client' => (int)$context['order_client_id'] === $userId,
            'client' => (int)$context['lead_client_id'] === $userId,
            'personal' => true,
            default => false,
        };
    }
    private function managerAllows(array $context, int $userId): bool {
        return match ((string)$context['type']) {
            'client' => empty($context['lead_manager_id'])
                || (int)$context['lead_manager_id'] === $userId,
            'order_client', 'order_team' => (int)$context['order_manager_id'] === $userId
                && empty($context['order_organizer_id']),
            'personal' => true,
            default => false,
        };
    }
    private function organizerAllows(array $context, int $userId): bool {
        return match ((string)$context['type']) {
            'order_client', 'order_team' => (int)$context['order_organizer_id'] === $userId,
            'event_group' => (int)$context['event_organizer_id'] === $userId,
            'personal' => true,
            default => false,
        };
    }
    private function workerAllows(array $context, int $userId): bool {
        return match ((string)$context['type']) {
            'order_team' => $this->workerCanAccessOrder((int)$context['order_id'], $userId),
            'event_group' => $this->workerCanAccessEvent((int)$context['event_id'], $userId),
            'personal' => true,
            default => false,
        };
    }
    private function workerCanAccessOrder(int $orderId, int $userId): bool {
        $allowed = false;
        if ($orderId > 0
            && dbTableExists('order_team')
            && dbColumnExists('order_team', 'participation_status')) {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM order_team
                 WHERE order_id=? AND worker_id=?
                   AND participation_status IN ('confirmed','on_location','completed')
                 LIMIT 1"
            );
            $stmt->execute([$orderId, $userId]);
            $allowed = (bool)$stmt->fetchColumn();
        }
        $canCheckEvent = !$allowed
            && $orderId > 0
            && dbTableExists('events')
            && dbTableExists('event_staff')
            && dbColumnExists('orders', 'event_id');
        if ($canCheckEvent) {
            $stmt = $this->db->prepare(
                "SELECT 1
                 FROM orders o
                 JOIN events e ON e.id=o.event_id
                 JOIN event_staff es ON es.event_id=e.id
                 WHERE o.id=? AND es.worker_id=?
                   AND es.status IN ('confirmed','on_location','completed')
                 LIMIT 1"
            );
            $stmt->execute([$orderId, $userId]);
            $allowed = (bool)$stmt->fetchColumn();
        }
        return $allowed;
    }
    private function workerCanAccessEvent(int $eventId, int $userId): bool {
        $allowed = false;
        if ($eventId > 0 && dbTableExists('event_staff')) {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM event_staff
                 WHERE event_id=? AND worker_id=?
                   AND status IN ('confirmed','on_location','completed')
                 LIMIT 1"
            );
            $stmt->execute([$eventId, $userId]);
            $allowed = (bool)$stmt->fetchColumn();
        }
        return $allowed;
    }
}
