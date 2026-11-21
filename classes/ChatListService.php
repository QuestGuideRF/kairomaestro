<?php
class ChatListService {
    private PDO $db;
    public function __construct(PDO $db) {
        $this->db = $db;
    }
    public function forUser(int $userId, Chat $access): array {
        $schema = $this->schemaFragments();
        $chats = $this->fetchChats($userId, $schema);
        if (!isAdminRole()) {
            $chats = array_values(array_filter(
                $chats,
                static fn(array $chat): bool => $access->userCanAccessChat(
                    (int)$chat['id'],
                    $userId
                )
            ));
        }
        return $this->decorateAndSort($chats, $userId);
    }
    private function schemaFragments(): array {
        $hasEvent = dbColumnExists('chats', 'event_id') && dbTableExists('events');
        $hasLead = dbColumnExists('chats', 'lead_id') && dbTableExists('leads');
        $hasOrder = dbColumnExists('chats', 'order_id') && dbTableExists('orders');
        return [
            'event_select' => $hasEvent
                ? 'e.title as event_title, e.event_date'
                : 'NULL as event_title, NULL as event_date',
            'lead_select' => $hasLead
                ? 'l.client_name as lead_client_name, l.event_type as lead_event_type'
                : 'NULL as lead_client_name, NULL as lead_event_type',
            'order_select' => $hasOrder
                ? 'o.title as order_title, o.event_date as order_event_date, o.status as order_status'
                : 'NULL as order_title, NULL as order_event_date, NULL as order_status',
            'event_join' => $hasEvent ? 'LEFT JOIN events e ON c.event_id = e.id' : '',
            'lead_join' => $hasLead ? 'LEFT JOIN leads l ON c.lead_id = l.id' : '',
            'order_join' => $hasOrder ? 'LEFT JOIN orders o ON c.order_id = o.id' : '',
            'manager_restriction' => $hasOrder && currentRole() === 'manager'
                ? "AND NOT (c.type IN ('order_client','order_team') AND o.organizer_id IS NOT NULL)"
                : '',
        ];
    }
    private function fetchChats(int $userId, array $schema): array {
        $participantJoin = isAdminRole()
            ? ''
            : 'JOIN chat_participants cp ON c.id = cp.chat_id';
        $participantWhere = isAdminRole() ? '1=1' : 'cp.user_id = ?';
        $stmt = $this->db->prepare(
            "SELECT c.*,
                    {$schema['event_select']},
                    {$schema['lead_select']},
                    {$schema['order_select']},
                    (
                        SELECT message FROM messages
                        WHERE chat_id = c.id ORDER BY created_at DESC LIMIT 1
                    ) as last_message,
                    (
                        SELECT created_at FROM messages
                        WHERE chat_id = c.id ORDER BY created_at DESC LIMIT 1
                    ) as last_message_time,
                    (
                        SELECT COUNT(*) FROM messages
                        WHERE chat_id = c.id AND is_read = 0 AND user_id != ?
                    ) as unread_count
             FROM chats c
             {$participantJoin}
             {$schema['event_join']}
             {$schema['lead_join']}
             {$schema['order_join']}
             WHERE {$participantWhere}
             {$schema['manager_restriction']}"
        );
        $stmt->execute(isAdminRole() ? [$userId] : [$userId, $userId]);
        return $stmt->fetchAll();
    }
    private function decorateAndSort(array $chats, int $userId): array {
        $presenter = new ChatPresenter($this->db);
        foreach ($chats as &$chat) {
            $chat = $presenter->withDisplayName($chat, $userId);
        }
        unset($chat);
        usort($chats, static function (array $left, array $right): int {
            $leftTime = $left['last_message_time'] ? strtotime($left['last_message_time']) : 0;
            $rightTime = $right['last_message_time'] ? strtotime($right['last_message_time']) : 0;
            return $rightTime - $leftTime;
        });
        return $chats;
    }
}
