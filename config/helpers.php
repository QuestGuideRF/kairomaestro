<?php
function formatRussianDate($date, $format = 'full') {
    $timestamp = strtotime($date);
    $months = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
        5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
        9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'
    ];
    $monthNames = [
        1 => 'январь', 2 => 'февраль', 3 => 'март', 4 => 'апрель',
        5 => 'май', 6 => 'июнь', 7 => 'июль', 8 => 'август',
        9 => 'сентябрь', 10 => 'октябрь', 11 => 'ноябрь', 12 => 'декабрь'
    ];
    $monthsShort = [
        1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр',
        5 => 'май', 6 => 'июн', 7 => 'июл', 8 => 'авг',
        9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек'
    ];
    $days = [
        'Monday' => 'Понедельник',
        'Tuesday' => 'Вторник',
        'Wednesday' => 'Среда',
        'Thursday' => 'Четверг',
        'Friday' => 'Пятница',
        'Saturday' => 'Суббота',
        'Sunday' => 'Воскресенье'
    ];
    $daysShort = [
        'Monday' => 'Пн',
        'Tuesday' => 'Вт',
        'Wednesday' => 'Ср',
        'Thursday' => 'Чт',
        'Friday' => 'Пт',
        'Saturday' => 'Сб',
        'Sunday' => 'Вс'
    ];
    $day = date('j', $timestamp);
    $month = (int)date('n', $timestamp);
    $year = date('Y', $timestamp);
    $dayName = date('l', $timestamp);
    return match ($format) {
        'full' => $day . ' ' . $months[$month] . ' ' . $year . ' (' . $days[$dayName] . ')',
        'short' => $day . ' ' . $monthsShort[$month],
        'month' => $months[$month],
        'month_name' => $monthNames[$month],
        'month_short' => $monthsShort[$month],
        'day' => $days[$dayName],
        'day_short' => $daysShort[$dayName],
        'date_only' => $day . ' ' . $months[$month] . ' ' . $year,
        default => date('d.m.Y', $timestamp),
    };
}
function formatTime($time) {
    return date('H:i', strtotime($time));
}
function formatRussianDateTime($datetime) {
    $date = formatRussianDate($datetime, 'date_only');
    $time = formatTime($datetime);
    return $date . ' в ' . $time;
}
function utf8Length($value) {
    $value = (string)$value;
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    if (function_exists('iconv_strlen')) {
        $length = iconv_strlen($value, 'UTF-8');
        return $length === false ? strlen($value) : $length;
    }
    return preg_match_all('/./us', $value, $matches) ?: strlen($value);
}
function utf8Substring($value, $start, $length = null) {
    $value = (string)$value;
    if (function_exists('mb_substr')) {
        return $length === null
            ? mb_substr($value, (int)$start, null, 'UTF-8')
            : mb_substr($value, (int)$start, (int)$length, 'UTF-8');
    }
    if (function_exists('iconv_substr')) {
        $result = iconv_substr($value, (int)$start, $length === null ? utf8Length($value) : (int)$length, 'UTF-8');
        if ($result !== false) {
            return $result;
        }
    }
    return $length === null ? substr($value, (int)$start) : substr($value, (int)$start, (int)$length);
}
function utf8Upper($value) {
    return function_exists('mb_strtoupper')
        ? mb_strtoupper((string)$value, 'UTF-8')
        : strtoupper((string)$value);
}
function utf8Lower($value) {
    $value = (string)$value;
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }
    return strtr(strtolower($value), [
        'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д',
        'Е' => 'е', 'Ё' => 'ё', 'Ж' => 'ж', 'З' => 'з', 'И' => 'и',
        'Й' => 'й', 'К' => 'к', 'Л' => 'л', 'М' => 'м', 'Н' => 'н',
        'О' => 'о', 'П' => 'п', 'Р' => 'р', 'С' => 'с', 'Т' => 'т',
        'У' => 'у', 'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц', 'Ч' => 'ч',
        'Ш' => 'ш', 'Щ' => 'щ', 'Ъ' => 'ъ', 'Ы' => 'ы', 'Ь' => 'ь',
        'Э' => 'э', 'Ю' => 'ю', 'Я' => 'я',
    ]);
}
function publicExceptionMessage($error, $fallback) {
    if (defined('APP_DEBUG') && APP_DEBUG) {
        return $error->getMessage();
    }
    if ($error instanceof AppException
        || get_class($error) === Exception::class
        || $error instanceof InvalidArgumentException
        || $error instanceof DomainException) {
        return $error->getMessage();
    }
    return $fallback;
}
function safeUploadedName($name, $limit = 190) {
    $name = basename(str_replace('\\', '/', (string)$name));
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    return utf8Substring($name ?: 'file', 0, max(1, (int)$limit));
}
function secureFileUrl($type, $id) {
    return '/download.php?type=' . rawurlencode((string)$type) . '&id=' . (int)$id;
}
