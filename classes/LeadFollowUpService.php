<?php
class LeadFollowUpService {
    private const RESULTS = ['contacted', 'no_answer', 'callback', 'waiting', 'irrelevant'];
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function schedule($leadId, $userId, $data) {
        try {
            $lead = (new Lead())->getById((int)$leadId);
            $this->assertAccess($lead, (int)$userId);
            if (!dbTableExists('lead_follow_ups')) {
                throw new PersistenceException('Примените миграцию follow-up заявок');
            }
            $scheduledAt = $this->dateTime($data['scheduled_at'] ?? '');
            $note = trim((string)($data['note'] ?? ''));
            $result = (string)($data['result'] ?? 'callback');
            if (utf8Length($note) > 500 || !in_array($result, self::RESULTS, true)) {
                throw new ValidationException('Проверьте результат и текст напоминания');
            }
            if (in_array($result, ['no_answer', 'callback', 'waiting'], true) && !$scheduledAt) {
                throw new ValidationException('Укажите дату и время следующего контакта');
            }
            if ($scheduledAt && strtotime($scheduledAt) < time() - 60) {
                throw new ValidationException('Следующий контакт должен быть запланирован на будущее');
            }
            $status = $scheduledAt ? 'scheduled' : 'completed';
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                'INSERT INTO lead_follow_ups
                    (lead_id,manager_id,scheduled_at,note,result,status)
                 VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([
                (int)$leadId, (int)$userId, $scheduledAt,
                $note !== '' ? $note : null, $result, $status,
            ]);
            $followUpId = (int)$this->db->lastInsertId();
            $this->db->prepare(
                'UPDATE leads SET next_contact_at=?,reminder_at=? WHERE id=?'
            )->execute([$scheduledAt, $scheduledAt, (int)$leadId]);
            $this->db->commit();
            AuditLog::record('lead.follow_up_saved', 'lead_follow_up', $followUpId, null, [
                'lead_id' => (int)$leadId,
                'scheduled_at' => $scheduledAt,
                'result' => $result,
            ], ['lead_id' => (int)$leadId, 'user_id' => (int)$userId]);
            return ['success' => true, 'follow_up_id' => $followUpId];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'success' => false,
                'error' => publicExceptionMessage($error, 'Не удалось сохранить следующий контакт'),
            ];
        }
    }
    public function history($leadId) {
        if (!dbTableExists('lead_follow_ups')) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT follow_up.*,user.first_name,user.last_name
             FROM lead_follow_ups follow_up
             JOIN users user ON user.id=follow_up.manager_id
             WHERE follow_up.lead_id=? ORDER BY follow_up.id DESC LIMIT 20'
        );
        $stmt->execute([(int)$leadId]);
        return $stmt->fetchAll();
    }
    public function labels() {
        return [
            'contacted' => 'Связались',
            'no_answer' => 'Не ответил',
            'callback' => 'Перезвонить',
            'waiting' => 'Ждём решение',
            'irrelevant' => 'Неактуально',
        ];
    }
    private function dateTime($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
        if (!$date || $date->format('Y-m-d\TH:i') !== $value) {
            throw new ValidationException('Проверьте дату следующего контакта');
        }
        return $date->format('Y-m-d H:i:s');
    }
    private function assertAccess($lead, $userId) {
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
