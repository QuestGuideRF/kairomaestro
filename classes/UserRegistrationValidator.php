<?php
class UserRegistrationValidator {
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function validate($data) {
        $role = $this->validatedRole($data['role'] ?? 'client');
        $firstName = $this->validatedName($data);
        $this->validatePassword($data['password'] ?? '');
        $contacts = $this->validatedContacts($data, $role);
        return [
            'role' => $role,
            'phone' => $contacts['phone'],
            'email' => $contacts['email'],
            'first_name' => $firstName,
        ];
    }
    private function validatedRole($role) {
        if (!in_array($role, allRoles(), true)) {
            throw new ValidationException('Недопустимая роль пользователя');
        }
        if (in_array($role, ['manager', 'organizer', 'admin'], true) && !$this->staffRegistrationAllowed()) {
            throw new AccessDeniedException('Служебные аккаунты создаёт администратор');
        }
        return $role;
    }
    private function validatedName($data) {
        $firstName = trim((string)($data['first_name'] ?? ''));
        if ($firstName === '') {
            throw new ValidationException('Укажите имя пользователя');
        }
        $tooLong = utf8Length($firstName) > 100
            || utf8Length($data['last_name'] ?? '') > 100
            || utf8Length($data['middle_name'] ?? '') > 100;
        if ($tooLong) {
            throw new ValidationException('Имя пользователя слишком длинное');
        }
        return $firstName;
    }
    private function validatePassword($password) {
        $length = utf8Length((string)$password);
        if ($length < 8 || $length > 1024) {
            throw new ValidationException('Пароль должен содержать от 8 до 1024 символов');
        }
    }
    private function validatedContacts($data, $role) {
        $phone = trim((string)($data['phone'] ?? '')) ?: null;
        $email = trim((string)($data['email'] ?? '')) ?: null;
        $telegram = trim((string)($data['telegram_username'] ?? '')) ?: null;
        if ($email !== null && (!filter_var($email, FILTER_VALIDATE_EMAIL) || utf8Length($email) > 190)) {
            throw new ValidationException('Некорректный адрес электронной почты');
        }
        if ($phone !== null && utf8Length($phone) > 40) {
            throw new ValidationException('Некорректный номер телефона');
        }
        if ($telegram !== null && utf8Length($telegram) > 120) {
            throw new ValidationException('Некорректное имя пользователя Telegram');
        }
        if ($role === 'client' && !$phone && !$email && !$telegram) {
            throw new ValidationException('Укажите телефон, email или Telegram');
        }
        if (($phone || $email || $telegram) && $this->contactExists($phone, $email, $telegram)) {
            throw new ConflictException('Пользователь с такими контактами уже зарегистрирован');
        }
        return ['phone' => $phone, 'email' => $email];
    }
    private function contactExists($phone, $email, $telegram) {
        $user = false;
        if ($phone) {
            $stmt = $this->db->prepare('SELECT id FROM users WHERE phone=? LIMIT 1');
            $stmt->execute([$phone]);
            $user = $stmt->fetchColumn();
        }
        if (!$user && $email && dbColumnExists('users', 'email')) {
            $stmt = $this->db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetchColumn();
        }
        if (!$user && $telegram && dbTableExists('client_profiles')) {
            $normalized = strtolower(ltrim($telegram, '@'));
            $stmt = $this->db->prepare("
                SELECT user_id
                FROM client_profiles
                WHERE LOWER(TRIM(LEADING '@' FROM telegram_username))=?
                LIMIT 1
            ");
            $stmt->execute([$normalized]);
            $user = $stmt->fetchColumn();
        }
        return (bool)$user;
    }
    private function staffRegistrationAllowed() {
        return PHP_SAPI === 'cli'
            || (isLoggedIn() && isAdminRole())
            || (strtolower((string)APP_ENV) !== 'production' && (bool)env('ALLOW_ORGANIZER_REGISTRATION', false));
    }
}
