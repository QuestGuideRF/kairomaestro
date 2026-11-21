<?php
class TelegramFileUploadService {
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function store(array $metadata, ?array $file): array {
        $request = $this->validatedRequest($metadata, $file);
        $this->assertEntityOwner(
            $request['entity'],
            $request['entity_id'],
            $request['telegram_user_id']
        );
        $limit = $this->uploadLimit((array)$request['file']);

        return $this->persist($request, $limit);
    }

    private function validatedRequest(array $metadata, ?array $file): array {
        $entity = (string)($metadata['entity'] ?? '');
        $entityId = (int)($metadata['entity_id'] ?? 0);
        $telegramUserId = (int)($metadata['telegram_user_id'] ?? 0);
        if (!is_array($file) || $entityId <= 0 || $telegramUserId <= 0) {
            throw new ValidationException('Файл или владелец не указан');
        }
        if (!in_array($entity, ['lead', 'candidate'], true)) {
            throw new ValidationException('Неизвестный тип файла');
        }
        if ($entity === 'candidate'
            && !dbTableExists('candidate_application_files')) {
            throw new ConfigurationException(
                'Telegram CRM migration is not installed'
            );
        }

        return [
            'entity' => $entity,
            'entity_id' => $entityId,
            'telegram_user_id' => $telegramUserId,
            'telegram_file_id' => substr(
                trim((string)($metadata['telegram_file_id'] ?? '')),
                0,
                255
            ),
            'category' => $entity === 'lead' ? 'references' : 'candidate',
            'file' => $file,
        ];
    }

    private function assertEntityOwner(
        string $entity,
        int $entityId,
        int $telegramUserId
    ): void {
        if ($entity === 'lead') {
            $sql = 'SELECT 1 FROM leads l JOIN users u ON u.id=l.client_id '
                . 'WHERE l.id=? AND u.telegram_user_id=? LIMIT 1';
            $error = 'Заявка недоступна';
        } else {
            $sql = 'SELECT 1 FROM candidate_applications '
                . 'WHERE id=? AND telegram_user_id=? LIMIT 1';
            $error = 'Заявка кандидата недоступна';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$entityId, $telegramUserId]);
        if (!$stmt->fetchColumn()) {
            throw new AccessDeniedException($error);
        }
    }

    private function uploadLimit(array $file): int {
        $tmpPath = (string)($file['tmp_name'] ?? '');
        $detectedMime = class_exists('finfo') && $tmpPath !== ''
            ? (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmpPath)
            : '';
        $limitMb = str_starts_with($detectedMime, 'image/')
            ? (int)env('TELEGRAM_MAX_PHOTO_MB', 15)
            : (int)env('TELEGRAM_MAX_DOCUMENT_MB', 30);

        return max(1, min(50, $limitMb)) * 1024 * 1024;
    }

    private function persist(array $request, int $limit): array {
        $upload = null;
        $this->db->beginTransaction();
        try {
            $existingId = $this->existingFileId($request);
            if ($existingId > 0) {
                $this->db->commit();
                $result = [
                    'success' => true,
                    'file_id' => $existingId,
                    'duplicate' => true,
                ];
            } else {
                $upload = $this->storeUpload($request, $limit);
                $fileId = $this->insertFile($request, $upload);
                $this->db->commit();
                $result = [
                    'success' => true,
                    'file_id' => $fileId,
                    'duplicate' => false,
                ];
            }
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->deleteStoredUpload($request, $upload);
            throw $error;
        }

        return $result;
    }

    private function existingFileId(array $request): int {
        $fileId = 0;
        $telegramFileId = (string)$request['telegram_file_id'];
        if ($telegramFileId !== '') {
            $stmt = $this->duplicateFileStatement((string)$request['entity']);
            if ($stmt !== null) {
                $stmt->execute([$request['entity_id'], $telegramFileId]);
                $fileId = (int)$stmt->fetchColumn();
            }
        }

        return $fileId;
    }

    private function duplicateFileStatement(string $entity): ?PDOStatement {
        $stmt = null;
        if ($entity === 'lead' && dbColumnExists('lead_files', 'telegram_file_id')) {
            $stmt = $this->db->prepare(
                'SELECT id FROM lead_files '
                . 'WHERE lead_id=? AND telegram_file_id=? LIMIT 1 FOR UPDATE'
            );
        } elseif ($entity === 'candidate') {
            $stmt = $this->db->prepare(
                'SELECT id FROM candidate_application_files '
                . 'WHERE candidate_application_id=? AND telegram_file_id=? '
                . 'LIMIT 1 FOR UPDATE'
            );
        }

        return $stmt;
    }

    private function storeUpload(array $request, int $limit): array {
        $upload = FileStorage::store(
            (array)$request['file'],
            (string)$request['category'],
            self::ALLOWED_MIME_TYPES,
            $limit
        );
        if (empty($upload['success'])) {
            throw new FileStorageException(
                (string)($upload['error'] ?? 'Не удалось сохранить файл')
            );
        }

        return $upload;
    }

    private function insertFile(array $request, array $upload): int {
        if ($request['entity'] === 'lead') {
            $this->insertLeadFile($request, $upload);
        } else {
            $this->db->prepare(
                'INSERT INTO candidate_application_files '
                . '(candidate_application_id,file_path,file_name,mime_type,'
                . 'file_size,telegram_file_id) VALUES (?,?,?,?,?,?)'
            )->execute($this->fileValues($request, $upload));
        }

        return (int)$this->db->lastInsertId();
    }

    private function insertLeadFile(array $request, array $upload): void {
        $values = $this->fileValues($request, $upload);
        if (dbColumnExists('lead_files', 'telegram_file_id')) {
            $sql = 'INSERT INTO lead_files '
                . '(lead_id,file_path,file_name,mime_type,file_size,'
                . 'telegram_file_id) VALUES (?,?,?,?,?,?)';
        } else {
            $sql = 'INSERT INTO lead_files '
                . '(lead_id,file_path,file_name,mime_type,file_size) '
                . 'VALUES (?,?,?,?,?)';
            array_pop($values);
        }
        $this->db->prepare($sql)->execute($values);
    }

    private function fileValues(array $request, array $upload): array {
        $telegramFileId = (string)$request['telegram_file_id'];

        return [
            $request['entity_id'],
            $upload['file_path'],
            $upload['file_name'],
            $upload['mime_type'],
            $upload['file_size'],
            $telegramFileId !== '' ? $telegramFileId : null,
        ];
    }

    private function deleteStoredUpload(array $request, ?array $upload): void {
        if (is_array($upload)
            && !empty($upload['success'])
            && !empty($upload['file_path'])) {
            FileStorage::deleteOwnedFile(
                (string)$upload['file_path'],
                (string)$request['category'],
                $request['entity'] === 'lead'
                    ? 'lead'
                    : 'candidate_application',
                (int)$request['entity_id']
            );
        }
    }
}
