<?php
class TelegramStaffAuthService {
    private const WORKER_ROLES = ['florist', 'courier', 'installer', 'helper', 'worker'];
    private const PRIVILEGED_ROLES = ['manager', 'organizer', 'admin'];
    private const WINDOW_MINUTES = 15;
    private const MAX_ATTEMPTS = 6;
    private PDO $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function authenticate(array $payload): array {
        $login = trim((string)($payload['login'] ?? ''));
        $password = (string)($payload['password'] ?? '');
        $telegramUserId = (int)($payload['telegram_user_id'] ?? 0);
        $telegramChatId = (int)($payload['telegram_chat_id'] ?? 0);
        $username = trim((string)($payload['telegram_username'] ?? ''));
        if ($login === '' || $password === '' || $telegramUserId <= 0 || $telegramChatId === 0) {
            throw new ValidationException('Заполните логин и пароль');
        }
        $scope = hash('sha256', strtolower($login) . '|' . $telegramUserId);
        $this->assertRateLimit($scope);
        $user = $this->findUser($login);
        $allowed = $user
            && (int)($user['is_active'] ?? 1) === 1
            && (empty($user['locked_until']) || strtotime((string)$user['locked_until']) <= time())
            && password_verify($password, (string)$user['password']);
        unset($password, $payload['password']);
        if (!$allowed) {
            $this->recordAttempt($scope, false);
            AuditLog::record('telegram.staff_login_failed', 'user', $user['id'] ?? null, null, [
                'telegram_user_hash' => substr(hash('sha256', (string)$telegramUserId), 0, 16),
            ], [
                'user_id' => $user['id'] ?? null,
                'role' => $user['role'] ?? null,
                'result' => 'failure',
            ]);
            throw new AuthenticationException('Неверный логин или пароль');
        }
        $role = (string)$user['role'];
        if (!in_array($role, array_merge(self::WORKER_ROLES, self::PRIVILEGED_ROLES), true)) {
            $this->recordAttempt($scope, false);
            throw new AccessDeniedException('Для этой роли рабочий вход недоступен');
        }
        if (in_array($role, self::PRIVILEGED_ROLES, true)
            && (int)($user['telegram_user_id'] ?? 0) !== $telegramUserId) {
            $this->recordAttempt($scope, false);
            throw new AccessDeniedException(
                'Для менеджера, организатора и администратора используйте '
                . 'одноразовую ссылку из настроек аккаунта на сайте.'
            );
        }
        $owner = $this->db->prepare('SELECT id FROM users WHERE telegram_user_id=? AND id<>? LIMIT 1');
        $owner->execute([$telegramUserId, (int)$user['id']]);
        if ($owner->fetchColumn()) {
            $this->recordAttempt($scope, false);
            throw new ConflictException('Этот Telegram уже привязан к другому аккаунту');
        }
        $fields = [
            'telegram_user_id=?',
            'telegram_chat_id=?',
            'telegram_username=?',
            'telegram_linked_at=COALESCE(telegram_linked_at,NOW())',
        ];
        $values = [$telegramUserId, $telegramChatId, $username !== '' ? $username : null];
        if (dbColumnExists('users', 'telegram_status')) {
            $fields[] = "telegram_status='active'";
        }
        $values[] = (int)$user['id'];
        $this->db->prepare('UPDATE users SET ' . implode(',', $fields) . ' WHERE id=?')->execute($values);
        $this->recordAttempt($scope, true);
        AuditLog::record('telegram.staff_linked', 'user', (int)$user['id'], null, [
            'telegram_user_hash' => substr(hash('sha256', (string)$telegramUserId), 0, 16),
        ], [
            'user_id' => (int)$user['id'],
            'role' => $role,
        ]);
        return [
            'success' => true,
            'user' => [
                'id' => (int)$user['id'],
                'role' => $role,
                'first_name' => (string)$user['first_name'],
                'last_name' => (string)$user['last_name'],
            ],
        ];
    }
    private function findUser(string $login): ?array {
        $normalized = strtolower($login);
        $telegram = strtolower(ltrim($login, '@'));
        $stmt = $this->db->prepare(
            "SELECT * FROM users
             WHERE LOWER(COALESCE(phone,''))=?
                OR LOWER(COALESCE(email,''))=?
                OR LOWER(TRIM(LEADING '@' FROM COALESCE(telegram_username,'')))=?
             LIMIT 2"
        );
        $stmt->execute([$normalized, $normalized, $telegram]);
        $users = $stmt->fetchAll();
        return count($users) === 1 ? $users[0] : null;
    }
    private function assertRateLimit(string $scope): void {
        if (!dbTableExists('telegram_rate_limits')) {
            throw new ConfigurationException('Telegram CRM migration is not installed');
        }
        $stmt = $this->db->prepare(
            'SELECT attempts,window_started_at,blocked_until FROM telegram_rate_limits '
            . 'WHERE scope_key=? LIMIT 1'
        );
        $stmt->execute([$scope]);
        $limit = $stmt->fetch();
        if ($limit && !empty($limit['blocked_until']) && strtotime((string)$limit['blocked_until']) > time()) {
            throw new AuthenticationException('Слишком много попыток. Повторите вход позже.');
        }
    }
    private function recordAttempt(string $scope, bool $success): void {
        if ($success) {
            $this->db->prepare('DELETE FROM telegram_rate_limits WHERE scope_key=?')->execute([$scope]);
            return;
        }
        $this->db->prepare(
            "INSERT INTO telegram_rate_limits
                (scope_key,attempts,window_started_at,blocked_until)
             VALUES (?,1,NOW(),NULL)
             ON DUPLICATE KEY UPDATE
                attempts=IF(window_started_at<DATE_SUB(NOW(),INTERVAL " . self::WINDOW_MINUTES . " MINUTE),1,attempts+1),
                window_started_at=IF(window_started_at<DATE_SUB(NOW(),INTERVAL " . self::WINDOW_MINUTES . " MINUTE),NOW(),window_started_at),
                blocked_until=IF(
                    IF(window_started_at<DATE_SUB(NOW(),INTERVAL " . self::WINDOW_MINUTES . " MINUTE),1,attempts+1)>="
                    . self::MAX_ATTEMPTS . ",
                    DATE_ADD(NOW(),INTERVAL 30 MINUTE),
                    blocked_until
                )"
        )->execute([$scope]);
    }
}
