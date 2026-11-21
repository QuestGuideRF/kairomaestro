<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$registration = new TelegramRegistration();
$nextPath = (string)(
    $_SESSION['telegram_confirmation_next']
    ?? dashboardPathForRole(currentRole())
);

if (isset($_GET['status'])) {
    header('Cache-Control: no-store, private');
    $linked = $registration->isLinked($userId);
    $redirectPath = $linked ? $registration->complete($userId) : null;
    jsonResponse([
        'linked' => $linked,
        'redirect' => $redirectPath,
    ]);
}

if ($registration->isLinked($userId)) {
    redirect($registration->complete($userId));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
}
$linkResult = $registration->createLink(
    $userId,
    $nextPath,
    $_SERVER['REQUEST_METHOD'] === 'POST'
);
if (empty($linkResult['success'])) {
    $error = (string)($linkResult['error'] ?? 'Не удалось создать ссылку');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <?php
    $themePictureConfig = [
        'light' => [
            'base' => 'assets/images/auth/login-light',
            'widths' => [480, 768, 1086],
            'fallback_width' => 1086,
            'width' => 1086,
            'height' => 1448,
            'sizes' => '(max-width: 900px) 100vw, 55vw',
        ],
        'dark' => [
            'base' => 'assets/images/auth/login-dark',
            'widths' => [480, 768, 1086],
            'fallback_width' => 1086,
            'width' => 1086,
            'height' => 1448,
            'sizes' => '(max-width: 900px) 100vw, 55vw',
        ],
    ];
    include_once __DIR__ . '/includes/registration-theme-head.php';
    ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Подтверждение Telegram — <?php echo escape(SITE_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/registration.min.css'); ?>">
    <?php include_once __DIR__ . '/includes/favicon-links.php'; ?>
</head>
<body class="registration-body">
<div class="auth-page auth-page-registration auth-page-login-themed auth-page-telegram-confirm">
    <?php include_once __DIR__ . '/includes/registration-visual.php'; ?>
    <main class="registration-panel">
        <div class="registration-panel__inner">
            <nav class="registration-toolbar" aria-label="Навигация подтверждения">
                <a class="registration-brand" href="/">Азбука Декора</a>
                <button class="theme-toggle" id="themeToggle" type="button" onclick="toggleTheme()" aria-label="Тема оформления">🌙</button>
            </nav>
            <div class="auth-container">
                <div class="auth-logo">
                    <p class="registration-kicker">Безопасность аккаунта</p>
                    <h1>Подтвердите Telegram</h1>
                    <p>Свяжем личный кабинет с вашим аккаунтом в мессенджере.</p>
                </div>
                <section class="auth-card telegram-confirm-card" aria-labelledby="telegramConfirmHeading">
                    <div class="telegram-confirm-card__heading">
                        <span class="telegram-confirm-card__mark" aria-hidden="true"></span>
                        <div>
                            <p class="eyebrow">Завершение регистрации</p>
                            <h2 id="telegramConfirmHeading">Один шаг до кабинета</h2>
                        </div>
                    </div>
                    <p class="auth-lead">
                        Подтверждённый аккаунт нужен для персональных уведомлений
                        и приглашения в закрытую группу вашего события.
                    </p>
                    <ol class="telegram-confirm-steps">
                        <li><span>1</span><p>Откройте Telegram по кнопке ниже.</p></li>
                        <li><span>2</span><p>Подтвердите привязку аккаунта в боте.</p></li>
                        <li><span>3</span><p>Вернитесь сюда — кабинет откроется автоматически.</p></li>
                    </ol>
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-error"><?php echo escape($error); ?></div>
                    <?php elseif (!empty($linkResult['url'])): ?>
                        <a
                            class="btn btn-primary btn-block telegram-confirm-primary"
                            href="<?php echo escape($linkResult['url']); ?>"
                            target="_blank"
                            rel="noopener"
                        >Открыть Telegram и подтвердить</a>
                        <p class="telegram-confirm-status" id="telegramConfirmationStatus" aria-live="polite">
                            <span aria-hidden="true"></span>
                            Ожидаем подтверждение в боте…
                        </p>
                    <?php endif; ?>
                    <div class="telegram-confirm-actions">
                        <button class="btn btn-outline" id="checkTelegramStatus" type="button">
                            Проверить статус
                        </button>
                        <form method="POST">
                            <?php echo csrfField(); ?>
                            <button class="btn btn-outline btn-block" type="submit">Получить новую ссылку</button>
                        </form>
                    </div>
                    <div class="telegram-confirm-footer">
                        <a class="quiet-link" href="/privacy" target="_blank" rel="noopener">Обработка данных</a>
                        <a class="quiet-link" href="/logout.php">Выйти из аккаунта</a>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
<script>
(function () {
    const status = document.getElementById('telegramConfirmationStatus');
    const button = document.getElementById('checkTelegramStatus');
    let checking = false;

    async function checkTelegram() {
        if (checking) {
            return;
        }
        checking = true;
        try {
            const response = await fetch('/telegram-confirm.php?status=1', {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                cache: 'no-store'
            });
            const result = await response.json();
            if (result.linked && result.redirect) {
                if (status) {
                    status.textContent = 'Telegram подтверждён. Открываем кабинет…';
                }
                window.location.replace(result.redirect);
                return;
            }
            if (status) {
                status.textContent = 'Подтверждение пока не получено.';
            }
        } catch (error) {
            if (status) {
                status.textContent = 'Не удалось проверить статус. Повторите попытку.';
            }
        } finally {
            checking = false;
        }
    }

    if (button) {
        button.addEventListener('click', checkTelegram);
    }
    window.setInterval(checkTelegram, 2500);
}());
</script>
<script src="<?php echo asset('assets/js/theme-toggle.min.js'); ?>" defer></script>
</body>
</html>
