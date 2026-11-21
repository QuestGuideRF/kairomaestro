<?php
class BotInternalAuth {
    private const CLOCK_SKEW_SECONDS = 300;
    private const MAX_JSON_BYTES = 2097152;
    private const INVALID_FILE_METADATA = 'Некорректные метаданные файла';
    private PDO $db;
    private string $secret;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->secret = trim((string)env('BOT_INTERNAL_SECRET', ''));
    }
    public function jsonPayload(): array {
        $this->assertTransport();
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_JSON_BYTES) {
            throw new ValidationException('Некорректный internal API payload');
        }
        $this->verifySignature(hash('sha256', $raw));
        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new ValidationException('Некорректный JSON');
        }
        if (!is_array($payload)) {
            throw new ValidationException('Некорректный internal API payload');
        }
        return $payload;
    }
    public function multipartMetadata(string $field = 'metadata'): array {
        $this->assertTransport();
        $rawMetadata = (string)($_POST[$field] ?? '');
        if ($rawMetadata === '' || strlen($rawMetadata) > 65536) {
            throw new ValidationException(self::INVALID_FILE_METADATA);
        }
        $fileDigests = [];
        foreach ($_FILES as $name => $file) {
            if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new ValidationException('Файл не был загружен');
            }
            $tmp = (string)($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new ValidationException('Недоверенный источник файла');
            }
            $digest = hash_file('sha256', $tmp);
            if (!is_string($digest)) {
                throw new ValidationException('Не удалось проверить файл');
            }
            $fileDigests[] = (string)$name . ':' . $digest;
        }
        sort($fileDigests, SORT_STRING);
        $digest = hash('sha256', $rawMetadata) . "\n" . implode("\n", $fileDigests);
        $this->verifySignature(hash('sha256', $digest));
        try {
            $metadata = json_decode($rawMetadata, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new ValidationException(self::INVALID_FILE_METADATA);
        }
        if (!is_array($metadata)) {
            throw new ValidationException(self::INVALID_FILE_METADATA);
        }
        return $metadata;
    }
    private function assertTransport(): void {
        if (strlen($this->secret) < 32) {
            throw new ConfigurationException('BOT_INTERNAL_SECRET is not configured');
        }
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        if (APP_ENV === 'production' && !$isHttps) {
            throw new AccessDeniedException('Internal API requires HTTPS');
        }
    }
    private function verifySignature(string $payloadDigest): void {
        $timestamp = (string)($_SERVER['HTTP_X_BOT_TIMESTAMP'] ?? '');
        $nonce = (string)($_SERVER['HTTP_X_BOT_NONCE'] ?? '');
        $signature = strtolower((string)($_SERVER['HTTP_X_BOT_SIGNATURE'] ?? ''));
        if (!ctype_digit($timestamp)
            || abs(time() - (int)$timestamp) > self::CLOCK_SKEW_SECONDS
            || !preg_match('/\A[A-Za-z0-9_-]{24,128}\z/', $nonce)
            || !preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
            throw new AccessDeniedException('Internal API authentication failed');
        }
        $base = $timestamp . "\n" . $nonce . "\n" . $payloadDigest;
        $expected = hash_hmac('sha256', $base, $this->secret);
        if (!hash_equals($expected, $signature)) {
            throw new AccessDeniedException('Internal API authentication failed');
        }
        if (!dbTableExists('telegram_service_nonces')) {
            throw new ConfigurationException('Telegram CRM migration is not installed');
        }
        try {
            $this->db->prepare(
                'INSERT INTO telegram_service_nonces (nonce_hash,expires_at) '
                . 'VALUES (?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))'
            )->execute([hash('sha256', $nonce)]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new AccessDeniedException('Internal API replay rejected');
            }
            throw $error;
        }
    }
}
