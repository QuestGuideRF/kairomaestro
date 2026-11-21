<?php
class PublicTeamProfile {
    private const EDITABLE_ROLES = [
        'manager', 'organizer', 'admin', 'worker', 'florist', 'courier',
        'installer', 'helper',
    ];
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function published($limit = 8) {
        if (!dbTableExists('public_team_profiles')) {
            return [];
        }
        $limit = max(1, min(12, (int)$limit));
        $stmt = $this->db->query(
            "SELECT profile.user_id,profile.public_role,profile.specialization,
                    profile.public_description,profile.public_quote,
                    profile.experience_years,user.first_name,user.last_name,
                    user.avatar,user.role
             FROM public_team_profiles profile
             JOIN users user ON user.id=profile.user_id
             WHERE profile.is_published=1 AND user.is_active=1
             ORDER BY profile.sort_order,profile.user_id
             LIMIT {$limit}"
        );
        return $stmt->fetchAll();
    }
    public function getForUser($userId) {
        if (!dbTableExists('public_team_profiles')) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT profile.*,user.role,user.first_name,user.last_name
             FROM public_team_profiles profile
             JOIN users user ON user.id=profile.user_id
             WHERE profile.user_id=? LIMIT 1'
        );
        $stmt->execute([(int)$userId]);
        return $stmt->fetch() ?: null;
    }
    public function saveOwn($userId, $data) {
        try {
            $userId = (int)$userId;
            $actorId = (int)($_SESSION['user_id'] ?? 0);
            $user = (new User())->getUserById($userId);
            if (!$user || $actorId !== $userId || !in_array($user['role'], self::EDITABLE_ROLES, true)) {
                throw new AccessDeniedException('Публичный профиль недоступен');
            }
            if (!dbTableExists('public_team_profiles')) {
                throw new PersistenceException('Примените миграцию публичной команды');
            }
            $profile = $this->normalize($data);
            $old = $this->getForUser($userId);
            $stmt = $this->db->prepare(
                'INSERT INTO public_team_profiles
                    (user_id,is_published,public_role,specialization,public_description,
                     public_quote,experience_years,sort_order,updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    is_published=?,public_role=?,specialization=?,public_description=?,
                    public_quote=?,experience_years=?,updated_by=?'
            );
            $base = [
                $userId, $profile['is_published'], $profile['public_role'],
                $profile['specialization'], $profile['public_description'],
                $profile['public_quote'], $profile['experience_years'], 100, $actorId,
            ];
            $stmt->execute(array_merge($base, [
                $profile['is_published'], $profile['public_role'],
                $profile['specialization'], $profile['public_description'],
                $profile['public_quote'], $profile['experience_years'], $actorId,
            ]));
            AuditLog::record(
                'public_team.profile_updated',
                'public_team_profile',
                $userId,
                $old ? ['is_published' => (bool)$old['is_published']] : null,
                ['is_published' => (bool)$profile['is_published']],
                ['user_id' => $actorId, 'role' => $user['role']]
            );
            return ['success' => true];
        } catch (Throwable $error) {
            return [
                'success' => false,
                'error' => publicExceptionMessage($error, 'Не удалось сохранить публичный профиль'),
            ];
        }
    }
    private function normalize($data) {
        $fields = [
            'public_role' => 120,
            'specialization' => 190,
            'public_description' => 5000,
            'public_quote' => 500,
        ];
        $result = ['is_published' => !empty($data['is_published']) ? 1 : 0];
        foreach ($fields as $field => $limit) {
            $value = trim((string)($data[$field] ?? ''));
            if (utf8Length($value) > $limit) {
                throw new ValidationException('Одно из публичных полей слишком длинное');
            }
            $result[$field] = $value !== '' ? $value : null;
        }
        if ($result['is_published'] && (!$result['public_role'] || !$result['public_description'])) {
            throw new ValidationException('Для публикации укажите роль и короткое описание');
        }
        $experience = str_replace(',', '.', trim((string)($data['experience_years'] ?? '0')));
        if (!is_numeric($experience) || (float)$experience < 0 || (float)$experience > 80) {
            throw new ValidationException('Проверьте стаж');
        }
        $result['experience_years'] = round((float)$experience, 1);
        return $result;
    }
}
