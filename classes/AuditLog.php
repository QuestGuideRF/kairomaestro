<?php
class AuditLog {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public static function record($action, $entityType = null, $entityId = null, $oldValues = null, $newValues = null, $context = []) {
        try {
            if (!dbTableExists('audit_log')) {
                return false;
            }
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                INSERT INTO audit_log (
                    user_id,role,action,entity_type,entity_id,order_id,lead_id,
                    old_values,new_values,ip_address,user_agent,request_url,
                    http_method,result,metadata
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $userId = $context['user_id'] ?? ($_SESSION['user_id'] ?? null);
            $role = $context['role'] ?? ($_SESSION['user_role'] ?? null);
            $stmt->execute([
                $userId ?: null,
                $role ?: null,
                substr((string)$action, 0, 100),
                $entityType ? substr((string)$entityType, 0, 80) : null,
                $entityId ?: null,
                $context['order_id'] ?? null,
                $context['lead_id'] ?? null,
                self::encodeSafe($oldValues),
                self::encodeSafe($newValues),
                clientIp(),
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
                substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 500) ?: null,
                substr((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'), 0, 10),
                in_array($context['result'] ?? 'success', ['success','failure','denied'], true) ? ($context['result'] ?? 'success') : 'failure',
                self::encodeSafe($context['metadata'] ?? null),
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('Audit log failure: ' . $e->getMessage());
            return false;
        }
    }
    public function search($filters = [], $page = 1, $perPage = 50) {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['action'])) {
            $where[] = 'a.action=?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id=?';
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['entity_type'])) {
            $where[] = 'a.entity_type=?';
            $params[] = $filters['entity_type'];
        }
        if (!empty($filters['query'])) {
            $where[] = '(a.action LIKE ? OR a.request_url LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
            $term = '%' . trim($filters['query']) . '%';
            array_push($params, $term, $term, $term, $term);
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'a.created_at>=?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'a.created_at<=?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        $offset = max(0, ((int)$page - 1) * (int)$perPage);
        $limit = max(1, min(200, (int)$perPage));
        $sql = "SELECT a.*,u.first_name,u.last_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE "
            . implode(' AND ', $where) . " ORDER BY a.id DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function cleanupTechnical($days = 180) {
        $days = max(30, min(730, (int)$days));
        $stmt = $this->db->prepare("
            DELETE FROM audit_log
            WHERE created_at<DATE_SUB(NOW(),INTERVAL {$days} DAY)
              AND result='success'
              AND action IN ('notification.queued','notification.sent','telegram.link_token_created','auth.password_verified','quiz.draft_saved')
        ");
        $stmt->execute();
        self::record('audit.technical_cleanup', 'audit_log', null, null, ['deleted' => $stmt->rowCount(), 'older_than_days' => $days]);
        return $stmt->rowCount();
    }
    private static function encodeSafe($value) {
        $result = null;
        if ($value === null) {
            return $result;
        }
        $encoded = json_encode(self::sanitize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $result = strlen($encoded) > 65535
                ? json_encode([
                    'truncated' => true,
                    'sha256' => hash('sha256', $encoded),
                    'size' => strlen($encoded),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $encoded;
        }
        return $result;
    }
    private static function sanitize($value, $depth = 0) {
        if ($depth > 5) {
            return '[truncated]';
        }
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $normalized = strtolower((string)$key);
                if (preg_match('/password|pass|secret|token|csrf|cookie|authorization|session/', $normalized)) {
                    $sanitized[$key] = '[redacted]';
                    continue;
                }
                $sanitized[$key] = self::sanitize($item, $depth + 1);
            }
        } elseif (is_object($value)) {
            $sanitized = self::sanitize((array)$value, $depth + 1);
        } elseif (is_string($value) && strlen($value) > 4000) {
            $sanitized = substr($value, 0, 4000) . '[truncated]';
        } else {
            $sanitized = $value;
        }
        return $sanitized;
    }
}
