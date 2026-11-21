<?php
class TelegramNotifier {
    private $queue;
    private $managerChatId;
    private $organizerChatId;
    public function __construct() {
        $this->queue = new NotificationQueue();
        $this->managerChatId = trim((string)env('TELEGRAM_MANAGER_CHAT_ID', ''));
        $this->organizerChatId = trim((string)env('TELEGRAM_ORGANIZER_CHAT_ID', $this->managerChatId));
    }
    public function notifyNewLead($lead) {
        $eventDate = $lead['event_date'] ?? '';
        $eventDate = $eventDate ?: 'не указана';
        $guests = $lead['guests_count'] ?? '';
        $guests = $guests ?: 'не указано';
        $budget = $lead['budget_range'] ?? '';
        $budget = $budget ?: 'не указан';
        $message = 'Новая заявка в ' . SITE_NAME . "\n"
            . "Клиент: " . ($lead['client_name'] ?? '') . "\n"
            . "Мероприятие: " . ($lead['event_type'] ?? '') . "\n"
            . "Дата: " . $eventDate . "\n"
            . "Гостей: " . $guests . "\n"
            . "Бюджет: " . $budget . "\n"
            . "Связь: " . ($lead['contact_method'] ?? 'site_chat') . "\n"
            . "Открыть: " . appUrl('/manager/lead.php?id=' . (int)$lead['id']);
        return $this->queue->enqueue('new_lead', [
            'text' => $message,
            'lead_id' => (int)$lead['id'],
        ], [
            'recipient_type' => 'manager',
            'recipient_id' => $lead['manager_id'] ?? null,
            'chat_id' => $this->managerChatId ?: null,
            'idempotency_key' => 'new-lead-' . (int)$lead['id'],
            'priority' => 20,
        ]);
    }
    public function notifyClientMessage($chatId, $sender, $messageId = null) {
        $message = "Новое сообщение от клиента\n"
            . "Клиент: " . trim(($sender['first_name'] ?? '') . ' ' . ($sender['last_name'] ?? '')) . "\n"
            . "Чат: " . appUrl('/chat.php?id=' . (int)$chatId);
        $suffix = $messageId ?: bin2hex(random_bytes(6));
        return $this->queue->enqueue('new_message', [
            'text' => $message,
            'chat_id' => (int)$chatId,
            'sender_id' => (int)($sender['id'] ?? 0),
            'message_id' => $messageId,
        ], [
            'recipient_type' => 'manager',
            'chat_id' => $this->managerChatId ?: null,
            'idempotency_key' => 'client-message-' . (int)$chatId . '-' . $suffix,
            'priority' => 40,
        ]);
    }
    public function notifyOrderStatus($order, $oldStatus, $newStatus) {
        $message = "Статус заказа изменён\n"
            . "Заказ: " . ($order['title'] ?? ('#' . (int)$order['id'])) . "\n"
            . "Было: " . ($oldStatus ?: 'не задано') . "\n"
            . "Стало: " . $newStatus . "\n"
            . "Открыть: " . appUrl('/organizer/order.php?id=' . (int)$order['id']);
        return $this->queue->enqueue('order_status_changed', [
            'text' => $message,
            'order_id' => (int)$order['id'],
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ], [
            'recipient_type' => 'organizer',
            'recipient_id' => $order['organizer_id'] ?? null,
            'chat_id' => $this->organizerChatId ?: null,
            'idempotency_key' => 'order-status-' . (int)$order['id'] . '-' . $newStatus . '-' . time(),
            'priority' => 60,
        ]);
    }
    public function notifyUser($userId, $eventType, $message, $idempotencyKey, $priority = 100) {
        return $this->queue->enqueue(
            $eventType,
            ['text' => $message, 'user_id' => (int)$userId],
            [
                'recipient_type' => 'user',
                'recipient_id' => (int)$userId,
                'idempotency_key' => $idempotencyKey,
                'priority' => $priority,
            ]
        );
    }
}
