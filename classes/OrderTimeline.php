<?php
class OrderTimeline {
    use OrderTimelineSupport;
    private const DATE_TIME_FORMAT = 'Y-m-d H:i:s';
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function statuses() {
        return [
            'planned' => 'Запланировано', 'soon' => 'Скоро', 'in_progress' => 'Выполняется',
            'completed' => 'Выполнено', 'delayed' => 'Задержка', 'cancelled' => 'Отменено',
        ];
    }
    public function create($orderId, $userId, $data, $participants = [], $checklist = [], $files = []) {
        $storedPaths = [];
        $itemId = null;
        try {
            $item = $this->normalizeTimelineInput($orderId, $userId, $data, $participants, $checklist, $files);
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("INSERT INTO order_timeline_items (order_id,title,description,starts_at,ends_at,location,responsible_id,status,priority,comments,sort_order,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                (int)$orderId,
                $item['title'],
                $item['description'] ?: null,
                $item['start'],
                $item['end'],
                $item['location'] ?: null,
                $item['responsible_id'],
                $item['status'],
                $item['priority'],
                $item['comments'] ?: null,
                $item['sort_order'],
                (int)$userId,
            ]);
            $itemId = (int)$this->db->lastInsertId();
            $this->storeTimelineRelations($itemId, $userId, $item, $files, $storedPaths);
            $this->db->prepare("INSERT INTO timeline_history (timeline_item_id,changed_by,new_values) VALUES (?,?,?)")
                ->execute([$itemId, (int)$userId, json_encode([
                    'status' => $item['status'],
                    'starts_at' => $item['start'],
                    'ends_at' => $item['end'],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $this->db->commit();
            AuditLog::record('timeline.created', 'timeline_item', $itemId, null, [
                'title' => $item['title'],
                'starts_at' => $item['start'],
                'responsible_id' => $item['responsible_id'],
            ], ['order_id' => (int)$orderId, 'user_id' => $userId]);
            foreach ($item['participants'] as $participantId) {
                (new TelegramNotifier())->notifyUser($participantId, 'timeline_assignment', 'Вы добавлены в этап «' . $item['title'] . '» (' . formatRussianDateTime($item['start']) . ').', 'timeline-' . $itemId . '-user-' . $participantId);
            }
            $result = ['success' => true, 'item_id' => $itemId];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            foreach ($storedPaths as $path) {
                FileStorage::deleteOwnedFile($path, 'timeline', 'timeline_item', $itemId);
            }
            $result = ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось создать этап таймлайна')];
        }
        return $result;
    }
    public function changeStatus($itemId, $status, $userId) {
        $item = $this->getById($itemId);
        $error = $this->timelineStatusError($item, $status, $userId);
        if ($error !== null) {
            $result = ['success' => false, 'error' => $error];
        } elseif ($item['status'] === $status) {
            $result = ['success' => true];
        } else {
            $completed = $status === 'completed';
            $stmt = $this->db->prepare("UPDATE order_timeline_items SET status=?,completed_by=?,completed_at=? WHERE id=?");
            $stmt->execute([$status, $completed ? (int)$userId : null, $completed ? date(self::DATE_TIME_FORMAT) : null, (int)$itemId]);
            $historyStmt = $this->db->prepare("INSERT INTO timeline_history (timeline_item_id,changed_by,old_values,new_values) VALUES (?,?,?,?)");
            $historyStmt->execute([
                (int)$itemId,
                (int)$userId,
                json_encode(['status' => $item['status']], JSON_THROW_ON_ERROR),
                json_encode(['status' => $status], JSON_THROW_ON_ERROR),
            ]);
            $historyId = (int)$this->db->lastInsertId();
            AuditLog::record('timeline.status_changed', 'timeline_item', (int)$itemId, ['status' => $item['status']], ['status' => $status], ['order_id' => $item['order_id'], 'user_id' => $userId]);
            $this->notifyTimelineStatus($item, $status, $userId, $historyId);
            $result = ['success' => true];
        }
        return $result;
    }
    public function toggleChecklist($checklistId, $userId, $done) {
        $stmt = $this->db->prepare("SELECT ci.*,ti.order_id FROM timeline_checklist_items ci JOIN order_timeline_items ti ON ti.id=ci.timeline_item_id WHERE ci.id=?");
        $stmt->execute([(int)$checklistId]);
        $item = $stmt->fetch();
        if (!$item) {
            return ['success' => false, 'error' => 'Пункт не найден'];
        }
        $timelineItem = $this->getById((int)$item['timeline_item_id']);
        if (!$timelineItem || !$this->canUpdateItem($timelineItem, $userId)) {
            return ['success' => false, 'error' => 'Пункт недоступен'];
        }
        $stmt = $this->db->prepare("UPDATE timeline_checklist_items SET is_done=?,done_by=?,done_at=? WHERE id=?");
        $stmt->execute([$done ? 1 : 0, $done ? (int)$userId : null, $done ? date(self::DATE_TIME_FORMAT) : null, (int)$checklistId]);
        AuditLog::record('timeline.checklist_changed', 'timeline_checklist', (int)$checklistId, ['is_done' => (bool)$item['is_done']], ['is_done' => (bool)$done], ['order_id' => $item['order_id'], 'user_id' => $userId]);
        return ['success' => true];
    }
    public function reorder($orderId, $orderedIds, $userId) {
        if (!$this->canManageOrder($orderId, $userId)) {
            return ['success' => false, 'error' => 'Таймлайн заказа недоступен'];
        }
        $orderedIds = array_slice(array_values(array_unique(array_map('intval', (array)$orderedIds))), 0, 500);
        $stmt = $this->db->prepare("UPDATE order_timeline_items SET sort_order=? WHERE id=? AND order_id=?");
        $position = 0;
        foreach ($orderedIds as $id) {
            $stmt->execute([$position++, $id, (int)$orderId]);
        }
        AuditLog::record('timeline.reordered', 'order', (int)$orderId, null, ['item_ids' => array_values($orderedIds)], ['order_id' => (int)$orderId, 'user_id' => $userId]);
        return ['success' => true];
    }
    public function move($itemId, $direction, $userId) {
        $item = $this->getById((int)$itemId);
        if (!$item || !$this->canManageOrder((int)$item['order_id'], (int)$userId)) {
            $result = ['success' => false, 'error' => 'Этап недоступен'];
        } elseif (!in_array($direction, ['up', 'down'], true)) {
            $result = ['success' => false, 'error' => 'Недопустимое направление'];
        } else {
            $stmt = $this->db->prepare("SELECT id FROM order_timeline_items WHERE order_id=? ORDER BY sort_order,starts_at,id");
            $stmt->execute([(int)$item['order_id']]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $index = array_search((int)$itemId, $ids, true);
            $target = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index === false || !isset($ids[$target])) {
                $result = ['success' => true];
            } else {
                [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
                $result = $this->reorder((int)$item['order_id'], $ids, (int)$userId);
            }
        }
        return $result;
    }
    public function getForOrder($orderId, $userId = null, $workerOnly = false) {
        $userId = (int)($userId ?: ($_SESSION['user_id'] ?? 0));
        $order = (new Order())->getById((int)$orderId);
        if ($workerOnly) {
            if (!isWorkerRole() || $userId !== (int)($_SESSION['user_id'] ?? 0)
                || !(new Order())->canCollaborate($order, $userId, currentRole())) {
                return [];
            }
        } elseif (!$order || !(new Order())->canCollaborate($order, $userId, currentRole())) {
            return [];
        }
        $sql = "SELECT ti.*,u.first_name responsible_first_name,u.last_name responsible_last_name FROM order_timeline_items ti LEFT JOIN users u ON u.id=ti.responsible_id";
        $params = [(int)$orderId];
        if ($workerOnly) {
            $sql .= ' JOIN timeline_participants tp ON tp.timeline_item_id=ti.id AND tp.user_id=?';
            array_unshift($params, (int)$userId);
        }
        $sql .= ' WHERE ti.order_id=? ORDER BY ti.sort_order,ti.starts_at,ti.id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            $item['checklist'] = $this->checklist($item['id']);
            $item['files'] = $this->files($item['id']);
            $item['participants'] = $this->participants($item['id']);
        }
        return $items;
    }
    public function progress($orderId) {
        $order = (new Order())->getById((int)$orderId);
        if (!$order || !(new Order())->canCollaborate($order, (int)($_SESSION['user_id'] ?? 0), currentRole())) {
            return ['total' => 0, 'completed' => 0, 'delayed' => 0, 'percent' => 0];
        }
        $stmt = $this->db->prepare("SELECT COUNT(*) total,SUM(status='completed') completed,SUM(status='delayed' OR (ends_at<NOW() AND status NOT IN ('completed','cancelled'))) delayed FROM order_timeline_items WHERE order_id=? AND status<>'cancelled'");
        $stmt->execute([(int)$orderId]);
        $row = $stmt->fetch();
        $row['percent'] = (int)$row['total'] > 0 ? round(((int)$row['completed'] / (int)$row['total']) * 100) : 0;
        return $row;
    }
    public function checklist($itemId) {
        $stmt = $this->db->prepare("SELECT * FROM timeline_checklist_items WHERE timeline_item_id=? ORDER BY sort_order,id");
        $stmt->execute([(int)$itemId]);
        return $stmt->fetchAll();
    }
    public function files($itemId) {
        $stmt = $this->db->prepare("SELECT * FROM timeline_files WHERE timeline_item_id=? ORDER BY id");
        $stmt->execute([(int)$itemId]);
        return $stmt->fetchAll();
    }
    public function participants($itemId) {
        $stmt = $this->db->prepare("SELECT u.id,u.first_name,u.last_name,u.role FROM timeline_participants tp JOIN users u ON u.id=tp.user_id WHERE tp.timeline_item_id=? ORDER BY u.first_name,u.last_name");
        $stmt->execute([(int)$itemId]);
        return $stmt->fetchAll();
    }
    public function getById($itemId) {
        $stmt = $this->db->prepare("SELECT * FROM order_timeline_items WHERE id=?");
        $stmt->execute([(int)$itemId]);
        return $stmt->fetch();
    }
    public function workerCanAccess($itemId, $userId) {
        if ((int)$userId !== (int)($_SESSION['user_id'] ?? 0) || !isWorkerRole()) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT 1 FROM timeline_participants WHERE timeline_item_id=? AND user_id=? LIMIT 1");
        $stmt->execute([(int)$itemId, (int)$userId]);
        return (bool)$stmt->fetchColumn();
    }
}
