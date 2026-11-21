<?php
class Order {
    use OrderAccess;
    use OrderSupport;
    use OrderOrganizerSupport;
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function statuses() {
        return [
            'new_lead' => 'новая заявка',
            'discussion' => 'обсуждение',
            'estimate' => 'смета',
            'contract' => 'договор',
            'prepayment' => 'предоплата',
            'preparation' => 'подготовка',
            'event_day' => 'день мероприятия',
            'completed' => 'завершено',
            'cancelled' => 'отменено',
        ];
    }
    public function createFromLead($leadId, $data, $creatorId) {
        return (new OrderCreationService($this->db))->createFromLead($leadId, $data, $creatorId);
    }
    public function updateVenue($orderId, $userId, $venue, $address) {
        try {
            $order = $this->getById((int)$orderId);
            if (!$this->canMutate($order, (int)$userId, currentRole())) {
                throw new AccessDeniedException('Недостаточно прав для изменения площадки');
            }
            $venue = trim((string)$venue);
            $address = trim((string)$address);
            if (utf8Length($venue) > 190 || utf8Length($address) > 255) {
                throw new ValidationException('Название или адрес площадки слишком длинные');
            }
            $location = 'Адрес уточняется';
            if ($address !== '') {
                $location = $address;
            } elseif ($venue !== '') {
                $location = $venue;
            }
            $this->db->beginTransaction();
            $this->db->prepare('UPDATE orders SET venue=?,address=? WHERE id=?')
                ->execute([$venue !== '' ? $venue : null, $address !== '' ? $address : null, (int)$orderId]);
            if (!empty($order['event_id'])) {
                $this->db->prepare('UPDATE events SET location=? WHERE id=? AND organizer_id=?')
                    ->execute([$location, (int)$order['event_id'], (int)$userId]);
            }
            if (dbTableExists('calendar_entries')) {
                $this->db->prepare('UPDATE calendar_entries SET location=? WHERE order_id=?')
                    ->execute([$location, (int)$orderId]);
            }
            $this->db->commit();
            AuditLog::record('order.venue_updated', 'order', (int)$orderId, [
                'venue' => $order['venue'] ?? null,
                'address' => $order['address'] ?? null,
            ], ['venue' => $venue, 'address' => $address], ['order_id' => (int)$orderId, 'user_id' => (int)$userId]);
            return ['success' => true];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => publicExceptionMessage($error, 'Не удалось сохранить площадку')];
        }
    }
    public function getForClient($clientId) {
        $stmt = $this->db->prepare("
            SELECT o.*, m.first_name as manager_first_name, m.last_name as manager_last_name,
                   org.first_name as organizer_first_name, org.last_name as organizer_last_name
            FROM orders o
            LEFT JOIN users m ON o.manager_id = m.id
            LEFT JOIN users org ON o.organizer_id = org.id
            WHERE o.client_id = ?
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    }
    public function getForCrmUser($userId, $role, $filters = []) {
        $clientEmailSelect = $this->clientEmailSelect();
        $clientEmailExpression = dbColumnExists('users', 'email') ? 'c.email' : "''";
        $sql = "
            SELECT o.*, c.first_name AS client_first_name, c.last_name AS client_last_name,
                   c.phone AS client_phone, {$clientEmailSelect},
                   org.first_name AS organizer_first_name, org.last_name AS organizer_last_name
            FROM orders o
            JOIN users c ON o.client_id = c.id
            LEFT JOIN users org ON o.organizer_id = org.id
            WHERE 1=1
        ";
        $params = [];
        if ($role === 'organizer') {
            $sql .= ' AND (o.organizer_id = ? OR o.organizer_id IS NULL)';
            $params[] = (int)$userId;
        }
        if (!empty($filters['status']) && array_key_exists($filters['status'], $this->statuses())) {
            $sql .= ' AND o.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['query'])) {
            $term = '%' . trim((string)$filters['query']) . '%';
            $sql .= ' AND (CAST(o.id AS CHAR) LIKE ? OR o.title LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?'
                . ' OR c.phone LIKE ? OR ' . $clientEmailExpression . ' LIKE ?'
                . ' OR org.first_name LIKE ? OR org.last_name LIKE ?)';
            array_push($params, $term, $term, $term, $term, $term, $term, $term, $term);
        }
        $sql .= ' ORDER BY o.event_date IS NULL, o.event_date ASC, o.created_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function updateStatus($orderId, $newStatus, $userId, $comment = null) {
        $order = $this->getById($orderId);
        $comment = trim((string)$comment);
        $error = $this->orderStatusError($order, $newStatus, $userId, $comment);
        if ($error !== null) {
            return ['success' => false, 'error' => $error];
        }
        $comment = $comment !== '' ? $comment : null;
        $oldStatus = $order['status'];
        if ($oldStatus === $newStatus) {
            return ['success' => true, 'unchanged' => true];
        }
        $stmt = $this->db->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $orderId]);
        $stmt = $this->db->prepare("
            INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, comment)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$orderId, $oldStatus, $newStatus, $userId, $comment]);
        AuditLog::record('order.status_changed', 'order', (int)$orderId, ['status' => $oldStatus], ['status' => $newStatus, 'comment' => $comment], [
            'order_id' => (int)$orderId, 'lead_id' => $order['lead_id'], 'user_id' => $userId,
        ]);
        (new Notification())->create(
            $order['client_id'],
            'order_status',
            'Статус заказа обновлен',
            'Новый статус: ' . ($this->statuses()[$newStatus] ?? $newStatus),
            '/account/order.php?id=' . (int)$orderId
        );
        (new TelegramNotifier())->notifyOrderStatus($order, $oldStatus, $newStatus);
        if ($newStatus === 'completed' && dbTableExists('reviews') && dbColumnExists('reviews', 'order_id')) {
            (new OrderReview())->requestForOrder($orderId, $userId);
        }
        return ['success' => true];
    }
    public function getStatusHistory($orderId) {
        $order = $this->getById((int)$orderId);
        if (!$this->canCollaborate($order, (int)($_SESSION['user_id'] ?? 0), currentRole())) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT h.*, u.first_name, u.last_name
            FROM order_status_history h
            LEFT JOIN users u ON h.changed_by = u.id
            WHERE h.order_id = ?
            ORDER BY h.created_at DESC
        ");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }
    public function getMaterials($orderId, $clientOnly = false) {
        $order = $this->getById((int)$orderId);
        if (!$this->canCollaborate($order, (int)($_SESSION['user_id'] ?? 0), currentRole())) {
            return [];
        }
        if (isWorkerRole()) {
            return [];
        }
        if (currentRole() === 'client') {
            $clientOnly = true;
        }
        $sql = "
            SELECT om.*, u.first_name, u.last_name
            FROM order_materials om
            LEFT JOIN users u ON om.uploaded_by = u.id
            WHERE om.order_id = ?
        ";
        $params = [$orderId];
        if ($clientOnly) {
            $sql .= " AND om.visibility = 'client'";
        }
        $sql .= " ORDER BY om.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function addMaterial($orderId, $userId, $data, $file = null) {
        $upload = ['success' => false];
        try {
            $material = $this->normalizeMaterialInput($orderId, $userId, $data);
            if ($file && !empty($file['name'])) {
                $upload = $this->uploadMaterialFile($file);
                if (!$upload['success']) {
                    throw new FileStorageException($upload['error']);
                }
            }
            if (empty($upload['file_path']) && $material['body'] === '' && $material['description'] === '') {
                throw new ValidationException('Добавьте текст, описание или файл');
            }
            $stmt = $this->db->prepare("
                INSERT INTO order_materials (
                    order_id, uploaded_by, title, material_type, file_path, file_name,
                    mime_type, file_size, body, description, visibility
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $orderId,
                $userId,
                $material['title'],
                $material['material_type'],
                $upload['file_path'] ?? null,
                $upload['file_name'] ?? null,
                $upload['mime_type'] ?? null,
                $upload['file_size'] ?? null,
                $material['body'] !== '' ? $material['body'] : null,
                $material['description'] !== '' ? $material['description'] : null,
                $material['visibility'],
            ]);
            $materialId = (int)$this->db->lastInsertId();
            AuditLog::record('order.material_created', 'order_material', $materialId, null, [
                'title' => $material['title'], 'material_type' => $material['material_type'],
                'visibility' => $material['visibility'], 'has_file' => !empty($upload['file_path']),
            ], ['order_id' => (int)$orderId, 'user_id' => $userId]);
            $this->announceClientMaterial($orderId, $userId, $material, $upload);
            $result = ['success' => true, 'material_id' => $materialId];
        } catch (Throwable $error) {
            if (!empty($upload['file_path'])) {
                FileStorage::deleteOwnedFile($upload['file_path'], 'materials', 'order', (int)$orderId);
            }
            $result = ['success' => false, 'error' => publicExceptionMessage($error, 'Не удалось сохранить материал')];
        }
        return $result;
    }
    public function getTasks($orderId, $clientOnly = false, $assignedTo = null) {
        $viewerId = (int)($_SESSION['user_id'] ?? 0);
        $role = currentRole();
        $order = $this->getById((int)$orderId);
        if (!$this->canCollaborate($order, $viewerId, $role)) {
            return [];
        }
        if ($role === 'client') {
            $clientOnly = true;
            $assignedTo = null;
        } elseif (isWorkerRole($role)) {
            $clientOnly = false;
            $assignedTo = $viewerId;
        }
        $sql = "
            SELECT t.*, u.first_name, u.last_name
            FROM order_tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.order_id = ?
        ";
        $params = [$orderId];
        if ($clientOnly) {
            $sql .= " AND t.visibility = 'client'";
        }
        if ($assignedTo) {
            $sql .= " AND t.assigned_to = ?";
            $params[] = $assignedTo;
        }
        $sql .= " ORDER BY FIELD(t.status, 'todo', 'in_progress', 'done', 'cancelled'), t.due_date IS NULL, t.due_date ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function addTask($orderId, $userId, $data) {
        try {
            $task = $this->normalizeTaskInput($orderId, $userId, $data);
            $stmt = $this->db->prepare("
                INSERT INTO order_tasks (order_id, assigned_to, created_by, title, description, status, due_date, visibility)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $orderId,
                $task['assigned_to'],
                $userId,
                $task['title'],
                $task['description'] !== '' ? $task['description'] : null,
                $task['status'],
                $task['due_date'] !== '' ? $task['due_date'] : null,
                $task['visibility'],
            ]);
            $taskId = (int)$this->db->lastInsertId();
            AuditLog::record('order.task_created', 'order_task', $taskId, null, [
                'title' => $task['title'], 'assigned_to' => $task['assigned_to'],
                'due_date' => $task['due_date'] !== '' ? $task['due_date'] : null,
            ], ['order_id' => (int)$orderId, 'user_id' => $userId]);
            if ($task['assigned_to']) {
                (new TelegramNotifier())->notifyUser($task['assigned_to'], 'task_created', 'Новая задача: ' . $task['title'], 'task-created-' . $taskId);
            }
            $result = ['success' => true, 'task_id' => $taskId];
        } catch (Throwable $error) {
            $result = ['success' => false, 'error' => publicExceptionMessage($error, 'Не удалось сохранить задачу')];
        }
        return $result;
    }
    public function getChecklist($orderId) {
        if (!dbTableExists('order_checklist_items')) {
            return [];
        }
        $order = $this->getById((int)$orderId);
        if (!$this->canCollaborate($order, (int)($_SESSION['user_id'] ?? 0), currentRole())) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT ci.*,creator.first_name creator_first_name,creator.last_name creator_last_name,done.first_name done_first_name,done.last_name done_last_name FROM order_checklist_items ci LEFT JOIN users creator ON creator.id=ci.created_by LEFT JOIN users done ON done.id=ci.done_by WHERE ci.order_id=? ORDER BY ci.sort_order,ci.id");
        $stmt->execute([(int)$orderId]);
        return $stmt->fetchAll();
    }
    public function updateTaskStatus($taskId, $userId, $status) {
        $stmt = $this->db->prepare("SELECT t.*,o.client_id,o.manager_id,o.organizer_id,o.title order_title FROM order_tasks t JOIN orders o ON o.id=t.order_id WHERE t.id=? LIMIT 1");
        $stmt->execute([(int)$taskId]);
        $task = $stmt->fetch();
        $context = $this->taskStatusContext($task, $userId, $status);
        if (!$context['success']) {
            return $context;
        }
        if ($task['status'] === $status) {
            return ['success' => true, 'unchanged' => true];
        }
        $stmt = $this->db->prepare('UPDATE order_tasks SET status=? WHERE id=? AND status=?');
        $stmt->execute([$status, (int)$taskId, $task['status']]);
        if ($stmt->rowCount() !== 1) {
            $result = ['success' => false, 'error' => 'Задача уже изменена другим пользователем'];
        } else {
            AuditLog::record('order.task_status_changed', 'order_task', (int)$taskId, ['status' => $task['status']], ['status' => $status], [
                'order_id' => (int)$task['order_id'], 'user_id' => (int)$userId,
            ]);
            $this->notifyTaskStatus($task, $userId, $status, $context['worker_update']);
            $result = ['success' => true];
        }
        return $result;
    }
    public function addChecklistItem($orderId, $userId, $title) {
        $order = $this->getById((int)$orderId);
        $title = trim((string)$title);
        if (!$this->canMutate($order, (int)$userId, currentRole())) {
            return ['success' => false, 'error' => 'Чек-лист заказа недоступен'];
        }
        if ($title === '' || $this->textLength($title) > 500) {
            return ['success' => false, 'error' => 'Проверьте текст пункта'];
        }
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order),-1)+1 FROM order_checklist_items WHERE order_id=?');
        $stmt->execute([(int)$orderId]);
        $sortOrder = (int)$stmt->fetchColumn();
        $stmt = $this->db->prepare('INSERT INTO order_checklist_items (order_id,title,sort_order,created_by) VALUES (?,?,?,?)');
        $stmt->execute([(int)$orderId, $title, $sortOrder, (int)$userId]);
        $id = (int)$this->db->lastInsertId();
        AuditLog::record('order.checklist_item_created', 'order_checklist_item', $id, null, ['title' => $title], ['order_id' => (int)$orderId, 'user_id' => $userId]);
        return ['success' => true, 'checklist_id' => $id];
    }
    public function toggleChecklistItem($itemId, $userId, $done) {
        $stmt = $this->db->prepare('SELECT * FROM order_checklist_items WHERE id=? LIMIT 1');
        $stmt->execute([(int)$itemId]);
        $item = $stmt->fetch();
        $order = $item ? $this->getById((int)$item['order_id']) : null;
        if (!$item || !$this->canMutate($order, (int)$userId, currentRole())) {
            return ['success' => false, 'error' => 'Пункт чек-листа недоступен'];
        }
        $done = (bool)$done;
        $this->db->prepare('UPDATE order_checklist_items SET is_done=?,done_by=?,done_at=? WHERE id=?')
            ->execute([$done ? 1 : 0, $done ? (int)$userId : null, $done ? date('Y-m-d H:i:s') : null, (int)$itemId]);
        AuditLog::record('order.checklist_item_changed', 'order_checklist_item', (int)$itemId, ['is_done' => (bool)$item['is_done']], ['is_done' => $done], ['order_id' => (int)$item['order_id'], 'user_id' => $userId]);
        return ['success' => true];
    }
    public function getTeam($orderId, $clientView = false) {
        $order = $this->getById((int)$orderId);
        if (!$this->canCollaborate($order, (int)($_SESSION['user_id'] ?? 0), currentRole())) {
            return [];
        }
        if (currentRole() === 'client' || isWorkerRole()) {
            $clientView = true;
        }
        if ($clientView
            && currentRole() === 'client'
            && dbColumnExists('orders', 'show_team_to_client')
            && empty($order['show_team_to_client'])) {
            return [];
        }
        $profilePublicRole = dbColumnExists('worker_profiles', 'public_role')
            ? 'wp.public_role as profile_public_role'
            : 'wp.specialty as profile_public_role';
        if (dbColumnExists('worker_profiles', 'public_description')) {
            $publicDescription = 'wp.public_description';
        } elseif (dbColumnExists('worker_profiles', 'additional_info')) {
            $publicDescription = 'wp.additional_info as public_description';
        } else {
            $publicDescription = 'NULL as public_description';
        }
        $experienceYears = dbColumnExists('worker_profiles', 'experience_years')
            ? 'wp.experience_years'
            : '0 as experience_years';
        $rating = !$clientView && dbColumnExists('worker_profiles', 'rating')
            ? 'wp.rating'
            : 'NULL as rating';
        $reviewsCount = !$clientView && dbColumnExists('worker_profiles', 'reviews_count')
            ? 'wp.reviews_count'
            : 'NULL as reviews_count';
        $teamFields = $clientView
            ? 'ot.id, ot.order_id, ot.worker_id, ot.public_role, ot.tasks_summary, ot.participation_status AS status'
            : 'ot.*';
        $sql = "
            SELECT {$teamFields}, u.first_name, u.last_name, u.avatar, u.role,
                   wp.specialty, {$profilePublicRole}, {$publicDescription},
                   {$experienceYears}, {$rating}, {$reviewsCount}
            FROM order_team ot
            JOIN users u ON ot.worker_id = u.id
            LEFT JOIN worker_profiles wp ON u.id = wp.user_id
            WHERE ot.order_id = ?
        ";
        $params = [$orderId];
        if ($clientView && dbColumnExists('order_team', 'show_to_client')) {
            $sql .= ' AND ot.show_to_client = 1';
        }
        $sql .= " ORDER BY ot.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function claimOrder($orderId, $organizerId) {
        return (new OrderClaimService($this->db, $this))->claim(
            (int)$orderId,
            (int)$organizerId
        );
    }
    public function replaceOrganizer(int $orderId, int $organizerId, int $actorId): array {
        return (new OrderOrganizerReplacementService($this->db))->replace(
            $orderId,
            $organizerId,
            $actorId
        );
    }
}
