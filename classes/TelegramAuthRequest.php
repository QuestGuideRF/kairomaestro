<?php
final class TelegramAuthRequest {
    public const LOGIN = 'login';
    public const PASSWORD_RESET = 'password_reset';

    private const SESSION_KEY = 'telegram_auth_requests';
    private const EXPIRES_IN = 600;
    private const MAX_REQUESTS_PER_IP = 10;

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public static function normalizePurpose($purpose): ?string {
        $purpose = trim((string)$purpose);
        return in_array($purpose, [self::LOGIN, self::PASSWORD_RESET], true)
            ? $purpose
            : null;
    }

    public function start(string $purpose, bool $force = false): array {
        $purpose = self::normalizePurpose($purpose);
        $error = $this->configurationError($purpose);
        if ($error !== null) {
            $result = ['success' => false, 'error' => $error];
        } else {
            $state = $this->sessionState($purpose);
            $result = !$force && $this->stateIsReusable($state)
                ? $this->stateResult($state)
                : $this->createRequest($purpose);
        }
        return $result;
    }

    private function createRequest(string $purpose): array {
        $sessionHash = $this->sessionHash();
        $ipHash = $this->ipHash();
        if ($this->isRateLimited($ipHash)) {
            return [
                'success' => false,
                'error' => 'Слишком много запросов. Повторите попытку через 15 минут.',
            ];
        }
        $token = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);
        $prefix = $purpose === self::LOGIN ? 'login_' : 'reset_';
        $username = trim((string)env('TELEGRAM_BOT_USERNAME', ''), '@ ');
        $url = 'https://t.me/' . rawurlencode($username) . '?start=' . $prefix . $token;
        $manageTransaction = !$this->db->inTransaction();

        try {
            if ($manageTransaction) {
                $this->db->beginTransaction();
            }
            $this->db->prepare(
                'UPDATE telegram_auth_requests
                 SET consumed_at=NOW()
                 WHERE browser_session_hash=? AND purpose=?
                   AND consumed_at IS NULL'
            )->execute([$sessionHash, $purpose]);
            $stmt = $this->db->prepare(
                'INSERT INTO telegram_auth_requests
                    (token_hash, purpose, browser_session_hash, request_ip_hash, request_ip, expires_at)
                 VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
            );
            $stmt->execute([
                $tokenHash,
                $purpose,
                $sessionHash,
                $ipHash,
                clientIp(),
            ]);
            $requestId = (int)$this->db->lastInsertId();
            if ($manageTransaction) {
                $this->db->commit();
            }
        } catch (Exception $e) {
            if ($manageTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Telegram auth request creation failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Не удалось создать запрос Telegram. Повторите попытку позже.',
            ];
        }

