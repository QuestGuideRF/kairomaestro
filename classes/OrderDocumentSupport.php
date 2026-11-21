<?php
trait OrderDocumentSupport {
    private function normalizeNewDocument($orderId, $userId, $data) {
        $type = $data['document_type'] ?? 'custom';
        $order = (new Order())->getById($orderId);
        if (!isset($this->types()[$type])) {
            throw new ValidationException('Недопустимый тип документа');
        }
        if (!$this->canManageOrder($order, $userId)) {
            throw new AccessDeniedException('Заказ недоступен для изменения');
        }
        $templateId = !empty($data['template_id']) ? (int)$data['template_id'] : null;
        $body = trim((string)($data['body_html'] ?? ''));
        if ($templateId) {
            $stmt = $this->db->prepare('SELECT * FROM document_templates WHERE id=? AND is_active=1');
            $stmt->execute([$templateId]);
            $template = $stmt->fetch();
            if (!$template) {
                throw new EntityNotFoundException('Шаблон не найден');
            }
            $body = $template['body_html'];
        }
        if ($body === '' || $this->textLength($body) > 500000) {
            throw new ValidationException($body === '' ? 'Заполните содержание документа' : 'Содержание документа слишком большое');
        }
        $title = trim((string)($data['title'] ?? '')) ?: $this->types()[$type];
        if ($this->textLength($title) > 190) {
            throw new ValidationException('Название документа слишком длинное');
        }
        return [
            'type' => $type,
            'order' => $order,
            'template_id' => $templateId,
            'title' => $title,
            'body' => $this->renderVariables($this->sanitizeHtml($body), $order),
        ];
    }
    private function normalizeNewVersion($documentId, $userId, $body) {
        $document = $this->getById($documentId);
        $order = $document ? (new Order())->getById($document['order_id']) : null;
        if (!$document || !$this->canManageOrder($order, $userId)) {
            throw new AccessDeniedException(self::ERROR_DOCUMENT_UNAVAILABLE);
        }
        $body = trim((string)$body);
        if ($this->textLength($body) > 500000) {
            throw new ValidationException('Содержание документа слишком большое');
        }
        $body = $this->renderVariables($this->sanitizeHtml($body), $order);
        if ($body === '') {
            throw new ValidationException('Содержание документа пустое');
        }
        return ['document' => $document, 'order' => $order, 'body' => $body];
    }
    private function confirmationData($documentId, $clientId, $decision, $comment) {
        if ((int)($_SESSION['user_id'] ?? 0) !== (int)$clientId || currentRole() !== 'client' || !can('confirm_documents')) {
            throw new AccessDeniedException('Недостаточно прав для подтверждения документа');
        }
        if (!in_array($decision, ['approved', 'changes_requested', 'signed'], true)) {
            throw new ValidationException('Недопустимое решение');
        }
        $comment = trim((string)$comment);
        if ($this->textLength($comment) > 5000) {
            throw new ValidationException('Комментарий слишком длинный');
        }
        $document = $this->getById($documentId);
        if (!$document || (int)$document['client_id'] !== (int)$clientId || !$document['current_version_id']
            || !in_array($document['status'], ['sent', 'viewed', 'changes_requested'], true)) {
            throw new AccessDeniedException(self::ERROR_DOCUMENT_UNAVAILABLE);
        }
        return ['document' => $document, 'comment' => $comment];
    }
    private function documentCommentError($document, $userId, $comment) {
        $order = $document ? (new Order())->getById((int)$document['order_id']) : null;
        if (!$document || !$this->canReadOrder($order, $userId)) {
            $error = self::ERROR_DOCUMENT_UNAVAILABLE;
        } elseif ($comment === '') {
            $error = 'Комментарий пустой';
        } elseif (currentRole() === 'client'
            && (!in_array($document['status'], ['sent', 'viewed', 'changes_requested', 'approved', 'signed'], true)
                || (int)$document['client_id'] !== $userId)) {
            $error = self::ERROR_DOCUMENT_UNAVAILABLE;
        } elseif ($this->textLength($comment) > 5000) {
            $error = 'Комментарий слишком длинный';
        } else {
            $error = null;
        }
        return $error;
    }
    private function storeDocumentComment($document, $userId, $comment) {
        $stmt = $this->db->prepare('INSERT INTO document_comments (document_id, version_id, user_id, comment) VALUES (?, ?, ?, ?)');
        $stmt->execute([(int)$document['id'], $document['current_version_id'], $userId, $comment]);
        $commentId = (int)$this->db->lastInsertId();
        $order = (new Order())->getById((int)$document['order_id']);
        $this->notifyDocumentComment($document, $order, $commentId);
        AuditLog::record('document.comment_created', 'order_document', (int)$document['id'], null, [
            'version_id' => $document['current_version_id'],
        ], [
            'order_id' => $document['order_id'],
            'user_id' => $userId,
        ]);
        return ['success' => true];
    }
    private function notifyDocumentComment($document, $order, $commentId) {
        $fromClient = currentRole() === 'client';
        $responsibleId = !empty($order['organizer_id'])
            ? (int)$order['organizer_id']
            : (int)$order['manager_id'];
        $recipientId = $fromClient ? $responsibleId : (int)$order['client_id'];
        if ($recipientId > 0) {
            $url = $fromClient
                ? '/organizer/order-workspace.php?id=' . (int)$document['order_id'] . '&section=documents'
                : '/account/order.php?id=' . (int)$document['order_id'] . '#documents';
            (new Notification())->create($recipientId, 'document_comment', 'Новый комментарий к документу', $document['title'], $url);
            (new TelegramNotifier())->notifyUser($recipientId, 'document_comment', 'Новый комментарий к документу «' . $document['title'] . '».', 'document-comment-' . $commentId);
        }
    }
    private function normalizeTemplate($userId, $data) {
        if ((int)($_SESSION['user_id'] ?? 0) !== (int)$userId || !can('manage_templates')) {
            throw new AccessDeniedException('Недостаточно прав');
        }
        $name = trim((string)($data['name'] ?? ''));
        $type = (string)($data['document_type'] ?? 'custom');
        $body = $this->sanitizeHtml(trim((string)($data['body_html'] ?? '')));
        if ($name === '' || $body === '' || !isset($this->types()[$type])) {
            throw new ValidationException('Заполните название, тип и содержание шаблона');
        }
        if ($this->textLength($name) > 190 || $this->textLength($body) > 500000) {
            throw new ValidationException('Шаблон слишком большой');
        }
        return ['name' => $name, 'type' => $type, 'body' => $body, 'active' => !empty($data['is_active']) ? 1 : 0];
    }
    private function nextNumber($orderId) {
        $stmt = $this->db->prepare('SELECT COUNT(*)+1 FROM order_documents WHERE order_id=?');
        $stmt->execute([(int)$orderId]);
        return 'AD-' . date('Y') . '-' . (int)$orderId . '-' . str_pad((string)$stmt->fetchColumn(), 3, '0', STR_PAD_LEFT);
    }
    private function canManageOrder($order, $userId) {
        return (new Order())->canMutate($order, (int)$userId, currentRole());
    }
    private function canReadOrder($order, $userId) {
        return in_array(currentRole(), ['admin', 'organizer', 'client'], true)
            && (new Order())->canCollaborate($order, (int)$userId, currentRole());
    }
    private function documentData($order) {
        return [
            'order_id' => (int)$order['id'],
            'order_title' => $order['title'],
            'client_name' => trim($order['client_first_name'] . ' ' . $order['client_last_name']),
            'event_date' => $order['event_date'],
            'venue' => $order['venue'],
            'address' => $order['address'],
        ];
    }
    private function renderVariables($body, $order) {
        foreach ($this->documentData($order) as $key => $value) {
            $body = str_replace('{{' . $key . '}}', escape($value), $body);
        }
        return $body;
    }
    private function sanitizeHtml($html) {
        $html = strip_tags($html, '<p><br><h1><h2><h3><h4><strong><b><em><i><u><ul><ol><li><table><thead><tbody><tr><th><td><blockquote><hr>');
        return preg_replace('/<([a-z][a-z0-9]*)\b[^>]*>/iu', '<$1>', $html);
    }
    private function textLength($value) {
        return utf8Length((string)$value);
    }
}
