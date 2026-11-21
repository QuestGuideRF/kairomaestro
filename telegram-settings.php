<?php
require_once __DIR__ . '/config/config.php';
requireLogin();
$userObj = new User();
$user = $userObj->getUserById((int)$_SESSION['user_id']);
$linkResult = null;
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create_link') {
        $linkResult = (new TelegramLink())->create((int)$_SESSION['user_id']);
        if (!$linkResult['success']) {
            $error = $linkResult['error'];
        }
    } elseif ($action === 'unlink') {
        $password = (string)($_POST['current_password'] ?? '');
        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Текущий пароль указан неверно.';
            AuditLog::record('telegram.unlink_failed', 'user', (int)$_SESSION['user_id'], null, null, ['result' => 'failure']);
        } else {
            (new TelegramLink())->unlink((int)$_SESSION['user_id']);
            $success = 'Telegram отвязан от аккаунта.';
            $user = $userObj->getUserById((int)$_SESSION['user_id']);
        }
    } elseif ($action === 'revoke_sessions') {
        $count = (new LoginSecurity())->revokeOtherSessions((int)$_SESSION['user_id']);
        $success = 'Другие активные сессии завершены: ' . $count . '.';
    } elseif ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmation = (string)($_POST['new_password_confirmation'] ?? '');
        $minimumLength = 10;
        if (!hash_equals($newPassword, $confirmation)) {
            $error = 'Новые пароли не совпадают.';
        } elseif (utf8Length($newPassword) < $minimumLength) {
            $error = 'Новый пароль должен содержать не менее ' . $minimumLength . ' символов.';
        } else {
            $result = $userObj->changePassword((int)$_SESSION['user_id'], $currentPassword, $newPassword, $confirmation);
            $success = $result['success'] ? 'Пароль изменён. Другие активные сессии завершены.' : '';
            $error = $result['success'] ? '' : $result['error'];
            $user = $userObj->getUserById((int)$_SESSION['user_id']);
        }
    }
}
$role = (string)($user['role'] ?? currentRole());
$passwordMinimum = 10;
$header = 'organizer-header.php';
$footer = 'organizer-footer.php';
if ($role === 'admin') {
    $header = 'admin-account-header.php';
} elseif (isWorkerRole($role)) {
    $header = 'worker-header.php';
    $footer = 'worker-footer.php';
} elseif ($role === 'client') {
    $header = 'client-header.php';
} elseif ($role === 'manager') {
    $header = 'manager-header.php';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Безопасность и Telegram - Азбука Декора</title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/includes/favicon-links.php'; ?></head>
<body>
<?php include_once __DIR__ . '/includes/' . $header; ?>
<main class="dashboard">
    <div class="container">
        <div class="dashboard-header">
            <div><p class="eyebrow">Уведомления и защита</p><h1>Безопасность и Telegram</h1><p>Управляйте паролем, активными сессиями и безопасной привязкой уведомлений.</p></div>
        </div>
        <?php if ($error): ?><div class="alert alert-error"><?php echo escape($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo escape($success); ?></div><?php endif; ?>
        <section class="panel-section telegram-settings">
            <?php if (!empty($user['telegram_user_id'])): ?>
                <div class="section-header"><div><h2>Аккаунт привязан</h2><p class="muted"><?php echo escape($user['telegram_username'] ? '@' . ltrim($user['telegram_username'], '@') : 'Telegram ID ' . $user['telegram_user_id']); ?></p></div><span class="badge">Активно</span></div>
                <form method="POST" class="stack-form telegram-unlink-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="unlink">
                    <div class="form-group"><label for="current_password">Текущий пароль для отвязки</label><input type="password" id="current_password" name="current_password" class="form-control" required autocomplete="current-password"></div>
                    <button class="btn btn-outline" type="submit">Отвязать Telegram</button>
                </form>
            <?php else: ?>
                <div class="section-header"><div><h2>Подключить Telegram</h2><p class="muted">Ссылка одноразовая и действует 15 минут.</p></div><span class="badge">Не подключён</span></div>
                <?php if ($linkResult && $linkResult['success']): ?>
                    <a class="btn btn-primary btn-lg" href="<?php echo escape($linkResult['url']); ?>" target="_blank" rel="noopener">Открыть бота и подтвердить</a>
                <?php else: ?>
                    <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="create_link"><button class="btn btn-primary" type="submit">Создать ссылку привязки</button></form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <section class="panel-section">
            <div class="section-header"><div><h2>Смена пароля</h2><p class="muted">После изменения все другие активные сессии будут завершены.</p></div></div>
            <form method="POST" class="stack-form telegram-unlink-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="change_password">
                <div class="form-group"><label for="password_current">Текущий пароль</label><input type="password" id="password_current" name="current_password" class="form-control" required autocomplete="current-password"></div>
                <div class="form-group"><label for="password_new">Новый пароль</label><input type="password" id="password_new" name="new_password" class="form-control" required minlength="<?php echo $passwordMinimum; ?>" maxlength="1024" autocomplete="new-password"><small class="field-hint">Не менее <?php echo $passwordMinimum; ?> символов.</small></div>
                <div class="form-group"><label for="password_confirmation">Повторите новый пароль</label><input type="password" id="password_confirmation" name="new_password_confirmation" class="form-control" required minlength="<?php echo $passwordMinimum; ?>" maxlength="1024" autocomplete="new-password"></div>
                <button class="btn btn-primary" type="submit">Изменить пароль</button>
            </form>
        </section>
        <section class="panel-section">
            <div class="section-header"><div><h2>Активные сессии</h2><p class="muted">Текущий браузер останется авторизованным.</p></div></div>
            <form method="POST" class="stack-form telegram-unlink-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="revoke_sessions">
                <button class="btn btn-outline" type="submit">Завершить другие сессии</button>
            </form>
        </section>
    </div>
</main>
<?php include_once __DIR__ . '/includes/' . $footer; ?>
</body>
</html>
