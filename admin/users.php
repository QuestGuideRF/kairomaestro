<?php
require_once __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $confirmation = trim((string)($_POST['confirmation'] ?? ''));
    if ($confirmation !== 'ИЗМЕНИТЬ') {
        adminRedirect('users.php', ['success' => false, 'error' => 'Введите подтверждение ИЗМЕНИТЬ']);
    }
    adminRedirect('users.php', $adminRepository->updateUser(
        (int)($_POST['user_id'] ?? 0),
        (string)($_POST['role'] ?? ''),
        !empty($_POST['is_active']),
        (int)$_SESSION['user_id']
    ));
}
$adminTitle = 'Пользователи';
$adminSection = 'users';
$roleFilter = trim((string)($_GET['role'] ?? ''));
$roleLabels = [
    'client' => 'Клиент',
    'manager' => 'Менеджер',
    'organizer' => 'Организатор',
    'florist' => 'Флорист',
    'courier' => 'Курьер',
    'installer' => 'Монтажник',
    'helper' => 'Помощник',
    'worker' => 'Сотрудник',
    'admin' => 'Администратор',
];
$roles = array_keys($roleLabels);
if ($roleFilter !== '' && !isset($roleLabels[$roleFilter])) {
    $roleFilter = '';
}
$users = $adminRepository->users($roleFilter ?: null);
$activeUsers = count(array_filter(
    $users,
    static fn(array $user): bool => !empty($user['is_active'])
));
$telegramUsers = count(array_filter(
    $users,
    static fn(array $user): bool => !empty($user['telegram_user_id'])
));
require_once __DIR__ . '/header.php';
?>
<section class="admin-users-summary" aria-label="Сводка по пользователям">
    <article class="admin-users-stat">
        <span>Показано</span>
        <strong><?php echo count($users); ?></strong>
        <small><?php echo $roleFilter !== '' ? 'С учётом выбранной роли' : 'Все учётные записи'; ?></small>
    </article>
    <article class="admin-users-stat">
        <span>Активны</span>
        <strong><?php echo $activeUsers; ?></strong>
        <small>Могут входить в систему</small>
    </article>
    <article class="admin-users-stat">
        <span>Telegram</span>
        <strong><?php echo $telegramUsers; ?></strong>
        <small>Подтверждённые аккаунты</small>
    </article>
</section>

<nav class="admin-filter admin-users-filter" aria-label="Фильтр по роли">
    <a class="<?php echo $roleFilter === '' ? 'is-active' : ''; ?>"
       href="/admin/users.php"
       <?php echo $roleFilter === '' ? 'aria-current="page"' : ''; ?>>Все</a>
    <?php foreach ($roles as $role): ?>
        <a class="<?php echo $roleFilter === $role ? 'is-active' : ''; ?>"
           href="/admin/users.php?role=<?php echo escape($role); ?>"
           <?php echo $roleFilter === $role ? 'aria-current="page"' : ''; ?>><?php echo escape($roleLabels[$role]); ?></a>
    <?php endforeach; ?>
</nav>

<div class="admin-table-wrap admin-users-table-wrap">
<table class="admin-table admin-users-table">
    <thead>
        <tr>
            <th>Пользователь</th>
            <th>Контакты</th>
            <th>Telegram</th>
            <th>Роль и доступ</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $user): ?>
        <?php
        $userId = (int)$user['id'];
        $fullName = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);
        $fullName = $fullName !== '' ? $fullName : 'Имя не указано';
        $telegramUsername = ltrim((string)($user['telegram_username'] ?? ''), '@');
        ?>
        <tr>
            <td class="admin-user-cell admin-user-cell--identity">
                <div class="admin-user-identity">
                    <span class="admin-user-id" aria-hidden="true">#<?php echo $userId; ?></span>
                    <div>
                        <strong><?php echo escape($fullName); ?></strong>
                        <span><?php echo escape($roleLabels[$user['role']] ?? $user['role']); ?></span>
                        <small>Создан: <?php echo escape((string)($user['created_at'] ?: '—')); ?></small>
                    </div>
                </div>
            </td>
            <td class="admin-user-cell admin-user-cell--contacts">
                <div class="admin-user-contacts">
                    <?php if (!empty($user['phone'])): ?>
                        <a href="tel:<?php echo escape((string)preg_replace('/[^+\d]/', '', $user['phone'])); ?>"><?php echo escape($user['phone']); ?></a>
                    <?php endif; ?>
                    <?php if (!empty($user['email'])): ?>
                        <a href="mailto:<?php echo escape($user['email']); ?>"><?php echo escape($user['email']); ?></a>
                    <?php endif; ?>
                    <?php if (empty($user['phone']) && empty($user['email'])): ?>
                        <span class="admin-muted">Не указаны</span>
                    <?php endif; ?>
                    <small>Последний вход: <?php echo escape((string)($user['last_login'] ?: '—')); ?></small>
                </div>
            </td>
            <td class="admin-user-cell admin-user-cell--telegram">
                <span class="admin-user-status <?php echo $user['telegram_user_id'] ? 'is-connected' : 'is-muted'; ?>">
                    <?php echo $user['telegram_user_id'] ? 'Подключён' : 'Не подключён'; ?>
                </span>
                <?php if ($telegramUsername !== ''): ?>
                    <small>@<?php echo escape($telegramUsername); ?></small>
                <?php else: ?>
                    <small>Имя пользователя не указано</small>
                <?php endif; ?>
            </td>
            <td class="admin-user-cell admin-user-cell--access">
                <form method="post" class="admin-user-access-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
                    <label class="admin-user-field" for="user-role-<?php echo $userId; ?>">
                        <span>Роль</span>
                        <select id="user-role-<?php echo $userId; ?>" name="role">
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo escape($role); ?>" <?php echo $role === $user['role'] ? 'selected' : ''; ?>><?php echo escape($roleLabels[$role]); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="admin-user-toggle" for="user-active-<?php echo $userId; ?>">
                        <input id="user-active-<?php echo $userId; ?>" type="checkbox" name="is_active" value="1" <?php echo $user['is_active'] ? 'checked' : ''; ?>>
                        <span>
                            <strong>Активен</strong>
                            <small>Разрешить вход</small>
                        </span>
                    </label>
                    <label class="admin-user-field" for="user-confirmation-<?php echo $userId; ?>">
                        <span>Подтверждение</span>
                        <input id="user-confirmation-<?php echo $userId; ?>"
                               type="text"
                               name="confirmation"
                               placeholder="ИЗМЕНИТЬ"
                               autocomplete="off"
                               autocapitalize="characters"
                               spellcheck="false"
                               required>
                    </label>
                    <button class="admin-button admin-user-save" type="submit">Сохранить</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?>
        <tr>
            <td class="admin-users-empty" colspan="4">Пользователей с такой ролью пока нет.</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
