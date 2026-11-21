<?php
class ManagerTemplate {
    private const NOT_FOUND = 'Шаблон не найден';
    private const MAX_TITLE_LENGTH = 120;
    private const MAX_BODY_LENGTH = 12000;
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function allForUser($userId) {
        if (!dbTableExists('manager_templates')) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT * FROM manager_templates WHERE manager_id=? ORDER BY updated_at DESC, id DESC');
        $stmt->execute([(int)$userId]);
        return $stmt->fetchAll();
    }
    public function save($templateId, $userId, $title, $body) {
        try {
            $this->assertActor($userId);
            if (!dbTableExists('manager_templates')) {
                throw new ConflictException('Сначала выполните миграцию базы данных для шаблонов менеджера');
            }
            $title = trim((string)$title);
            $body = trim((string)$body);
            if ($title === '' || $body === '') {
                throw new ValidationException('Укажите название и текст шаблона');
            }
            if (utf8Length($title) > self::MAX_TITLE_LENGTH || utf8Length($body) > self::MAX_BODY_LENGTH) {
                throw new ValidationException('Шаблон слишком длинный');
            }
            if ((int)$templateId > 0) {
                $stmt = $this->db->prepare('UPDATE manager_templates SET title=?,body=? WHERE id=? AND manager_id=?');
                $stmt->execute([$title, $body, (int)$templateId, (int)$userId]);
                if ($stmt->rowCount() === 0 && !$this->belongsTo((int)$templateId, (int)$userId)) {
                    throw new EntityNotFoundException(self::NOT_FOUND);
                }
                $id = (int)$templateId;
                $action = 'manager_template.updated';
            } else {
                $stmt = $this->db->prepare('INSERT INTO manager_templates (manager_id,title,body) VALUES (?,?,?)');
                $stmt->execute([(int)$userId, $title, $body]);
                $id = (int)$this->db->lastInsertId();
                $action = 'manager_template.created';
            }
            AuditLog::record($action, 'manager_template', $id, null, ['title' => $title], ['user_id' => (int)$userId]);
            return ['success' => true, 'template_id' => $id];
        } catch (Throwable $error) {
            return ['success' => false, 'error' => publicExceptionMessage($error, 'Не удалось сохранить шаблон')];
        }
    }
    public function delete($templateId, $userId) {
        try {
            $this->assertActor($userId);
            if (!dbTableExists('manager_templates')) {
                throw new EntityNotFoundException(self::NOT_FOUND);
            }
            $stmt = $this->db->prepare('DELETE FROM manager_templates WHERE id=? AND manager_id=?');
            $stmt->execute([(int)$templateId, (int)$userId]);
            if ($stmt->rowCount() !== 1) {
                throw new EntityNotFoundException(self::NOT_FOUND);
            }
            AuditLog::record('manager_template.deleted', 'manager_template', (int)$templateId, null, null, ['user_id' => (int)$userId]);
            return ['success' => true];
        } catch (Throwable $error) {
            return ['success' => false, 'error' => publicExceptionMessage($error, 'Не удалось удалить шаблон')];
        }
    }
    private function belongsTo($templateId, $userId) {
        $stmt = $this->db->prepare('SELECT 1 FROM manager_templates WHERE id=? AND manager_id=?');
        $stmt->execute([$templateId, $userId]);
        return (bool)$stmt->fetchColumn();
    }
    private function assertActor($userId) {
        if (!in_array(currentRole(), ['manager', 'admin'], true)
            || (int)$userId !== (int)($_SESSION['user_id'] ?? 0)) {
            throw new AccessDeniedException('Недостаточно прав для работы с шаблонами');
        }
    }
}
