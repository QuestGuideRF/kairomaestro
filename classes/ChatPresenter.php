<?php
class ChatPresenter {
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function withDisplayName($chat, $userId) {
        if ($chat['type'] === 'personal') {
            $chat = $this->personalChatName($chat, $userId);
        } else {
            $chat['chat_name'] = $this->contextChatName($chat);
        }
        return $chat;
    }
    private function personalChatName($chat, $userId) {
        $stmt = $this->db->prepare('
            SELECT u.id, u.first_name, u.last_name
            FROM users u
            JOIN chat_participants cp ON u.id = cp.user_id
            WHERE cp.chat_id = ? AND cp.user_id != ?
        ');
        $stmt->execute([$chat['id'], $userId]);
        $otherUser = $stmt->fetch();
        if ($otherUser) {
            $chat['chat_name'] = $otherUser['first_name'] . ' ' . $otherUser['last_name'];
            $chat['other_user_id'] = $otherUser['id'];
        }
        return $chat;
    }
    private function contextChatName($chat) {
        return match ($chat['type']) {
            'event_group' => ($chat['event_title'] ?? 'Групповой чат') . ' (команда)',
            'client' => 'Заявка: ' . ($chat['lead_client_name'] ?? 'клиент'),
            'order_client' => 'Заказ: ' . ($chat['order_title'] ?? 'клиентский заказ'),
            'order_team' => 'Команда: ' . ($chat['order_title'] ?? 'заказ'),
            default => !empty($chat['title']) ? $chat['title'] : 'Чат',
        };
    }
}
