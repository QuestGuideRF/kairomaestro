<?php
trait OrderTimelineSupport {
    private function dateTime($value) {
        $value = str_replace('T', ' ', trim((string)$value));
        $result = null;
        foreach ([self::DATE_TIME_FORMAT, 'Y-m-d H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($result === null && $date && $date->format($format) === $value) {
                $result = $date->format(self::DATE_TIME_FORMAT);
            }
        }
        return $result;
    }
    private function canManageOrder($orderId, $userId) {
        $order = (new Order())->getById((int)$orderId);
        return can('manage_timeline') && (new Order())->canMutate($order, (int)$userId, currentRole());
    }
    private function canUpdateItem($item, $userId) {
        return (int)($_SESSION['user_id'] ?? 0) === (int)$userId
            && ($this->canManageOrder((int)$item['order_id'], $userId)
                || (isWorkerRole() && $this->workerCanAccess((int)$item['id'], (int)$userId)));
    }
    private function participantsBelongToOrder($orderId, $participantIds) {
        $valid = true;
        if ($participantIds) {
            $order = (new Order())->getById((int)$orderId);
            $allowed = array_filter([(int)($order['manager_id'] ?? 0), (int)($order['organizer_id'] ?? 0)]);
            $stmt = $this->db->prepare('SELECT worker_id FROM order_team WHERE order_id=?');
            $stmt->execute([(int)$orderId]);
            $allowed = array_values(array_unique(array_merge($allowed, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
            $valid = count(array_diff($participantIds, $allowed)) === 0;
        }
        return $valid;
    }
    private function eventIdForOrder($orderId) {
        $stmt = $this->db->prepare('SELECT event_id FROM orders WHERE id=?');
        $stmt->execute([(int)$orderId]);
        return (int)$stmt->fetchColumn();
    }
    private function normalizeFiles($files) {
        if (!$files || empty($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            return [$files];
        }
        $result = [];
        foreach ($files['name'] as $index => $name) {
            if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $result[] = [
                    'name' => $name,
                    'type' => $files['type'][$index] ?? '',
                    'tmp_name' => $files['tmp_name'][$index] ?? '',
                    'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$index] ?? 0,
                ];
            }
        }
        return array_slice($result, 0, 10);
    }
    private function fileCount($files) {
        $names = $files['name'] ?? [];
        if (is_array($names)) {
            $count = count(array_filter($names, static fn($name) => trim((string)$name) !== ''));
        } else {
            $count = trim((string)$names) === '' ? 0 : 1;
        }
        return $count;
    }
    private function textLength($value) {
        return utf8Length((string)$value);
    }
    private function normalizeTimelineInput($orderId, $userId, $data, $participants, $checklist, $files) {
        if (!$this->canManageOrder($orderId, $userId)) {
            throw new AccessDeniedException('Таймлайн заказа недоступен');
        }
        $item = [
            'start' => $this->dateTime($data['starts_at'] ?? ''),
            'end' => $this->dateTime($data['ends_at'] ?? ''),
            'title' => trim((string)($data['title'] ?? '')),
            'description' => trim((string)($data['description'] ?? '')),
            'location' => trim((string)($data['location'] ?? '')),
            'comments' => trim((string)($data['comments'] ?? '')),
            'status' => (string)($data['status'] ?? 'planned'),
            'priority' => in_array($data['priority'] ?? '', ['low', 'normal', 'high', 'critical'], true)
                ? $data['priority']
                : 'normal',
            'sort_order' => max(0, min(100000, (int)($data['sort_order'] ?? 0))),
            'responsible_id' => !empty($data['responsible_id']) ? (int)$data['responsible_id'] : null,
        ];
        $this->validateTimelineItem($item);
        $item['checklist'] = $this->normalizeChecklist($checklist, $files);
        $item['participants'] = $this->normalizeParticipants($orderId, $participants, $item['responsible_id']);
        return $item;
    }
    private function validateTimelineItem($item) {
        if ($item['title'] === '' || !$item['start'] || !$item['end'] || strtotime($item['end']) < strtotime($item['start'])) {
            throw new ValidationException('Заполните название и корректное время этапа');
        }
        if ($this->textLength($item['title']) > 190 || $this->textLength($item['description']) > 20000
            || $this->textLength($item['location']) > 500 || $this->textLength($item['comments']) > 10000) {
            throw new ValidationException('Одно из полей этапа слишком длинное');
        }
        if (!isset($this->statuses()[$item['status']])) {
            throw new ValidationException('Недопустимый статус');
        }
    }
    private function normalizeChecklist($checklist, $files) {
        $checklist = array_values((array)$checklist);
        if (count($checklist) > 100 || $this->fileCount($files) > 10) {
            throw new ValidationException('Слишком много пунктов чек-листа или файлов');
        }
        foreach ($checklist as $checkTitle) {
            if ($this->textLength(trim((string)$checkTitle)) > 500) {
                throw new ValidationException('Пункт чек-листа слишком длинный');
            }
        }
        return $checklist;
    }
    private function normalizeParticipants($orderId, $participants, $responsibleId) {
        $participantIds = array_values(array_unique(array_filter(array_map('intval', (array)$participants))));
        if ($responsibleId) {
            $participantIds[] = (int)$responsibleId;
            $participantIds = array_values(array_unique($participantIds));
        }
        if (!$this->participantsBelongToOrder((int)$orderId, $participantIds)) {
            throw new AccessDeniedException('Участник таймлайна не входит в команду заказа');
        }
        return $participantIds;
    }
    private function storeTimelineRelations($itemId, $userId, $item, $files, &$storedPaths) {
        $participantStmt = $this->db->prepare("INSERT IGNORE INTO timeline_participants (timeline_item_id,user_id) VALUES (?,?)");
        foreach ($item['participants'] as $participantId) {
            $participantStmt->execute([$itemId, $participantId]);
        }
        $checklistStmt = $this->db->prepare("INSERT INTO timeline_checklist_items (timeline_item_id,title,sort_order) VALUES (?,?,?)");
        $position = 0;
        foreach ($item['checklist'] as $checkTitle) {
            $checkTitle = trim((string)$checkTitle);
            if ($checkTitle !== '') {
                $checklistStmt->execute([$itemId, $checkTitle, $position++]);
            }
        }
        $fileStmt = $this->db->prepare("INSERT INTO timeline_files (timeline_item_id,file_path,file_name,mime_type,file_size,uploaded_by) VALUES (?,?,?,?,?,?)");
        foreach ($this->normalizeFiles($files) as $file) {
            $upload = FileStorage::store($file, 'timeline', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
            if (!$upload['success']) {
                throw new FileStorageException($upload['error']);
            }
            $storedPaths[] = $upload['file_path'];
            $fileStmt->execute([$itemId, $upload['file_path'], $upload['file_name'], $upload['mime_type'], $upload['file_size'], (int)$userId]);
        }
    }
    private function timelineStatusError($item, $status, $userId) {
        if (!isset($this->statuses()[$status])) {
            $error = 'Недопустимый статус';
        } elseif (!$item) {
            $error = 'Этап не найден';
        } elseif (!$this->canUpdateItem($item, $userId)) {
            $error = 'Этап недоступен';
        } elseif (isWorkerRole() && !in_array($status, ['in_progress', 'completed', 'delayed'], true)) {
            $error = 'Сотрудник не может установить этот статус';
        } else {
            $error = null;
        }
        return $error;
    }
    private function notifyTimelineStatus($item, $status, $userId, $historyId) {
        $stmt = $this->db->prepare("SELECT DISTINCT tp.user_id,u.role FROM timeline_participants tp JOIN users u ON u.id=tp.user_id WHERE tp.timeline_item_id=? AND tp.user_id<>?");
        $stmt->execute([(int)$item['id'], (int)$userId]);
        foreach ($stmt->fetchAll() as $participant) {
            $participantId = (int)$participant['user_id'];
            $url = isWorkerRole($participant['role'])
                ? '/worker/event.php?id=' . $this->eventIdForOrder((int)$item['order_id'])
                : '/organizer/order-workspace.php?id=' . (int)$item['order_id'] . '&section=timeline';
            (new Notification())->create($participantId, 'timeline_status_changed', 'Изменён этап мероприятия', '«' . $item['title'] . '»: ' . $this->statuses()[$status], $url);
            (new TelegramNotifier())->notifyUser($participantId, 'timeline_status_changed', 'Статус этапа «' . $item['title'] . '» изменён: ' . $this->statuses()[$status] . '.', 'timeline-status-' . $historyId . '-user-' . $participantId);
        }
    }
}
