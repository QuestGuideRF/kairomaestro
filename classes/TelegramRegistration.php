<?php
class TelegramRegistration {
    private const SESSION_KEY = 'telegram_registration_link';
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function isLinked(int $userId): bool {
        $stmt = $this->db->prepare(
            'SELECT telegram_user_id FROM users WHERE id=? AND is_active=1 LIMIT 1'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function createLink(int $userId, string $nextPath, bool $force = false): array {
        $nextPath = $this->safeNextPath(
            $nextPath,
            dashboardPathForRole(currentRole())
        );
        $_SESSION['telegram_confirmation_next'] = $nextPath;
        if ($this->isLinked($userId)) {
            return ['success' => true, 'linked' => true, 'next' => $nextPath];
        }
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        $reusable = !$force
            && is_array($state)
            && (int)($state['user_id'] ?? 0) === $userId
            && (int)($state['expires_at'] ?? 0) > time() + 15
            && !empty($state['url']);
        if ($reusable) {
            return [
                'success' => true,
                'linked' => false,
                'url' => (string)$state['url'],
                'expires_at' => (int)$state['expires_at'],
            ];
        }
        $result = (new TelegramLink())->create($userId);
        if (!empty($result['success'])) {
            $_SESSION[self::SESSION_KEY] = [
                'user_id' => $userId,
                'url' => (string)$result['url'],
                'expires_at' => time() + (int)($result['expires_in'] ?? 900),
            ];
        }
        return $result;
    }

    public function complete(int $userId): string {
        if (!$this->isLinked($userId)) {
            throw new ConflictException('Telegram ещё не подтверждён');
        }
        if (dbColumnExists('users', 'telegram_status')) {
            $this->db->prepare(
                "UPDATE users SET telegram_status='active'
                 WHERE id=? AND telegram_user_id IS NOT NULL"
            )->execute([$userId]);
        }
        $nextPath = $this->safeNextPath(
            (string)($_SESSION['telegram_confirmation_next'] ?? ''),
            dashboardPathForRole(currentRole())
        );
        unset(
            $_SESSION[self::SESSION_KEY],
            $_SESSION['telegram_confirmation_next']
        );
        AuditLog::record(
            'telegram.registration_confirmed',
            'user',
            $userId,
            null,
            ['next' => $nextPath],
            ['user_id' => $userId, 'role' => currentRole()]
        );
        return $nextPath;
    }

    private function safeNextPath(string $path, string $fallback): string {
        $parts = parse_url($path);
        $valid = $path !== ''
            && str_starts_with($path, '/')
            && !str_starts_with($path, '//')
            && is_array($parts)
            && empty($parts['scheme'])
            && empty($parts['host']);
        return $valid ? $path : $fallback;
    }
}
