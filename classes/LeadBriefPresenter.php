<?php
class LeadBriefPresenter {
    private LeadAnswerPresenter $answers;

    public function __construct() {
        $this->answers = new LeadAnswerPresenter();
    }

    public function present(array $lead, array $answerRows): array {
        $map = $this->answerMap($answerRows);
        return [
            'summary' => $this->summary($lead, $map),
            'highlights' => $this->highlights($lead, $map),
            'sections' => $this->sections($answerRows),
            'visual_groups' => $this->visualGroups($map),
            'palette' => $this->palette($map),
        ];
    }

    private function answerMap(array $rows): array {
        $map = [];
        foreach ($rows as $row) {
            $key = trim((string)($row['question_key'] ?? ''));
            if ($key !== '') {
                $map[$key] = $this->decode($row['answer_value'] ?? '');
            }
        }
        return $map;
    }

    private function sections(array $answerRows): array {
        $rowsByKey = [];
        foreach ($this->answers->rows($answerRows) as $row) {
            $rowsByKey[$row['key']] = $row;
        }
        $sections = [];
        $used = [];
        foreach (QuizBrief::sections() as $key => $definition) {
            $items = [];
            foreach ($definition['keys'] as $answerKey) {
                if (!isset($rowsByKey[$answerKey]) || $this->isEmptyDisplay($rowsByKey[$answerKey]['value'])) {
                    continue;
                }
                $items[] = $rowsByKey[$answerKey];
                $used[$answerKey] = true;
            }
            if ($items) {
                $sections[] = ['key' => $key, 'title' => $definition['title'], 'items' => $items];
            }
        }
        $other = array_values(array_filter(
            $rowsByKey,
            fn(array $row): bool => !isset($used[$row['key']]) && !$this->isEmptyDisplay($row['value'])
        ));
        if ($other) {
            $sections[] = ['key' => 'other', 'title' => 'Дополнительные данные', 'items' => $other];
        }
        return $sections;
    }

    private function summary(array $lead, array $map): string {
        $event = trim((string)($lead['event_type'] ?? $map['event_type'] ?? ''));
        $guests = $this->guestText($lead, $map);
        $date = trim((string)($lead['event_date'] ?? $map['event_date'] ?? ''));
        $opening = $event;
        if ($guests !== '') {
            $opening .= ($opening !== '' ? ' на ' : '') . $guests;
        }
        if ($date !== '') {
            $opening .= ($opening !== '' ? ', ' : '') . $this->display('event_date', $date);
        }
        $sentences = $opening !== '' ? [$opening . '.'] : [];

        $services = $this->display('services_needed', $map['services_needed'] ?? $lead['services_needed'] ?? '');
        if ($services !== '') {
            $sentences[] = 'Нужны: ' . $services . '.';
        }
        $visual = $this->display('visual_styles', $map['visual_styles'] ?? $map['style'] ?? '');
        $palette = $this->display('palette', $map['palette'] ?? $map['color_palette'] ?? '');
        if ($visual !== '' || $palette !== '') {
            $parts = [];
            if ($visual !== '') {
                $parts[] = 'стиль: ' . $visual;
            }
            if ($palette !== '') {
                $parts[] = 'палитра: ' . $palette;
            }
            $sentences[] = 'Визуальное направление: ' . implode('; ', $parts) . '.';
        }
        $priority = trim((string)($map['main_priority'] ?? ''));
        if ($priority !== '') {
            $sentences[] = 'Главный приоритет: ' . rtrim($priority, ". \t\n\r\0\x0B") . '.';
        }
        return $sentences ? implode(' ', $sentences) : 'Подробный бриф не заполнен. Используйте основные данные заявки и итог разговора.';
    }

    private function highlights(array $lead, array $map): array {
        $items = [
            ['label' => 'Дата', 'value' => $this->display('event_date', $lead['event_date'] ?? $map['event_date'] ?? '')],
            ['label' => 'Гости', 'value' => $this->guestText($lead, $map)],
            ['label' => 'Площадка', 'value' => $this->venueText($lead, $map)],
            ['label' => 'Бюджет', 'value' => $this->display('budget_range', $lead['budget_range'] ?? $map['budget_range'] ?? '')],
        ];
        return array_values(array_filter($items, static fn(array $item): bool => $item['value'] !== ''));
    }

    private function visualGroups(array $map): array {
        $assets = QuizBrief::visualAssets();
        $labels = QuizBrief::optionSets();
        $groupLabels = [
            'visual_styles' => 'Стиль события',
            'venue_type' => 'Тип площадки',
            'ceremony_style' => 'Оформление церемонии',
            'floristry_styles' => 'Флористика',
        ];
        $groups = [];
        foreach ($groupLabels as $key => $title) {
            $values = $this->asArray($map[$key] ?? []);
            $items = [];
            foreach ($values as $value) {
                if (!isset($assets[$key][$value])) {
                    continue;
                }
                $items[] = [
                    'value' => $value,
                    'label' => $labels[$key][$value] ?? $value,
                    'asset' => $assets[$key][$value],
                ];
            }
            if ($items) {
                $groups[] = ['key' => $key, 'title' => $title, 'items' => $items];
            }
        }
        return $groups;
    }

    private function palette(array $map): ?array {
        $value = is_string($map['palette'] ?? null) ? trim($map['palette']) : '';
        $colors = QuizBrief::paletteMeta()[$value] ?? null;
        if (!$colors) {
            return null;
        }
        return [
            'value' => $value,
            'label' => QuizBrief::optionSets()['palette'][$value] ?? $value,
            'colors' => $colors,
        ];
    }

    private function guestText(array $lead, array $map): string {
        $count = trim((string)($lead['guests_count'] ?? $map['guests_count'] ?? ''));
        if ($count !== '' && (int)$count > 0) {
            return (int)$count . ' гостей';
        }
        return $this->display('guests_range', $map['guests_range'] ?? '');
    }

    private function venueText(array $lead, array $map): string {
        $name = trim((string)($lead['venue'] ?? $map['venue_name'] ?? ''));
        $city = trim((string)($map['location_city'] ?? ''));
        $lower = static fn(string $value): string => function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
        if ($name !== '' && $city !== '' && !str_contains($lower($name), $lower($city))) {
            return $name . ', ' . $city;
        }
        return $name !== '' ? $name : $city;
    }

    private function display(string $key, $value): string {
        if ($this->isEmpty($value)) {
            return '';
        }
        $display = $this->answers->displayValue($key, $value);
        return $this->isEmptyDisplay($display) ? '' : $display;
    }

    private function decode($value) {
        if (is_array($value)) {
            return $value;
        }
        $text = trim((string)$value);
        if ($text !== '' && in_array($text[0] ?? '', ['[', '{'], true)) {
            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $text;
    }

    private function asArray($value): array {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        return trim((string)$value) === '' ? [] : [trim((string)$value)];
    }

    private function isEmpty($value): bool {
        return $value === null || $value === '' || $value === [];
    }

    private function isEmptyDisplay(string $value): bool {
        return $value === '' || $value === 'Не указано';
    }
}
