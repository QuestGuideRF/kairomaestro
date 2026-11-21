<?php
class UserAuthenticationService {
    private const INVALID_CREDENTIALS = 'Неверный телефон, email, Telegram или пароль';
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function login($identifier, $password) {
        $login = trim((string)$identifier);
        $security = new LoginSecurity();
        if ($this->invalidInput($login, $password)) {
            usleep(250000);
            $security->recordAttempt($login, null, false, 'invalid_input');
            $result = ['success' => false, 'error' => self::INVALID_CREDENTIALS];
        } else {
            $user = $this->findUser($login);
            $gate = $security->checkAllowed($login, $user ?: null);
            $this->applyDelay($gate);
            $result = $gate['allowed']
                ? $this->authenticate($security, $login, (string)$password, $user)
                : $this->rateLimitedResult($login, $user);
        }
        return $result;
    }
    private function invalidInput($login, $password) {
        return utf8Length((string)$login) > 190 || utf8Length((string)$password) > 1024;
    }
    private function findUser($login) {
        $telegramLogin = strtolower(ltrim($login, '@'));
        $hasEmail = dbColumnExists('users', 'email');
        $hasClientProfiles = dbTableExists('client_profiles');
        if ($hasEmail && $hasClientProfiles) {
            $stmt = $this->db->prepare("
                SELECT users.*
                FROM users
                LEFT JOIN client_profiles profile ON profile.user_id=users.id
                WHERE users.phone=?
                   OR users.email=?
                   OR (
                       users.role='client'
                       AND LOWER(TRIM(LEADING '@' FROM profile.telegram_username))=?
                   )
                LIMIT 2
            ");
            $stmt->execute([$login, $login, $telegramLogin]);
        } elseif ($hasEmail) {
            $stmt = $this->db->prepare('SELECT * FROM users WHERE phone=? OR email=? LIMIT 2');
            $stmt->execute([$login, $login]);
        } elseif ($hasClientProfiles) {
            $stmt = $this->db->prepare("
                SELECT users.*
                FROM users
                LEFT JOIN client_profiles profile ON profile.user_id=users.id
                WHERE users.phone=?
                   OR (
                       users.role='client'
                       AND LOWER(TRIM(LEADING '@' FROM profile.telegram_username))=?
                   )
                LIMIT 2
            ");
            $stmt->execute([$login, $telegramLogin]);
        } else {
            $stmt = $this->db->prepare('SELECT * FROM users WHERE phone=? LIMIT 2');
            $stmt->execute([$login]);
        }
        $matches = $stmt->fetchAll();
        return count($matches) === 1 ? $matches[0] : false;
    }
    private function applyDelay($gate) {
        $delay = max(0, (int)($gate['delay_ms'] ?? 0));
        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }
    private function authenticate($security, $login, $password, $user) {
        $verifier = $user && !empty($user['password'])
            ? (string)$user['password']
            : AuthenticationTimingGuard::verifier();
        $verified = password_verify($password, $verifier) && $this->accountIsAvailable($user);
        if ($verified) {
            $security->recordAttempt($login, $user, true);
            $result = $this->completeLogin($security, $user);
        } else {
            $security->recordAttempt($login, $user ?: null, false, $user ? 'invalid_credentials' : 'unknown_account');
            $result = ['success' => false, 'error' => self::INVALID_CREDENTIALS];
        }
        return $result;
    }
    private function accountIsAvailable($user) {
        return $user
            && (int)($user['is_active'] ?? 1) === 1
            && (empty($user['locked_until']) || strtotime($user['locked_until']) <= time());
    }
    private function completeLogin($security, $user) {
        if ($security->needsTelegramChallenge($user)) {
            session_regenerate_id(true);
            $result = array_merge($security->createTelegramChallenge($user), ['user' => $user]);
        } else {
            $security->completeLogin($user);
            $result = ['success' => true, 'user' => $user];
        }
        return $result;
    }
    private function rateLimitedResult($login, $user) {
        AuditLog::record('auth.login_rate_limited', 'user', $user['id'] ?? null, null, [
            'identifier_hash' => hash('sha256', strtolower($login)),
        ], [
            'user_id' => $user['id'] ?? null,
            'role' => $user['role'] ?? null,
            'result' => 'denied',
        ]);
        return ['success' => false, 'error' => self::INVALID_CREDENTIALS . '. Повторите попытку позже.'];
    }
}
