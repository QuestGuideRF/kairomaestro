<?php
require_once __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $raw = trim((string)($_POST['setting_value'] ?? ''));
    $value = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        adminRedirect('settings.php', ['success' => false, 'error' => 'Значение должно быть корректным JSON']);
    }
    adminRedirect('settings.php', $adminRepository->saveSetting(
        (string)($_POST['setting_key'] ?? ''),
        $value,
        (int)$_SESSION['user_id']
    ));
}
$adminTitle = 'Настройки';
$adminSection = 'settings';
$rows = $adminRepository->settings();
require_once __DIR__ . '/header.php';
?>
<section class="admin-panel"><p>Секреты, токен Telegram и пароль базы здесь намеренно не показываются и меняются только в <code>.env</code>.</p></section>
<section class="admin-panel">
<h2>Изменить безопасную настройку</h2>
<p>Порог пула принимает число от 0 до 100, срок приглашения — от 5 до 1440 минут.</p>
<form method="post" class="admin-form"><?php echo csrfField(); ?>
<label>Ключ<select name="setting_key"><option value="telegram.group_pool_low_threshold">Порог свободных групп</option><option value="telegram.invite_ttl_minutes">Срок приглашения, минут</option></select></label>
<label>Целое число<input name="setting_value" type="number" value="60" min="0" max="1440" required></label>
<button class="admin-button">Сохранить</button>
</form>
</section>
<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Ключ</th><th>Значение</th><th>Обновлено</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?php echo escape($row['setting_key']); ?></td><td><code><?php echo escape($row['setting_value']); ?></code></td><td><?php echo escape($row['updated_at']); ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php require_once __DIR__ . '/footer.php'; ?>
