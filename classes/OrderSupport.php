<?php
trait OrderSupport {
    private function orderStatusError($order, $newStatus, $userId, $comment) {
        if (!array_key_exists($newStatus, $this->statuses())) {
            $error = 'Недопустимый статус заказа';
        } elseif (!$order) {
            $error = $this->orderNotFoundMessage();
        } elseif (!$this->canMutate($order, (int)$userId, currentRole())) {
            $error = 'Недостаточно прав для изменения заказа';
        } elseif ($this->textLength($comment) > 2000) {
            $error = 'Комментарий слишком длинный';
        } else {
            $error = null;
        }
        return $error;
    }
    private function normalizeMaterialInput($orderId, $userId, $data) {
        $order = $this->getById($orderId);
        if (!$this->canMutate($order, (int)$userId, currentRole())) {
            throw new AccessDeniedException('Недостаточно прав для добавления материала');
        }
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '' || $this->textLength($title) > 190) {
            throw new ValidationException($title === '' ? 'Укажите название материала' : 'Название материала слишком длинное');
        }
        $body = trim((string)($data['body'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        if ($this->textLength($body) > 50000 || $this->textLength($description) > 5000) {
            throw new ValidationException('Текст материала слишком длинный');
        }
        $types = ['pdf', 'photo', 'text', 'estimate', 'contract', 'concept', 'timeline', 'plan', 'moodboard', 'file'];
        return [
            'title' => $title,
            'body' => $body,
            'description' => $description,
            'material_type' => in_array(($data['material_type'] ?? ''), $types, true) ? $data['material_type'] : 'file',
            'visibility' => in_array(($data['visibility'] ?? ''), ['internal', 'client'], true) ? $data['visibility'] : 'internal',
        ];
    }
    private function announceClientMaterial($orderId, $userId, $material, $upload) {
        if ($material['visibility'] === 'client') {
            $chat = (new Chat())->getOrderClientChat($orderId);
            if ($chat) {
                $messageUpload = ($upload['success'] ?? false) ? $upload : null;
                (new Chat())->sendMessage(
                    $chat['id'],
                    $userId,
                    'Добавлен материал заказа: ' . $material['title'],
                    $messageUpload,
                    $messageUpload['mime_type'] ?? null
                );
            }
        }
    }
    private function normalizeTaskInput($orderId, $userId, $data) {
        $order = $this->getById($orderId);
        if (!$this->canMutate($order, (int)$userId, currentRole())) {
            throw new AccessDeniedException('Недостаточно прав для добавления задачи');
        }
        $title = trim((string)($data['title'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $dueDate = trim((string)($data['due_date'] ?? ''));
        if ($title === '' || $this->textLength($title) > 190) {
            throw new ValidationException($title === '' ? 'Укажите название задачи' : 'Название задачи слишком длинное');
        }
        if ($this->textLength($description) > 10000) {
            throw new ValidationException('Описание задачи слишком длинное');
        }
        if ($dueDate !== '' && !$this->isValidDate($dueDate)) {
            throw new ValidationException('Некорректный срок задачи');
        }
        $assignedTo = !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null;
        if ($assignedTo) {
            $check = $this->db->prepare(
                "SELECT 1 FROM order_team
                 WHERE order_id=? AND worker_id=?
                   AND participation_status IN ('confirmed','on_location','completed')
                 LIMIT 1"
            );
            $check->execute([(int)$orderId, $assignedTo]);
            if (!$check->fetchColumn()) {
                throw new ValidationException('Сначала добавьте исполнителя в команду заказа');
            }
        }
        return [
            'title' => $title,
            'description' => $description,
            'due_date' => $dueDate,
            'assigned_to' => $assignedTo,
            'status' => in_array(($data['status'] ?? ''), ['todo', 'in_progress', 'done', 'cancelled'], true) ? $data['status'] : 'todo',
            'visibility' => in_array(($data['visibility'] ?? ''), ['internal', 'client'], true) ? $data['visibility'] : 'internal',
        ];
    }
    private function taskStatusContext($task, $userId, $status) {
        $allowedStatuses = ['todo', 'in_progress', 'done', 'cancelled'];
        $order = $task ? $this->getById((int)$task['order_id']) : null;
        $workerUpdate = $task && isWorkerRole() && (int)$task['assigned_to'] === (int)$userId
            && $this->canCollaborate($order, (int)$userId, currentRole());
        $managerUpdate = $task && $this->canMutate($order, (int)$userId, currentRole());
        $workerTransitions = ['todo' => ['in_progress', 'done'], 'in_progress' => ['done'], 'done' => [], 'cancelled' => []];
        if (!in_array($status, $allowedStatuses, true)) {
            $error = 'Недопустимый статус задачи';
        } elseif (!$task || (int)($_SESSION['user_id'] ?? 0) !== (int)$userId || (!$workerUpdate && !$managerUpdate)) {
            $error = 'Задача недоступна';
        } elseif ($workerUpdate && $task['status'] !== $status && !in_array($status, $workerTransitions[$task['status']] ?? [], true)) {
            $error = 'Недопустимый переход статуса задачи';
        } else {
            $error = null;
        }
        return $error === null
            ? ['success' => true, 'worker_update' => $workerUpdate]
            : ['success' => false, 'error' => $error];
    }
    private function notifyTaskStatus($task, $userId, $status, $workerUpdate) {
        $recipientId = $workerUpdate ? (int)$task['organizer_id'] : (int)$task['assigned_to'];
        if ($recipientId && $recipientId !== (int)$userId) {
            (new TelegramNotifier())->notifyUser(
                $recipientId,
                'task_changed',
                'Статус задачи «' . $task['title'] . '» изменён: ' . $status . '.',
                'task-status-' . (int)$task['id'] . '-' . $status . '-' . bin2hex(random_bytes(6))
            );
        }
    }
    private function uploadMaterialFile($file) {
        return FileStorage::store(
            $file,
            'materials',
            ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain'],
            UPLOAD_LIMIT_BYTES
        );
    }
    private function clientEmailSelect() {
        return dbColumnExists('users', 'email') ? 'c.email as client_email' : 'NULL as client_email';
    }
    private function orderNotFoundMessage() {
        return 'Заказ не найден';
    }
    private function isValidDate($value) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
        return $date && $date->format('Y-m-d') === $value;
    }
    private function textLength($value) {
        return utf8Length((string)$value);
    }
}
