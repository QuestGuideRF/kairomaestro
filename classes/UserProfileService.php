<?php
class UserProfileService {
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function update($userId, $data) {
        $manageTransaction = false;
        try {
            $oldProfile = $this->loadAuthorizedProfile($userId);
            $this->normalizeIdentity($data, $userId);
            $this->normalizeWorkerData($data, $oldProfile);
            $manageTransaction = !$this->db->inTransaction();
            if ($manageTransaction) {
                $this->db->beginTransaction();
            }
            $this->persistUser($userId, $data);
            $this->persistWorkerProfile($userId, $data);
            $this->persistClientProfile($userId, $data);
            if ($manageTransaction) {
                $this->db->commit();
            }
            AuditLog::record('profile.updated', 'user', (int)$userId, [
                'first_name' => $oldProfile['first_name'] ?? null,
                'last_name' => $oldProfile['last_name'] ?? null,
                'email' => $oldProfile['email'] ?? null,
            ], array_intersect_key($data, array_flip([
                'first_name', 'last_name', 'email', 'specialty', 'has_car', 'hourly_rate', 'avatar',
            ])), ['user_id' => (int)$userId, 'role' => currentRole()]);
            $result = ['success' => true];
        } catch (Exception $e) {
            if ($manageTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось сохранить профиль')];
        }
        return $result;
    }
    public function uploadAvatar($file) {
        $error = $this->avatarValidationError($file);
        if ($error !== null) {
            return ['success' => false, 'error' => $error];
        }
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $maxSize = min(UPLOAD_LIMIT_BYTES, 5 * 1024 * 1024);
        $upload = FileStorage::store($file, 'avatars', $allowedTypes, $maxSize);
        if (!$upload['success']) {
            return $upload;
        }
        $fileName = basename($upload['file_path']);
        $user = $this->findUser((int)$_SESSION['user_id']);
        if (!empty($user['avatar'])) {
            FileStorage::deleteOwnedFile(
                'uploads/avatars/' . basename((string)$user['avatar']),
                'avatars',
                'user_avatar',
                (int)$_SESSION['user_id']
            );
        }
        return ['success' => true, 'filename' => $fileName];
    }
    private function loadAuthorizedProfile($userId) {
        if ((int)($_SESSION['user_id'] ?? 0) !== (int)$userId && !isAdminRole()) {
            AuditLog::record('profile.update_denied', 'user', (int)$userId, null, null, ['result' => 'denied']);
            throw new AccessDeniedException('Профиль недоступен');
        }
        $profile = $this->findUser((int)$userId);
        if (!$profile) {
            throw new EntityNotFoundException('Профиль не найден');
        }
        return $profile;
    }
    private function findUser($userId) {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([(int)$userId]);
        return $stmt->fetch();
    }
    private function normalizeIdentity(&$data, $userId) {
        foreach (['first_name', 'last_name', 'middle_name'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = trim((string)$data[$field]);
                if (utf8Length($data[$field]) > 100) {
                    throw new ValidationException('Имя или фамилия слишком длинные');
                }
            }
        }
        if (array_key_exists('first_name', $data) && $data['first_name'] === '') {
            throw new ValidationException('Укажите имя');
        }
        $this->normalizeEmail($data, $userId);
        $this->normalizePhone($data, $userId);
        if (isset($data['avatar']) && !preg_match('/^avatars_[a-f0-9]{32}\.(?:jpg|png|webp)$/', (string)$data['avatar'])) {
            throw new ValidationException('Некорректное имя файла профиля');
        }
    }
    private function normalizeEmail(&$data, $userId) {
        if (!array_key_exists('email', $data)) {
            return;
        }
        $data['email'] = trim((string)$data['email']);
        if ($data['email'] !== '' && (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || utf8Length($data['email']) > 190)) {
            throw new ValidationException('Проверьте email');
        }
        if ($data['email'] !== '' && dbColumnExists('users', 'email')) {
            $stmt = $this->db->prepare('SELECT 1 FROM users WHERE LOWER(email)=LOWER(?) AND id<>? LIMIT 1');
            $stmt->execute([$data['email'], (int)$userId]);
            if ($stmt->fetchColumn()) {
                throw new ConflictException('Этот email уже используется');
            }
        }
    }
    private function normalizePhone(&$data, $userId) {
        if (!array_key_exists('phone', $data)) {
            return;
        }
        $data['phone'] = trim((string)$data['phone']);
        if (utf8Length($data['phone']) > 40) {
            throw new ValidationException('Проверьте телефон');
        }
        if ($data['phone'] !== '') {
            $stmt = $this->db->prepare('SELECT 1 FROM users WHERE phone=? AND id<>? LIMIT 1');
            $stmt->execute([$data['phone'], (int)$userId]);
            if ($stmt->fetchColumn()) {
                throw new ConflictException('Этот телефон уже используется');
            }
        }
    }
    private function normalizeWorkerData(&$data, $oldProfile) {
        if (!isset($data['specialty'])) {
            return;
        }
        if (!isWorkerRole($oldProfile['role'])) {
            throw new AccessDeniedException('Профиль сотрудника недоступен');
        }
        $data['specialty'] = trim((string)$data['specialty']);
        if ($data['specialty'] === '' || utf8Length($data['specialty']) > 120) {
            throw new ValidationException('Проверьте специализацию');
        }
        foreach (['car_brand' => 120, 'car_number' => 40, 'additional_info' => 10000, 'public_role' => 120, 'public_description' => 10000] as $field => $limit) {
            $data[$field] = trim((string)($data[$field] ?? ''));
            if (utf8Length($data[$field]) > $limit) {
                throw new ValidationException('Одно из полей профиля слишком длинное');
            }
        }
        $rate = str_replace(',', '.', trim((string)($data['hourly_rate'] ?? '0')));
        $experience = str_replace(',', '.', trim((string)($data['experience_years'] ?? '0')));
        if (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > 1000000) {
            throw new ValidationException('Проверьте ставку');
        }
        if (!is_numeric($experience) || (float)$experience < 0 || (float)$experience > 80) {
            throw new ValidationException('Проверьте стаж');
        }
        $data['hourly_rate'] = round((float)$rate, 2);
        $data['experience_years'] = round((float)$experience, 1);
        $data['has_car'] = !empty($data['has_car']) ? 1 : 0;
        if (!$data['has_car']) {
            $data['car_brand'] = '';
            $data['car_number'] = '';
        }
    }
    private function persistUser($userId, $data) {
        $mapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'middle_name' => 'middle_name',
            'phone' => 'phone',
            'avatar' => 'avatar',
        ];
        if (dbColumnExists('users', 'email')) {
            $mapping['email'] = 'email';
        }
        $updates = [];
        $values = [];
        foreach ($mapping as $field => $column) {
            if (isset($data[$field])) {
                $updates[] = $column . ' = ?';
                $values[] = in_array($field, ['email', 'phone'], true) && $data[$field] === '' ? null : $data[$field];
            }
        }
        if ($updates) {
            $values[] = (int)$userId;
            $stmt = $this->db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?');
            $stmt->execute($values);
        }
    }
    private function persistWorkerProfile($userId, $data) {
        if (!isset($data['specialty']) || !dbTableExists('worker_profiles')) {
            return;
        }
        $fields = [
            'specialty' => $data['specialty'],
            'has_car' => $data['has_car'] ?? 0,
            'car_brand' => $data['car_brand'] ?: null,
            'car_number' => $data['car_number'] ?: null,
            'additional_info' => $data['additional_info'] ?: null,
            'hourly_rate' => $data['hourly_rate'] ?? 0,
        ];
        foreach (['public_role', 'public_description', 'experience_years'] as $field) {
            if (dbColumnExists('worker_profiles', $field)) {
                $fields[$field] = $data[$field] ?? null;
            }
        }
        $stmt = $this->db->prepare('SELECT id FROM worker_profiles WHERE user_id = ? LIMIT 1');
        $stmt->execute([(int)$userId]);
        if ($stmt->fetchColumn()) {
            $assignments = array_map(static fn($column) => $column . ' = ?', array_keys($fields));
            $values = array_values($fields);
            $values[] = (int)$userId;
            $stmt = $this->db->prepare('UPDATE worker_profiles SET ' . implode(', ', $assignments) . ' WHERE user_id = ?');
        } else {
            $columns = array_merge(['user_id'], array_keys($fields));
            $values = array_merge([(int)$userId], array_values($fields));
            $stmt = $this->db->prepare(
                'INSERT INTO worker_profiles (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')'
            );
        }
        $stmt->execute($values);
    }
    private function persistClientProfile($userId, $data) {
        if (!isset($data['preferred_contact']) || !dbTableExists('client_profiles')) {
            return;
        }
        $stmt = $this->db->prepare("
            INSERT INTO client_profiles (user_id, preferred_contact, telegram_username, city, notes)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                preferred_contact = VALUES(preferred_contact),
                telegram_username = VALUES(telegram_username),
                city = VALUES(city),
                notes = VALUES(notes)
        ");
        $stmt->execute([
            (int)$userId,
            $data['preferred_contact'],
            $data['telegram_username'] ?? null,
            $data['city'] ?? null,
            $data['notes'] ?? null,
        ]);
    }
    private function avatarValidationError($file) {
        $error = null;
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $error = 'Файл не был загружен';
        } elseif (!class_exists('finfo')) {
            $error = 'Проверка типа файла недоступна на сервере';
        } elseif ((int)($file['size'] ?? 0) > min(UPLOAD_LIMIT_BYTES, 5 * 1024 * 1024)) {
            $error = 'Файл слишком большой. Максимум 5MB';
        }
        return $error;
    }
}
