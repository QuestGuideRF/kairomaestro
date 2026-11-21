<?php
class CandidateApplication {
    private const ROLES = [
        'florist' => 'Флорист',
        'installer' => 'Монтажник',
        'courier' => 'Курьер',
        'helper' => 'Помощник',
    ];
    private const EXPERIENCE = [
        'none' => 'Без опыта',
        'under_year' => 'До 1 года',
        'one_to_three' => '1–3 года',
        'over_three' => 'Более 3 лет',
    ];
    private const STATUSES = [
        'new' => 'Новая',
        'contacted' => 'Связались',
        'interview' => 'Собеседование',
        'rejected' => 'Отклонена',
        'hired' => 'Принят в команду',
    ];
    private PDO $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public static function roles(): array {
        return self::ROLES;
    }
    public static function experienceOptions(): array {
        return self::EXPERIENCE;
    }
    public static function statuses(): array {
        return self::STATUSES;
    }
    public function submit(array $input, string $submissionKey, array $context = []): array {
        $result = null;
        if (!dbTableExists('candidate_applications')) {
            $result = [
                'success' => false,
                'error' => 'Форма временно недоступна. Администратору нужно применить миграцию заявок кандидатов.',
            ];
        } else {
            $validation = $this->validate($input);
            if (!$validation['success']) {
                $result = $validation;
            } elseif (!preg_match('/\A[a-f0-9]{64}\z/', $submissionKey)) {
                $result = ['success' => false, 'error' => 'Сессия формы устарела. Обновите страницу и попробуйте снова.'];
            } else {
                $result = $this->persist($validation['data'], $submissionKey, $context);
            }
        }
        return $result;
    }
    private function persist(array $data, string $submissionKey, array $context): array {
        try {
            $this->db->beginTransaction();
            $existing = $this->db->prepare('SELECT id FROM candidate_applications WHERE submission_key=? LIMIT 1');
            $existing->execute([$submissionKey]);
            $existingId = (int)$existing->fetchColumn();
            if ($existingId > 0) {
                $this->db->commit();
                $result = ['success' => true, 'candidate_id' => $existingId, 'duplicate' => true];
            } else {
                $candidateId = $this->insert($data, $submissionKey, $context);
                $this->db->commit();
                AuditLog::record(
                    'candidate.submitted',
                    'candidate_application',
                    $candidateId,
                    null,
                    [
                        'desired_role' => $data['desired_role'],
                        'source' => $context['source'] ?? 'public_site',
                    ]
                );
                $this->notifyAdmins($candidateId, $data);
                $result = ['success' => true, 'candidate_id' => $candidateId, 'duplicate' => false];
            }
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Candidate application submit failed: ' . $e->getMessage());
            $result = ['success' => false, 'error' => 'Не удалось отправить заявку. Попробуйте ещё раз немного позже.'];
        }
        return $result;
    }
    private function insert(array $data, string $submissionKey, array $context): int {
        $fields = [
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'telegram' => $data['telegram'] ?: null,
            'desired_role' => $data['desired_role'],
            'experience' => $data['experience'],
            'desired_salary' => $data['desired_salary'],
            'portfolio_url' => $data['portfolio_url'] ?: null,
            'comment' => $data['comment'] ?: null,
            'preferred_schedule' => $data['preferred_schedule'] ?: null,
            'status' => 'new',
            'submission_key' => $submissionKey,
            'source' => $context['source'] ?? 'public_site',
        ];
        foreach (['telegram_user_id', 'telegram_chat_id'] as $column) {
            if (dbColumnExists('candidate_applications', $column)
                && !empty($context[$column])) {
                $fields[$column] = (int)$context[$column];
            }
        }
        $stmt = $this->db->prepare(
            'INSERT INTO candidate_applications (`' . implode('`,`', array_keys($fields)) . '`) VALUES ('
            . implode(',', array_fill(0, count($fields), '?')) . ')'
        );
        $stmt->execute(array_values($fields));
        return (int)$this->db->lastInsertId();
    }
    public function all(?string $status = null, int $limit = 200): array {
        if (!isAdminRole() || !dbTableExists('candidate_applications')) {
            return [];
        }
        $params = [];
        $sql = 'SELECT * FROM candidate_applications';
        if ($status && isset(self::STATUSES[$status])) {
            $sql .= ' WHERE status=?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function updateStatus(int $candidateId, string $status, int $actorId): array {
        if (!isAdminRole() || !isset(self::STATUSES[$status])) {
            return ['success' => false, 'error' => 'Недостаточно прав или неверный статус.'];
        }
        $stmt = $this->db->prepare('SELECT status FROM candidate_applications WHERE id=? LIMIT 1');
        $stmt->execute([$candidateId]);
        $oldStatus = $stmt->fetchColumn();
        if ($oldStatus === false) {
            return ['success' => false, 'error' => 'Заявка кандидата не найдена.'];
        }
        $update = $this->db->prepare('UPDATE candidate_applications SET status=?,updated_at=NOW() WHERE id=?');
        $update->execute([$status, $candidateId]);
        AuditLog::record(
            'candidate.status_changed',
            'candidate_application',
            $candidateId,
            ['status' => $oldStatus],
            ['status' => $status],
            ['user_id' => $actorId]
        );
        return ['success' => true];
    }
    private function validate(array $input): array {
        $data = $this->normalizeInput($input);
        $error = $this->validationError($input, $data);
        if ($error !== '') {
            return ['success' => false, 'error' => $error];
        }
        $data['desired_salary'] = number_format((float)$data['desired_salary'], 2, '.', '');
        return [
            'success' => true,
            'data' => $data,
        ];
    }
    private function normalizeInput(array $input): array {
        return [
            'full_name' => $this->cleanText($input['full_name'] ?? '', 190),
            'phone' => $this->cleanText($input['phone'] ?? '', 32),
            'email' => strtolower($this->cleanText($input['email'] ?? '', 190)),
            'telegram' => ltrim($this->cleanText($input['telegram'] ?? '', 120), '@'),
            'desired_role' => (string)($input['desired_role'] ?? ''),
            'experience' => (string)($input['experience'] ?? ''),
            'desired_salary' => filter_var(
                str_replace([' ', ','], ['', '.'], (string)($input['desired_salary'] ?? '')),
                FILTER_VALIDATE_FLOAT
            ),
            'portfolio_url' => $this->cleanText($input['portfolio_url'] ?? '', 500),
            'comment' => $this->cleanText($input['comment'] ?? '', 3000),
            'preferred_schedule' => $this->cleanText($input['preferred_schedule'] ?? '', 500),
        ];
    }
    private function validationError(array $input, array $data): string {
        $error = $this->basicValidationError($input, $data);
        if ($error === '') {
            $error = $this->contactValidationError($data);
        }
        if ($error === '') {
            $error = $this->applicationValidationError($input, $data);
        }
        return $error;
    }
    private function basicValidationError(array $input, array $data): string {
        $error = '';
        if (trim((string)($input['website'] ?? '')) !== '') {
            $error = 'Не удалось отправить форму.';
        } elseif ($this->length($data['full_name']) < 3) {
            $error = 'Укажите имя и фамилию.';
        }
        return $error;
    }
    private function contactValidationError(array $data): string {
        $error = '';
        if ($data['phone'] === '' && $data['email'] === '' && $data['telegram'] === '') {
            $error = 'Укажите телефон, email или Telegram.';
        } elseif ($data['phone'] !== '' && !preg_match('/\A[0-9+() \-]{7,32}\z/', $data['phone'])) {
            $error = 'Проверьте номер телефона.';
        } elseif ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Проверьте адрес электронной почты.';
        } elseif ($data['telegram'] !== '' && !preg_match('/\A\w{5,32}\z/', $data['telegram'])) {
            $error = 'Проверьте имя пользователя Telegram.';
        }
        return $error;
    }
    private function applicationValidationError(array $input, array $data): string {
        $error = '';
        if (!isset(self::ROLES[$data['desired_role']])) {
            $error = 'Выберите желаемую роль.';
        } elseif (!isset(self::EXPERIENCE[$data['experience']])) {
            $error = 'Укажите опыт работы.';
        } elseif ($data['desired_salary'] === false || $data['desired_salary'] < 0 || $data['desired_salary'] > 100000000) {
            $error = 'Проверьте желаемую оплату.';
        } elseif (!$this->isValidPortfolioUrl($data['portfolio_url'])) {
            $error = 'Ссылка на портфолио должна начинаться с http:// или https://.';
        } elseif (empty($input['privacy_consent'])) {
            $error = 'Подтвердите согласие на обработку данных.';
        }
        return $error;
    }
    private function isValidPortfolioUrl(string $url): bool {
        if ($url === '') {
            return true;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true);
    }
    private function notifyAdmins(int $candidateId, array $data): void {
        try {
            $activeClause = dbColumnExists('users', 'is_active') ? ' AND is_active=1' : '';
            $admins = $this->db->query("SELECT id FROM users WHERE role='admin'{$activeClause}")->fetchAll();
            $roleLabel = self::ROLES[$data['desired_role']] ?? $data['desired_role'];
            $contact = $data['phone'];
            if ($contact === '') {
                $contact = $data['email'];
            }
            if ($contact === '') {
                $contact = '@' . $data['telegram'];
            }
            $message = $data['full_name'] . ' · ' . $roleLabel . ' · ' . $contact;
            $notification = new Notification();
            foreach ($admins as $admin) {
                $notification->create(
                    (int)$admin['id'],
                    'candidate_application',
                    'Новая заявка в команду',
                    $message,
                    '/organizer/candidates.php'
                );
            }
            $telegramText = "Новая заявка в команду " . SITE_NAME . "\n"
                . "Кандидат: {$data['full_name']}\n"
                . "Роль: {$roleLabel}\n"
                . "Связь: {$contact}\n"
                . 'Открыть: ' . appUrl('/organizer/candidates.php');
            $adminChatId = trim((string)env('TELEGRAM_ADMIN_CHAT_ID', ''));
            $queue = new NotificationQueue();
            if ($adminChatId !== '') {
                $queue->enqueue(
                    'candidate_application',
                    ['text' => $telegramText, 'candidate_id' => $candidateId],
                    [
                        'recipient_type' => 'admin',
                        'chat_id' => $adminChatId,
                        'idempotency_key' => 'candidate-application-' . $candidateId,
                        'priority' => 30,
                    ]
                );
                return;
            }
            foreach ($admins as $admin) {
                $adminId = (int)$admin['id'];
                $queue->enqueue(
                    'candidate_application',
                    ['text' => $telegramText, 'candidate_id' => $candidateId, 'user_id' => $adminId],
                    [
                        'recipient_type' => 'user',
                        'recipient_id' => $adminId,
                        'idempotency_key' => 'candidate-application-' . $candidateId . '-admin-' . $adminId,
                        'priority' => 30,
                    ]
                );
            }
        } catch (Throwable $e) {
            error_log('Candidate notification failed: ' . $e->getMessage());
        }
    }
    private function cleanText(mixed $value, int $maxLength): string {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
        if ($this->length($text) <= $maxLength) {
            return $text;
        }
        return function_exists('mb_substr')
            ? mb_substr($text, 0, $maxLength, 'UTF-8')
            : substr($text, 0, $maxLength);
    }
    private function length(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
