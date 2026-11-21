<?php
class QuizDraft {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function save($payload, $token = null) {
        if (!dbTableExists('quiz_drafts')) {
            return ['success' => false, 'error' => 'Миграция черновиков не установлена'];
        }
        unset($payload['password'], $payload['csrf_token'], $payload['submission_key']);
        $payload = $this->filterPayload($payload);
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false || strlen($encodedPayload) > 50000) {
            return ['success' => false, 'error' => 'Черновик слишком большой'];
        }
        $ttlDays = max(1, min(30, (int)env('QUIZ_DRAFT_TTL_DAYS', 7)));
        $sessionKey = hash('sha256', session_id());
        if ($token && preg_match('/^[a-f0-9]{64}$/', $token)) {
            $tokenHash = hash('sha256', $token);
            $stmt = $this->db->prepare("UPDATE quiz_drafts SET payload_json=?, expires_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE token_hash=? AND submitted_at IS NULL AND expires_at>NOW()");
            $stmt->execute([$encodedPayload, $ttlDays, $tokenHash]);
            if ($stmt->rowCount() > 0) {
                $result = ['success' => true, 'token' => $token];
            }
        }
        if (!isset($result)) {
            $token = bin2hex(random_bytes(32));
            $stmt = $this->db->prepare("INSERT INTO quiz_drafts (token_hash, session_key, user_id, payload_json, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))");
            $stmt->execute([
                hash('sha256', $token),
                $sessionKey,
                $_SESSION['user_id'] ?? null,
                $encodedPayload,
                $ttlDays,
            ]);
            $result = ['success' => true, 'token' => $token];
        }
        return $result;
    }
    public function load($token) {
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', (string)$token) || !dbTableExists('quiz_drafts')) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM quiz_drafts WHERE token_hash=? AND submitted_at IS NULL AND expires_at>NOW() LIMIT 1");
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $payload = json_decode($row['payload_json'], true);
        return is_array($payload) ? $payload : [];
    }
    public function markSubmitted($token) {
        if ($token && dbTableExists('quiz_drafts')) {
            $this->db->prepare("UPDATE quiz_drafts SET submitted_at=NOW() WHERE token_hash=? AND submitted_at IS NULL")
                ->execute([hash('sha256', $token)]);
        }
    }
    public function cleanupExpired() {
        if (!dbTableExists('quiz_drafts')) {
            return 0;
        }
        $stmt = $this->db->prepare("DELETE FROM quiz_drafts WHERE expires_at<NOW() OR (submitted_at IS NOT NULL AND submitted_at<DATE_SUB(NOW(), INTERVAL 30 DAY))");
        $stmt->execute();
        return $stmt->rowCount();
    }
    private function filterPayload($payload) {
        return QuizBrief::filterDraft(is_array($payload) ? $payload : []);
    }
}
