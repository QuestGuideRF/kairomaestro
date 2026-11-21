<?php
function quizBriefSelected($formData, string $name, string $value): bool {
    $selected = $formData[$name] ?? null;
    return is_array($selected)
        ? in_array($value, array_map('strval', $selected), true)
        : (string)$selected === $value;
}

function quizBriefOptionId(string $name, string $value): string {
    $safeName = trim((string)preg_replace('/[^a-z0-9-]+/i', '-', $name), '-');
    $safeValue = trim((string)preg_replace('/[^a-z0-9-]+/i', '-', $value), '-');
    return 'quiz-' . ($safeName ?: 'field') . '-' . ($safeValue ?: substr(hash('sha256', $value), 0, 10));
}

function quizBriefPicture(string $assetBase): void {
    $base = 'assets/images/quiz/' . ltrim($assetBase, '/');
    $avif320 = asset($base . '-320.avif');
    $avif640 = asset($base . '-640.avif');
    $webp320 = asset($base . '-320.webp');
    $webp640 = asset($base . '-640.webp');
    ?>
    <picture>
        <source type="image/avif" srcset="<?php echo escape($avif320); ?> 320w, <?php echo escape($avif640); ?> 640w" sizes="(max-width: 600px) 46vw, (max-width: 980px) 30vw, 260px">
        <img src="<?php echo escape($webp320); ?>" srcset="<?php echo escape($webp320); ?> 320w, <?php echo escape($webp640); ?> 640w" sizes="(max-width: 600px) 46vw, (max-width: 980px) 30vw, 260px" alt="" width="640" height="480" loading="lazy" decoding="async">
    </picture>
    <?php
}

function quizBriefVisualOptions(
    string $name,
    array $options,
    array $formData,
    array $assets,
    array $descriptions = [],
    string $type = 'checkbox'
): void {
    $fieldName = $type === 'checkbox' ? $name . '[]' : $name;
    foreach ($options as $value => $label) {
        $id = quizBriefOptionId($name, (string)$value);
        $checked = quizBriefSelected($formData, $name, (string)$value);
        ?>
        <label class="quiz-visual-card<?php echo isset($assets[$value]) ? '' : ' quiz-visual-card--text'; ?>">
            <input id="<?php echo escape($id); ?>" type="<?php echo escape($type); ?>" name="<?php echo escape($fieldName); ?>" value="<?php echo escape($value); ?>"<?php echo $checked ? ' checked' : ''; ?><?php echo in_array($value, ['unsure', 'none', 'nothing'], true) ? ' data-exclusive="1"' : ''; ?>>
            <span class="quiz-visual-card__surface">
                <?php if (isset($assets[$value])): ?>
                    <span class="quiz-visual-card__media"><?php quizBriefPicture($assets[$value]); ?></span>
                <?php endif; ?>
                <span class="quiz-visual-card__content">
                    <strong><?php echo escape($label); ?></strong>
                    <?php if (!empty($descriptions[$value])): ?><span><?php echo escape($descriptions[$value]); ?></span><?php endif; ?>
                    <?php if (isset($assets[$value])): ?><small>Пример визуального направления</small><?php endif; ?>
                </span>
                <span class="quiz-visual-card__check" aria-hidden="true"></span>
            </span>
        </label>
        <?php
    }
}

function quizBriefChoiceOptions(
    string $name,
    array $options,
    array $formData,
    string $type = 'radio',
    array $descriptions = []
): void {
    $fieldName = $type === 'checkbox' ? $name . '[]' : $name;
    foreach ($options as $value => $label) {
        $id = quizBriefOptionId($name, (string)$value);
        ?>
        <label class="quiz-choice-card">
            <input id="<?php echo escape($id); ?>" type="<?php echo escape($type); ?>" name="<?php echo escape($fieldName); ?>" value="<?php echo escape($value); ?>"<?php echo quizBriefSelected($formData, $name, (string)$value) ? ' checked' : ''; ?><?php echo in_array($value, ['unsure', 'none', 'nothing'], true) ? ' data-exclusive="1"' : ''; ?>>
            <span class="quiz-choice-card__surface">
                <span class="quiz-choice-card__check" aria-hidden="true"></span>
                <span><strong><?php echo escape($label); ?></strong><?php if (!empty($descriptions[$value])): ?><small><?php echo escape($descriptions[$value]); ?></small><?php endif; ?></span>
            </span>
        </label>
        <?php
    }
}

function quizBriefChipOptions(
    string $name,
    array $options,
    array $formData,
    string $type = 'checkbox'
): void {
    $fieldName = $type === 'checkbox' ? $name . '[]' : $name;
    foreach ($options as $value => $label) {
        $id = quizBriefOptionId($name, (string)$value);
        ?>
        <label class="quiz-chip">
            <input id="<?php echo escape($id); ?>" type="<?php echo escape($type); ?>" name="<?php echo escape($fieldName); ?>" value="<?php echo escape($value); ?>"<?php echo quizBriefSelected($formData, $name, (string)$value) ? ' checked' : ''; ?><?php echo $type === 'checkbox' && in_array($value, ['unsure', 'none', 'nothing'], true) ? ' data-exclusive="1"' : ''; ?>>
            <span><?php echo escape($label); ?></span>
        </label>
        <?php
    }
}

function quizBriefPaletteOptions(array $options, array $formData): void {
    $palettes = QuizBrief::paletteMeta();
    foreach ($options as $value => $label) {
        $id = 'quiz-palette-' . $value;
        ?>
        <label class="quiz-palette-card<?php echo isset($palettes[$value]) ? '' : ' quiz-palette-card--plain'; ?>">
            <input id="<?php echo escape($id); ?>" type="radio" name="palette" value="<?php echo escape($value); ?>"<?php echo quizBriefSelected($formData, 'palette', (string)$value) ? ' checked' : ''; ?>>
            <span class="quiz-palette-card__surface">
                <?php if (isset($palettes[$value])): ?>
                    <span class="quiz-palette-card__swatches" aria-hidden="true">
                        <?php foreach ($palettes[$value] as $index => $color): ?><i class="quiz-palette-card__swatch quiz-palette-card__swatch--<?php echo escape($value . '-' . ($index + 1)); ?>"></i><?php endforeach; ?>
                    </span>
                <?php endif; ?>
                <strong><?php echo escape($label); ?></strong>
                <span class="quiz-palette-card__check" aria-hidden="true"></span>
            </span>
        </label>
        <?php
    }
}
