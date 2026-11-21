<?php
class LoginSecurity {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function checkAllowed($identifier, $user = null) {
        if (!dbTableExists('login_attempts')) {
            return ['allowed' => true, 'delay_ms' => 0];
        }
        $identifierHash = self::identifierHash($identifier);
        $ip = clientIp();
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM login_attempts failed
            WHERE failed.was_successful=0 AND failed.identifier_hash=?
              AND failed.created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
              AND failed.created_at > COALESCE((
                  SELECT MAX(ok.created_at) FROM login_attempts ok
                  WHERE ok.identifier_hash=failed.identifier_hash AND ok.was_successful=1
              ), '1970-01-01')
        ");
        $stmt->execute([$identifierHash]);
        $accountFailures = (int)$stmt->fetchColumn();
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM login_attempts WHERE was_successful=0 AND ip_address=? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute([$ip]);
        $ipFailures = (int)$stmt->fetchColumn();
        if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $result = ['allowed' => false, 'retry_at' => $user['locked_until'], 'delay_ms' => 0];
        } else {
            $strict = $user && in_array($user['role'] ?? '', ['admin', 'manager', 'organizer'], true);
            $accountLimit = $strict ? 5 : 7;
            $result = $accountFailures >= $accountLimit || $ipFailures >= 20
                ? ['allowed' => false, 'retry_at' => date('Y-m-d H:i:s', time() + 900), 'delay_ms' => 0]
                : ['allowed' => true, 'delay_ms' => min(1500, $accountFailures * 250)];
        }
        return $result;
    }
    public function recordAttempt($identifier, $user, $success, $reason = null) {
        if (!dbTableExists('login_attempts')) {
            return;
        }
        $stmt = $this->db->prepare("INSERT INTO login_attempts (identifier_hash, ip_address, user_id, was_successful, failure_reason) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            self::identifierHash($identifier),
            clientIp(),
            $user['id'] ?? null,
            $success ? 1 : 0,
            $reason ? substr($reason, 0, 120) : null,
        ]);
        if (!$success && $user) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM login_attempts failed
                WHERE failed.user_id=? AND failed.was_successful=0
                  AND failed.created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                  AND failed.created_at > COALESCE((
                      SELECT MAX(ok.created_at) FROM login_attempts ok
                      WHERE ok.user_id=failed.user_id AND ok.was_successful=1
                  ), '1970-01-01')
            ");
            $stmt->execute([(int)$user['id']]);
            $failures = (int)$stmt->fetchColumn();
            $limit = in_array($user['role'], ['admin', 'manager', 'organizer'], true) ? 5 : 7;
            if ($failures >= $limit && dbColumnExists('users', 'locked_until')) {
                $minutes = min(60, 10 * max(1, $failures - $limit + 1));
                $minutes = max(1, min(60, (int)$minutes));
                $stmt = $this->db->prepare("UPDATE users SET locked_until=DATE_ADD(NOW(), INTERVAL {$minutes} MINUTE) WHERE id=?");
                $stmt->execute([(int)$user['id']]);
                AuditLog::record('auth.account_temporarily_locked', 'user', (int)$user['id'], null, ['minutes' => $minutes], [
                    'user_id' => (int)$user['id'], 'role' => $user['role'],
                ]);
            }
        }
        AuditLog::record($success ? 'auth.password_verified' : 'auth.login_failed', 'user', $user['id'] ?? null, null, [
            'identifier_hash' => self::identifierHash($identifier),
            'reason' => $reason,
        ], [
            'user_id' => $user['id'] ?? null,
            'role' => $user['role'] ?? null,
            'result' => $success ? 'success' : 'failure',
        ]);
    }
    public function needsTelegramChallenge($user) {
        return in_array($user['role'] ?? '', ['admin', 'manager', 'organizer'], true)
            && !empty($user['telegram_chat_id'])
            && dbTableExists('login_challenges')
            && dbTableExists('notification_queue');
    }
    public function createTelegramChallenge($user) {
        $code = (string)random_int(100000, 999999);
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $this->db->prepare("UPDATE login_challenges SET used_at=NOW() WHERE user_id=? AND purpose='login' AND used_at IS NULL")
            ->execute([(int)$user['id']]);
        $stmt = $this->db->prepare("INSERT INTO login_challenges (user_id, purpose, code_hash, expires_at) VALUES (?, 'login', ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
        $stmt->execute([(int)$user['id'], $hash]);
        $challengeId = (int)$this->db->lastInsertId();
        $_SESSION['pending_login_challenge_id'] = $challengeId;
        $_SESSION['pending_login_user_id'] = (int)$user['id'];
        $queued = (new NotificationQueue())->enqueue('privileged_login_code', [
            'text' => "Код подтверждения входа:\n\n<code>{$code}</code>\n\n"
                . "Нажмите на код, чтобы скопировать.\n"
                . "Код действует 10 минут. Если это не вы, смените пароль.",
            'copy_text' => $code,
            'user_id' => (int)$user['id'],
        ], [
            'recipient_type' => 'user',
            'recipient_id' => (int)$user['id'],
            'chat_id' => (string)$user['telegram_chat_id'],
            'idempotency_key' => 'login-challenge-' . $challengeId,
            'priority' => 10,
        ]);
        if (!$queued['success']) {
            $this->db->prepare("UPDATE login_challenges SET used_at=NOW() WHERE id=?")->execute([$challengeId]);
            unset($_SESSION['pending_login_challenge_id'], $_SESSION['pending_login_user_id']);
            AuditLog::record('auth.challenge_queue_failed', 'user', (int)$user['id'], null, null, [
                'user_id' => (int)$user['id'], 'role' => $user['role'], 'result' => 'failure',
            ]);
            return ['success' => false, 'error' => 'Не удалось отправить код подтверждения. Повторите попытку позже.'];
        }
        AuditLog::record('auth.challenge_created', 'user', (int)$user['id'], null, ['challenge_id' => $challengeId], [
            'user_id' => (int)$user['id'], 'role' => $user['role'],
        ]);
        return ['success' => true, 'requires_2fa' => true];
    }
    public function verifyTelegramChallenge($code) {
        $challengeId = (int)($_SESSION['pending_login_challenge_id'] ?? 0);
        $userId = (int)($_SESSION['pending_login_user_id'] ?? 0);
        if (!$challengeId || !$userId) {
            return ['success' => false, 'error' => 'Подтверждение входа устарело. Войдите заново.'];
        }
        $stmt = $this->db->prepare("SELECT * FROM login_challenges WHERE id=? AND user_id=? AND purpose='login' AND used_at IS NULL AND expires_at>NOW() LIMIT 1");
        $stmt->execute([$challengeId, $userId]);
        $challenge = $stmt->fetch();
        if (!$challenge || (int)$challenge['attempts'] >= 5) {
            $result = ['success' => false, 'error' => 'Код устарел или исчерпано число попыток.'];
        } elseif (!password_verify(trim((string)$code), (string)$challenge['code_hash'])) {
            $this->db->prepare("UPDATE login_challenges SET attempts=attempts+1 WHERE id=?")->execute([$challengeId]);
            AuditLog::record('auth.challenge_failed', 'user', $userId, null, ['challenge_id' => $challengeId], [
                'user_id' => $userId, 'result' => 'failure',
            ]);
            $result = ['success' => false, 'error' => 'Неверный или устаревший код.'];
        } else {
            $this->db->prepare("UPDATE login_challenges SET used_at=NOW() WHERE id=?")->execute([$challengeId]);
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            unset($_SESSION['pending_login_challenge_id'], $_SESSION['pending_login_user_id']);
            if (!$user || (int)($user['is_active'] ?? 1) !== 1) {
                $result = ['success' => false, 'error' => 'Вход недоступен.'];
            } else {
                $this->completeLogin($user);
                $result = ['success' => true, 'user' => $user];
            }
        }
        return $result;
    }
    public function completeLogin($user) {
        $currentIp = clientIp();
        $newAddress = false;
        $hasFailureBurst = false;
        $latestFailedAttemptId = 0;
        if (dbTableExists('user_sessions')) {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) AS total_sessions,
                    SUM(CASE WHEN ip_address=? THEN 1 ELSE 0 END) AS matching_sessions
                FROM user_sessions
                WHERE user_id=?
            ");
            $stmt->execute([$currentIp, (int)$user['id']]);
            $sessionStats = $stmt->fetch();
            $newAddress = (int)($sessionStats['total_sessions'] ?? 0) > 0
                && (int)($sessionStats['matching_sessions'] ?? 0) === 0;
        }
        if (dbTableExists('login_attempts')) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) AS failure_count, COALESCE(MAX(id), 0) AS latest_failure_id
                FROM login_attempts
                WHERE user_id=? AND was_successful=0
                  AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)
            ");
            $stmt->execute([(int)$user['id']]);
            $failureStats = $stmt->fetch();
            $hasFailureBurst = (int)($failureStats['failure_count'] ?? 0) >= 3;
            $latestFailedAttemptId = (int)($failureStats['latest_failure_id'] ?? 0);
        }
        $suspicious = $newAddress || $hasFailureBurst;
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
        $_SESSION['last_activity_at'] = time();
        $_SESSION['session_touch_at'] = 0;
        if (dbTableExists('user_sessions')) {
            $sessionHash = hash('sha256', session_id());
            $stmt = $this->db->prepare("INSERT INTO user_sessions (user_id, session_hash, ip_address, user_agent, last_activity_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE revoked_at=NULL, last_activity_at=NOW()");
            $stmt->execute([(int)$user['id'], $sessionHash, clientIp(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]);
        }
        if (dbColumnExists('users', 'locked_until')) {
            $this->db->prepare("UPDATE users SET last_login=NOW(), locked_until=NULL WHERE id=?")->execute([(int)$user['id']]);
        } else {
            $this->db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([(int)$user['id']]);
        }
        AuditLog::record('auth.login_success', 'user', (int)$user['id'], null, ['role' => $user['role']], [
            'user_id' => (int)$user['id'], 'role' => $user['role'],
        ]);
        if ($suspicious && !empty($user['telegram_chat_id'])) {
            $message = 'Зафиксирован вход в аккаунт ' . SITE_NAME . ' с нового адреса или после серии неудачных попыток.'
                . "\nIP: " . $currentIp
                . "\nВремя: " . date('d.m.Y H:i')
                . "\nЕсли это были не вы, смените пароль и завершите другие сессии.";
            $incidentKey = $hasFailureBurst && $latestFailedAttemptId > 0
                ? 'failures-' . $latestFailedAttemptId
                : 'address-' . substr(hash('sha256', $currentIp), 0, 24);
            $queueResult = (new NotificationQueue())->enqueue(
                'suspicious_login',
                ['text' => $message, 'user_id' => (int)$user['id']],
                [
                    'recipient_type' => 'user',
                    'recipient_id' => (int)$user['id'],
                    'chat_id' => (string)$user['telegram_chat_id'],
                    'idempotency_key' => 'suspicious-login-' . (int)$user['id'] . '-' . $incidentKey,
                    'priority' => 5,
                ]
            );
            AuditLog::record('auth.suspicious_login_detected', 'user', (int)$user['id'], null, [
                'new_address' => $newAddress,
                'failure_burst' => $hasFailureBurst,
                'notification_queued' => !empty($queueResult['queued']),
            ], ['user_id' => (int)$user['id'], 'role' => $user['role']]);
        }
    }
    public static function validateCurrentSession() {
        if (empty($_SESSION['user_id'])) {
            return;
        }
        $now = time();
        if (self::idleSessionExpired($now)) {
            AuditLog::record('auth.session_expired', 'user', (int)$_SESSION['user_id']);
            self::destroySession();
            return;
        }
        $_SESSION['last_activity_at'] = $now;
        if (self::sessionNeedsDatabaseTouch($now)) {
            self::touchCurrentSession($now);
        }
    }
    private static function idleSessionExpired($now) {
        $idleLimit = max(300, (int)env('SESSION_IDLE_TIMEOUT', 7200));
        return !empty($_SESSION['last_activity_at'])
            && $now - (int)$_SESSION['last_activity_at'] > $idleLimit;
    }
    private static function sessionNeedsDatabaseTouch($now) {
        return dbTableExists('user_sessions')
            && $now - (int)($_SESSION['session_touch_at'] ?? 0) >= 300;
    }
    private static function touchCurrentSession($now) {
        $db = Database::getInstance()->getConnection();
        $sessionHash = hash('sha256', session_id());
        $activeSelect = dbColumnExists('users', 'is_active') ? 'u.is_active' : '1 AS is_active';
        $stmt = $db->prepare("
            SELECT s.revoked_at,{$activeSelect},u.role
            FROM user_sessions s
            JOIN users u ON u.id=s.user_id
            WHERE s.session_hash=? AND s.user_id=?
            LIMIT 1
        ");
        $stmt->execute([$sessionHash, (int)$_SESSION['user_id']]);
        $record = $stmt->fetch();
        if (self::sessionRecordInvalid($record)) {
            self::destroySession();
        } else {
            $db->prepare('UPDATE user_sessions SET last_activity_at=NOW() WHERE session_hash=?')->execute([$sessionHash]);
            $_SESSION['session_touch_at'] = $now;
        }
    }
    private static function sessionRecordInvalid($record) {
        return !$record
            || $record['revoked_at']
            || (int)$record['is_active'] !== 1
            || $record['role'] !== currentRole();
    }
    public function revokeOtherSessions($userId) {
        if (!dbTableExists('user_sessions')) {
            return 0;
        }
        $stmt = $this->db->prepare("UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND session_hash<>? AND revoked_at IS NULL");
        $stmt->execute([(int)$userId, hash('sha256', session_id())]);
        AuditLog::record('auth.other_sessions_revoked', 'user', (int)$userId, null, ['count' => $stmt->rowCount()]);
        return $stmt->rowCount();
    }
    public static function destroySession() {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
    private static function identifierHash($identifier) {
        return hash('sha256', strtolower(trim((string)$identifier)));
    }
}
