<?php
class OrderDocument {
    use OrderDocumentSupport;
    protected const ERROR_DOCUMENT_UNAVAILABLE = 'Документ недоступен';
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function types() {
        return [
            'proposal' => 'Коммерческое предложение',
            'contract' => 'Договор',
            'estimate_appendix' => 'Приложение со сметой',
            'invoice' => 'Счёт',
            'act' => 'Акт выполненных работ',
            'memo' => 'Памятка для клиента',
            'custom' => 'Произвольный документ',
        ];
    }
    public function statuses() {
        return [
            'draft' => 'Черновик', 'sent' => 'Отправлен', 'viewed' => 'Просмотрен',
            'changes_requested' => 'Требуется изменение', 'approved' => 'Согласован',
            'signed' => 'Подписан', 'cancelled' => 'Отменён',
        ];
    }
    public function create($orderId, $userId, $data) {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            $document = $this->normalizeNewDocument($orderId, $userId, $data);
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }
            $number = $this->nextNumber($orderId);
            $stmt = $this->db->prepare("INSERT INTO order_documents (order_id, template_id, document_type, document_number, title, status, created_by) VALUES (?, ?, ?, ?, ?, 'draft', ?)");
            $stmt->execute([(int)$orderId, $document['template_id'], $document['type'], $number, $document['title'], (int)$userId]);
            $documentId = (int)$this->db->lastInsertId();
            $stmt = $this->db->prepare("INSERT INTO order_document_versions (document_id, version_no, body_html, source_data, created_by) VALUES (?, 1, ?, ?, ?)");
            $stmt->execute([$documentId, $document['body'], json_encode($this->documentData($document['order']), JSON_UNESCAPED_UNICODE), (int)$userId]);
            $versionId = (int)$this->db->lastInsertId();
            $this->db->prepare("UPDATE order_documents SET current_version_id=? WHERE id=?")->execute([$versionId, $documentId]);
            if ($ownsTransaction) {
                $this->db->commit();
            }
            AuditLog::record('document.created', 'order_document', $documentId, null, ['order_id' => $orderId, 'version' => 1, 'type' => $document['type']], ['order_id' => $orderId]);
            $result = ['success' => true, 'document_id' => $documentId];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось создать документ')];
        }
        return $result;
    }
    public function addVersion($documentId, $userId, $body) {
        try {
            $version = $this->normalizeNewVersion($documentId, $userId, $body);
            $document = $version['document'];
            $order = $version['order'];
            $body = $version['body'];
            $stmt = $this->db->prepare("SELECT COALESCE(MAX(version_no),0)+1 FROM order_document_versions WHERE document_id=?");
            $stmt->execute([(int)$documentId]);
            $versionNo = (int)$stmt->fetchColumn();
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("INSERT INTO order_document_versions (document_id, version_no, body_html, source_data, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([(int)$documentId, $versionNo, $body, json_encode($this->documentData($order), JSON_UNESCAPED_UNICODE), (int)$userId]);
            $versionId = (int)$this->db->lastInsertId();
            $this->db->prepare("UPDATE order_documents SET current_version_id=?, status='draft', public_token_hash=NULL, public_expires_at=NULL WHERE id=?")
                ->execute([$versionId, (int)$documentId]);
            $this->db->commit();
            AuditLog::record('document.version_created', 'order_document', (int)$documentId, ['version' => $versionNo - 1], ['version' => $versionNo], ['order_id' => $document['order_id']]);
            $result = ['success' => true, 'version_id' => $versionId, 'version_no' => $versionNo];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось создать версию документа')];
        }
        return $result;
    }
    public function sendToClient($documentId, $userId) {
        $document = $this->getById($documentId);
        if (!$document || !$document['current_version_id'] || !$this->canManageOrder((new Order())->getById($document['order_id']), $userId)) {
            return ['success' => false, 'error' => 'Документ или версия не найдены'];
        }
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare("UPDATE order_documents SET status='sent', public_token_hash=?, public_expires_at=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id=?");
        $stmt->execute([hash('sha256', $token), (int)$documentId]);
        $order = (new Order())->getById($document['order_id']);
        (new Notification())->create($order['client_id'], 'document_sent', 'Новый документ', $document['title'], '/account/order.php?id=' . (int)$document['order_id'] . '#documents');
        (new TelegramNotifier())->notifyUser((int)$order['client_id'], 'document_sent', 'В личном кабинете доступен документ «' . $document['title'] . '».', 'document-sent-' . (int)$documentId . '-' . (int)$document['current_version_id']);
        AuditLog::record('document.sent', 'order_document', (int)$documentId, ['status' => $document['status']], ['status' => 'sent'], ['order_id' => $document['order_id'], 'user_id' => $userId]);
        return ['success' => true, 'public_url' => appUrl('/document.php?token=' . $token)];
    }
    public function confirm($documentId, $clientId, $decision, $comment = null) {
        try {
            $confirmation = $this->confirmationData($documentId, $clientId, $decision, $comment);
            $document = $confirmation['document'];
            $comment = $confirmation['comment'];
            $stmt = $this->db->prepare("INSERT INTO document_confirmations (document_id, version_id, user_id, decision, comment, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([(int)$documentId, (int)$document['current_version_id'], (int)$clientId, $decision, $comment !== '' ? $comment : null, clientIp(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]);
            $this->db->prepare("UPDATE order_documents SET status=? WHERE id=?")->execute([$decision, (int)$documentId]);
            $order = (new Order())->getById((int)$document['order_id']);
            $responsibleId = (int)($order['organizer_id'] ?: $order['manager_id']);
            if ($responsibleId > 0) {
                $label = $this->statuses()[$decision] ?? $decision;
                (new Notification())->create($responsibleId, 'document_decision', 'Решение по документу', '«' . $document['title'] . '»: ' . $label, '/organizer/order-workspace.php?id=' . (int)$document['order_id'] . '&section=documents');
                (new TelegramNotifier())->notifyUser($responsibleId, 'document_decision', 'Клиент отправил решение по документу «' . $document['title'] . '»: ' . $label . '.', 'document-decision-' . (int)$documentId . '-' . (int)$document['current_version_id'] . '-' . $decision);
            }
            AuditLog::record('document.confirmed', 'order_document', (int)$documentId, ['status' => $document['status']], ['status' => $decision, 'version_id' => $document['current_version_id']], ['order_id' => $document['order_id'], 'user_id' => $clientId, 'role' => 'client']);
            $result = ['success' => true];
        } catch (PDOException $e) {
            $result = ['success' => false, 'error' => $e->getCode() === '23000'
                ? 'Эта версия уже подтверждена'
                : 'Не удалось сохранить решение'];
        } catch (Exception $e) {
            $result = ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось сохранить решение')];
        }
        return $result;
    }
    public function addComment($documentId, $userId, $comment) {
        $comment = trim((string)$comment);
        $document = $this->getById($documentId);
        $error = $this->documentCommentError($document, (int)$userId, $comment);
        if ($error !== null) {
            $result = ['success' => false, 'error' => $error];
        } else {
            $result = $this->storeDocumentComment($document, (int)$userId, $comment);
        }
        return $result;
    }
    public function getForOrder($orderId, $clientOnly = false, $viewerId = null) {
        $viewerId = (int)($viewerId ?: ($_SESSION['user_id'] ?? 0));
        $order = (new Order())->getById((int)$orderId);
        if ($clientOnly) {
            if (!$order || (int)$order['client_id'] !== $viewerId) {
                return [];
            }
        } elseif (!$order || !$this->canReadOrder($order, $viewerId)) {
            return [];
        }
        $sql = "SELECT d.*, v.version_no, v.body_html, v.created_at AS version_created_at FROM order_documents d LEFT JOIN order_document_versions v ON v.id=d.current_version_id WHERE d.order_id=?";
        if ($clientOnly) {
            $sql .= " AND d.status<>'draft' AND d.status<>'cancelled'";
        }
        $sql .= ' ORDER BY d.id DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int)$orderId]);
        $documents = $stmt->fetchAll();
        foreach ($documents as &$document) {
            if ($clientOnly && $document['status'] === 'sent') {
                $this->db->prepare("UPDATE order_documents SET status='viewed' WHERE id=? AND status='sent'")->execute([(int)$document['id']]);
                $document['status'] = 'viewed';
                AuditLog::record('document.viewed', 'order_document', (int)$document['id'], ['status' => 'sent'], ['status' => 'viewed'], ['order_id' => (int)$orderId, 'user_id' => $viewerId, 'role' => 'client']);
            }
            $document['versions'] = $this->versions((int)$document['id']);
            $document['comments'] = $this->comments((int)$document['id']);
        }
        unset($document);
        return $documents;
    }
    public function getById($documentId) {
        $stmt = $this->db->prepare("SELECT d.*, o.client_id, v.version_no, v.body_html FROM order_documents d JOIN orders o ON o.id=d.order_id LEFT JOIN order_document_versions v ON v.id=d.current_version_id WHERE d.id=?");
        $stmt->execute([(int)$documentId]);
        return $stmt->fetch();
    }
    public function getByPublicToken($token) {
        if (!preg_match('/^[a-f0-9]{64}$/', (string)$token)) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT d.*, o.client_id, v.version_no, v.body_html FROM order_documents d JOIN orders o ON o.id=d.order_id JOIN order_document_versions v ON v.id=d.current_version_id WHERE d.public_token_hash=? AND d.public_expires_at>NOW() AND d.status NOT IN ('draft','cancelled') LIMIT 1");
        $stmt->execute([hash('sha256', (string)$token)]);
        $document = $stmt->fetch();
        if ($document && $document['status'] === 'sent') {
            $this->db->prepare("UPDATE order_documents SET status='viewed' WHERE id=? AND status='sent'")->execute([(int)$document['id']]);
            AuditLog::record('document.public_viewed', 'order_document', (int)$document['id'], ['status' => 'sent'], ['status' => 'viewed'], ['order_id' => $document['order_id']]);
        }
        return $document;
    }
    public function versions($documentId) {
        $stmt = $this->db->prepare("SELECT v.id,v.version_no,v.created_at,u.first_name,u.last_name FROM order_document_versions v LEFT JOIN users u ON u.id=v.created_by WHERE v.document_id=? ORDER BY v.version_no DESC");
        $stmt->execute([(int)$documentId]);
        return $stmt->fetchAll();
    }
    public function comments($documentId) {
        $stmt = $this->db->prepare("SELECT dc.*,v.version_no,u.first_name,u.last_name,u.role FROM document_comments dc LEFT JOIN order_document_versions v ON v.id=dc.version_id JOIN users u ON u.id=dc.user_id WHERE dc.document_id=? ORDER BY dc.id");
        $stmt->execute([(int)$documentId]);
        return $stmt->fetchAll();
    }
    public function saveTemplate($templateId, $userId, $data) {
        try {
            $template = $this->normalizeTemplate($userId, $data);
            if ($templateId) {
                $stmt = $this->db->prepare("SELECT * FROM document_templates WHERE id=?");
                $stmt->execute([(int)$templateId]);
                $old = $stmt->fetch();
                if (!$old) {
                    throw new EntityNotFoundException('Шаблон не найден');
                }
                $this->db->prepare("UPDATE document_templates SET name=?,document_type=?,body_html=?,is_active=? WHERE id=?")
                    ->execute([$template['name'], $template['type'], $template['body'], $template['active'], (int)$templateId]);
                AuditLog::record('document_template.updated', 'document_template', (int)$templateId, $old, ['name' => $template['name'], 'document_type' => $template['type'], 'is_active' => $template['active']], ['user_id' => $userId]);
                $id = (int)$templateId;
            } else {
                $stmt = $this->db->prepare("INSERT INTO document_templates (name,document_type,body_html,is_active,created_by) VALUES (?,?,?,?,?)");
                $stmt->execute([$template['name'], $template['type'], $template['body'], $template['active'], (int)$userId]);
                $id = (int)$this->db->lastInsertId();
                AuditLog::record('document_template.created', 'document_template', $id, null, ['name' => $template['name'], 'document_type' => $template['type'], 'is_active' => $template['active']], ['user_id' => $userId]);
            }
            $result = ['success' => true, 'template_id' => $id];
        } catch (Exception $e) {
            $result = ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось сохранить шаблон')];
        }
        return $result;
    }
    public function templates($type = null, $activeOnly = true) {
        $sql = 'SELECT * FROM document_templates WHERE 1=1';
        $params = [];
        if ($activeOnly) {
            $sql .= ' AND is_active=1';
        }
        if ($type) {
            $sql .= ' AND document_type=?';
            $params[] = $type;
        }
        $sql .= ' ORDER BY name';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
