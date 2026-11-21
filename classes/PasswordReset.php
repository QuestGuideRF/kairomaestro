<?php
class PasswordReset {
    private const TOKEN_TTL_MINUTES = 45;
    private const REQUEST_MESSAGE = 'Если аккаунт найден, мы отправили инструкции по восстановлению пароля.';
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function request($identifier) {
        $startedAt = microtime(true);
        $identifier = trim((string)$identifier);
        $identifierHash = hash('sha256', utf8Lower($identifier));
        $ipHash = hash('sha256', clientIp() . '|' . (string)APP_KEY);
        try {
            if (!$this->schemaReady() || $identifier === '' || utf8Length($identifier) > 190) {
                $this->constantDelay($startedAt);
                return ['success' => true, 'message' => self::REQUEST_MESSAGE];
            }
            $limited = $this->rateLimited($identifierHash, $ipHash);
            $user = $limited ? null : $this->findUser($identifier);
            $canDeliver = $user && (int)($user['is_active'] ?? 1) === 1
                && !empty($user['telegram_chat_id'])
                && dbTableExists('notification_queue');
            $queued = false;
            if ($canDeliver) {
                $queued = $this->createAndQueue($user, $identifierHash, $ipHash);
            } else {
                $this->recordRequest($identifierHash, $ipHash, $user['id'] ?? null, false);
            }
            AuditLog::record('auth.password_reset_requested', 'user', $user['id'] ?? null, null, [
                'identifier_hash' => $identifierHash,
                'rate_limited' => $limited,
                'notification_queued' => $queued,
            ], ['user_id' => $user['id'] ?? null, 'result' => 'accepted']);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Password reset request failed: ' . $error->getMessage());
        }
        $this->constantDelay($startedAt);
        return ['success' => true, 'message' => self::REQUEST_MESSAGE];
    }
    public function inspect($token) {
        if (!$this->validTokenFormat($token) || !dbTableExists('password_reset_tokens')) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT token.id,token.user_id
             FROM password_reset_tokens token
             JOIN users user ON user.id=token.user_id
             WHERE token.token_hash=? AND token.used_at IS NULL
               AND token.expires_at>NOW() AND user.is_active=1
             LIMIT 1'
        );
        $stmt->execute([$this->tokenHash($token)]);
        return $stmt->fetch() ?: false;
    }
    public function complete($token, $password, $confirmation) {
        $error = $this->passwordError($token, $password, $confirmation);
        if ($error !== null) {
            return ['success' => false, 'error' => $error];
        }
        $record = null;
        $userId = null;
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                'SELECT token.id,token.user_id,user.role
                 FROM password_reset_tokens token
                 JOIN users user ON user.id=token.user_id
                 WHERE token.token_hash=? AND token.used_at IS NULL
                   AND token.expires_at>NOW() AND user.is_active=1
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$this->tokenHash($token)]);
            $record = $stmt->fetch();
            if (!$record) {
                throw new ValidationException('Ссылка устарела или уже была использована.');
            }
            $userId = (int)$record['user_id'];
            $this->db->prepare('UPDATE users SET password=? WHERE id=?')
                ->execute([password_hash((string)$password, PASSWORD_DEFAULT), $userId]);
            $this->db->prepare(
                'UPDATE password_reset_tokens SET used_at=NOW()
                 WHERE user_id=? AND used_at IS NULL'
            )->execute([$userId]);
            if (dbTableExists('user_sessions')) {
                $this->db->prepare(
                    'UPDATE user_sessions SET revoked_at=NOW()
                     WHERE user_id=? AND revoked_at IS NULL'
                )->execute([$userId]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'success' => false,
                'error' => publicExceptionMessage($error, 'Не удалось изменить пароль. Запросите новую ссылку.'),
            ];
        }
        try {
            AuditLog::record('auth.password_reset_completed', 'user', $userId, null, [
                'reset_id' => (int)$record['id'],
                'sessions_revoked' => true,
            ], ['user_id' => $userId, 'role' => $record['role'], 'result' => 'success']);
        } catch (Throwable $auditError) {
            error_log('Password reset audit failed after commit: ' . $auditError->getMessage());
        }
        try {
            $notification = (new TelegramNotifier())->notifyUser(
                $userId,
                'password_reset_completed',
                'Пароль аккаунта ' . SITE_NAME . ' изменён. Если это были не вы, свяжитесь с администратором.',
                'password-reset-completed-' . (int)$record['id'],
                10
            );
            if (empty($notification['success'])) {
                error_log('Password reset completion notification was not queued');
            }
        } catch (Throwable $notificationError) {
            error_log('Password reset completion notification failed: ' . $notificationError->getMessage());
        }
        return ['success' => true];
    }
    private function createAndQueue(array $user, $identifierHash, $ipHash) {
        $token = bin2hex(random_bytes(32));
        $this->db->beginTransaction();
        $this->db->prepare(
            'UPDATE password_reset_tokens SET used_at=NOW()
             WHERE user_id=? AND used_at IS NULL'
        )->execute([(int)$user['id']]);
        $stmt = $this->db->prepare(
            'INSERT INTO password_reset_tokens (user_id,token_hash,expires_at)
             VALUES (?,?,DATE_ADD(NOW(),INTERVAL ' . self::TOKEN_TTL_MINUTES . ' MINUTE))'
        );
        $stmt->execute([(int)$user['id'], $this->tokenHash($token)]);
        $resetId = (int)$this->db->lastInsertId();
        $url = appUrl('/reset-password?token=' . rawurlencode($token));
        $queue = (new NotificationQueue())->enqueue(
            'password_reset_requested',
            [
                'text' => 'Восстановление пароля ' . SITE_NAME . "\n\n"
                    . 'Откройте ссылку: ' . $url . "\n\n"
                    . 'Ссылка действует ' . self::TOKEN_TTL_MINUTES . ' минут и используется один раз.',
                'user_id' => (int)$user['id'],
            ],
            [
                'recipient_type' => 'user',
                'recipient_id' => (int)$user['id'],
                'chat_id' => (string)$user['telegram_chat_id'],
                'idempotency_key' => 'password-reset-' . $resetId,
                'priority' => 10,
            ]
        );
        if (empty($queue['success'])) {
            throw new NotificationException($queue['error'] ?? 'Не удалось поставить уведомление в очередь');
        }
        $this->recordRequest($identifierHash, $ipHash, (int)$user['id'], true);
        $this->db->commit();
        return true;
    }
    private function recordRequest($identifierHash, $ipHash, $userId, $queued) {
        if (!dbTableExists('password_reset_requests')) {
            return;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO password_reset_requests
                (identifier_hash,ip_hash,user_id,was_queued) VALUES (?,?,?,?)'
        );
        $stmt->execute([$identifierHash, $ipHash, $userId ?: null, $queued ? 1 : 0]);
    }
    private function rateLimited($identifierHash, $ipHash) {
        $stmt = $this->db->prepare(
            'SELECT
                SUM(identifier_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)) identifier_count,
                SUM(ip_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)) ip_count
             FROM password_reset_requests
             WHERE created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)'
        );
        $stmt->execute([$identifierHash, $ipHash]);
        $counts = $stmt->fetch();
        return (int)($counts['identifier_count'] ?? 0) >= 3
            || (int)($counts['ip_count'] ?? 0) >= 10;
    }
    private function findUser($identifier) {
        $telegram = utf8Lower(ltrim($identifier, '@'));
        $stmt = $this->db->prepare(
            "SELECT user.*
             FROM users user
             LEFT JOIN client_profiles profile ON profile.user_id=user.id
             WHERE user.phone=? OR user.email=?
                OR (user.role='client' AND LOWER(TRIM(LEADING '@' FROM profile.telegram_username))=?)
             LIMIT 2"
        );
        $stmt->execute([$identifier, $identifier, $telegram]);
        $rows = $stmt->fetchAll();
        return count($rows) === 1 ? $rows[0] : null;
    }
    private function passwordError($token, $password, $confirmation) {
        if (!$this->schemaReady() || !$this->validTokenFormat($token)) {
            return 'Ссылка для восстановления недействительна.';
        }
        if (!hash_equals((string)$password, (string)$confirmation)) {
            return 'Пароли не совпадают.';
        }
        $length = utf8Length((string)$password);
        return $length < 10 || $length > 1024
            ? 'Пароль должен содержать от 10 до 1024 символов.'
            : null;
    }
    private function schemaReady() {
        return dbTableExists('password_reset_tokens')
            && dbTableExists('password_reset_requests');
    }
    private function validTokenFormat($token) {
        return preg_match('/\A[a-f0-9]{64}\z/i', trim((string)$token)) === 1;
    }
    private function tokenHash($token) {
        return hash('sha256', utf8Lower(trim((string)$token)));
    }
    private function constantDelay($startedAt) {
        $elapsed = microtime(true) - $startedAt;
        $target = 0.35;
        if ($elapsed < $target) {
            usleep((int)(($target - $elapsed) * 1000000));
        }
    }
}
