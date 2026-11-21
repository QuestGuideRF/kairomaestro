<?php
class User extends UserDirectory {
    private const ACCOUNT_UNAVAILABLE = 'Аккаунт недоступен';
    public function register($data) {
        $manageTransaction = !$this->db->inTransaction();
        try {
            if ($manageTransaction) {
                $this->db->beginTransaction();
            }
            $normalized = $this->validateRegistrationData($data);
            $role = $normalized['role'];
            $userId = $this->insertRegisteredUser($data, $normalized);
            $this->createRegistrationProfile($userId, $role, $data);
            if ($manageTransaction) {
                $this->db->commit();
            }
            $auditValues = [
                'role' => $role,
                'has_phone' => $normalized['phone'] !== null,
                'has_email' => $normalized['email'] !== null,
            ];
            if (!empty($data['privacy_consent'])) {
                $auditValues['privacy_consent'] = true;
                $auditValues['privacy_policy_version'] = (string)(
                    $data['privacy_policy_version'] ?? PRIVACY_POLICY_VERSION
                );
                $auditValues['privacy_consent_at'] = (string)(
                    $data['privacy_consent_at'] ?? date(DATE_ATOM)
                );
            }
            AuditLog::record(
                'user.created',
                'user',
                (int)$userId,
                null,
                $auditValues,
                ['user_id' => $_SESSION['user_id'] ?? null]
            );
            $result = ['success' => true, 'user_id' => $userId];
        } catch (Exception $e) {
            if ($manageTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось создать пользователя')];
        }
        return $result;
    }
    public function createClientFromLead($data) {
        $identity = $this->leadClientIdentity($data);
        $matches = $this->matchingLeadAccounts($identity);
        $existingIds = array_values(array_unique(array_map(
            static fn(array $user): int => (int)$user['id'],
            $matches
        )));

        return $this->resolveLeadClient(
            $data,
            $identity,
            $matches,
            $existingIds
        );
    }
    private function leadClientIdentity(array $data): array {
        $name = trim((string)($data['client_name'] ?? 'Клиент'));
        $parts = preg_split('/\s+/u', $name, 2);

        return [
            'first_name' => $parts[0] ?: 'Клиент',
            'last_name' => $parts[1] ?? '',
            'phone' => trim((string)($data['phone'] ?? '')) ?: null,
            'email' => trim((string)($data['email'] ?? '')) ?: null,
            'telegram' => trim(
                (string)($data['telegram_username'] ?? '')
            ) ?: null,
        ];
    }
    private function matchingLeadAccounts(array $identity): array {
        $matches = [
            $identity['phone']
                ? $this->findByPhoneOrEmail($identity['phone'], null)
                : null,
            $identity['email']
                ? $this->findByPhoneOrEmail(null, $identity['email'])
                : null,
            $identity['telegram']
                ? $this->findByTelegramUsername($identity['telegram'])
                : null,
        ];

        return array_values(array_filter($matches, 'is_array'));
    }
    private function resolveLeadClient(
        array $data,
        array $identity,
        array $matches,
        array $existingIds
    ): array {
        if (count($existingIds) > 1) {
            $result = ['success' => false, 'error' => 'Указанные контакты относятся к разным аккаунтам'];
        } else {
            $existing = $matches[0] ?? null;
            if ($existing && ($existing['role'] ?? '') !== 'client') {
                $result = ['success' => false, 'error' => 'Контакты принадлежат служебному аккаунту'];
            } elseif ($existing) {
                $result = ['success' => true, 'user_id' => $existing['id'], 'existing' => true];
            } else {
                $result = $this->register([
                    'role' => 'client',
                    'phone' => $identity['phone'],
                    'email' => $identity['email'],
                    'password' => $data['password'] ?? bin2hex(random_bytes(8)),
                    'first_name' => $identity['first_name'],
                    'last_name' => $identity['last_name'],
                    'preferred_contact' => $data['contact_method'] ?? 'site_chat',
                    'telegram_username' => $data['telegram_username'] ?? null,
                    'city' => $data['location_city'] ?? null,
                    'notes' => $data['comment'] ?? null,
                    'require_telegram_confirmation' => true,
                ]);
            }
        }

        return $result;
    }
    public function login($phone, $password) {
        return (new UserAuthenticationService($this->db))->login($phone, $password);
    }
    public function logout() {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId) {
            AuditLog::record('auth.logout', 'user', $userId);
            if (dbTableExists('user_sessions')) {
                $stmt = $this->db->prepare("UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND session_hash=?");
                $stmt->execute([$userId, hash('sha256', session_id())]);
            }
        }
        LoginSecurity::destroySession();
        return true;
    }
    public function changePassword($userId, $currentPassword, $newPassword, $confirmation) {
        $user = $this->getUserById((int)$userId);
        $error = $this->passwordChangeError($userId, $user, $currentPassword, $newPassword, $confirmation);
        if ($error !== null) {
            $action = $error === self::ACCOUNT_UNAVAILABLE ? 'auth.password_change_denied' : 'auth.password_change_failed';
            $auditResult = $error === self::ACCOUNT_UNAVAILABLE ? 'denied' : 'failure';
            AuditLog::record($action, 'user', (int)$userId, null, null, ['user_id' => (int)$userId, 'result' => $auditResult]);
            $result = ['success' => false, 'error' => $error];
        } else {
            $hash = password_hash((string)$newPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare('UPDATE users SET password=? WHERE id=?');
            $stmt->execute([$hash, (int)$userId]);
            (new LoginSecurity())->revokeOtherSessions((int)$userId);
            AuditLog::record('auth.password_changed', 'user', (int)$userId, null, ['other_sessions_revoked' => true], ['user_id' => (int)$userId, 'role' => $user['role']]);
            if (!empty($user['telegram_chat_id'])) {
                (new TelegramNotifier())->notifyUser((int)$userId, 'password_changed', 'Пароль аккаунта ' . SITE_NAME . ' изменён. Если это были не вы, немедленно свяжитесь с администратором.', 'password-changed-' . (int)$userId . '-' . time(), 10);
            }
            $result = ['success' => true];
        }
        return $result;
    }
    public function updateProfile($userId, $data) {
        return (new UserProfileService($this->db))->update($userId, $data);
    }
    public function uploadAvatar($file) {
        return (new UserProfileService($this->db))->uploadAvatar($file);
    }
    private function validateRegistrationData($data) {
        return (new UserRegistrationValidator($this->db))->validate($data);
    }
    private function insertRegisteredUser($data, $normalized) {
        $fields = ['phone', 'password', 'first_name', 'last_name', 'middle_name', 'role'];
        $values = [
            $normalized['phone'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $normalized['first_name'],
            trim($data['last_name'] ?? ''),
            trim($data['middle_name'] ?? '') ?: null,
            $normalized['role'],
        ];
        if ($this->usersHasColumn('email')) {
            array_splice($fields, 1, 0, ['email']);
            array_splice($values, 1, 0, [$normalized['email']]);
        }
        if ($this->usersHasColumn('telegram_status')) {
            $fields[] = 'telegram_status';
            $values[] = !empty($data['require_telegram_confirmation'])
                ? 'required'
                : 'unlinked';
        }
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $stmt = $this->db->prepare('INSERT INTO users (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')');
        $stmt->execute($values);
        return $this->db->lastInsertId();
    }
    private function createRegistrationProfile($userId, $role, $data) {
        if (in_array($role, workerRoles(), true)) {
            $this->createWorkerProfile($userId, $role, $data);
            $stmt = $this->db->prepare('INSERT INTO worker_statistics (worker_id) VALUES (?)');
            $stmt->execute([$userId]);
        } elseif ($role === 'client' && dbTableExists('client_profiles')) {
            $stmt = $this->db->prepare("
                INSERT INTO client_profiles (user_id, preferred_contact, telegram_username, city, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $data['preferred_contact'] ?? 'site_chat',
                $data['telegram_username'] ?? null,
                $data['city'] ?? null,
                $data['notes'] ?? null,
            ]);
        }
    }
    private function passwordChangeError($userId, $user, $currentPassword, $newPassword, $confirmation) {
        $minimumLength = 10;
        if ((int)($_SESSION['user_id'] ?? 0) !== (int)$userId) {
            $error = self::ACCOUNT_UNAVAILABLE;
        } elseif (!$user || !password_verify((string)$currentPassword, $user['password'])) {
            $error = 'Неверный текущий пароль';
        } elseif (!hash_equals((string)$newPassword, (string)$confirmation)) {
            $error = 'Новые пароли не совпадают';
        } elseif (utf8Length((string)$newPassword) < $minimumLength || utf8Length((string)$newPassword) > 1024) {
            $error = 'Новый пароль должен содержать от ' . $minimumLength . ' до 1024 символов';
        } elseif (password_verify((string)$newPassword, $user['password'])) {
            $error = 'Новый пароль должен отличаться от текущего';
        } else {
            $error = null;
        }
        return $error;
    }
}
