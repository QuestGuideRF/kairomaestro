<?php
class AdminRepository {
    use AdminTelegramRepository;
    use AdminUserRepository;
    use AdminSettingsRepository;
    private const USER_ROLES = [
        'client', 'manager', 'organizer', 'florist',
        'courier', 'installer', 'helper', 'worker', 'admin',
    ];
    private PDO $db;
    public function __construct() {
        if (!isAdminRole()) {
            throw new AccessDeniedException('Раздел доступен только администратору');
        }
        $this->db = Database::getInstance()->getConnection();
    }
    public function dashboard(): array {
        return [
            'users' => $this->count('users'),
            'active_users' => $this->count('users', 'is_active=1'),
            'new_leads' => $this->count('leads', "status='new'"),
            'active_orders' => $this->count(
                'orders',
                "status NOT IN ('completed','cancelled')"
            ),
            'new_candidates' => $this->count('candidate_applications', "status='new'"),
            'queue_pending' => $this->count(
                'notification_queue',
                "status IN ('pending','retry','processing')"
            ),
            'queue_failed' => $this->count('notification_queue', "status='failed'"),
            'pool_available_client' => $this->count(
                'telegram_group_pool',
                "kind='client' AND status='available'"
            ),
            'pool_available_team' => $this->count(
                'telegram_group_pool',
                "kind='team' AND status='available'"
            ),
        ];
    }
    public function users(?string $role = null, int $limit = 300): array {
        $params = [];
        $where = '';
        if ($role && in_array($role, self::USER_ROLES, true)) {
            $where = ' WHERE role=?';
            $params[] = $role;
        }
        $stmt = $this->db->prepare(
            'SELECT id,first_name,last_name,phone,email,role,is_active,locked_until,'
            . 'telegram_user_id,telegram_username,telegram_status,created_at,last_login '
            . 'FROM users' . $where . ' ORDER BY id DESC LIMIT '
            . max(1, min(500, $limit))
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function leads(int $limit = 300): array {
        $stmt = $this->db->query(
            'SELECT l.id,l.client_name,l.event_type,l.event_date,l.status,l.source,'
            . 'l.created_at,l.order_id,u.first_name manager_first_name,'
            . 'u.last_name manager_last_name '
            . 'FROM leads l LEFT JOIN users u ON u.id=l.manager_id '
            . 'ORDER BY l.id DESC LIMIT ' . max(1, min(500, $limit))
        );
        return $stmt->fetchAll();
    }
    public function orders(int $limit = 300): array {
        $stmt = $this->db->query(
            'SELECT o.id,o.title,o.event_date,o.status,o.venue,o.address,o.created_at,'
            . 'o.organizer_id,'
            . 'c.first_name client_first_name,c.last_name client_last_name,'
            . 'g.first_name organizer_first_name,g.last_name organizer_last_name '
            . 'FROM orders o JOIN users c ON c.id=o.client_id '
            . 'LEFT JOIN users g ON g.id=o.organizer_id '
            . 'ORDER BY o.id DESC LIMIT ' . max(1, min(500, $limit))
        );
        return $stmt->fetchAll();
    }
    public function activeOrganizers(): array {
        $stmt = $this->db->query(
            "SELECT id,first_name,last_name
             FROM users
             WHERE role='organizer' AND is_active=1
             ORDER BY first_name,last_name,id"
        );
        return $stmt->fetchAll();
    }
    public function replaceOrderOrganizer(
        int $orderId,
        int $organizerId,
        int $actorId
    ): array {
        return (new Order())->replaceOrganizer($orderId, $organizerId, $actorId);
    }
    public function candidates(int $limit = 300): array {
        if (!dbTableExists('candidate_applications')) {
            return [];
        }
        $stmt = $this->db->query(
            'SELECT * FROM candidate_applications ORDER BY id DESC LIMIT '
            . max(1, min(500, $limit))
        );
        $rows = $stmt->fetchAll();
        if (!$rows || !dbTableExists('candidate_application_files')) {
            return $rows;
        }
        $candidateIds = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
        $filesStmt = $this->db->prepare(
            'SELECT id,candidate_application_id,file_name,mime_type,file_size '
            . 'FROM candidate_application_files '
            . 'WHERE candidate_application_id IN (' . $placeholders . ') ORDER BY id'
        );
        $filesStmt->execute($candidateIds);
        $files = [];
        foreach ($filesStmt->fetchAll() as $file) {
            $files[(int)$file['candidate_application_id']][] = $file;
        }
        foreach ($rows as &$row) {
            $row['files'] = $files[(int)$row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }
    public function chats(int $limit = 300): array {
        $stmt = $this->db->query(
            'SELECT c.id,c.type,c.title,c.order_id,c.lead_id,c.created_at,'
            . 'COUNT(DISTINCT cp.user_id) participant_count,'
            . 'COUNT(DISTINCT m.id) message_count,MAX(m.created_at) last_message_at '
            . 'FROM chats c LEFT JOIN chat_participants cp ON cp.chat_id=c.id '
            . 'LEFT JOIN messages m ON m.chat_id=c.id '
            . 'GROUP BY c.id ORDER BY COALESCE(MAX(m.created_at),c.created_at) DESC LIMIT '
            . max(1, min(500, $limit))
        );
        return $stmt->fetchAll();
    }
    public function queue(?string $status = null, int $limit = 300): array {
        $allowed = ['pending','processing','sent','retry','failed','cancelled'];
        $params = [];
        $where = '';
        if ($status && in_array($status, $allowed, true)) {
            $where = ' WHERE status=?';
            $params[] = $status;
        }
        $stmt = $this->db->prepare(
            'SELECT id,event_type,recipient_type,recipient_id,order_id,site_chat_id,'
            . 'site_message_id,status,priority,attempts,max_attempts,next_attempt_at,'
            . 'sent_at,failed_at,last_error,created_at '
            . 'FROM notification_queue' . $where . ' ORDER BY id DESC LIMIT '
            . max(1, min(500, $limit))
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function audit(int $limit = 300): array {
        $stmt = $this->db->query(
            'SELECT id,user_id,role,action,entity_type,entity_id,result,ip_address,'
            . 'created_at FROM audit_log ORDER BY id DESC LIMIT '
            . max(1, min(500, $limit))
        );
        return $stmt->fetchAll();
    }
    public function systemState(): array {
        $runtime = dirname(__DIR__) . '/bot/runtime/';
        $heartbeat = $this->readJson($runtime . 'heartbeat.json');
        $heartbeatAt = is_array($heartbeat)
            ? strtotime((string)($heartbeat['updated_at'] ?? ''))
            : false;
        $staleSeconds = max(60, (int)env('BOT_WATCHDOG_STALE_SECONDS', 180));
        $botStatus = 'Не запущен';
        if ($heartbeatAt !== false) {
            $botStatus = time() - $heartbeatAt <= $staleSeconds
                ? 'Работает'
                : 'Heartbeat устарел';
        }
        $latestMigration = null;
        if (dbTableExists('migration_log')) {
            $latestMigration = $this->db->query(
                'SELECT migration_key FROM migration_log ORDER BY id DESC LIMIT 1'
            )->fetchColumn() ?: null;
        }
        return [
            'php_version' => PHP_VERSION,
            'mysql_version' => (string)$this->db->query('SELECT VERSION()')->fetchColumn(),
            'app_env' => APP_ENV,
            'app_debug' => APP_DEBUG,
            'heartbeat' => $heartbeat,
            'watchdog' => $this->readJson($runtime . 'watchdog-state.json'),
            'pid' => $this->readText($runtime . 'bot.pid'),
            'migration_count' => $this->count('migration_log'),
            'schema_version' => $latestMigration,
            'queue_backlog' => $this->count(
                'notification_queue',
                "status IN ('pending','retry','processing')"
            ),
            'telegram_status' => $botStatus,
            'deletion_observer' => 'Недоступен в стандартном Bot API',
        ];
    }
    public function retryQueue(int $queueId, int $actorId): array {
        return (new NotificationQueue())->retry($queueId, $actorId);
    }
    public function updateCandidateStatus(int $candidateId, string $status, int $actorId): array {
        return (new CandidateApplication())->updateStatus($candidateId, $status, $actorId);
    }
    private function count(string $table, string $where = '1=1'): int {
        $allowed = [
            'users', 'leads', 'orders', 'candidate_applications',
            'notification_queue', 'telegram_group_pool', 'migration_log',
        ];
        if (!in_array($table, $allowed, true) || !dbTableExists($table)) {
            return 0;
        }
        return (int)$this->db->query(
            'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where
        )->fetchColumn();
    }
    private function readJson(string $path): ?array {
        $raw = $this->readText($path);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
    private function readText(string $path): ?string {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $value = file_get_contents($path);
        return is_string($value) ? trim($value) : null;
    }
}
