<?php
class LeadQuizService {
    use LeadQuizValidation;
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function submit($data, $files = [], $context = []) {
        $data = QuizBrief::normalizeSubmission(
            is_array($data) ? $data : [],
            ($context['channel'] ?? '') === 'telegram'
        );
        $storedPaths = [];
        $leadId = null;
        $passwordSetupToken = null;
        $submissionHash = $this->submissionHash($data);
        try {
            $validationError = $this->validationError($data, $files);
            if ($validationError !== null) {
                throw new ValidationException($validationError);
            }
            $duplicate = $this->duplicateResult($submissionHash);
            if ($duplicate !== null) {
                return $duplicate;
            }
            $this->db->beginTransaction();
            $clientResult = $this->resolveClient($data, $context);
            if (!$clientResult['success']) {
                throw new ValidationException($clientResult['error']);
            }
            if (($context['channel'] ?? '') === 'telegram' && empty($clientResult['existing'])) {
                $passwordSetupToken = (new PasswordSetup())->issue((int)$clientResult['user_id']);
            }
            $managerId = null;
            $leadId = $this->insertLead($data, $clientResult['user_id'], $managerId, $submissionHash);
            $this->insertInitialHistory($leadId);
            $this->insertAnswers($leadId, $data);
            $this->storeReferences($leadId, $files, $storedPaths);
            $chatResult = $this->createClientChat($leadId, $clientResult['user_id'], $managerId);
            $this->requireSuccess($chatResult, 'Не удалось создать чат заявки');
            $this->notifyManagers($leadId, $data);
            $telegramResult = (new TelegramNotifier())->notifyNewLead($this->findLead($leadId));
            $this->requireSuccess($telegramResult, 'Не удалось поставить уведомление менеджеру в очередь');
            $this->db->commit();
            AuditLog::record('lead.created', 'lead', $leadId, null, [
                'status' => 'new',
                'event_type' => $data['event_type'] ?? null,
                'event_date' => $data['event_date'] ?? null,
                'source' => $data['source'] ?? 'quiz',
            ], ['lead_id' => $leadId, 'user_id' => $clientResult['user_id'], 'role' => 'client']);
            $result = [
                'success' => true,
                'lead_id' => $leadId,
                'client_id' => $clientResult['user_id'],
                'client_existing' => $clientResult['existing'] ?? false,
                'chat_id' => $chatResult['chat_id'] ?? null,
                'password_setup_token' => $passwordSetupToken,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            foreach ($storedPaths as $path) {
                FileStorage::deleteOwnedFile($path, 'references', 'lead', $leadId);
            }
            $duplicate = $e instanceof PDOException && $e->getCode() === '23000'
                ? $this->duplicateResult($submissionHash)
                : null;
            $result = $duplicate ?? [
                'success' => false,
                'error' => publicExceptionMessage($e, 'Не удалось сохранить заявку. Повторите попытку.'),
            ];
        }
        return $result;
    }
    private function resolveClient($data, $context) {
        if (($context['channel'] ?? '') !== 'telegram') {
            return (new User())->createClientFromLead($data);
        }
        return $this->resolveTelegramClient($data, $context);
    }
    private function resolveTelegramClient($data, $context) {
        $telegramUserId = (int)($context['telegram_user_id'] ?? 0);
        $telegramChatId = (int)($context['telegram_chat_id'] ?? 0);
        $telegramUsername = trim((string)($context['telegram_username'] ?? ''));
        if ($telegramUserId <= 0 || $telegramChatId === 0) {
            throw new ValidationException('Не удалось подтвердить Telegram-аккаунт');
        }
        $stmt = $this->db->prepare('SELECT * FROM users WHERE telegram_user_id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$telegramUserId]);
        $linked = $stmt->fetch();
        if ($linked) {
            if (($linked['role'] ?? '') !== 'client' || (int)($linked['is_active'] ?? 1) !== 1) {
                throw new AccessDeniedException('Telegram привязан к служебному или недоступному аккаунту');
            }
            $this->refreshTelegramIdentity((int)$linked['id'], $telegramUserId, $telegramChatId, $telegramUsername);
            return ['success' => true, 'user_id' => (int)$linked['id'], 'existing' => true];
        }
        $directory = new User();
        $phone = trim((string)($data['phone'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $profileUsername = trim((string)($data['telegram_username'] ?? ''));
        $contactOwner = $directory->findByPhoneOrEmail($phone ?: null, $email ?: null);
        if (!$contactOwner && $profileUsername !== '') {
            $contactOwner = $directory->findByTelegramUsername($profileUsername);
        }
        if ($contactOwner) {
            throw new ConflictException(
                'Телефон, email или Telegram уже принадлежат аккаунту. '
                . 'Привяжите Telegram через личный кабинет сайта.'
            );
        }
        $clientResult = $directory->createClientFromLead($data);
        if (!$clientResult['success']) {
            return $clientResult;
        }
        $this->refreshTelegramIdentity(
            (int)$clientResult['user_id'],
            $telegramUserId,
            $telegramChatId,
            $telegramUsername
        );
        return $clientResult;
    }
    private function refreshTelegramIdentity($userId, $telegramUserId, $telegramChatId, $telegramUsername) {
        $fields = [
            'telegram_user_id=?',
            'telegram_chat_id=?',
            'telegram_username=?',
            'telegram_linked_at=COALESCE(telegram_linked_at,NOW())',
        ];
        $values = [
            (int)$telegramUserId,
            (int)$telegramChatId,
            $telegramUsername !== '' ? $telegramUsername : null,
        ];
        if (dbColumnExists('users', 'telegram_status')) {
            $fields[] = "telegram_status='active'";
        }
        $values[] = (int)$userId;
        $values[] = (int)$telegramUserId;
        $stmt = $this->db->prepare(
            'UPDATE users SET ' . implode(',', $fields)
            . ' WHERE id=? AND (telegram_user_id IS NULL OR telegram_user_id=?)'
        );
        $stmt->execute($values);
        if ($stmt->rowCount() === 0) {
            $check = $this->db->prepare('SELECT telegram_user_id FROM users WHERE id=?');
            $check->execute([(int)$userId]);
            if ((int)$check->fetchColumn() !== (int)$telegramUserId) {
                throw new ConflictException('Аккаунт уже привязан к другому Telegram');
            }
        }
    }
    private function submissionHash($data) {
        $key = trim((string)($data['submission_key'] ?? ''));
        return $key !== '' ? hash('sha256', $key) : null;
    }
    private function duplicateResult($submissionHash) {
        $result = null;
        if ($submissionHash && dbColumnExists('leads', 'submission_key')) {
            $stmt = $this->db->prepare('SELECT id, client_id FROM leads WHERE submission_key=? LIMIT 1');
            $stmt->execute([$submissionHash]);
            $lead = $stmt->fetch();
            if ($lead) {
                $chat = (new Chat())->getLeadChat((int)$lead['id']);
                $result = [
                    'success' => true,
                    'duplicate' => true,
                    'lead_id' => (int)$lead['id'],
                    'client_id' => (int)$lead['client_id'],
                    'client_existing' => true,
                    'chat_id' => $chat['id'] ?? null,
                ];
            }
        }
        return $result;
    }
    private function insertLead($data, $clientId, $managerId, $submissionHash) {
        return (new LeadQuizWriter($this->db))->insert($data, $clientId, $managerId, $submissionHash);
    }
    private function insertInitialHistory($leadId) {
        if (dbTableExists('lead_status_history')) {
            $this->db->prepare("INSERT INTO lead_status_history (lead_id, old_status, new_status, changed_by, comment) VALUES (?, NULL, 'new', NULL, 'Заявка создана из квиза')")
                ->execute([$leadId]);
        }
    }
    private function insertAnswers($leadId, $data) {
        $stmt = $this->db->prepare('INSERT INTO quiz_answers (lead_id, question_key, answer_value) VALUES (?, ?, ?)');
        foreach (QuizBrief::answerKeys() as $key) {
            $value = $data[$key] ?? '';
            if ($value === '' || $value === [] || $value === null) {
                continue;
            }
            $storedValue = is_array($value)
                ? json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : (string)$value;
            $stmt->execute([$leadId, $key, $storedValue]);
        }
    }
    private function storeReferences($leadId, $files, &$paths) {
        foreach ($this->normalizeFiles($files['references'] ?? null) as $file) {
            $upload = FileStorage::store(
                $file,
                'references',
                ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
                UPLOAD_LIMIT_BYTES
            );
            if (!$upload['success']) {
                throw new FileStorageException($upload['error']);
            }
            $paths[] = $upload['file_path'];
            $stmt = $this->db->prepare("
                INSERT INTO lead_files (lead_id, file_path, file_name, mime_type, file_size)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $leadId, $upload['file_path'], $upload['file_name'], $upload['mime_type'], $upload['file_size'],
            ]);
        }
    }
    private function normalizeFiles($files) {
        if (!$files || empty($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            return [$files];
        }
        $normalized = [];
        foreach ($files['name'] as $index => $name) {
            if ($name !== '') {
                $normalized[] = [
                    'name' => $name,
                    'type' => $files['type'][$index] ?? '',
                    'tmp_name' => $files['tmp_name'][$index] ?? '',
                    'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$index] ?? 0,
                ];
            }
        }
        return array_slice($normalized, 0, 10);
    }
    private function createClientChat($leadId, $clientId, $managerId) {
        $chat = new Chat();
        $result = $chat->createLeadChat($leadId, $clientId, $managerId);
        if ($result['success']) {
            $messageResult = $chat->sendSystemMessage(
                (int)$result['chat_id'],
                'Здравствуйте! Мы получили вашу заявку и скоро вернёмся с первыми вопросами.'
            );
            $this->requireSuccess($messageResult, 'Не удалось создать системное сообщение');
        }
        return $result;
    }
    private function notifyManagers($leadId, $data) {
        $activeWhere = dbColumnExists('users', 'is_active') ? ' AND is_active=1' : '';
        $stmt = $this->db->query("SELECT id FROM users WHERE role IN ('manager','admin'){$activeWhere}");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $managerId) {
            $result = (new Notification())->create(
                (int)$managerId,
                'new_lead',
                'Новая заявка',
                'Клиент ' . trim((string)$data['client_name']) . ' отправил квиз.',
                '/manager/lead.php?id=' . (int)$leadId
            );
            $this->requireSuccess($result, 'Не удалось создать уведомление менеджера');
        }
    }
    private function requireSuccess(array $result, string $fallback): void {
        if (empty($result['success'])) {
            throw new PersistenceException((string)($result['error'] ?? $fallback));
        }
    }
    private function findLead($leadId) {
        $stmt = $this->db->prepare('SELECT * FROM leads WHERE id=? LIMIT 1');
        $stmt->execute([(int)$leadId]);
        return $stmt->fetch();
    }
}
