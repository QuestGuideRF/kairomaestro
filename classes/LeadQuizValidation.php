<?php
trait LeadQuizValidation {
    private function validationError($data, $files) {
        $error = null;
        foreach (['contactError', 'eventError', 'servicesError', 'contentError'] as $validator) {
            if ($error === null) {
                $error = $this->{$validator}($data, $files);
            }
        }
        return $error;
    }
    private function contactError($data) {
        $contact = [
            'name' => trim((string)($data['client_name'] ?? '')),
            'phone' => trim((string)($data['phone'] ?? '')),
            'email' => trim((string)($data['email'] ?? '')),
            'telegram' => trim((string)($data['telegram_username'] ?? '')),
            'method' => (string)($data['contact_method'] ?? 'site_chat'),
            'telegram_verified' => !empty($data['_telegram_identity_verified']),
        ];
        $error = $this->contactIdentityError($contact);
        if ($error === null) {
            $error = $this->contactMethodError($contact);
        }
        return $error;
    }
    private function contactIdentityError($contact) {
        if ($contact['name'] === '' || utf8Length($contact['name']) > 190) {
            $error = 'Укажите корректное имя';
        } elseif ($contact['phone'] === '' && $contact['email'] === '' && $contact['telegram'] === ''
            && !$contact['telegram_verified']) {
            $error = 'Укажите контакт для связи';
        } elseif ($contact['email'] !== '' && (!filter_var($contact['email'], FILTER_VALIDATE_EMAIL) || utf8Length($contact['email']) > 190)) {
            $error = 'Проверьте адрес электронной почты';
        } elseif (utf8Length($contact['phone']) > 40 || utf8Length($contact['telegram']) > 120) {
            $error = 'Проверьте контактные данные';
        } else {
            $error = null;
        }
        return $error;
    }
    private function contactMethodError($contact) {
        $methods = ['site_chat', 'telegram', 'whatsapp', 'call', 'sms', 'email'];
        if (!in_array($contact['method'], $methods, true)) {
            $error = 'Выберите способ связи';
        } elseif (in_array($contact['method'], ['whatsapp', 'call', 'sms'], true) && $contact['phone'] === '') {
            $error = 'Для выбранного способа связи нужен номер телефона';
        } elseif ($contact['method'] === 'telegram' && $contact['telegram'] === ''
            && $contact['phone'] === '' && !$contact['telegram_verified']) {
            $error = 'Для Telegram укажите username или телефон';
        } elseif ($contact['method'] === 'email' && $contact['email'] === '') {
            $error = 'Для связи по email укажите адрес почты';
        } else {
            $error = null;
        }
        return $error;
    }
    private function eventError($data) {
        $eventType = trim((string)($data['event_type'] ?? ''));
        $eventDate = trim((string)($data['event_date'] ?? ''));
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
        $minimumDate = (new DateTimeImmutable('today'))->modify('+7 days');
        $eventTime = trim((string)($data['event_time'] ?? ''));
        $parsedTime = $eventTime === '' ? null : DateTimeImmutable::createFromFormat('!H:i', $eventTime);
        $guestsRaw = trim((string)($data['guests_count'] ?? ''));
        $guests = $guestsRaw === '' ? null : filter_var($guestsRaw, FILTER_VALIDATE_INT);
        $guestRanges = array_keys(QuizBrief::optionSets()['guests_range']);
        $guestsRange = (string)($data['guests_range'] ?? '');
        $telegram = !empty($data['_telegram_identity_verified']);
        if ($eventType === '') {
            $error = 'Выберите тип мероприятия';
        } elseif (!$parsedDate || $parsedDate->format('Y-m-d') !== $eventDate || $parsedDate < $minimumDate) {
            $error = 'Дата мероприятия должна быть не раньше ' . $minimumDate->format('d.m.Y');
        } elseif ($eventTime !== '' && (!$parsedTime || $parsedTime->format('H:i') !== $eventTime)) {
            $error = 'Проверьте время мероприятия';
        } elseif (($guestsRaw !== '' && ($guests === false || $guests < 1 || $guests > 10000))
            || ($guestsRaw === '' && !in_array($guestsRange, $guestRanges, true))) {
            $error = 'Укажите корректное количество гостей';
        } elseif (!$telegram && empty($data['event_format'])) {
            $error = 'Выберите формат мероприятия';
        } else {
            $error = null;
        }
        return $error;
    }
    private function servicesError($data) {
        $allowed = array_keys(QuizBrief::coreServices());
        $services = array_values(array_unique(array_map('strval', (array)($data['services_needed'] ?? []))));
        if (!$services || array_diff($services, $allowed)) {
            $error = 'Выберите услуги из списка';
        } elseif (empty($data['privacy_consent'])) {
            $error = 'Подтвердите согласие на обработку данных';
        } else {
            $error = null;
        }
        return $error;
    }
    private function contentError($data, $files) {
        $error = null;
        foreach (['location_details' => 500, 'color_palette' => 255, 'comment' => 10000] as $field => $limit) {
            if ($error === null && utf8Length($data[$field] ?? '') > $limit) {
                $error = 'Одно из полей квиза превышает допустимую длину';
            }
        }
        if ($error === null && $this->uploadedReferenceCount($files) > 10) {
            $error = 'Можно приложить не более 10 файлов';
        }
        return $error;
    }
    private function uploadedReferenceCount($files) {
        $names = $files['references']['name'] ?? [];
        if (is_array($names)) {
            $count = count(array_filter($names, static fn($name) => trim((string)$name) !== ''));
        } else {
            $count = trim((string)$names) === '' ? 0 : 1;
        }
        return $count;
    }
}
