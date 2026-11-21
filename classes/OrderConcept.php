<?php
class OrderConcept {
    use OrderConceptWriteSupport;
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function statuses() {
        return [
            'draft' => 'Черновик', 'sent' => 'Отправлено клиенту', 'viewed' => 'Просмотрено',
            'changes_requested' => 'Нужны изменения', 'approved' => 'Утверждено',
            'rejected' => 'Отклонено', 'replaced' => 'Заменено новой версией',
        ];
    }
    public function createVersion($orderId, $userId, $data, $files = []) {
        return $this->createConceptVersion($orderId, $userId, $data, $files);
    }
    public function send($versionId, $userId) {
        $version = $this->getVersion($versionId);
        $order = $version ? (new Order())->getById($version['order_id']) : null;
        if (!$version || (int)$version['current_version_id'] !== (int)$versionId
            || !in_array($version['status'], ['draft', 'changes_requested'], true)
            || !(new Order())->canMutate($order, (int)$userId, currentRole())) {
            return ['success' => false, 'error' => 'Версия не найдена'];
        }
        $this->db->prepare("UPDATE concept_versions SET status='sent' WHERE id=? AND status IN ('draft','changes_requested')")->execute([(int)$versionId]);
        $order = (new Order())->getById($version['order_id']);
        (new Notification())->create($order['client_id'], 'concept_sent', 'Концепция оформления', 'Доступна новая версия концепции.', '/account/order.php?id=' . (int)$version['order_id'] . '#concept');
        (new TelegramNotifier())->notifyUser((int)$order['client_id'], 'concept_approval_requested', 'В личном кабинете доступна концепция оформления для согласования.', 'concept-sent-' . (int)$versionId);
        AuditLog::record('concept.sent', 'concept_version', (int)$versionId, ['status' => $version['status']], ['status' => 'sent'], ['order_id' => $version['order_id'], 'user_id' => $userId]);
        return ['success' => true];
    }
    public function comment($versionId, $userId, $comment) {
        $comment = trim((string)$comment);
        $version = $this->getVersion($versionId);
        $order = $version ? (new Order())->getById($version['order_id']) : null;
        $error = $this->commentError($version, $order, $versionId, $userId, $comment);
        if ($error !== null) {
            $result = ['success' => false, 'error' => $error];
        } else {
            $this->db->prepare("INSERT INTO concept_comments (version_id,user_id,comment) VALUES (?,?,?)")
                ->execute([(int)$versionId, (int)$userId, $comment]);
            $commentId = (int)$this->db->lastInsertId();
            $this->notifyComment($version, $order, $commentId);
            AuditLog::record('concept.comment_created', 'concept_version', (int)$versionId, null, ['author_id' => $userId], ['order_id' => $version['order_id'], 'user_id' => $userId]);
            $result = ['success' => true];
        }
        return $result;
    }
    public function decide($versionId, $clientId, $decision, $comment = null) {
        try {
            $decisionData = $this->normalizeDecision($versionId, $clientId, $decision, $comment);
            $version = $decisionData['version'];
            $comment = $decisionData['comment'];
            $stmt = $this->db->prepare("INSERT INTO concept_approvals (version_id,client_id,decision,comment,ip_address,user_agent) VALUES (?,?,?,?,?,?)");
            $stmt->execute([(int)$versionId, (int)$clientId, $decision, $comment !== '' ? $comment : null, clientIp(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]);
            $this->db->prepare("UPDATE concept_versions SET status=? WHERE id=?")->execute([$decision, (int)$versionId]);
            $order = (new Order())->getById($version['order_id']);
            $responsibleId = (int)($order['organizer_id'] ?: $order['manager_id']);
            if ($responsibleId > 0) {
                (new Notification())->create($responsibleId, 'concept_decision', 'Решение по концепции', 'Клиент выбрал: ' . ($this->statuses()[$decision] ?? $decision), '/organizer/order-workspace.php?id=' . (int)$version['order_id'] . '&section=concept');
                (new TelegramNotifier())->notifyUser($responsibleId, 'concept_decision', 'Клиент отправил решение по концепции: ' . ($this->statuses()[$decision] ?? $decision), 'concept-decision-' . (int)$versionId . '-' . $decision);
            }
            AuditLog::record('concept.client_decision', 'concept_version', (int)$versionId, ['status' => $version['status']], ['status' => $decision, 'version_no' => $version['version_no']], ['order_id' => $version['order_id'], 'user_id' => $clientId, 'role' => 'client']);
            $result = ['success' => true];
        } catch (PDOException $e) {
            $result = ['success' => false, 'error' => $e->getCode() === '23000' ? 'Решение по этой версии уже сохранено' : 'Не удалось сохранить решение'];
        } catch (Exception $e) {
            $result = ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось сохранить решение')];
        }
        return $result;
    }
    public function getForOrder($orderId, $clientView = false, $viewerId = null) {
        $viewerId = (int)($viewerId ?: ($_SESSION['user_id'] ?? 0));
        $order = (new Order())->getById((int)$orderId);
        if ($clientView) {
            if (!$order || (int)$order['client_id'] !== $viewerId) {
                return null;
            }
        } elseif (!in_array(currentRole(), ['admin', 'organizer'], true)
            || !$order || !(new Order())->canCollaborate($order, $viewerId, currentRole())) {
            return null;
        }
        $sql = "SELECT c.*,v.version_no,v.description,v.style_name,v.color_palette,v.composition_notes,v.status,v.created_at version_created_at FROM order_concepts c JOIN concept_versions v ON v.id=c.current_version_id WHERE c.order_id=?";
        if ($clientView) {
            $sql .= " AND v.status<>'draft'";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int)$orderId]);
        $concept = $stmt->fetch();
        if ($concept) {
            if ($clientView && $concept['status'] === 'sent') {
                $this->db->prepare("UPDATE concept_versions SET status='viewed' WHERE id=? AND status='sent'")->execute([(int)$concept['current_version_id']]);
                $concept['status'] = 'viewed';
                AuditLog::record('concept.viewed', 'concept_version', (int)$concept['current_version_id'], ['status' => 'sent'], ['status' => 'viewed'], ['order_id' => (int)$orderId, 'user_id' => $viewerId, 'role' => 'client']);
            }
            $concept['files'] = $this->files($concept['current_version_id']);
            $concept['comments'] = $this->comments($concept['current_version_id']);
            $concept['versions'] = $this->versions((int)$concept['id'], $clientView);
        }
        return $concept;
    }
    public function versions($conceptId, $clientView = false) {
        $sql = "SELECT v.*,u.first_name,u.last_name FROM concept_versions v LEFT JOIN users u ON u.id=v.created_by WHERE v.concept_id=?";
        if ($clientView) {
            $sql .= " AND v.status<>'draft'";
        }
        $sql .= ' ORDER BY v.version_no DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int)$conceptId]);
        $versions = $stmt->fetchAll();
        foreach ($versions as &$version) {
            $version['files'] = $this->files((int)$version['id']);
        }
        unset($version);
        return $versions;
    }
    public function getVersion($versionId) {
        $stmt = $this->db->prepare("SELECT v.*,c.order_id,c.current_version_id,o.client_id FROM concept_versions v JOIN order_concepts c ON c.id=v.concept_id JOIN orders o ON o.id=c.order_id WHERE v.id=?");
        $stmt->execute([(int)$versionId]);
        return $stmt->fetch();
    }
    public function files($versionId) {
        $stmt = $this->db->prepare("SELECT * FROM concept_files WHERE version_id=? ORDER BY sort_order,id");
        $stmt->execute([(int)$versionId]);
        return $stmt->fetchAll();
    }
    public function comments($versionId) {
        $stmt = $this->db->prepare("SELECT cc.*,u.first_name,u.last_name,u.role FROM concept_comments cc JOIN users u ON u.id=cc.user_id WHERE cc.version_id=? ORDER BY cc.id");
        $stmt->execute([(int)$versionId]);
        return $stmt->fetchAll();
    }
    private function normalizeDecision($versionId, $clientId, $decision, $comment) {
        if ((int)($_SESSION['user_id'] ?? 0) !== (int)$clientId || currentRole() !== 'client' || !can('approve_concepts')) {
            throw new AccessDeniedException('Недостаточно прав для согласования концепции');
        }
        if (!in_array($decision, ['approved','changes_requested','rejected'], true)) {
            throw new ValidationException('Недопустимое решение');
        }
        $comment = trim((string)$comment);
        if ($this->textLength($comment) > 5000) {
            throw new ValidationException('Комментарий слишком длинный');
        }
        $version = $this->getVersion($versionId);
        if (!$version || (int)$version['client_id'] !== (int)$clientId
            || (int)$version['current_version_id'] !== (int)$versionId
            || !in_array($version['status'], ['sent','viewed','changes_requested'], true)) {
            throw new AccessDeniedException('Эта версия недоступна для согласования');
        }
        return ['version' => $version, 'comment' => $comment];
    }
    private function commentError($version, $order, $versionId, $userId, $comment) {
        $allowedRoles = ['admin', 'organizer', 'client'];
        $role = currentRole();
        if (!$version || !$order || !in_array($role, $allowedRoles, true)
            || !(new Order())->canCollaborate($order, (int)$userId, $role)) {
            $error = 'Версия концепции недоступна';
        } elseif ($comment === '') {
            $error = 'Комментарий пустой';
        } elseif ($role === 'client' && ((int)$version['client_id'] !== (int)$userId
            || (int)$version['current_version_id'] !== (int)$versionId
            || !in_array($version['status'], ['sent', 'viewed', 'changes_requested', 'approved', 'rejected'], true))) {
            $error = 'Версия концепции недоступна';
        } elseif ($this->textLength($comment) > 5000) {
            $error = 'Комментарий слишком длинный';
        } else {
            $error = null;
        }
        return $error;
    }
    private function notifyComment($version, $order, $commentId) {
        if (currentRole() === 'client') {
            $recipientId = (int)($order['organizer_id'] ?: $order['manager_id']);
            $url = '/organizer/order-workspace.php?id=' . (int)$version['order_id'] . '&section=concept';
        } else {
            $recipientId = (int)$order['client_id'];
            $url = '/account/order.php?id=' . (int)$version['order_id'] . '#concept';
        }
        if ($recipientId > 0) {
            (new Notification())->create($recipientId, 'concept_comment', 'Комментарий к концепции', 'Добавлен новый комментарий к версии ' . (int)$version['version_no'] . '.', $url);
            (new TelegramNotifier())->notifyUser($recipientId, 'concept_comment', 'Добавлен комментарий к концепции оформления.', 'concept-comment-' . $commentId);
        }
    }
    private function textLength($value) {
        return function_exists('mb_strlen')
            ? mb_strlen((string)$value, 'UTF-8')
            : strlen((string)$value);
    }
}
