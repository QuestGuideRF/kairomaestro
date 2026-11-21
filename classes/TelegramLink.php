<?php
class TelegramLink {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function create($userId) {
        if ((int)($_SESSION['user_id'] ?? 0) !== (int)$userId) {
            $error = 'Недостаточно прав для привязки Telegram';
        } elseif (!dbTableExists('telegram_link_tokens')) {
            $error = 'Миграция Telegram-привязки не установлена';
        } else {
            $error = null;
        }
        $username = trim((string)env('TELEGRAM_BOT_USERNAME', ''), '@ ');
        if ($error === null && $username === '') {
            $error = 'TELEGRAM_BOT_USERNAME не настроен';
        }
        if ($error !== null) {
            $result = ['success' => false, 'error' => $error];
        } else {
            $this->db->prepare("UPDATE telegram_link_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL")
                ->execute([(int)$userId]);
            $token = bin2hex(random_bytes(24));
            $stmt = $this->db->prepare("INSERT INTO telegram_link_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
            $stmt->execute([(int)$userId, hash('sha256', $token)]);
            AuditLog::record('telegram.link_token_created', 'user', (int)$userId);
            $result = [
                'success' => true,
                'url' => 'https://t.me/' . rawurlencode($username) . '?start=link_' . $token,
                'expires_in' => 900,
            ];
        }
        return $result;
    }
    public function unlink($userId) {
        if ((int)($_SESSION['user_id'] ?? 0) !== (int)$userId) {
            return ['success' => false, 'error' => 'Недостаточно прав для отвязки Telegram'];
        }
        $stmt = $this->db->prepare("UPDATE users SET telegram_user_id=NULL, telegram_chat_id=NULL, telegram_username=NULL, telegram_linked_at=NULL WHERE id=?");
        $stmt->execute([(int)$userId]);
        $this->db->prepare("UPDATE telegram_link_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL")->execute([(int)$userId]);
        AuditLog::record('telegram.unlinked', 'user', (int)$userId);
        return ['success' => true];
    }
}
