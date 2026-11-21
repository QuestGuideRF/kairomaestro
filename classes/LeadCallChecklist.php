<?php
class LeadCallChecklist {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function forLead(array $lead, array $files = []) {
        $items = $this->build($lead, $files);
        if (!dbTableExists('lead_call_checklist_items')) {
            return $items;
        }
        $stmt = $this->db->prepare(
            'SELECT item_key,is_done,completed_at FROM lead_call_checklist_items WHERE lead_id=?'
        );
        $stmt->execute([(int)$lead['id']]);
        $stored = [];
        foreach ($stmt->fetchAll() as $row) {
            $stored[$row['item_key']] = $row;
        }
        foreach ($items as &$item) {
            $state = $stored[$item['key']] ?? null;
            $item['is_done'] = $state ? (bool)$state['is_done'] : false;
            $item['completed_at'] = $state['completed_at'] ?? null;
        }
        unset($item);
        return $items;
    }
    public function setCompleted($leadId, $itemKey, $isDone, $userId) {
        try {
            $lead = (new Lead())->getById((int)$leadId);
            $this->assertCanManage($lead, (int)$userId);
            if (!dbTableExists('lead_call_checklist_items')) {
                throw new PersistenceException('Примените миграцию чек-листа звонка');
            }
            $available = [];
            foreach ($this->build($lead, (new Lead())->getFiles((int)$leadId)) as $item) {
                $available[$item['key']] = $item['title'];
            }
            if (!isset($available[$itemKey])) {
                throw new ValidationException('Пункт чек-листа больше не актуален');
            }
            $done = (bool)$isDone;
            $stmt = $this->db->prepare(
                'INSERT INTO lead_call_checklist_items
                    (lead_id,item_key,title,is_done,completed_by,completed_at)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE title=?,is_done=?,completed_by=?,completed_at=?'
            );
            $completedBy = $done ? (int)$userId : null;
            $completedAt = $done ? date('Y-m-d H:i:s') : null;
            $stmt->execute([
                (int)$leadId, $itemKey, $available[$itemKey], $done ? 1 : 0,
                $completedBy, $completedAt, $available[$itemKey], $done ? 1 : 0,
                $completedBy, $completedAt,
            ]);
            AuditLog::record(
                'lead.call_checklist_changed',
                'lead',
                (int)$leadId,
                null,
                ['item_key' => $itemKey, 'is_done' => $done],
                ['lead_id' => (int)$leadId, 'user_id' => (int)$userId]
            );
            return ['success' => true];
        } catch (Throwable $error) {
            return [
                'success' => false,
                'error' => publicExceptionMessage($error, 'Не удалось обновить чек-лист'),
            ];
        }
    }
    private function build(array $lead, array $files) {
        $items = [
            ['key' => 'confirm_event_format', 'title' => 'Подтвердить формат и задачу события'],
            ['key' => 'confirm_event_date', 'title' => empty($lead['event_date'])
                ? 'Уточнить дату события'
                : 'Подтвердить дату и время события'],
            ['key' => 'confirm_guests', 'title' => empty($lead['guests_count'])
                ? 'Уточнить количество гостей'
                : 'Подтвердить количество гостей'],
        ];
        $venue = trim((string)($lead['venue'] ?? $lead['location_details'] ?? ''));
        if ($venue === '') {
            $items[] = ['key' => 'clarify_venue', 'title' => 'Уточнить площадку и статус её выбора'];
        } else {
            $items[] = ['key' => 'venue_agreements', 'title' => 'Уточнить договорённости с площадкой'];
            $items[] = ['key' => 'venue_installation_rules', 'title' => 'Узнать ограничения монтажа и время доступа'];
        }
        if (trim((string)($lead['budget_range'] ?? '')) === '') {
            $items[] = ['key' => 'clarify_budget', 'title' => 'Уточнить комфортный диапазон бюджета'];
        }
        $services = $this->services($lead['services_needed'] ?? []);
        if ($this->hasService($services, ['церемон', 'ceremony'])) {
            $items[] = ['key' => 'ceremony_format', 'title' => 'Уточнить формат выездной церемонии'];
            $items[] = ['key' => 'ceremony_decor', 'title' => 'Уточнить состав оформления церемонии'];
        }
        if ($this->hasService($services, ['флорист', 'flor'])) {
            $items[] = ['key' => 'favorite_flowers', 'title' => 'Уточнить любимые цветы'];
            $items[] = ['key' => 'unwanted_flowers', 'title' => 'Уточнить нежелательные цветы'];
            $items[] = ['key' => 'flower_seasonality', 'title' => 'Обсудить сезонность и допустимые замены'];
        }
        if ($files) {
            $items[] = ['key' => 'discuss_references', 'title' => 'Обсудить референсы клиента'];
        }
        if ($this->isUrgent($lead['event_date'] ?? null)) {
            $items[] = ['key' => 'decision_urgency', 'title' => 'Уточнить срочность ключевых решений'];
        }
        if (($lead['source'] ?? '') === 'short_form') {
            $items[] = ['key' => 'complete_short_brief', 'title' => 'Собрать недостающие данные короткой заявки'];
        }
        $items[] = ['key' => 'agree_next_step', 'title' => 'Зафиксировать следующий шаг и срок связи'];
        foreach ($items as &$item) {
            $item['is_done'] = false;
            $item['completed_at'] = null;
        }
        unset($item);
        return $items;
    }
    private function services($value) {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        return array_map(static fn($item) => utf8Lower((string)$item), (array)$value);
    }
    private function hasService(array $services, array $needles) {
        foreach ($services as $service) {
            foreach ($needles as $needle) {
                if (str_contains($service, $needle)) {
                    return true;
                }
            }
        }
        return false;
    }
    private function isUrgent($eventDate) {
        if (!$eventDate) {
            return false;
        }
        $days = (int)floor((strtotime((string)$eventDate) - strtotime('today')) / 86400);
        return $days >= 0 && $days <= 45;
    }
    private function assertCanManage($lead, $userId) {
        if (!$lead || $userId !== (int)($_SESSION['user_id'] ?? 0)
            || !in_array(currentRole(), ['manager', 'admin'], true)) {
            throw new AccessDeniedException('Заявка недоступна');
        }
        if (currentRole() === 'manager' && !empty($lead['manager_id'])
            && (int)$lead['manager_id'] !== $userId) {
            throw new AccessDeniedException('Заявка назначена другому менеджеру');
        }
        if (currentRole() === 'manager' && empty($lead['manager_id'])) {
            $claim = (new LeadAssignmentService($this->db))->claim((int)$lead['id'], $userId);
            if (empty($claim['success'])) {
                throw new ConflictException($claim['error'] ?? 'Заявку уже взял другой менеджер');
            }
        }
    }
}
