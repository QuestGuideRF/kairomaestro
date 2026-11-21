<?php
class EventValidator {
    public function validate($data) {
        $error = $this->contentError($data);
        if ($error === null) {
            $error = $this->scheduleError($data);
        }
        return $error;
    }
    private function contentError($data) {
        $title = trim((string)($data['title'] ?? ''));
        $location = trim((string)($data['location'] ?? ''));
        if ($title === '' || $location === '' || utf8Length($title) > 255 || utf8Length($location) > 255) {
            $error = 'Проверьте название и место мероприятия';
        } elseif (utf8Length($data['technical_task'] ?? '') > 50000) {
            $error = 'Техническое задание слишком длинное';
        } else {
            $error = null;
        }
        return $error;
    }
    private function scheduleError($data) {
        $error = $this->dateError((string)($data['event_date'] ?? ''));
        if ($error === null) {
            $error = $this->timeError($data);
        }
        if ($error === null && !in_array(($data['status'] ?? 'active'), ['draft', 'active', 'completed', 'cancelled'], true)) {
            $error = 'Недопустимый статус мероприятия';
        }
        if ($error === null) {
            $error = $this->coordinateError($data);
        }
        return $error;
    }
    private function dateError($value) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return !$date || $date->format('Y-m-d') !== $value ? 'Проверьте дату мероприятия' : null;
    }
    private function timeError($data) {
        $error = null;
        foreach (['start_time' => false, 'end_time' => true] as $field => $optional) {
            $value = trim((string)($data[$field] ?? ''));
            if ($error === null && !($optional && $value === '') && !$this->validTime($value)) {
                $error = 'Проверьте время мероприятия';
            }
        }
        return $error;
    }
    private function coordinateError($data) {
        $error = null;
        foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $field => $range) {
            $value = $data[$field] ?? null;
            if ($error === null && $value !== null && $value !== ''
                && (!is_numeric($value) || (float)$value < $range[0] || (float)$value > $range[1])) {
                $error = 'Проверьте координаты площадки';
            }
        }
        return $error;
    }
    private function validTime($value) {
        $valid = false;
        foreach (['H:i', 'H:i:s'] as $format) {
            $time = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($time && $time->format($format) === $value) {
                $valid = true;
            }
        }
        return $valid;
    }
}