        $state = [
            'id' => $requestId,
            'purpose' => $purpose,
            'url' => $url,
            'expires_at' => time() + self::EXPIRES_IN,
        ];
        $_SESSION[self::SESSION_KEY][$purpose] = $state;
        AuditLog::record(
            'auth.telegram_request_created',
            'telegram_auth_request',
            $requestId,
            null,
            ['purpose' => $purpose, 'expires_in' => self::EXPIRES_IN],
            ['result' => 'success']
        );
        return $this->stateResult($state);
    }

    public function current(string $purpose): array {
        $purpose = self::normalizePurpose($purpose);
        if ($purpose === null || !dbTableExists('telegram_auth_requests')) {
            $result = [
                'success' => false,
                'error' => 'Запрос Telegram недоступен.',
            ];
        } else {
            $state = $this->sessionState($purpose);
            if (!$this->stateIsReusable($state)) {
                $this->clearSessionState($purpose);
                $result = [
                    'success' => false,
                    'error' => 'Ссылка устарела. Создайте новую.',
                ];
            } else {
                $stmt = $this->db->prepare(
                    'SELECT approved_at, consumed_at, expires_at
                     FROM telegram_auth_requests
                     WHERE id=? AND purpose=? AND browser_session_hash=?
                     LIMIT 1'
                );
                $stmt->execute([
                    (int)$state['id'],
                    $purpose,
                    $this->sessionHash(),
                ]);
                $request = $stmt->fetch();
                $requestIsExpired = !$request
                    || !empty($request['consumed_at'])
                    || strtotime((string)$request['expires_at']) <= time();
                if ($requestIsExpired) {
                    $this->clearSessionState($purpose);
                    $result = [
                        'success' => false,
                        'error' => 'Ссылка устарела. Создайте новую.',
                    ];
                } else {
                    $result = array_merge(
                        $this->stateResult($state),
                        ['approved' => !empty($request['approved_at'])]
                    );
                }
            }
        }
        return $result;
    }

    public function consumeApproved(string $purpose): array {
        $purpose = self::normalizePurpose($purpose);
        $state = $purpose === null ? null : $this->sessionState($purpose);
        if ($purpose === null || !$this->stateIsReusable($state)) {
            return ['success' => false, 'error' => 'Запрос Telegram устарел.'];
        }

        $result = ['success' => true, 'approved' => false];
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                'SELECT *
                 FROM telegram_auth_requests
                 WHERE id=? AND purpose=? AND browser_session_hash=?
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([
                (int)$state['id'],
                $purpose,
                $this->sessionHash(),
            ]);
            $request = $stmt->fetch();
            if (!$request
                || !empty($request['consumed_at'])
                || strtotime((string)$request['expires_at']) <= time()) {
                $this->db->rollBack();
                $this->clearSessionState($purpose);
                $result = [
                    'success' => false,
                    'error' => 'Запрос Telegram устарел.',
                ];
            } elseif (empty($request['approved_at'])
                || empty($request['user_id'])) {
                $this->db->rollBack();
            } else {
                $stmt = $this->db->prepare(
                    'SELECT *
                     FROM users
                     WHERE id=? AND is_active=1
                       AND (locked_until IS NULL OR locked_until<=NOW())
                     LIMIT 1'
                );
                $stmt->execute([(int)$request['user_id']]);
                $user = $stmt->fetch();
                $this->db->prepare(
                    'UPDATE telegram_auth_requests
                     SET consumed_at=NOW()
                     WHERE id=? AND consumed_at IS NULL'
                )->execute([(int)$request['id']]);
                $this->db->commit();
                $this->clearSessionState($purpose);

                if (!$user) {
                    $result = [
                        'success' => false,
                        'error' => 'Вход для этого аккаунта недоступен.',
                    ];
                } else {
                    AuditLog::record(
                        'auth.telegram_request_consumed',
                        'telegram_auth_request',
                        (int)$request['id'],
                        null,
                        ['purpose' => $purpose],
                        [
                            'user_id' => (int)$user['id'],
                            'role' => (string)$user['role'],
                            'result' => 'success',
                        ]
                    );
                    $result = [
                        'success' => true,
                        'approved' => true,
                        'user' => $user,
                    ];
                }
            }
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Telegram auth request consumption failed: ' . $e->getMessage());
            $result = [
                'success' => false,
                'error' => 'Не удалось завершить подтверждение. Создайте новый запрос.',
            ];
        }
        return $result;
    }

    private function configurationError(?string $purpose): ?string {
        if ($purpose === null) {
            $error = 'Неизвестный тип подтверждения.';
        } elseif (!dbTableExists('telegram_auth_requests')) {
            $error = 'Функция временно недоступна: требуется обновление базы данных.';
        } elseif ($purpose === self::PASSWORD_RESET
            && !dbTableExists('login_challenges')) {
            $error = 'Сброс пароля временно недоступен.';
        } elseif (trim((string)env('TELEGRAM_BOT_USERNAME', ''), '@ ') === '') {
            $error = 'Telegram-бот не настроен.';
        } elseif (session_status() !== PHP_SESSION_ACTIVE
            || session_id() === '') {
            $error = 'Сессия браузера недоступна. Обновите страницу.';
        } else {
            $error = null;
        }
        return $error;
    }

    private function sessionState(string $purpose): ?array {
        $state = $_SESSION[self::SESSION_KEY][$purpose] ?? null;
        return is_array($state) ? $state : null;
    }

    private function stateIsReusable(?array $state): bool {
        return is_array($state)
            && (int)($state['id'] ?? 0) > 0
            && (int)($state['expires_at'] ?? 0) > time() + 5
            && !empty($state['url']);
    }

    private function stateResult(array $state): array {
        return [
            'success' => true,
            'url' => (string)$state['url'],
            'expires_at' => (int)$state['expires_at'],
        ];
    }

    private function clearSessionState(string $purpose): void {
        unset($_SESSION[self::SESSION_KEY][$purpose]);
        if (empty($_SESSION[self::SESSION_KEY])) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    private function sessionHash(): string {
        return hash('sha256', session_id());
    }

    private function ipHash(): string {
        $key = APP_KEY !== '' ? APP_KEY : __DIR__;
        return hash_hmac('sha256', clientIp(), $key);
    }

    private function isRateLimited(string $ipHash): bool {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM telegram_auth_requests
             WHERE request_ip_hash=?
               AND created_at>=DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $stmt->execute([$ipHash]);
        return (int)$stmt->fetchColumn() >= self::MAX_REQUESTS_PER_IP;
    }
}
