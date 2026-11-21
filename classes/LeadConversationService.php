<?php
class LeadConversationService {
    private const ALLOWED_SERVICES = [
        'организация под ключ',
        'декор',
        'флористика',
        'координация',
        'выездная церемония',
        'монтаж',
        'другое',
    ];
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function update($leadId, $data, $userId) {
        $leadId = (int)$leadId;
        $userId = (int)$userId;
        try {
            if ($userId !== (int)($_SESSION['user_id'] ?? 0)
                || !in_array(currentRole(), ['manager', 'admin'], true)) {
                throw new AccessDeniedException('Заявка недоступна');
            }
            $claim = (new LeadAssignmentService($this->db))->claim($leadId, $userId);
            if (!$claim['success']) {
                throw new ConflictException($claim['error']);
            }
            $lead = (new Lead())->getById($leadId);
            if (!$lead || (currentRole() === 'manager' && (int)$lead['manager_id'] !== $userId)) {
                throw new AccessDeniedException('Заявка недоступна');
            }
            if (!empty($lead['order_id'])) {
                throw new ConflictException('Заказ уже создан, сведения заявки доступны только для просмотра');
            }
            $values = $this->normalize($data);
            $this->persist($lead, $values, $userId);
            return ['success' => true];
        } catch (Throwable $error) {
            return ['success' => false, 'error' => publicExceptionMessage($error, 'Не удалось сохранить итог разговора')];
        }
    }
    private function normalize($data) {
        $eventDate = trim((string)($data['event_date'] ?? ''));
        if ($eventDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
            if (!$date || $date->format('Y-m-d') !== $eventDate) {
                throw new ValidationException('Проверьте уточнённую дату');
            }
        }
        $guestsRaw = trim((string)($data['guests_count'] ?? ''));
        if ($guestsRaw !== '' && (!ctype_digit($guestsRaw) || (int)$guestsRaw < 1 || (int)$guestsRaw > 100000)) {
            throw new ValidationException('Количество гостей должно быть от 1 до 100 000');
        }
        $services = array_values(array_unique(array_intersect(
            self::ALLOWED_SERVICES,
            array_map('strval', (array)($data['services_needed'] ?? []))
        )));
        return [
            'event_date' => $eventDate !== '' ? $eventDate : null,
            'guests_count' => $guestsRaw !== '' ? (int)$guestsRaw : null,
            'venue' => $this->text($data['venue'] ?? '', 190, 'Название площадки'),
            'location_details' => $this->text($data['location_details'] ?? '', 255, 'Адрес площадки'),
            'budget_range' => $this->text($data['budget_range'] ?? '', 120, 'Бюджет'),
            'services_needed' => json_encode($services, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'client_wishes' => $this->text($data['client_wishes'] ?? '', 20000, 'Пожелания'),
            'client_likes' => $this->text($data['client_likes'] ?? '', 10000, 'Предпочтения'),
            'client_dislikes' => $this->text($data['client_dislikes'] ?? '', 10000, 'Антипредпочтения'),
            'client_additional_comments' => $this->text($data['client_additional_comments'] ?? '', 20000, 'Дополнительный комментарий клиента'),
            'client_questions' => $this->text($data['client_questions'] ?? '', 20000, 'Вопросы клиента'),
            'manager_answers' => $this->text($data['manager_answers'] ?? '', 20000, 'Ответы менеджера'),
            'conversation_summary' => $this->text($data['conversation_summary'] ?? '', 30000, 'Итог разговора'),
        ];
    }
    private function persist($lead, $values, $userId) {
        $sets = [];
        $params = [];
        $stored = [];
        foreach ($values as $column => $value) {
            if (dbColumnExists('leads', $column)) {
                $sets[] = "`{$column}`=?";
                $params[] = $value;
                $stored[$column] = $value;
            }
        }
        if (!$sets) {
            throw new ValidationException('Схема заявок не поддерживает итог разговора. Примените миграцию.');
        }
        $params[] = (int)$lead['id'];
        $params[] = (int)$userId;
        $sql = 'UPDATE leads SET ' . implode(',', $sets) . ' WHERE id=? AND manager_id=? AND order_id IS NULL';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            $check = $this->db->prepare('SELECT manager_id,order_id FROM leads WHERE id=?');
            $check->execute([(int)$lead['id']]);
            $current = $check->fetch();
            if (!$current || !empty($current['order_id']) || (int)$current['manager_id'] !== (int)$userId) {
                throw new ConflictException('Заявка изменилась в другой сессии. Обновите страницу.');
            }
        }
        AuditLog::record('lead.conversation_saved', 'lead', (int)$lead['id'], array_intersect_key($lead, $stored), $stored, [
            'lead_id' => (int)$lead['id'],
            'user_id' => (int)$userId,
        ]);
    }
    private function text($value, $maxLength, $label) {
        $value = trim((string)$value);
        if (utf8Length($value) > $maxLength) {
            throw new ValidationException($label . ': превышена допустимая длина');
        }
        return $value !== '' ? $value : null;
    }
}
