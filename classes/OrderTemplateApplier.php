<?php
class OrderTemplateApplier {
    private const DEFAULT_START_TIME = '10:00';
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function apply($templateId, $orderId, $userId) {
        try {
            [$order, $payload] = $this->context($templateId, $orderId, $userId);
            $this->db->beginTransaction();
            $this->copyPayload($templateId, $orderId, $userId, $order, $payload);
            (new OrderFinance())->recalculate($orderId);
            $this->db->commit();
            AuditLog::record('template.applied', 'event_template', (int)$templateId, null, [
                'order_id' => (int)$orderId,
                'snapshot' => $payload,
            ], ['order_id' => (int)$orderId, 'user_id' => $userId]);
            $result = ['success' => true, 'warnings' => []];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $duplicate = $this->isDuplicateApplication($error);
            $result = [
                'success' => false,
                'error' => $duplicate
                    ? 'Этот шаблон уже применён к заказу'
                    : publicExceptionMessage($error, 'Не удалось применить шаблон. Проверьте его данные и повторите попытку.'),
            ];
        }
        return $result;
    }
    private function isDuplicateApplication(Throwable $error) {
        if (!$error instanceof PDOException) {
            return false;
        }
        $driverCode = (int)($error->errorInfo[1] ?? 0);
        $message = (string)($error->errorInfo[2] ?? $error->getMessage());
        return $driverCode === 1062 && str_contains($message, 'uq_template_application');
    }
    private function context($templateId, $orderId, $userId) {
        $stmt = $this->db->prepare("SELECT * FROM event_templates WHERE id=?");
        $stmt->execute([(int)$templateId]);
        $template = $stmt->fetch();
        $order = (new Order())->getById($orderId);
        if (!$template || !$order || !(int)$template['is_active']) {
            throw new EntityNotFoundException('Шаблон или заказ не найден');
        }
        if ((int)($_SESSION['user_id'] ?? 0) !== (int)$userId || !can('manage_orders')
            || !(new Order())->canMutate($order, (int)$userId, currentRole())) {
            throw new AccessDeniedException('Заказ недоступен для применения шаблона');
        }
        $payload = json_decode($template['template_payload'] ?? '[]', true);
        if (!is_array($payload)) {
            throw new ValidationException('Содержимое шаблона повреждено');
        }
        return [$order, $payload];
    }
    private function copyPayload($templateId, $orderId, $userId, $order, $payload) {
        $snapshotStmt = $this->db->prepare("INSERT INTO order_template_applications (order_id,template_id,snapshot_json,applied_by) VALUES (?,?,?,?)");
        $snapshotStmt->execute([
            (int)$orderId,
            (int)$templateId,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            (int)$userId,
        ]);
        $this->copyTasks($orderId, $userId, $order, $payload['tasks'] ?? []);
        $this->copyChecklists($orderId, $userId, $payload['checklists'] ?? []);
        $this->copyExpenses($orderId, $userId, $payload['expenses'] ?? []);
        $this->copyPayments($orderId, $userId, $order, $payload['payments'] ?? []);
        $this->copyTimeline($orderId, $userId, $order, $payload['timeline'] ?? []);
        $this->copyRoles($orderId, $payload['roles'] ?? []);
        $this->copyNotifications($orderId, $order, $payload['notifications'] ?? []);
        $this->copyDocuments($orderId, $userId, $payload['documents'] ?? []);
    }
    private function copyTasks($orderId, $userId, $order, $tasks) {
        $stmt = $this->db->prepare("INSERT INTO order_tasks (order_id,created_by,title,description,status,due_date,visibility) VALUES (?,?,?,?,'todo',?,?)");
        foreach ((array)$tasks as $task) {
            $title = trim((string)($task['title'] ?? ''));
            if ($title !== '') {
                $visibility = in_array($task['visibility'] ?? '', ['internal', 'client'], true) ? $task['visibility'] : 'internal';
                $stmt->execute([
                    (int)$orderId,
                    (int)$userId,
                    $title,
                    trim((string)($task['description'] ?? '')) ?: null,
                    $this->relativeDate($order['event_date'], $task['days_before'] ?? null),
                    $visibility,
                ]);
            }
        }
    }
    private function copyChecklists($orderId, $userId, $checklists) {
        if (!dbTableExists('order_checklist_items')) {
            return;
        }
        $stmt = $this->db->prepare("INSERT INTO order_checklist_items (order_id,title,sort_order,created_by) VALUES (?,?,?,?)");
        foreach ((array)$checklists as $position => $check) {
            $title = trim((string)(is_array($check) ? ($check['title'] ?? '') : $check));
            if ($title !== '') {
                $title = function_exists('mb_substr') ? mb_substr($title, 0, 500, 'UTF-8') : substr($title, 0, 500);
                $stmt->execute([(int)$orderId, $title, (int)$position, (int)$userId]);
            }
        }
    }
    private function copyExpenses($orderId, $userId, $expenses) {
        $categories = ['flowers', 'decor', 'rent', 'transport', 'installation', 'dismantling', 'contractors', 'staff', 'other'];
        $stmt = $this->db->prepare("INSERT INTO order_expenses (order_id,category,title,planned_amount,actual_amount,created_by) VALUES (?,?,?,?,0,?)");
        foreach ((array)$expenses as $expense) {
            $category = in_array($expense['category'] ?? '', $categories, true) ? $expense['category'] : 'other';
            $stmt->execute([
                (int)$orderId,
                $category,
                trim((string)($expense['title'] ?? 'Расход')),
                max(0, (float)($expense['amount'] ?? 0)),
                (int)$userId,
            ]);
        }
    }
    private function copyPayments($orderId, $userId, $order, $payments) {
        $stmt = $this->db->prepare("INSERT INTO order_payments (order_id,title,amount,due_date,status,created_by) VALUES (?,?,?,?,'planned',?)");
        foreach ((array)$payments as $payment) {
            $stmt->execute([
                (int)$orderId,
                trim((string)($payment['title'] ?? 'Платёж')),
                max(0, (float)($payment['amount'] ?? 0)),
                $this->relativeDate($order['event_date'], $payment['days_before'] ?? null),
                (int)$userId,
            ]);
        }
    }
    private function copyTimeline($orderId, $userId, $order, $timeline) {
        $timelineStmt = $this->db->prepare("INSERT INTO order_timeline_items (order_id,title,description,starts_at,ends_at,status,priority,sort_order,created_by) VALUES (?,?,?,?,?,'planned',?,?,?)");
        $checkStmt = $this->db->prepare("INSERT INTO timeline_checklist_items (timeline_item_id,title,sort_order) VALUES (?,?,?)");
        $eventDate = $order['event_date'] ?: date('Y-m-d');
        foreach ((array)$timeline as $position => $item) {
            $startTime = $item['start_time'] ?? self::DEFAULT_START_TIME;
            $start = $eventDate . ' ' . $startTime . ':00';
            $end = $eventDate . ' ' . ($item['end_time'] ?? $startTime) . ':00';
            if (strtotime($end) < strtotime($start)) {
                $end = date('Y-m-d H:i:s', strtotime($end . ' +1 day'));
            }
            $priority = in_array($item['priority'] ?? '', ['low', 'normal', 'high', 'critical'], true) ? $item['priority'] : 'normal';
            $timelineStmt->execute([
                (int)$orderId,
                trim((string)($item['title'] ?? 'Этап')),
                trim((string)($item['description'] ?? '')) ?: null,
                $start,
                $end,
                $priority,
                (int)$position,
                (int)$userId,
            ]);
            $timelineId = (int)$this->db->lastInsertId();
            foreach ((array)($item['checklist'] ?? []) as $checkPosition => $checkTitle) {
                $checkTitle = trim((string)$checkTitle);
                if ($checkTitle !== '') {
                    $checkStmt->execute([$timelineId, $checkTitle, (int)$checkPosition]);
                }
            }
        }
    }
    private function copyRoles($orderId, $roles) {
        $allowedRoles = [
            'florist' => 'Флорист',
            'courier' => 'Курьер',
            'installer' => 'Монтажник',
            'helper' => 'Помощник',
        ];
        $stmt = $this->db->prepare("INSERT INTO order_role_requirements (order_id,role_key,role_label,quantity,notes) VALUES (?,?,?,?,?)");
        foreach ((array)$roles as $role) {
            $key = preg_replace('/[^a-z\d_-]/i', '', (string)($role['role'] ?? $role['key'] ?? ''));
            if (!isset($allowedRoles[$key])) {
                continue;
            }
            $stmt->execute([
                (int)$orderId,
                $key,
                $allowedRoles[$key],
                max(1, min(100, (int)($role['quantity'] ?? 1))),
                trim((string)($role['notes'] ?? '')) ?: null,
            ]);
        }
    }
    private function copyNotifications($orderId, $order, $notifications) {
        $stmt = $this->db->prepare("INSERT INTO order_scheduled_notifications (order_id,event_type,recipient_type,recipient_id,send_at,payload_json) VALUES (?,?,?,?,?,?)");
        foreach ((array)$notifications as $notice) {
            $sendDate = $this->relativeDate($order['event_date'], $notice['days_before'] ?? null);
            if ($sendDate) {
                $recipientType = in_array($notice['recipient_type'] ?? '', ['client', 'organizer', 'user'], true)
                    ? $notice['recipient_type']
                    : 'organizer';
                $sendTime = preg_match('/^\d{2}:\d{2}$/', (string)($notice['send_time'] ?? ''))
                    ? $notice['send_time']
                    : self::DEFAULT_START_TIME;
                $text = trim((string)($notice['text'] ?? 'Напоминание по заказу «' . $order['title'] . '»'));
                $stmt->execute([
                    (int)$orderId,
                    substr((string)($notice['event_type'] ?? 'template_reminder'), 0, 80),
                    $recipientType,
                    !empty($notice['recipient_id']) ? (int)$notice['recipient_id'] : null,
                    $sendDate . ' ' . $sendTime . ':00',
                    json_encode(['text' => $text], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }
    private function copyDocuments($orderId, $userId, $documents) {
        foreach ((array)$documents as $document) {
            $result = (new OrderDocument())->create($orderId, $userId, [
                'template_id' => $document['template_id'] ?? null,
                'document_type' => $document['document_type'] ?? 'custom',
                'title' => $document['title'] ?? '',
                'body_html' => $document['body_html'] ?? '',
            ]);
            if (!$result['success']) {
                throw new PersistenceException($result['error'] ?? 'Не удалось создать документ шаблона');
            }
        }
    }
    private function relativeDate($eventDate, $daysBefore) {
        $date = null;
        if ($eventDate && $daysBefore !== null && $daysBefore !== '') {
            $date = date('Y-m-d', strtotime($eventDate . ' -' . max(0, (int)$daysBefore) . ' days'));
        }
        return $date;
    }
}
