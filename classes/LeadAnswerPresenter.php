<?php
class LeadAnswerPresenter {
    private const LABELS = [
        'client_name' => 'Имя клиента',
        'phone' => 'Телефон',
        'email' => 'Электронная почта',
        'telegram_username' => 'Telegram',
        'contact_method' => 'Удобный способ связи',
        'event_type' => 'Тип события',
        'event_date' => 'Дата события',
        'event_time' => 'Время начала',
        'location_city' => 'Город / локация',
        'location_details' => 'Площадка или адрес',
        'guests_count' => 'Количество гостей',
        'budget_range' => 'Примерный бюджет',
        'services_needed' => 'Какие услуги нужны',
        'style' => 'Стиль события',
        'color_palette' => 'Цветовая палитра',
        'comment' => 'Комментарий клиента',
        'urgency' => 'Срочность подготовки',
        'source' => 'Источник заявки',
        'privacy_consent' => 'Согласие на обработку данных',
    ];
    private const VALUES = [
        'site_chat' => 'Чат на сайте',
        'telegram' => 'Telegram',
        'whatsapp' => 'WhatsApp',
        'call' => 'Звонок',
        'sms' => 'СМС',
        'email' => 'Электронная почта',
        'planning' => 'Планируем заранее',
        'soon' => 'Нужно начать в ближайшее время',
        'urgent' => 'Срочно',
        'site' => 'Публичный сайт',
        'quiz' => 'Квиз на сайте',
        'telegram_bot' => 'Telegram-бот',
        'search' => 'Поиск',
        'social' => 'Социальные сети',
        'recommendation' => 'Рекомендация',
        'partner' => 'Партнёр',
        'other' => 'Другое',
        'wedding' => 'Свадьба',
        'birthday' => 'День рождения',
        'corporate' => 'Корпоратив',
        'graduation' => 'Выпускной',
        'yes' => 'Да',
        'no' => 'Нет',
        '1' => 'Да',
        '0' => 'Нет',
    ];
    public function rows(array $answers) {
        $rows = [];
        $labels = array_merge(self::LABELS, QuizBrief::labels());
        foreach ($answers as $answer) {
            $key = (string)($answer['question_key'] ?? '');
            if ($key === '' || str_starts_with($key, 'utm_')) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $this->humanizeKey($key),
                'value' => $this->displayValue($key, $answer['answer_value'] ?? ''),
            ];
        }
        return $rows;
    }
    public function displayValue($key, $rawValue) {
        $value = $this->decodeValue($rawValue);
        if (is_array($value)) {
            $items = array_map(fn($item) => $this->translateScalar((string)$key, (string)$item), $value);
            $display = implode(', ', array_filter($items, static fn($item) => $item !== ''));
        } elseif ($key === 'event_date' && $this->isDate((string)$value)) {
            $display = formatRussianDate((string)$value, 'date_only');
        } elseif ($key === 'event_time' && preg_match('/^\d{2}:\d{2}/', (string)$value)) {
            $display = substr((string)$value, 0, 5);
        } else {
            $display = $this->translateScalar((string)$key, (string)$value);
        }
        return $display;
    }
    private function decodeValue($rawValue) {
        if (is_array($rawValue)) {
            $value = $rawValue;
        } else {
            $value = trim((string)$rawValue);
            if ($value !== '' && (($value[0] ?? '') === '[' || ($value[0] ?? '') === '{')) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }
        }
        return $value;
    }
    private function translateScalar($key, $value) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'Не указано';
        }
        $options = QuizBrief::optionSets()[$key] ?? [];
        if (array_key_exists($trimmed, $options)) {
            return $options[$trimmed];
        }
        return self::VALUES[strtolower($trimmed)] ?? $trimmed;
    }
    private function humanizeKey($key) {
        $text = str_replace('_', ' ', trim($key));
        return function_exists('mb_convert_case')
            ? mb_convert_case($text, MB_CASE_TITLE, 'UTF-8')
            : ucfirst($text);
    }
    private function isDate($value) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }
}
