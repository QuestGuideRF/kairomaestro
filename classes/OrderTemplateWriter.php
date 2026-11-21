<?php
class OrderTemplateWriter {
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function save($templateId, $userId, $data) {
        try {
            if ((int)($_SESSION['user_id'] ?? 0) !== (int)$userId || !can('manage_templates')) {
                throw new AccessDeniedException('Недостаточно прав для шаблонов');
            }
            $template = $this->normalize($data);
            $id = $templateId
                ? $this->update((int)$templateId, (int)$userId, $template)
                : $this->insert((int)$userId, $template);
            $result = ['success' => true, 'template_id' => $id];
        } catch (Throwable $error) {
            $result = ['success' => false, 'error' => publicExceptionMessage($error, 'Не удалось сохранить шаблон')];
        }
        return $result;
    }
    private function normalize($data) {
        $template = [
            'name' => trim((string)($data['template_name'] ?? '')),
            'description' => trim((string)($data['description'] ?? '')),
            'event_type' => trim((string)($data['event_type'] ?? '')),
            'title' => trim((string)($data['title'] ?? '')),
            'location' => trim((string)($data['location'] ?? '')),
            'technical_task' => trim((string)($data['technical_task'] ?? '')),
            'duration' => ($data['duration_hours'] ?? '') === '' ? null : (int)$data['duration_hours'],
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
        if ($template['name'] === '') {
            throw new ValidationException('Укажите название шаблона');
        }
        $limits = [
            'name' => 190,
            'description' => 10000,
            'event_type' => 120,
            'title' => 255,
            'location' => 255,
            'technical_task' => 50000,
        ];
        foreach ($limits as $field => $limit) {
            if ($this->textLength($template[$field]) > $limit) {
                throw new ValidationException('Одно из полей шаблона превышает допустимую длину');
            }
        }
        if ($template['duration'] !== null && ($template['duration'] < 1 || $template['duration'] > 1000)) {
            throw new ValidationException('Длительность должна быть от 1 до 1000 часов');
        }
        $template['payload'] = $this->normalizePayload($data['template_payload'] ?? []);
        return $template;
    }
    private function update($templateId, $userId, $template) {
        $stmt = $this->db->prepare("SELECT * FROM event_templates WHERE id=?");
        $stmt->execute([$templateId]);
        $old = $stmt->fetch();
        if (!$old) {
            throw new EntityNotFoundException('Шаблон не найден');
        }
        if (!isAdminRole() && (int)$old['organizer_id'] !== $userId) {
            throw new AccessDeniedException('Шаблон недоступен');
        }
        $stmt = $this->db->prepare("UPDATE event_templates SET template_name=?,description=?,event_type=?,title=?,duration_hours=?,location=?,technical_task=?,template_payload=?,is_active=? WHERE id=?");
        $stmt->execute($this->statementValues($template, [$templateId]));
        AuditLog::record('template.updated', 'event_template', $templateId, [
            'template_name' => $old['template_name'],
            'payload' => $old['template_payload'],
        ], ['template_name' => $template['name'], 'payload' => $template['payload']], ['user_id' => $userId]);
        return $templateId;
    }
    private function insert($userId, $template) {
        $stmt = $this->db->prepare("INSERT INTO event_templates (organizer_id,template_name,description,event_type,title,duration_hours,location,technical_task,template_payload,is_active) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute(array_merge([$userId], $this->statementValues($template)));
        $id = (int)$this->db->lastInsertId();
        AuditLog::record('template.created', 'event_template', $id, null, [
            'template_name' => $template['name'],
            'payload' => $template['payload'],
        ], ['user_id' => $userId]);
        return $id;
    }
    private function statementValues($template, $suffix = []) {
        return array_merge([
            $template['name'],
            $template['description'] ?: null,
            $template['event_type'] ?: null,
            $template['title'] ?: null,
            $template['duration'],
            $template['location'] ?: null,
            $template['technical_task'] ?: null,
            json_encode($template['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $template['is_active'],
        ], $suffix);
    }
    private function normalizePayload($payload) {
        if (is_string($payload)) {
            if (strlen($payload) > 250000) {
                throw new ValidationException('Содержимое шаблона слишком большое');
            }
            try {
                $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new ValidationException('Содержимое шаблона должно быть корректным JSON', 0, $error);
            }
        }
        if (!is_array($payload)) {
            throw new ValidationException('Содержимое шаблона должно быть корректным JSON');
        }
        $payload = array_intersect_key($payload, array_flip(['tasks', 'checklists', 'roles', 'timeline', 'expenses', 'payments', 'documents', 'notifications']));
        foreach ($payload as $section => $items) {
            if (!is_array($items)) {
                throw new ValidationException('Раздел «' . $section . '» должен быть массивом');
            }
            if (count($items) > 200) {
                throw new ValidationException('В одном разделе шаблона допускается не более 200 элементов');
            }
        }
        return $payload;
    }
    private function textLength($value) {
        return function_exists('mb_strlen')
            ? mb_strlen((string)$value, 'UTF-8')
            : strlen((string)$value);
    }
}
