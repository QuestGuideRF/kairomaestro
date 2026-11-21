<?php
class FileStorage {
    private const PROTECTED_FILENAMES = [
        '.htaccess',
        '.gitkeep',
        'index.php',
    ];
    private const ALLOWED_CATEGORIES = [
        'avatars', 'chat', 'materials', 'references', 'receipts',
        'media', 'finance', 'timeline', 'concepts', 'reviews', 'candidate',
    ];
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'video/mp4' => 'mp4',
        'text/plain' => 'txt',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];
    public static function store($file, $category, $allowedMimes = null, $maxBytes = null) {
        $allowedMimes = $allowedMimes ?: array_keys(self::MIME_EXTENSIONS);
        $maxBytes = $maxBytes ?: UPLOAD_LIMIT_BYTES;
        $category = strtolower((string)$category);
        $error = self::storeValidationError($file, $category, $allowedMimes, $maxBytes);
        if ($error !== null) {
            return ['success' => false, 'error' => $error];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
        $dir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $result = ['success' => false, 'error' => 'Не удалось подготовить каталог загрузки'];
        } else {
            $filename = $category . '_' . bin2hex(random_bytes(16)) . '.' . self::MIME_EXTENSIONS[$mime];
            if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                $result = ['success' => false, 'error' => 'Не удалось сохранить файл'];
            } else {
                $result = [
                    'success' => true,
                    'file_path' => 'uploads/' . $category . '/' . $filename,
                    'file_name' => safeUploadedName($file['name'] ?? $filename),
                    'mime_type' => $mime,
                    'file_size' => (int)$file['size'],
                ];
            }
        }
        return $result;
    }
    public static function deleteOwnedFile($storedPath, $category, $entityType = 'file', $entityId = null) {
        $category = strtolower((string)$category);
        $entry = self::resolveStoredFile((string)$storedPath, $category);
        if ($entry === null) {
            AuditLog::record('file.delete_denied', $entityType, $entityId, null, null, ['result' => 'denied']);
            return false;
        }
        $filename = $entry->getFilename();
        $deleted = self::quarantineEntry($entry);
        AuditLog::record(
            $deleted ? 'file.deleted' : 'file.delete_failed',
            $entityType,
            $entityId,
            null,
            ['category' => $category, 'name_hash' => hash('sha256', $filename)],
            ['result' => $deleted ? 'success' : 'failure']
        );
        return $deleted;
    }
    private static function resolveStoredFile(
        string $storedPath,
        string $category
    ): ?SplFileInfo {
        $entry = null;
        $relativePath = self::normalizeStoredPath($storedPath, $category);
        if ($relativePath !== null) {
            $uploadRoot = realpath(rtrim(UPLOAD_DIR, '/\\'));
            $categoryRoot = realpath(
                rtrim(UPLOAD_DIR, '/\\')
                . DIRECTORY_SEPARATOR
                . $category
            );
            $rootsAreValid = $uploadRoot
                && $categoryRoot
                && self::pathIsWithin($categoryRoot, $uploadRoot)
                && is_readable($categoryRoot);
            if ($rootsAreValid) {
                $candidate = $categoryRoot
                    . DIRECTORY_SEPARATOR
                    . basename($relativePath);
                $target = is_link($candidate) ? false : realpath($candidate);
                $targetIsValid = $target
                    && is_file($target)
                    && self::pathIsWithin($target, $categoryRoot)
                    && self::pathIsWithin($target, $uploadRoot);
                if ($targetIsValid) {
                    $entry = new SplFileInfo($target);
                }
            }
        }
        return $entry;
    }
    private static function normalizeStoredPath(
        string $storedPath,
        string $category
    ): ?string {
        $storedPath = trim($storedPath);
        if (!in_array($category, self::ALLOWED_CATEGORIES, true)
            || $storedPath === ''
            || strpos($storedPath, "\0") !== false
            || preg_match('/^[\/\\\\]/', $storedPath)
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $storedPath)
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $storedPath)) {
            return null;
        }
        $normalized = str_replace('\\', '/', $storedPath);
        $segments = explode('/', $normalized);
        if (count($segments) !== 3
            || $segments[0] !== 'uploads'
            || $segments[1] !== $category
            || $segments[2] === ''
            || $segments[2][0] === '.'
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || in_array(strtolower($segments[2]), self::PROTECTED_FILENAMES, true)) {
            return null;
        }
        return implode('/', $segments);
    }
    private static function pathIsWithin(string $target, string $root): bool {
        $rootPrefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $normalizedTarget = rtrim($target, '/\\') . (
            is_dir($target) ? DIRECTORY_SEPARATOR : ''
        );
        if (DIRECTORY_SEPARATOR === '\\') {
            return strncasecmp(
                $normalizedTarget,
                $rootPrefix,
                strlen($rootPrefix)
            ) === 0;
        }
        return strncmp(
            $normalizedTarget,
            $rootPrefix,
            strlen($rootPrefix)
        ) === 0;
    }
    private static function quarantineEntry(SplFileInfo $entry): bool {
        $quarantine = rtrim(STORAGE_DIR, '/\\')
            . DIRECTORY_SEPARATOR
            . 'deleted-files';
        $ready = is_dir($quarantine)
            || @mkdir($quarantine, 0700, true)
            || is_dir($quarantine);
        $removed = false;
        if ($ready) {
            $handle = @fopen($entry->getPathname(), 'c+b');
            if ($handle !== false) {
                $locked = flock($handle, LOCK_EX);
                $cleared = $locked
                    && ftruncate($handle, 0)
                    && fflush($handle);
                if ($locked) {
                    flock($handle, LOCK_UN);
                }
                fclose($handle);
                if ($cleared) {
                    $tombstone = $quarantine
                        . DIRECTORY_SEPARATOR
                        . hash(
                            'sha256',
                            $entry->getFilename()
                                . ':'
                                . bin2hex(random_bytes(16))
                        )
                        . '.deleted';
                    $removed = @rename(
                        $entry->getPathname(),
                        $tombstone
                    );
                }
            }
        }

        return $removed;
    }
    private static function storeValidationError($file, $category, $allowedMimes, $maxBytes) {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            $error = 'Файл не был загружен';
        } elseif ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > $maxBytes) {
            $error = 'Размер файла превышает допустимый';
        } elseif (!class_exists('finfo')) {
            $error = 'Проверка типа файла недоступна на сервере';
        } elseif (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            $error = 'Недопустимая категория файла';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($file['tmp_name']);
            $error = !in_array($mime, $allowedMimes, true) || !isset(self::MIME_EXTENSIONS[$mime])
                ? 'Недопустимый тип файла'
                : null;
        }
        return $error;
    }
}
