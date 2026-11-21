<?php
class ShortLeadService {
    private const EVENT_FALLBACK = 'Событие — детали уточняются';
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function submit($data) {
        $leadId = null;
        $submissionHash = null;
        try {
            $values = $this->normalize($data);
            $submissionHash = hash('sha256', (string)$values['submission_key']);
            $duplicate = $this->duplicate($submissionHash);
            if ($duplicate) {
                return $duplicate;
            }
            $this->assertContactOwnership($values);
            $this->db->beginTransaction();
            $client = (new User())->createClientFromLead($values);
            if (empty($client['success'])) {
                throw new ValidationException($client['error'] ?? 'Не удалось создать кабинет');
            }
            $leadId = (new LeadQuizWriter($this->db))->insert(
                $values,
                (int)$client['user_id'],
                null,
                $submissionHash
            );
            $this->createHistory($leadId);
            $chat = new Chat();
            $chatResult = $chat->createLeadChat($leadId, (int)$client['user_id'], null);
            if (empty($chatResult['success'])) {
                throw new PersistenceException($chatResult['error'] ?? 'Не удалось создать чат');
            }
            $chat->sendSystemMessage(
                (int)$chatResult['chat_id'],
                'Здравствуйте! Мы получили короткую заявку. Менеджер уточнит детали в чате или выбранным способом.'
            );
            $this->notifyManagers($leadId, $values['client_name']);
            $queued = (new TelegramNotifier())->notifyNewLead($this->findLead($leadId));
            if (empty($queued['success'])) {
                throw new PersistenceException($queued['error'] ?? 'Не удалось поставить уведомление в очередь');
            }
            $this->db->commit();
            AuditLog::record('lead.created', 'lead', $leadId, null, [
                'status' => 'new',
                'event_type' => $values['event_type'],
                'source' => 'short_form',
            ], ['lead_id' => $leadId, 'user_id' => (int)$client['user_id'], 'role' => 'client']);
            return [
                'success' => true,
                'lead_id' => $leadId,
                'client_id' => (int)$client['user_id'],
                'client_existing' => !empty($client['existing']),
                'chat_id' => (int)$chatResult['chat_id'],
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $duplicate = $submissionHash !== null
                && $error instanceof PDOException
                && $error->getCode() === '23000'
                    ? $this->duplicate($submissionHash)
                    : null;
            return $duplicate ?? [
                'success' => false,
                'error' => publicExceptionMessage($error, 'Не удалось сохранить заявку. Повторите попытку.'),
            ];
        }
    }
    private function normalize($data) {
        $name = trim((string)($data['client_name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $telegram = ltrim(trim((string)($data['telegram_username'] ?? '')), '@');
        $eventType = trim((string)($data['event_type'] ?? ''));
        $comment = trim((string)($data['comment'] ?? ''));
        if ($name === '' || utf8Length($name) > 190) {
            throw new ValidationException('Укажите имя');
        }
        if (($phone === '' && $telegram === '') || utf8Length($phone) > 40
            || utf8Length($telegram) > 120) {
            throw new ValidationException('Укажите телефон или имя пользователя в Telegram');
        }
        if (utf8Length($eventType) > 120 || utf8Length($comment) > 10000) {
            throw new ValidationException('Проверьте текст заявки');
        }
        if (empty($data['privacy_consent'])) {
            throw new ValidationException('Подтвердите согласие на обработку данных');
        }
        $key = trim((string)($data['submission_key'] ?? ''));
        if (!preg_match('/\A[a-f0-9]{64}\z/i', $key)) {
            throw new ValidationException('Форма устарела. Обновите страницу и повторите отправку.');
        }
        return [
            'client_name' => $name,
            'phone' => $phone,
            'email' => '',
            'telegram_username' => $telegram,
            'contact_method' => $telegram !== '' && $phone === '' ? 'telegram' : 'call',
            'event_type' => $eventType !== '' ? $eventType : self::EVENT_FALLBACK,
            'services_needed' => [],
            'comment' => $comment,
            'source' => 'short_form',
            'privacy_consent' => '1',
            'submission_key' => $key,
            'referer_url' => substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 500),
        ];
    }
    private function assertContactOwnership($values) {
        $directory = new User();
        $matches = array_filter([
            $values['phone'] !== '' ? $directory->findByPhoneOrEmail($values['phone'], null) : null,
            $values['telegram_username'] !== ''
                ? $directory->findByTelegramUsername($values['telegram_username'])
                : null,
        ]);
        $ids = array_values(array_unique(array_map(static fn($user) => (int)$user['id'], $matches)));
        if (count($ids) > 1) {
            throw new ConflictException('Указанные контакты относятся к разным аккаунтам');
        }
        if ($ids && (int)($_SESSION['user_id'] ?? 0) !== $ids[0]) {
            throw new AuthenticationException('По этим контактам уже есть кабинет. Войдите перед отправкой заявки.');
        }
    }
    private function duplicate($submissionHash) {
        $stmt = $this->db->prepare('SELECT id,client_id FROM leads WHERE submission_key=? LIMIT 1');
        $stmt->execute([$submissionHash]);
        $lead = $stmt->fetch();
        if (!$lead || (int)$lead['client_id'] !== (int)($_SESSION['user_id'] ?? $lead['client_id'])) {
            return null;
        }
        $chat = (new Chat())->getLeadChat((int)$lead['id']);
        return [
            'success' => true,
            'duplicate' => true,
            'lead_id' => (int)$lead['id'],
            'client_id' => (int)$lead['client_id'],
            'client_existing' => true,
            'chat_id' => $chat['id'] ?? null,
        ];
    }
    private function createHistory($leadId) {
        if (dbTableExists('lead_status_history')) {
            $this->db->prepare(
                "INSERT INTO lead_status_history
                    (lead_id,old_status,new_status,changed_by,comment)
                 VALUES (?,NULL,'new',NULL,'Короткая заявка с сайта')"
            )->execute([(int)$leadId]);
        }
    }
    private function notifyManagers($leadId, $clientName) {
        $stmt = $this->db->query("SELECT id FROM users WHERE role IN ('manager','admin') AND is_active=1");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $managerId) {
            $result = (new Notification())->create(
                (int)$managerId,
                'new_short_lead',
                'Новая короткая заявка',
                'Клиент ' . $clientName . ' оставил короткую заявку. Подробности нужно уточнить.',
                '/manager/lead.php?id=' . (int)$leadId
            );
            if (empty($result['success'])) {
                throw new PersistenceException($result['error'] ?? 'Не удалось создать уведомление');
            }
        }
    }
    private function findLead($leadId) {
        $stmt = $this->db->prepare('SELECT * FROM leads WHERE id=? LIMIT 1');
        $stmt->execute([(int)$leadId]);
        return $stmt->fetch();
    }
}
