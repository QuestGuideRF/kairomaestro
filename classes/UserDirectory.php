<?php
class UserDirectory {
    protected $db;
    protected const NULL_EMAIL_SELECT = 'NULL AS email';
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function getAssignableManagers() {
        $emailSelect = $this->usersHasColumn('email') ? 'email' : self::NULL_EMAIL_SELECT;
        $activeWhere = $this->usersHasColumn('is_active') ? ' AND is_active = 1' : '';
        $stmt = $this->db->prepare("
            SELECT id, first_name, last_name, phone, {$emailSelect}, role
            FROM users
            WHERE role IN ('manager', 'admin'){$activeWhere}
            ORDER BY FIELD(role, 'manager', 'admin'), first_name, last_name
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    public function getOrganizers() {
        $emailSelect = $this->usersHasColumn('email') ? 'email' : self::NULL_EMAIL_SELECT;
        $activeWhere = $this->usersHasColumn('is_active') ? ' AND is_active = 1' : '';
        $stmt = $this->db->prepare("
            SELECT id, first_name, last_name, phone, {$emailSelect}, role
            FROM users
            WHERE role IN ('organizer', 'admin'){$activeWhere}
            ORDER BY first_name, last_name
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    public function findByPhoneOrEmail($phone = null, $email = null) {
        $user = null;
        if ($phone) {
            $stmt = $this->db->prepare('SELECT * FROM users WHERE phone = ? LIMIT 1');
            $stmt->execute([$phone]);
            $user = $stmt->fetch() ?: null;
        }
        if ($user === null && $email && $this->usersHasColumn('email')) {
            $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch() ?: null;
        }
        return $user;
    }
    public function findByTelegramUsername($username) {
        $normalized = strtolower(ltrim(trim((string)$username), '@'));
        if ($normalized === '' || !dbTableExists('client_profiles')) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT users.*
            FROM client_profiles profile
            JOIN users ON users.id=profile.user_id
            WHERE LOWER(TRIM(LEADING '@' FROM profile.telegram_username))=?
              AND users.role='client'
            LIMIT 2
        ");
        $stmt->execute([$normalized]);
        $matches = $stmt->fetchAll();
        return count($matches) === 1 ? $matches[0] : null;
    }
    public function getUserById($id) {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    public function getClientProfile($userId) {
        if (!dbTableExists('client_profiles')) {
            return $this->getUserById($userId);
        }
        $stmt = $this->db->prepare("
            SELECT u.*, cp.preferred_contact, cp.telegram_username, cp.city, cp.notes
            FROM users u
            LEFT JOIN client_profiles cp ON u.id = cp.user_id
            WHERE u.id = ? AND u.role = 'client'
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    public function getWorkerProfile($userId) {
        if ((int)$userId !== (int)($_SESSION['user_id'] ?? 0) && !isCrmRole()) {
            return false;
        }
        $workerRoleList = implode("','", workerRoles());
        $emailSelect = $this->usersHasColumn('email') ? 'u.email' : self::NULL_EMAIL_SELECT;
        $activeSelect = $this->usersHasColumn('is_active') ? 'u.is_active' : '1 AS is_active';
        $profileSelect = $this->workerProfileSelect();
        $stmt = $this->db->prepare("
            SELECT u.id, u.phone, {$emailSelect}, u.first_name, u.last_name, u.middle_name,
                   u.role, u.avatar, {$activeSelect}, u.created_at, u.last_login,
                   wp.user_id, {$profileSelect}
            FROM users u
            LEFT JOIN worker_profiles wp ON u.id = wp.user_id
            WHERE u.id = ? AND u.role IN ('$workerRoleList')
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    public function getAllWorkers($filters = []) {
        if (!isCrmRole()) {
            return [];
        }
        $workerRoleList = implode("','", workerRoles());
        $activeWhere = $this->usersHasColumn('is_active') ? ' AND u.is_active = 1' : '';
        $emailSelect = $this->usersHasColumn('email') ? 'u.email' : self::NULL_EMAIL_SELECT;
        $activeSelect = $this->usersHasColumn('is_active') ? 'u.is_active' : '1 AS is_active';
        $profileSelect = $this->workerProfileSelect();
        $sql = "
            SELECT u.id, u.phone, {$emailSelect}, u.first_name, u.last_name, u.middle_name,
                   u.role, u.avatar, {$activeSelect}, u.created_at, u.last_login,
                   (SELECT COUNT(*) FROM event_staff es
                    WHERE es.worker_id = u.id AND es.status <> 'declined') AS total_events,
                   {$profileSelect}
            FROM users u
            LEFT JOIN worker_profiles wp ON u.id = wp.user_id
            WHERE u.role IN ('$workerRoleList'){$activeWhere}
        ";
        $params = [];
        if (!empty($filters['specialty'])) {
            $sql .= ' AND wp.specialty = ?';
            $params[] = $filters['specialty'];
        }
        if (!empty($filters['role'])) {
            $sql .= ' AND u.role = ?';
            $params[] = $filters['role'];
        }
        $sql .= ' ORDER BY u.first_name, u.last_name';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function isWorkerAvailable($workerId, $date, $startTime = null, $endTime = null, $excludeEventId = null) {
        $stmt = $this->db->prepare('SELECT id FROM worker_availability WHERE worker_id = ? AND unavailable_date = ?');
        $stmt->execute([$workerId, $date]);
        $available = !$stmt->fetch();
        if ($available) {
            $sql = "
                SELECT e.id FROM events e
                JOIN event_staff es ON e.id = es.event_id
                WHERE es.worker_id = ? AND e.event_date = ? AND e.status != 'cancelled'
                  AND es.status <> 'declined'
            ";
            $params = [(int)$workerId, $date];
            if ($startTime && $endTime) {
                $sql .= " AND COALESCE(e.start_time,'00:00:00') < ? AND COALESCE(e.end_time,'23:59:59') > ?";
                array_push($params, $endTime, $startTime);
            }
            if ($excludeEventId) {
                $sql .= ' AND e.id<>?';
                $params[] = (int)$excludeEventId;
            }
            $stmt = $this->db->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            $available = !$stmt->fetch();
        }
        return $available;
    }
    protected function roleToSpecialty($role) {
        $map = [
            'florist' => 'Флорист',
            'courier' => 'Курьер',
            'installer' => 'Монтажник',
            'helper' => 'Помощник',
            'worker' => 'Работник',
        ];
        return $map[$role] ?? 'Работник';
    }
    protected function usersHasColumn($column) {
        return dbColumnExists('users', $column);
    }
    protected function workerProfileHasColumn($column) {
        return dbColumnExists('worker_profiles', $column);
    }
    protected function textLength($value) {
        return utf8Length((string)$value);
    }
    protected function workerProfileSelect() {
        $selects = [
            'wp.specialty',
            $this->workerProfileHasColumn('public_role') ? 'wp.public_role' : 'wp.specialty AS public_role',
            'wp.has_car',
            'wp.car_brand',
            'wp.car_number',
            'wp.additional_info',
            'wp.hourly_rate',
            $this->workerProfileHasColumn('experience_years') ? 'wp.experience_years' : '0 AS experience_years',
            $this->workerProfileHasColumn('rating') ? 'wp.rating' : '5.00 AS rating',
            $this->workerProfileHasColumn('reviews_count') ? 'wp.reviews_count' : '0 AS reviews_count',
            $this->workerProfileHasColumn('public_description') ? 'wp.public_description' : 'NULL AS public_description',
        ];
        return implode(', ', $selects);
    }
    protected function createWorkerProfile($userId, $role, $data) {
        $fields = ['user_id', 'specialty', 'has_car', 'car_brand', 'car_number', 'additional_info', 'hourly_rate'];
        $values = [
            $userId,
            $data['specialty'] ?? $this->roleToSpecialty($role),
            $data['has_car'] ?? 0,
            $data['car_brand'] ?? null,
            $data['car_number'] ?? null,
            $data['additional_info'] ?? null,
            $data['hourly_rate'] ?? 0,
        ];
        $optional = [
            'public_role' => $data['public_role'] ?? $this->roleToSpecialty($role),
            'public_description' => $data['public_description'] ?? null,
            'experience_years' => $data['experience_years'] ?? 0,
        ];
        foreach ($optional as $field => $value) {
            if ($this->workerProfileHasColumn($field)) {
                $fields[] = $field;
                $values[] = $value;
            }
        }
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $stmt = $this->db->prepare('INSERT INTO worker_profiles (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')');
        $stmt->execute($values);
    }
}
