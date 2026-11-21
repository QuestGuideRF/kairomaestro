<?php
require_once __DIR__ . '/config/config.php';
if (isLoggedIn()) {
    $currentUser = getCurrentUser();
    if (!$currentUser || !in_array($currentUser['role'], allRoles(), true)) {
        unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name']);
    } else {
        $_SESSION['user_role'] = $currentUser['role'];
        $_SESSION['user_name'] = trim($currentUser['first_name'] . ' ' . $currentUser['last_name']);
        $dashboardPath = dashboardPathForRole($currentUser['role']);
        if ($dashboardPath !== '/login.php') {
            redirect($dashboardPath);
        }
        unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name']);
    }
}
$error = '';
$requiresTwoFactor = !empty($_SESSION['pending_login_challenge_id']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (($_POST['action'] ?? '') === 'verify_telegram_code') {
        $result = (new LoginSecurity())->verifyTelegramChallenge($_POST['code'] ?? '');
        if ($result['success']) {
            redirect(dashboardPathForRole($result['user']['role']));
        }
        $requiresTwoFactor = true;
        $error = $result['error'];
    } else {
        $phone = $_POST['phone'] ?? '';
        $password = $_POST['password'] ?? '';
        if (empty($phone) || empty($password)) {
            $error = 'Заполните все поля';
        } else {
            $userObj = new User();
            $result = $userObj->login($phone, $password);
            if ($result['success'] && empty($result['requires_2fa'])) {
                redirect(dashboardPathForRole($result['user']['role']));
            }
            if (!empty($result['requires_2fa'])) {
                $requiresTwoFactor = true;
            } else {
                $error = $result['error'];
            }
        }
    }
}
$pageTitle = 'Вход в личный кабинет — ' . SITE_NAME;
$pageDescription = 'Вход в личный кабинет «Азбуки Декора» для клиентов, менеджеров, организаторов и участников команды с дополнительной защитой через Telegram.';
$pageCanonical = appUrl('/login');
$pageRobots = 'noindex,follow';
$pageImage = appUrl('/assets/images/hero/home-hero-light-1440.webp');
$pageImageAlt = 'Свадебная церемония с цветочным оформлением';
$pageImageWidth = 1440;
$pageImageHeight = 810;
$pageSchemas = [
    PublicSeo::webPageNode(
        $pageCanonical,
        $pageTitle,
        $pageDescription,
        PublicSeo::imageData($pageImage, $pageImageAlt, $pageImageWidth, $pageImageHeight)
    ),
];
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
    <?php include_once __DIR__ . '/includes/yandex-metrika-head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include_once __DIR__ . '/includes/public-meta.php'; ?>
    <title><?php echo escape($pageTitle); ?></title>
    <meta name="description" content="<?php echo escape($pageDescription); ?>">
    <link rel="canonical" href="<?php echo escape($pageCanonical); ?>">
    <?php include_once __DIR__ . '/includes/open-graph.php'; ?>
    <?php include_once __DIR__ . '/includes/structured-data.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/registration.min.css'); ?>">
</head>
<body class="registration-body">
    <?php include_once __DIR__ . '/includes/yandex-metrika-noscript.php'; ?>
    <div class="auth-page auth-page-registration auth-page-login-themed">
        <?php include_once __DIR__ . '/includes/registration-visual.php'; ?>
        <main class="registration-panel">
            <div class="registration-panel__inner">
                <nav class="registration-toolbar" aria-label="Навигация входа">
                    <a class="registration-brand" href="/">Азбука Декора</a>
                    <button class="theme-toggle" id="themeToggle" type="button" onclick="toggleTheme()" aria-label="Тема оформления">🌙</button>
                </nav>
                <div class="auth-container">
                    <div class="auth-logo">
                        <h1>Азбука Декора</h1>
                        <p>Личный кабинет клиента, менеджера и команды</p>
                    </div>
                    <div class="auth-card">
                        <h2>Вход</h2>
                        <p class="auth-lead">Войдите по телефону, адресу электронной почты или имени пользователя Telegram, указанному в квизе.</p>
                        <?php if ($error): ?>
                            <div class="alert alert-error"><?php echo escape($error); ?></div>
                        <?php endif; ?>
                        <?php if ($requiresTwoFactor): ?>
                            <p class="auth-lead">Мы отправили одноразовый код в привязанный Telegram. Он действует 10 минут.</p>
                            <form method="POST" class="ym-hide-content" autocomplete="off">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="verify_telegram_code">
                                <div class="form-group">
                                    <label for="code">Код подтверждения</label>
                                    <input type="text" id="code" name="code" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
                                </div>
                                <p class="auth-privacy">Обработка данных при входе описана в <a href="/privacy" target="_blank" rel="noopener">политике обработки персональных данных</a>.</p>
                                <button type="submit" class="btn btn-primary btn-block">Подтвердить вход</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" class="ym-hide-content">
                                <?php echo csrfField(); ?>
                                <div class="form-group">
                                    <label for="phone">Телефон, электронная почта или Telegram</label>
                                    <input type="text" id="phone" name="phone" class="form-control no-phone-mask" autocomplete="username"
                                           value="<?php echo escape($_POST['phone'] ?? ''); ?>"
                                           placeholder="+7, адрес почты или @username" required>
                                </div>
                                <div class="form-group">
                                    <label for="password">Пароль</label>
                                    <input type="password" id="password" name="password" class="form-control" autocomplete="current-password"
                                           placeholder="Введите пароль" required>
                                </div>
                                <p class="auth-privacy">Обработка данных при входе описана в <a href="/privacy" target="_blank" rel="noopener">политике обработки персональных данных</a>.</p>
                                <button type="submit" class="btn btn-primary btn-block">Войти</button>
                            </form>
                            <div class="auth-divider" aria-hidden="true"><span>или</span></div>
                            <div class="telegram-login-options">
                                <form method="POST" action="/telegram-auth.php">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="purpose" value="login">
                                    <button type="submit" class="btn btn-outline btn-block">Войти через Telegram</button>
                                </form>
                                <form method="POST" action="/telegram-auth.php">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="purpose" value="password_reset">
                                    <button type="submit" class="quiet-link telegram-reset-link">Быстрый сброс через Telegram</button>
                                </form>
                                <a class="quiet-link telegram-reset-link" href="/forgot-password">Забыли пароль?</a>
                                <p class="telegram-login-note">Доступно для аккаунта с подтверждённым Telegram.</p>
                            </div>
                        <?php endif; ?>
                        <div class="auth-footer">
                            <p>Нет аккаунта? <a href="/quiz">Оставить заявку</a></p>
                            <p><a href="/">На сайт агентства</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="<?php echo asset('assets/js/metrika-goals.min.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/theme-toggle.min.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/phone-mask.js'); ?>" defer></script>
</body>
</html>
