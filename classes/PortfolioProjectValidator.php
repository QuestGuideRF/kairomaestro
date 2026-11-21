<?php
final class PortfolioProjectValidator {
    public static function validate(array $input): array {
        $title = trim((string)($input['title'] ?? ''));
        $slug = strtolower(trim((string)($input['slug'] ?? '')));
        $description = trim((string)($input['description'] ?? ''));
        $style = trim((string)($input['style'] ?? ''));
        $location = trim((string)($input['location'] ?? ''));
        $altText = trim((string)($input['alt_text'] ?? ''));
        $galleryAltPrefix = trim(
            (string)($input['gallery_alt_prefix'] ?? '')
        );
        $guests = trim((string)($input['guests_count'] ?? ''));
        $eventDate = trim((string)($input['event_date'] ?? ''));

        self::validateRequiredText($title, $slug, $description);
        self::validateOptionalText(
            $style,
            $location,
            $altText,
            $galleryAltPrefix
        );

        return [
            'title' => $title,
            'slug' => $slug,
            'description' => $description,
            'style' => $style ?: null,
            'location' => $location ?: null,
            'alt_text' => $altText ?: $title,
            'gallery_alt_prefix' => $galleryAltPrefix,
            'guests_count' => self::validatedGuests($guests),
            'event_date' => self::validatedEventDate($eventDate),
            'is_published' => !empty($input['is_published']) ? 1 : 0,
            'sort_order' => max(
                -10000,
                min(10000, (int)($input['sort_order'] ?? 0))
            ),
        ];
    }

    private static function validateRequiredText(
        string $title,
        string $slug,
        string $description
    ): void {
        if ($title === '' || utf8Length($title) > 190) {
            throw new ValidationException(
                'Укажите название проекта длиной до 190 символов.'
            );
        }
        if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug)) {
            throw new ValidationException(
                'Slug должен содержать латинские буквы, цифры и дефисы.'
            );
        }
        if ($description === '' || utf8Length($description) > 12000) {
            throw new ValidationException(
                'Добавьте описание проекта длиной до 12 000 символов.'
            );
        }
    }

    private static function validateOptionalText(
        string $style,
        string $location,
        string $altText,
        string $galleryAltPrefix
    ): void {
        if (utf8Length($style) > 120
            || utf8Length($location) > 190
            || utf8Length($altText) > 190
            || utf8Length($galleryAltPrefix) > 170) {
            throw new ValidationException(
                'Одно из текстовых полей слишком длинное.'
            );
        }
    }

    private static function validatedGuests(string $guests): ?int {
        if ($guests === '') {
            return null;
        }
        if (!ctype_digit($guests)
            || (int)$guests < 1
            || (int)$guests > 100000) {
            throw new ValidationException(
                'Укажите корректное количество гостей.'
            );
        }
        return (int)$guests;
    }

    private static function validatedEventDate(string $eventDate): ?string {
        if ($eventDate === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
        if (!$date || $date->format('Y-m-d') !== $eventDate) {
            throw new ValidationException('Укажите корректную дату события.');
        }
        return $eventDate;
    }
}
