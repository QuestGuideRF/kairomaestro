<?php
class OrderReview {
    private const ANONYMOUS_CLIENT = 'Клиент';
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function requestForOrder($orderId, $userId = null) {
        try {
            $order = (new Order())->getById($orderId);
            if (!$order || $order['status'] !== 'completed') {
                throw new ValidationException('Запрос отзыва доступен после завершения заказа');
            }
            $userId = (int)($userId ?: ($_SESSION['user_id'] ?? 0));
            if (!$userId || !(new Order())->canMutate($order, $userId, currentRole())) {
                throw new AccessDeniedException('Недостаточно прав для запроса отзыва');
            }
            $stmt = $this->db->prepare("SELECT * FROM reviews WHERE order_id=? LIMIT 1");
            $stmt->execute([(int)$orderId]);
            if ($stmt->fetch()) {
                $result = ['success' => true, 'duplicate' => true];
            } else {
                $token = bin2hex(random_bytes(32));
                $stmt = $this->db->prepare("INSERT INTO reviews (order_id,client_id,token_hash,token_expires_at,client_name,event_type,rating,text,status,is_published,requested_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 30 DAY),?,?,NULL,NULL,'requested',0,NOW())");
                $stmt->execute([(int)$orderId, (int)$order['client_id'], hash('sha256', $token), trim($order['client_first_name'] . ' ' . $order['client_last_name']), $order['event_type']]);
                $reviewId = (int)$this->db->lastInsertId();
                $url = appUrl('/review.php?token=' . $token);
                (new TelegramNotifier())->notifyUser((int)$order['client_id'], 'review_requested', 'Спасибо, что доверили нам событие. Оставьте отзыв: ' . $url, 'review-request-' . $reviewId);
                (new Notification())->create((int)$order['client_id'], 'review_requested', 'Расскажите о событии', 'Форма отзыва доступна в заказе.', '/account/order.php?id=' . (int)$orderId . '#review');
                AuditLog::record('review.requested', 'review', $reviewId, null, ['order_id' => (int)$orderId], ['order_id' => (int)$orderId, 'user_id' => $userId]);
                $result = ['success' => true, 'review_id' => $reviewId, 'url' => $url];
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $result = ['success' => true, 'duplicate' => true];
            } else {
                $result = ['success' => false, 'error' => 'Не удалось создать запрос отзыва'];
            }
        } catch (AppException $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }
        return $result;
    }
    public function submit($reviewId, $clientId, $data, $files = []) {
        $storedPaths = [];
        try {
            $review = $this->getById($reviewId);
            $submission = $this->normalizeSubmission($review, $clientId, $data, $files);
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("UPDATE reviews SET rating=?,service_rating=?,decor_rating=?,team_rating=?,text=?,publication_consent=?,photo_consent=?,display_name_mode=?,status='submitted',submitted_at=NOW(),token_hash=NULL WHERE id=? AND status IN ('pending','requested','opened')");
            $stmt->execute([
                $submission['ratings']['rating'],
                $submission['ratings']['service_rating'],
                $submission['ratings']['decor_rating'],
                $submission['ratings']['team_rating'],
                $submission['text'],
                $submission['publication_consent'],
                $submission['photo_consent'],
                $submission['display_name_mode'],
                (int)$reviewId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new ConflictException('Отзыв уже был отправлен');
            }
            $this->storeFiles($reviewId, $files, $storedPaths);
            $this->db->commit();
            AuditLog::record('review.submitted', 'review', (int)$reviewId, ['status' => $review['status']], ['status' => 'submitted', 'ratings' => $submission['ratings']], ['order_id' => $review['order_id'], 'user_id' => $clientId, 'role' => 'client']);
            $result = ['success' => true];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            foreach ($storedPaths as $path) {
                FileStorage::deleteOwnedFile($path, 'reviews', 'review', (int)$reviewId);
            }
            $result = ['success' => false, 'error' => publicExceptionMessage($e, 'Не удалось сохранить отзыв')];
        }
        return $result;
    }
    public function moderate($reviewId, $userId, $status) {
        $error = null;
        if ((int)$userId !== (int)($_SESSION['user_id'] ?? 0) || !can('publish_reviews')) {
            $error = 'Недостаточно прав для модерации';
        } elseif (!in_array($status, ['approved', 'rejected', 'published'], true)) {
            $error = 'Недопустимый статус';
        }
        $review = $error === null ? $this->getById($reviewId) : null;
        if ($error === null && (!$review || !in_array($review['status'], ['submitted', 'approved', 'rejected', 'published'], true))) {
            $error = 'Отзыв недоступен для модерации';
        }
        $publish = $error === null && $status === 'published' && (int)$review['publication_consent'] === 1;
        if ($error === null && $status === 'published' && !$publish) {
            $error = 'Клиент не разрешил публикацию';
        }
        if ($error !== null) {
            $result = ['success' => false, 'error' => $error];
        } else {
            $stmt = $this->db->prepare("UPDATE reviews SET status=?,is_published=?,moderated_by=?,moderated_at=NOW() WHERE id=?");
            $stmt->execute([$status, $publish ? 1 : 0, (int)$userId, (int)$reviewId]);
            $action = match (true) {
                $publish => 'review.published',
                (int)$review['is_published'] === 1 => 'review.unpublished',
                default => 'review.moderated',
            };
            AuditLog::record($action, 'review', (int)$reviewId, ['status' => $review['status'], 'is_published' => $review['is_published']], ['status' => $status, 'is_published' => $publish], ['order_id' => $review['order_id'], 'user_id' => $userId]);
            $result = ['success' => true];
        }
        return $result;
    }
    public function getForOrder($orderId) {
        $order = (new Order())->getById((int)$orderId);
        if (!in_array(currentRole(), ['admin', 'manager', 'organizer', 'client'], true)
            || !$order || !(new Order())->canCollaborate($order, (int)($_SESSION['user_id'] ?? 0), currentRole())) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT * FROM reviews WHERE order_id=? LIMIT 1");
        $stmt->execute([(int)$orderId]);
        return $stmt->fetch();
    }
    public function getByToken($token) {
        if (!preg_match('/^[a-f0-9]{64}$/', (string)$token)) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT * FROM reviews WHERE token_hash=? AND token_expires_at>NOW() LIMIT 1");
        $stmt->execute([hash('sha256', (string)$token)]);
        $review = $stmt->fetch();
        if ($review && $review['status'] === 'requested') {
            $this->db->prepare("UPDATE reviews SET status='opened',opened_at=NOW() WHERE id=? AND status='requested'")->execute([$review['id']]);
        }
        return $review;
    }
    public function getById($id) {
        $stmt = $this->db->prepare("SELECT * FROM reviews WHERE id=?");
        $stmt->execute([(int)$id]);
        return $stmt->fetch();
    }
    public function pendingModeration() {
        if (!can('publish_reviews') || !isAdminRole()) {
            return [];
        }
        return $this->db->query("SELECT r.*,o.title order_title FROM reviews r LEFT JOIN orders o ON o.id=r.order_id WHERE r.status IN ('submitted','approved','rejected','published') ORDER BY r.submitted_at DESC")->fetchAll();
    }
    public function published($limit = 6) {
        $limit = max(1, min(12, (int)$limit));
        $stmt = $this->db->query(
            "SELECT review.id,review.client_name,review.event_type,review.rating,
                    review.text,review.display_name_mode,review.submitted_at,
                    orders.title AS order_title,orders.event_type AS order_event_type,
                    orders.venue,
                    CASE WHEN review.photo_consent=1 THEN (
                        SELECT file.id FROM review_files file
                        WHERE file.review_id=review.id
                          AND file.mime_type IN ('image/jpeg','image/png','image/webp')
                        ORDER BY file.id LIMIT 1
                    ) ELSE NULL END AS photo_id
             FROM reviews review
             LEFT JOIN orders ON orders.id=review.order_id
             WHERE review.status='published' AND review.is_published=1
               AND review.publication_consent=1
             ORDER BY review.moderated_at DESC,review.id DESC
             LIMIT {$limit}"
        );
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if ($row['display_name_mode'] === 'hidden') {
                $row['display_name'] = self::ANONYMOUS_CLIENT;
            } elseif ($row['display_name_mode'] === 'first_name') {
                $parts = preg_split('/\s+/u', trim((string)$row['client_name']), -1, PREG_SPLIT_NO_EMPTY);
                $row['display_name'] = $parts[0] ?? self::ANONYMOUS_CLIENT;
            } else {
                $row['display_name'] = trim((string)$row['client_name']) ?: self::ANONYMOUS_CLIENT;
            }
        }
        return $rows;
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
            if ($name !== '') {
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
        if (!is_array($names)) {
            return trim((string)$names) === '' ? 0 : 1;
        }
        return count(array_filter($names, static fn($name) => trim((string)$name) !== ''));
    }
    private function textLength($value) {
        return function_exists('mb_strlen')
            ? mb_strlen((string)$value, 'UTF-8')
            : strlen((string)$value);
    }
    private function normalizeSubmission($review, $clientId, $data, $files) {
        if (!$review || (int)$review['client_id'] !== (int)$clientId
            || !in_array($review['status'], ['pending', 'requested', 'opened'], true)) {
            throw new AccessDeniedException('Отзыв недоступен или уже отправлен');
        }
        $ratings = [];
        foreach (['rating', 'service_rating', 'decor_rating', 'team_rating'] as $field) {
            $ratings[$field] = (int)($data[$field] ?? 0);
            if ($ratings[$field] < 1 || $ratings[$field] > 5) {
                throw new ValidationException('Все оценки должны быть от 1 до 5');
            }
        }
        $text = trim((string)($data['text'] ?? ''));
        if ($text === '') {
            throw new ValidationException('Напишите несколько слов о событии');
        }
        if ($this->textLength($text) > 20000) {
            throw new ValidationException('Отзыв слишком длинный');
        }
        if ($this->fileCount($files) > 10) {
            throw new ValidationException('Можно приложить не более 10 фотографий');
        }
        $displayMode = in_array($data['display_name_mode'] ?? '', ['hidden', 'first_name', 'full_name'], true)
            ? $data['display_name_mode']
            : 'first_name';
        return [
            'ratings' => $ratings,
            'text' => $text,
            'publication_consent' => !empty($data['publication_consent']) ? 1 : 0,
            'photo_consent' => !empty($data['photo_consent']) ? 1 : 0,
            'display_name_mode' => $displayMode,
        ];
    }
    private function storeFiles($reviewId, $files, &$storedPaths) {
        $fileStmt = $this->db->prepare("INSERT INTO review_files (review_id,file_path,file_name,mime_type,file_size) VALUES (?,?,?,?,?)");
        foreach ($this->normalizeFiles($files) as $file) {
            $upload = FileStorage::store($file, 'reviews', ['image/jpeg', 'image/png', 'image/webp'], min(UPLOAD_LIMIT_BYTES, 8 * 1024 * 1024));
            if (!$upload['success']) {
                throw new FileStorageException($upload['error']);
            }
            $storedPaths[] = $upload['file_path'];
            $fileStmt->execute([(int)$reviewId, $upload['file_path'], $upload['file_name'], $upload['mime_type'], $upload['file_size']]);
        }
    }
}
