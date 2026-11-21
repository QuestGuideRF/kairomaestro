<?php
class ClientOrderTimeline {
    private const STEPS = [
        ['key' => 'lead', 'title' => 'Заявка', 'description' => 'Бриф получен и сохранён в личном кабинете.'],
        ['key' => 'discussion', 'title' => 'Знакомство', 'description' => 'Уточняем формат, приоритеты и исходные данные.'],
        ['key' => 'concept', 'title' => 'Концепция', 'description' => 'Формируем и согласовываем визуальное направление.'],
        ['key' => 'estimate', 'title' => 'Смета', 'description' => 'Фиксируем состав работ и стоимость проекта.'],
        ['key' => 'contract', 'title' => 'Договор', 'description' => 'Согласовываем и подписываем документы.'],
        ['key' => 'prepayment', 'title' => 'Предоплата', 'description' => 'Подтверждаем первый этап оплаты.'],
        ['key' => 'preparation', 'title' => 'Подготовка', 'description' => 'Команда ведёт задачи, материалы и логистику.'],
        ['key' => 'final_approval', 'title' => 'Финальное согласование', 'description' => 'Проверяем готовность площадки, команды и тайминга.'],
        ['key' => 'event_day', 'title' => 'День мероприятия', 'description' => 'Проводим монтаж и координируем событие.'],
        ['key' => 'completed', 'title' => 'Завершение', 'description' => 'Закрываем проект и сохраняем итоговые материалы.'],
    ];
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function build(array $order, $userId, $role = null) {
        $this->assertAccess($order, (int)$userId, $role ?? currentRole());
        $current = $this->currentStep($order);
        $dates = $this->completionDates((int)$order['id'], $order);
        $steps = [];
        foreach (self::STEPS as $index => $definition) {
            $state = $index < $current ? 'completed' : ($index === $current ? 'current' : 'upcoming');
            if ($current >= count(self::STEPS)) {
                $state = 'completed';
            }
            $steps[] = array_merge($definition, [
                'state' => $state,
                'completed_at' => $state === 'completed' ? ($dates[$definition['key']] ?? null) : null,
                'next_step' => $state === 'current' ? $this->nextStepText($definition['key'], $order) : null,
            ]);
        }
        return $steps;
    }
    private function currentStep(array $order) {
        $statusMap = [
            'new_lead' => 1,
            'discussion' => 1,
            'estimate' => 3,
            'contract' => 4,
            'prepayment' => 5,
            'preparation' => 6,
            'event_day' => 8,
            'completed' => 10,
            'cancelled' => 0,
        ];
        $step = $statusMap[$order['status'] ?? 'new_lead'] ?? 1;
        if ($step === 6 && $this->readyForFinalApproval((int)$order['id'], $order['event_date'] ?? null)) {
            $step = 7;
        }
        return $step;
    }
    private function readyForFinalApproval($orderId, $eventDate) {
        if (!$eventDate || strtotime((string)$eventDate) > strtotime('+21 days')) {
            return false;
        }
        $openDocuments = 0;
        if (dbTableExists('order_documents')) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM order_documents
                 WHERE order_id=? AND status IN ('draft','sent','viewed','changes_requested')"
            );
            $stmt->execute([(int)$orderId]);
            $openDocuments = (int)$stmt->fetchColumn();
        }
        return $openDocuments === 0;
    }
    private function completionDates($orderId, array $order) {
        $dates = ['lead' => $order['created_at'] ?? null];
        if (!dbTableExists('order_status_history')) {
            return $dates;
        }
        $stmt = $this->db->prepare(
            'SELECT new_status,MIN(created_at) completed_at
             FROM order_status_history WHERE order_id=? GROUP BY new_status'
        );
        $stmt->execute([(int)$orderId]);
        $statusKeys = [
            'discussion' => 'discussion',
            'estimate' => 'concept',
            'contract' => 'estimate',
            'prepayment' => 'contract',
            'preparation' => 'prepayment',
            'event_day' => 'preparation',
            'completed' => 'event_day',
        ];
        foreach ($stmt->fetchAll() as $row) {
            $key = $statusKeys[$row['new_status']] ?? null;
            if ($key) {
                $dates[$key] = $row['completed_at'];
            }
        }
        if (($order['status'] ?? '') === 'completed') {
            $dates['completed'] = $order['updated_at'] ?? null;
            $dates['final_approval'] = $dates['event_day'] ?? $order['event_date'] ?? null;
        }
        return $dates;
    }
    private function nextStepText($key, array $order) {
        return match ($key) {
            'lead' => 'Менеджер изучит заявку и предложит удобное время разговора.',
            'discussion' => 'Согласуем задачи, бюджетный ориентир и формат работы.',
            'concept' => 'После готовности концепция появится здесь для вашего решения.',
            'estimate' => 'Команда подготовит состав работ и этапы оплаты.',
            'contract' => 'Проверьте документы и оставьте решение в личном кабинете.',
            'prepayment' => 'Следующий платёж и срок отображаются в разделе оплаты.',
            'preparation' => 'Следите за согласованиями и клиентскими задачами ниже.',
            'final_approval' => 'Подтвердим итоговый тайминг и готовность команды.',
            'event_day' => 'Организатор координирует команду по утверждённому плану.',
            'completed' => 'Проект завершён. Можно оставить отзыв о работе команды.',
            default => 'Команда сообщит о следующем шаге.',
        };
    }
    private function assertAccess(array $order, $userId, $role) {
        $allowed = $role === 'admin'
            || ($role === 'client' && (int)$order['client_id'] === $userId);
        if (!$allowed) {
            throw new AccessDeniedException('Этапы заказа недоступны');
        }
    }
}
