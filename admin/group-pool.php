<?php
require_once __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    adminRedirect('group-pool.php', $adminRepository->requestGroupRegistration(
        (string)($_POST['telegram_chat_id'] ?? ''),
        (string)($_POST['kind'] ?? ''),
        (int)$_SESSION['user_id']
    ));
}
$adminTitle = 'Пул Telegram-групп';
$adminSection = 'pool';
$rows = $adminRepository->groupPool();
require_once __DIR__ . '/header.php';
?>
<section class="admin-panel">
<p>Группы создаются вручную как приватные supergroup. Добавьте бота администратором и выполните внутри группы <code>/register_group client</code> или <code>/register_group team</code>.</p>
<form method="post" class="admin-inline-form">
    <?php echo csrfField(); ?>
    <label>
        ID приватной supergroup
        <input name="telegram_chat_id" inputmode="numeric" placeholder="-1001234567890" required>
    </label>
    <label>
        Назначение
        <select name="kind" required>
            <option value="client">Клиентская группа</option>
            <option value="team">Командная группа</option>
        </select>
    </label>
    <button type="submit">Проверить и добавить</button>
</form>
<p><small>Бот асинхронно проверит тип группы и права администратора. Токен Telegram из PHP не используется.</small></p>
</section>
<div class="admin-table-wrap"><table class="admin-table">
<thead><tr><th>Группа</th><th>Тип</th><th>Статус</th><th>Заказ</th><th>Права</th><th>Проверка</th></tr></thead>
<tbody><?php foreach ($rows as $row): ?><tr>
<td><?php echo escape($row['title'] ?: 'Без названия'); ?><br><small><?php echo escape($row['telegram_chat_id']); ?></small></td>
<td><?php echo escape($row['kind']); ?></td><td><?php echo escape($row['status']); ?></td><td><?php echo $row['bound_order_id'] ? '#' . (int)$row['bound_order_id'] : '—'; ?></td>
<td><?php echo $row['bot_is_admin'] ? 'Администратор' : 'Нет прав'; ?><br><small>invite <?php echo (int)$row['can_invite_users']; ?> · delete <?php echo (int)$row['can_delete_messages']; ?> · manage <?php echo (int)$row['can_manage_chat']; ?> · change <?php echo (int)$row['can_change_info']; ?> · restrict <?php echo (int)$row['can_restrict_members']; ?></small></td>
<td><?php echo escape($row['last_checked_at'] ?: 'Не проверялась'); ?><br><small><?php echo escape($row['last_error'] ?: ''); ?></small></td>
</tr><?php endforeach; ?></tbody>
</table></div>
<?php require_once __DIR__ . '/footer.php'; ?>
