<?php
require_once __DIR__ . '/config/config.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');

if (isLoggedIn()) {
    redirect(dashboardPathForRole(currentRole()));
}

$purpose = TelegramAuthRequest::normalizePurpose(
    $_POST['purpose'] ?? $_GET['purpose'] ?? ''
);
$authRequest = new TelegramAuthRequest();

if (isset($_GET['status'])) {
    header('Cache-Control: no-store, private');
    if ($purpose === null) {
        jsonResponse(['complete' => false, 'error' => 'Некорректный запрос.'], 400);
    }
    $result = $authRequest->consumeApproved($purpose);
    if (empty($result['success'])) {
        jsonResponse([
            'complete' => false,
            'terminal' => true,
            'error' => (string)($result['error'] ?? 'Не удалось проверить подтверждение.'),
        ], 409);
    }
    if (empty($result['approved'])) {
        jsonResponse(['complete' => false]);
    }

    $user = $result['user'];
    try {
        if ($purpose === TelegramAuthRequest::LOGIN) {
            (new LoginSecurity())->completeLogin($user);
            $redirectPath = dashboardPathForRole((string)$user['role']);
        } else {
            $token = (new PasswordSetup())->issue((int)$user['id']);
            $redirectPath = '/set-password.php?token=' . rawurlencode($token);
        }
        jsonResponse(['complete' => true, 'redirect' => $redirectPath]);
    } catch (Exception $e) {
        error_log('Telegram auth completion failed: ' . $e->getMessage());
        jsonResponse([
            'complete' => false,
            'terminal' => true,
            'error' => 'Не удалось завершить операцию. Создайте новый запрос.',
        ], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if ($purpose === null) {
        $_SESSION['telegram_auth_flash_error'] = 'Некорректный запрос Telegram.';
        redirect('/login.php');
    }
    $result = $authRequest->start(
        $purpose,
        !empty($_POST['regenerate'])
    );
    if (empty($result['success'])) {
        $_SESSION['telegram_auth_flash_error'] = (string)(
            $result['error'] ?? 'Не удалось создать запрос Telegram.'
        );
    }
    redirect('/telegram-auth.php?purpose=' . rawurlencode($purpose));
}

if ($purpose === null) {
    redirect('/login.php');
}

$flashError = (string)($_SESSION['telegram_auth_flash_error'] ?? '');
unset($_SESSION['telegram_auth_flash_error']);
$linkResult = $authRequest->current($purpose);
$error = $flashError;
if ($error === '' && empty($linkResult['success'])) {
    $error = (string)($linkResult['error'] ?? 'Ссылка Telegram недоступна.');
}
$telegramStartCommand = '';
$telegramUrl = (string)($linkResult['url'] ?? '');
if ($telegramUrl !== '') {
    $telegramQuery = [];
    parse_str((string)parse_url($telegramUrl, PHP_URL_QUERY), $telegramQuery);
    $telegramStartPayload = (string)($telegramQuery['start'] ?? '');
    if (preg_match('/^(?:login|reset)_[0-9a-f]{48}$/i', $telegramStartPayload)) {
        $telegramStartCommand = '/start ' . $telegramStartPayload;
    }
}

$isLogin = $purpose === TelegramAuthRequest::LOGIN;
$pageTitle = $isLogin ? 'Вход через Telegram' : 'Сброс пароля через Telegram';
$pageLead = $isLogin
    ? 'Подтвердите вход в привязанном Telegram — пароль вводить не потребуется.'
    : 'Подтвердите запрос в привязанном Telegram, затем задайте новый пароль на сайте.';
$telegramButton = $isLogin
    ? 'Открыть Telegram и подтвердить вход'
    : 'Открыть Telegram и подтвердить сброс';
$statusText = $isLogin
    ? 'Ожидаем подтверждение входа в боте…'
    : 'Ожидаем подтверждение сброса в боте…';
$successText = $isLogin
    ? 'Вход подтверждён. Открываем кабинет…'
    : 'Запрос подтверждён. Открываем установку пароля…';
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
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="no-referrer">
    <title><?php echo escape($pageTitle); ?> — <?php echo escape(SITE_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/registration.min.css'); ?>">
    <?php include_once __DIR__ . '/includes/favicon-links.php'; ?>
</head>
<body class="registration-body">
<div
    class="auth-page auth-page-registration auth-page-login-themed auth-page-telegram-confirm"
    data-telegram-auth
    data-status-url="/telegram-auth.php?status=1&amp;purpose=<?php echo escape($purpose); ?>"
    data-success-text="<?php echo escape($successText); ?>"
>
    <?php include_once __DIR__ . '/includes/registration-visual.php'; ?>
    <main class="registration-panel">
        <div class="registration-panel__inner">
            <nav class="registration-toolbar" aria-label="Навигация подтверждения">
                <a class="registration-brand" href="/">Азбука Декора</a>
                <button class="theme-toggle" id="themeToggle" type="button" onclick="toggleTheme()" aria-label="Тема оформления">🌙</button>
            </nav>
            <div class="auth-container">
                <div class="auth-logo">
                    <p class="registration-kicker">Безопасный доступ</p>
                    <h1><?php echo escape($pageTitle); ?></h1>
                    <p><?php echo escape($pageLead); ?></p>
                </div>
                <section class="auth-card telegram-confirm-card" aria-labelledby="telegramAuthHeading">
                    <div class="telegram-confirm-card__heading">
                        <span class="telegram-confirm-card__mark" aria-hidden="true"></span>
                        <div>
                            <p class="eyebrow">Подтверждение в боте</p>
                            <h2 id="telegramAuthHeading">Это займёт меньше минуты</h2>
                        </div>
                    </div>
                    <ol class="telegram-confirm-steps">
                        <li><span>1</span><p>Откройте Telegram по кнопке ниже.</p></li>
                        <li><span>2</span><p>Подтвердите действие в привязанном аккаунте.</p></li>
                        <li><span>3</span><p>Вернитесь сюда — продолжение откроется автоматически.</p></li>
                    </ol>
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-error"><?php echo escape($error); ?></div>
                    <?php elseif (!empty($linkResult['url'])): ?>
                        <a
                            class="btn btn-primary btn-block telegram-confirm-primary"
                            href="<?php echo escape($linkResult['url']); ?>"
                            target="_blank"
                            rel="noopener"
                        ><?php echo escape($telegramButton); ?></a>
                        <p class="telegram-confirm-status" id="telegramAuthStatus" aria-live="polite">
                            <span class="telegram-confirm-status__indicator" aria-hidden="true"></span>
                            <span data-telegram-auth-message><?php echo escape($statusText); ?></span>
                        </p>
                        <?php if ($telegramStartCommand !== ''): ?>
                            <div class="telegram-confirm-fallback">
                                <p>Если Telegram открыл обычное меню бота, скопируйте и отправьте команду подтверждения.</p>
                                <button
                                    class="telegram-confirm-copy"
                                    type="button"
                                    data-telegram-auth-command="<?php echo escape($telegramStartCommand); ?>"
                                >
                                    <span data-telegram-auth-copy-label>Скопировать команду</span>
                                </button>
                            </div>
                        <?php endif; ?>
                        <div class="telegram-confirm-actions">
                            <button class="btn btn-outline" id="checkTelegramAuthStatus" type="button">
                                Проверить статус
                            </button>
                            <form method="POST" action="/telegram-auth.php">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="purpose" value="<?php echo escape($purpose); ?>">
                                <input type="hidden" name="regenerate" value="1">
                                <button class="btn btn-outline btn-block" type="submit">Получить новую ссылку</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="/telegram-auth.php">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="purpose" value="<?php echo escape($purpose); ?>">
                            <button class="btn btn-primary btn-block" type="submit">Создать ссылку</button>
                        </form>
                    <?php endif; ?>
                    <div class="telegram-confirm-footer">
                        <a class="quiet-link" href="/privacy" target="_blank" rel="noopener">Обработка данных</a>
                        <a class="quiet-link" href="/login.php">Вернуться ко входу</a>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
<script src="<?php echo asset('assets/js/telegram-auth.min.js'); ?>" defer></script>
<script src="<?php echo asset('assets/js/theme-toggle.min.js'); ?>" defer></script>
</body>
</html>
