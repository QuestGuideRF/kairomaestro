<?php
class PasswordSetup {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function issue($userId) {
        $userId = (int)$userId;
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        unset($_SESSION['password_setup_challenge']);
        if (!dbTableExists('login_challenges')) {
            $this->storeSessionChallenge($userId, $tokenHash);
            return $token;
        }
        try {
            $this->db->prepare("
                UPDATE login_challenges
                SET used_at=NOW()
                WHERE user_id=? AND purpose='password_setup' AND used_at IS NULL
            ")->execute([$userId]);
            $stmt = $this->db->prepare("
                INSERT INTO login_challenges (user_id, purpose, code_hash, expires_at)
                VALUES (?, 'password_setup', ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
            ");
            $stmt->execute([$userId, $tokenHash]);
            AuditLog::record('auth.password_setup_created', 'user', $userId, null, [
                'challenge_id' => (int)$this->db->lastInsertId(),
                'expires_in_hours' => 24,
            ], ['user_id' => $userId, 'role' => 'client']);
        } catch (Exception $e) {
            error_log('Password setup DB challenge unavailable: ' . $e->getMessage());
            $this->storeSessionChallenge($userId, $tokenHash);
        }
        return $token;
    }
    public function complete($token, $password, $confirmation) {
        $error = $this->validationError($token, $password, $confirmation);
        if ($error !== null) {
            $result = ['success' => false, 'error' => $error];
        } else {
            $tokenHash = hash('sha256', strtolower(trim((string)$token)));
            $result = !dbTableExists('login_challenges')
                || isset($_SESSION['password_setup_challenge'])
                ? $this->completeSessionChallenge($tokenHash, $password)
                : $this->completeDatabaseChallenge($tokenHash, $password);
        }
        return $result;
    }
    private function completeDatabaseChallenge($tokenHash, $password) {
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("
                SELECT challenge.id, challenge.user_id, users.role
                FROM login_challenges challenge
                JOIN users ON users.id=challenge.user_id
                WHERE challenge.purpose='password_setup'
                  AND challenge.code_hash=?
                  AND challenge.used_at IS NULL
                  AND challenge.expires_at>NOW()
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$tokenHash]);
            $challenge = $stmt->fetch();
            if (!$challenge) {
                throw new ValidationException('Ссылка устарела или уже была использована.');
            }
            $userId = (int)$challenge['user_id'];
            $this->db->prepare('UPDATE users SET password=? WHERE id=?')
                ->execute([password_hash((string)$password, PASSWORD_DEFAULT), $userId]);
            $this->db->prepare('UPDATE login_challenges SET used_at=NOW() WHERE id=? AND used_at IS NULL')
                ->execute([(int)$challenge['id']]);
            $this->revokeOtherSessions($userId);
            $this->db->commit();
            AuditLog::record('auth.password_setup_completed', 'user', $userId, null, [
                'challenge_id' => (int)$challenge['id'],
                'other_sessions_revoked' => true,
            ], ['user_id' => $userId, 'role' => $challenge['role']]);
            return ['success' => true];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'success' => false,
                'error' => publicExceptionMessage($e, 'Не удалось установить пароль. Запросите новую ссылку.'),
            ];
        }
    }
    private function validationError($token, $password, $confirmation) {
        if (!preg_match('/\A[a-f0-9]{64}\z/i', trim((string)$token))) {
            $error = 'Ссылка для установки пароля недействительна.';
        } elseif (!hash_equals((string)$password, (string)$confirmation)) {
            $error = 'Пароли не совпадают.';
        } else {
            $length = utf8Length((string)$password);
            $error = $length < 10 || $length > 1024
                ? 'Пароль должен содержать от 10 до 1024 символов.'
                : null;
        }
        return $error;
    }
    private function storeSessionChallenge($userId, $tokenHash) {
        $_SESSION['password_setup_challenge'] = [
            'user_id' => (int)$userId,
            'token_hash' => $tokenHash,
            'expires_at' => time() + 86400,
        ];
        AuditLog::record('auth.password_setup_created', 'user', (int)$userId, null, [
            'storage' => 'session',
            'expires_in_hours' => 24,
        ], ['user_id' => (int)$userId, 'role' => 'client']);
    }
    private function completeSessionChallenge($tokenHash, $password) {
        $challenge = $_SESSION['password_setup_challenge'] ?? null;
        $valid = is_array($challenge)
            && (int)($challenge['user_id'] ?? 0) === (int)($_SESSION['user_id'] ?? 0)
            && (int)($challenge['expires_at'] ?? 0) >= time()
            && hash_equals((string)($challenge['token_hash'] ?? ''), $tokenHash);
        if (!$valid) {
            return ['success' => false, 'error' => 'Ссылка устарела или уже была использована.'];
        }
        $userId = (int)$challenge['user_id'];
        $this->db->prepare('UPDATE users SET password=? WHERE id=?')
            ->execute([password_hash((string)$password, PASSWORD_DEFAULT), $userId]);
        unset($_SESSION['password_setup_challenge']);
        $this->revokeOtherSessions($userId);
        AuditLog::record('auth.password_setup_completed', 'user', $userId, null, [
            'storage' => 'session',
            'other_sessions_revoked' => true,
        ], ['user_id' => $userId, 'role' => 'client']);
        return ['success' => true];
    }
    private function revokeOtherSessions($userId) {
        if (!dbTableExists('user_sessions')) {
            return;
        }
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        if ($currentUserId === (int)$userId && session_status() === PHP_SESSION_ACTIVE) {
            $stmt = $this->db->prepare("
                UPDATE user_sessions
                SET revoked_at=NOW()
                WHERE user_id=? AND session_hash<>? AND revoked_at IS NULL
            ");
            $stmt->execute([(int)$userId, hash('sha256', session_id())]);
            return;
        }
        $this->db->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL')
            ->execute([(int)$userId]);
    }
}
