<?php
$publicTeamValues = array_merge($publicTeamFallback ?? [], $publicTeamProfile ?: []);
?>
<section class="profile-section public-team-profile-editor">
    <div class="section-header">
        <div>
            <p class="eyebrow">Страница «О нас»</p>
            <h2>Публичный профиль команды</h2>
            <p class="muted">Здесь используются только имя, фотография и поля ниже. Контакты и рабочие данные не публикуются.</p>
        </div>
    </div>
    <form method="POST" class="event-form">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="public_team_profile">
        <div class="form-row">
            <div class="form-group">
                <label for="public-team-role">Публичная роль</label>
                <input id="public-team-role" name="public_role" class="form-control" maxlength="120" value="<?php echo escape($publicTeamValues['public_role'] ?? ''); ?>" placeholder="Организатор проекта">
            </div>
            <div class="form-group">
                <label for="public-team-experience">Опыт, лет</label>
                <input id="public-team-experience" type="number" name="experience_years" class="form-control" min="0" max="80" step="0.5" value="<?php echo escape($publicTeamValues['experience_years'] ?? 0); ?>">
            </div>
        </div>
        <div class="form-group">
            <label for="public-team-specialization">Специализация</label>
            <input id="public-team-specialization" name="specialization" class="form-control" maxlength="190" value="<?php echo escape($publicTeamValues['specialization'] ?? ''); ?>" placeholder="Свадьбы под ключ и координация">
        </div>
        <div class="form-group">
            <label for="public-team-description">Короткое описание</label>
            <textarea id="public-team-description" name="public_description" class="form-control" rows="4" maxlength="5000" placeholder="Что вы делаете для клиента и проекта"><?php echo escape($publicTeamValues['public_description'] ?? ''); ?></textarea>
        </div>
        <div class="form-group">
            <label for="public-team-quote">Личный комментарий <span class="muted">необязательно</span></label>
            <textarea id="public-team-quote" name="public_quote" class="form-control" rows="2" maxlength="500"><?php echo escape($publicTeamValues['public_quote'] ?? ''); ?></textarea>
        </div>
        <label class="settings-toggle" for="public-team-published">
            <input id="public-team-published" type="checkbox" name="is_published" value="1" <?php echo !empty($publicTeamValues['is_published']) ? 'checked' : ''; ?>>
            <span><strong>Показывать профиль на странице «О нас»</strong><small>Публикацию можно выключить в любой момент.</small></span>
        </label>
        <button class="btn btn-primary" type="submit">Сохранить публичный профиль</button>
    </form>
</section>
