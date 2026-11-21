<?php
trait AdminSettingsRepository {
    public function settings(): array {
        if (!dbTableExists('app_settings')) {
            return [];
        }

        return $this->db->query(
            'SELECT setting_key,setting_value,updated_by,updated_at '
            . 'FROM app_settings ORDER BY setting_key'
        )->fetchAll();
    }

    public function saveSetting(string $key, mixed $value, int $actorId): array {
        $validationError = $this->settingValidationError($key, $value);
        if ($validationError !== null) {
            return ['success' => false, 'error' => $validationError];
        }
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $this->db->prepare(
            'INSERT INTO app_settings '
            . '(setting_key,setting_value,updated_by) VALUES (?,?,?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'setting_value=VALUES(setting_value),'
            . 'updated_by=VALUES(updated_by),updated_at=NOW()'
        )->execute([$key, $encoded, $actorId]);
        AuditLog::record(
            'admin.setting_updated',
            'app_setting',
            null,
            null,
            ['setting_key' => $key],
            ['user_id' => $actorId, 'role' => 'admin']
        );

        return ['success' => true];
    }

    private function settingValidationError(
        string $key,
        mixed $value
    ): ?string {
        $error = null;
        if (!in_array($key, $this->publicSettingKeys(), true)) {
            $error = 'Эта настройка недоступна из интерфейса';
        } elseif (!dbTableExists('app_settings')) {
            $error = 'Примените миграцию Telegram CRM';
        } elseif (!is_int($value)) {
            $error = 'Значение должно быть целым числом';
        } elseif (!$this->settingValueIsValid($key, $value)) {
            $error = 'Значение выходит за допустимый диапазон';
        }

        return $error;
    }

    private function publicSettingKeys(): array {
        return [
            'telegram.group_pool_low_threshold',
            'telegram.invite_ttl_minutes',
        ];
    }

    private function settingValueIsValid(string $key, int $value): bool {
        return match ($key) {
            'telegram.group_pool_low_threshold' => $value >= 0
                && $value <= 100,
            'telegram.invite_ttl_minutes' => $value >= 5
                && $value <= 1440,
            default => false,
        };
    }
}
