<?php
class SecureFile {
    private const JPEG_MIME = 'image/jpeg';
    private const TYPES = [
        'message' => 'chat',
        'material' => 'materials',
        'lead' => 'references',
        'finance_payment' => 'finance',
        'finance_expense' => 'finance',
        'timeline' => 'timeline',
        'concept' => 'concepts',
        'review' => 'reviews',
        'receipt' => 'receipts',
        'candidate' => 'candidate',
    ];
    private $db;
    private $failureStatus = 404;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function find($type, $id, $userId, $role) {
        $this->failureStatus = 404;
        $type = (string)$type;
        $id = (int)$id;
        $userId = (int)$userId;
        $record = false;
        if ($id >= 1 && $userId >= 1 && isset(self::TYPES[$type])) {
            $record = match ($type) {
                'message' => $this->message($id, $userId),
                'material' => $this->material($id, $userId, $role),
                'lead' => $this->leadFile($id, $userId, $role),
                'finance_payment' => $this->financeFile('order_payments', $id, $userId, $role),
                'finance_expense' => $this->financeFile('order_expenses', $id, $userId, $role),
                'timeline' => $this->timelineFile($id, $userId, $role),
                'concept' => $this->conceptFile($id, $userId, $role),
                'review' => $this->reviewFile($id, $userId, $role),
                'receipt' => $this->receipt($id, $userId, $role),
                'candidate' => $this->candidateFile($id, $role),
                default => false,
            };
            $absolutePath = $record && !empty($record['stored_path'])
                ? $this->absolutePath($record['stored_path'], self::TYPES[$type])
                : false;
            if ($absolutePath) {
                $record['absolute_path'] = $absolutePath;
                $record['download_name'] = safeUploadedName($record['download_name'] ?? basename($absolutePath));
                $record['mime_type'] = $this->detectMime($absolutePath, $record['mime_type'] ?? null);
            } else {
                $record = false;
            }
        }
        return $record;
    }
    public function failureStatus() {
        return $this->failureStatus;
    }
    public function deliver($type, $id, $userId, $role, $variant = 'original') {
        $record = $this->find($type, $id, $userId, $role);
        if (!$record) {
            $delivered = false;
        } elseif ($variant === 'thumb' && $type === 'lead') {
            $delivered = (new SecureImageThumbnail())->deliver($record);
            if (!$delivered) {
                $delivered = $this->streamRecord($record);
            }
        } else {
            $delivered = $this->streamRecord($record);
        }
        return $delivered;
    }
    public function deliverPublishedReviewImage($id): bool {
        $this->failureStatus = 404;
        $stmt = $this->db->prepare(
            "SELECT file.id,file.file_path AS stored_path,file.file_name AS download_name,file.mime_type
             FROM review_files file
             JOIN reviews review ON review.id=file.review_id
             WHERE file.id=? AND file.mime_type IN ('image/jpeg','image/png','image/webp')
               AND review.status='published' AND review.is_published=1
               AND review.publication_consent=1 AND review.photo_consent=1
             LIMIT 1"
        );
        $stmt->execute([(int)$id]);
        $record = $stmt->fetch();
        $absolutePath = $record && !empty($record['stored_path'])
            ? $this->absolutePath($record['stored_path'], 'reviews')
            : false;
        if (!$absolutePath) {
            return false;
        }
        $record['absolute_path'] = $absolutePath;
        $record['download_name'] = safeUploadedName($record['download_name'] ?? basename($absolutePath));
        $record['mime_type'] = $this->detectMime($absolutePath, $record['mime_type'] ?? null);
        $thumbnail = (new SecureImageThumbnail())->deliver($record, true);
        return $thumbnail ?: $this->streamRecord($record, true);
    }
    private function streamRecord(array $record, bool $public = false): bool {
        $path = $record['absolute_path'];
        $size = filesize($path);
        $handle = $size !== false ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            return false;
        }
        $name = safeUploadedName($record['download_name'] ?? basename($path));
        $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'download';
        $mime = (string)$record['mime_type'];
        $disposition = str_starts_with($mime, 'image/') || $mime === 'application/pdf'
            ? 'inline'
            : 'attachment';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (int)$size);
        header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: ' . ($public ? 'public, max-age=86400' : 'private, no-store, max-age=0'));
        if (!$public) {
            header('Pragma: no-cache');
        }
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox');
        fpassthru($handle);
        fclose($handle);
        return true;
    }
    private function message($id, $userId) {
        $stmt = $this->db->prepare('SELECT id,chat_id,file_path AS stored_path,file_name AS download_name,file_type AS mime_type FROM messages WHERE id=? AND file_path IS NOT NULL');
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        return $record && (new Chat())->userCanAccessChat((int)$record['chat_id'], $userId)
            ? $record
            : $this->denied($record);
    }
    private function material($id, $userId, $role) {
        if (isWorkerRole($role)) {
            $result = $this->denied(true);
        } else {
            $stmt = $this->db->prepare('SELECT om.id,om.order_id,om.file_path AS stored_path,om.file_name AS download_name,om.mime_type,om.visibility,o.client_id,o.manager_id,o.organizer_id FROM order_materials om JOIN orders o ON o.id=om.order_id WHERE om.id=? AND om.file_path IS NOT NULL');
            $stmt->execute([$id]);
            $record = $stmt->fetch();
            $allowed = $record && ($role === 'client'
                ? (int)$record['client_id'] === $userId && $record['visibility'] === 'client'
                : $this->crmCanReadOrder($record, $userId, $role));
            $result = $allowed ? $record : $this->denied($record);
        }
        return $result;
    }
    private function leadFile($id, $userId, $role) {
        $stmt = $this->db->prepare('SELECT lf.id,lf.lead_id,lf.file_path AS stored_path,lf.file_name AS download_name,lf.mime_type,l.client_id,l.manager_id FROM lead_files lf JOIN leads l ON l.id=lf.lead_id WHERE lf.id=?');
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        $allowed = $record && match ($role) {
            'admin' => true,
            'client' => (int)$record['client_id'] === $userId,
            'manager' => empty($record['manager_id']) || (int)$record['manager_id'] === $userId,
            default => false,
        };
        if ($record && !$allowed && $role === 'organizer') {
            $check = $this->db->prepare('SELECT 1 FROM orders WHERE lead_id=? AND organizer_id=? LIMIT 1');
            $check->execute([(int)$record['lead_id'], $userId]);
            $allowed = (bool)$check->fetchColumn();
        }
        return $allowed ? $record : $this->denied($record);
    }
    private function financeFile($table, $id, $userId, $role) {
        $clientPayment = $role === 'client' && $table === 'order_payments';
        $crmFinance = in_array($role, ['admin', 'manager', 'organizer'], true)
            && can('view_finance', $role);
        if (!$clientPayment && !$crmFinance) {
            return $this->denied(true);
        }
        $sql = "SELECT f.id,f.order_id,f.proof_path AS stored_path,CONCAT('finance-',f.id) AS download_name,NULL AS mime_type,o.client_id,o.manager_id,o.organizer_id FROM {$table} f JOIN orders o ON o.id=f.order_id WHERE f.id=? AND f.proof_path IS NOT NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        $allowed = $record && ($clientPayment
            ? (int)$record['client_id'] === $userId
            : $this->crmCanReadOrder($record, $userId, $role));
        return $allowed
            ? $record
            : $this->denied($record);
    }
    private function timelineFile($id, $userId, $role) {
        $stmt = $this->db->prepare('SELECT tf.id,tf.timeline_item_id,tf.file_path AS stored_path,tf.file_name AS download_name,tf.mime_type,ti.order_id,o.client_id,o.manager_id,o.organizer_id FROM timeline_files tf JOIN order_timeline_items ti ON ti.id=tf.timeline_item_id JOIN orders o ON o.id=ti.order_id WHERE tf.id=?');
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        if (!$record) {
            $result = false;
        } elseif (isWorkerRole($role)) {
            $result = (new OrderTimeline())->workerCanAccess((int)$record['timeline_item_id'], $userId)
                ? $record
                : $this->denied($record);
        } else {
            $result = $this->crmCanReadOrder($record, $userId, $role)
                ? $record
                : $this->denied($record);
        }
        return $result;
    }
    private function conceptFile($id, $userId, $role) {
        if (isWorkerRole($role)) {
            $result = $this->denied(true);
        } else {
            $stmt = $this->db->prepare('SELECT cf.id,cf.file_path AS stored_path,cf.file_name AS download_name,cf.mime_type,v.status,c.order_id,o.client_id,o.manager_id,o.organizer_id FROM concept_files cf JOIN concept_versions v ON v.id=cf.version_id JOIN order_concepts c ON c.id=v.concept_id JOIN orders o ON o.id=c.order_id WHERE cf.id=?');
            $stmt->execute([$id]);
            $record = $stmt->fetch();
            $allowed = $record && ($role === 'client'
                ? (int)$record['client_id'] === $userId && $record['status'] !== 'draft'
                : $this->crmCanReadOrder($record, $userId, $role));
            $result = $allowed ? $record : $this->denied($record);
        }
        return $result;
    }
    private function reviewFile($id, $userId, $role) {
        if (isWorkerRole($role)) {
            $result = $this->denied(true);
        } else {
            $stmt = $this->db->prepare('SELECT rf.id,rf.file_path AS stored_path,rf.file_name AS download_name,rf.mime_type,r.order_id,r.client_id,o.manager_id,o.organizer_id FROM review_files rf JOIN reviews r ON r.id=rf.review_id LEFT JOIN orders o ON o.id=r.order_id WHERE rf.id=?');
            $stmt->execute([$id]);
            $record = $stmt->fetch();
            $allowed = $record && ($role === 'client'
                ? (int)$record['client_id'] === $userId
                : $this->crmCanReadOrder($record, $userId, $role));
            $result = $allowed ? $record : $this->denied($record);
        }
        return $result;
    }
    private function receipt($id, $userId, $role) {
        $stmt = $this->db->prepare("SELECT p.id,p.event_id,p.worker_id,p.travel_receipt AS stored_path,CONCAT('receipt-',p.id) AS download_name,NULL AS mime_type,e.organizer_id FROM payments p JOIN events e ON e.id=p.event_id WHERE p.id=? AND p.travel_receipt IS NOT NULL");
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        $allowed = $record && match (true) {
            $role === 'admin' => true,
            $role === 'organizer' => (int)$record['organizer_id'] === $userId,
            isWorkerRole($role) => (int)$record['worker_id'] === $userId,
            default => false,
        };
        return $allowed ? $record : $this->denied($record);
    }
    private function candidateFile($id, $role) {
        if ($role !== 'admin' || !dbTableExists('candidate_application_files')) {
            return $this->denied(true);
        }
        $stmt = $this->db->prepare(
            'SELECT id,file_path AS stored_path,file_name AS download_name,mime_type '
            . 'FROM candidate_application_files WHERE id=?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: false;
    }
    private function denied($record) {
        if ($record) {
            $this->failureStatus = 403;
        }
        return false;
    }
    private function crmCanReadOrder($record, $userId, $role) {
        return match ($role) {
            'admin' => true,
            'organizer' => !empty($record['organizer_id']) && (int)$record['organizer_id'] === $userId,
            default => false,
        };
    }
    private function absolutePath($storedPath, $category) {
        $relative = ltrim(str_replace('\\', '/', (string)$storedPath), '/');
        $expectedPrefix = 'uploads/' . $category . '/';
        if (strpos($relative, "\0") !== false
            || strpos($relative, '://') !== false
            || !str_starts_with($relative, $expectedPrefix)
            || in_array('..', explode('/', $relative), true)) {
            return false;
        }
        $uploadRoot = realpath(rtrim(UPLOAD_DIR, '/\\'));
        $root = realpath(rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $category);
        $projectRoot = dirname(rtrim(UPLOAD_DIR, '/\\'));
        $rawCandidate = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $candidate = realpath($rawCandidate);
        $uploadPrefix = $uploadRoot ? rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';
        if (!$uploadRoot || !$root || !str_starts_with($root . DIRECTORY_SEPARATOR, $uploadPrefix)
            || !$candidate || !is_file($candidate) || is_link($rawCandidate)) {
            return false;
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($candidate, $prefix) ? $candidate : false;
    }
    private function detectMime($path, $storedMime) {
        $mime = class_exists('finfo') ? (string)(new finfo(FILEINFO_MIME_TYPE))->file($path) : '';
        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = (string)$storedMime;
        }
        $allowed = [
            self::JPEG_MIME, 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'application/pdf', 'text/plain',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        return in_array($mime, $allowed, true) ? $mime : 'application/octet-stream';
    }
}
