<?php
require_once __DIR__ . '/config/config.php';
if (isLoggedIn()) {
    redirect(dashboardPathForRole(currentRole()));
}
$role = $_GET['role'] ?? '';
if ($role === 'worker') {
    redirect('/register-worker.php?step=1');
} elseif (
    $role === 'organizer'
    && strtolower((string)APP_ENV) !== 'production'
    && (bool)env('ALLOW_ORGANIZER_REGISTRATION', false)
) {
    redirect('/register-organizer.php?step=1');
}
$pageTitle = 'Регистрация и начало работы — ' . SITE_NAME;
$pageDescription = 'Выберите подходящий способ начать работу с «Азбукой Декора»: оставить заявку на событие или заполнить анкету для сотрудничества с командой.';
$pageCanonical = appUrl('/register');
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include_once __DIR__ . '/includes/registration-theme-head.php'; ?>
    <?php include_once __DIR__ . '/includes/yandex-metrika-head.php'; ?>
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
    <div class="auth-page auth-page-registration">
        <?php include_once __DIR__ . '/includes/registration-visual.php'; ?>
        <main class="registration-panel">
            <div class="registration-panel__inner">
                <nav class="registration-toolbar" aria-label="Навигация регистрации">
                    <a class="registration-brand" href="/">Азбука Декора</a>
                    <button class="theme-toggle" id="themeToggle" type="button" onclick="toggleTheme()" aria-label="Тема оформления">🌙</button>
                </nav>
                <div class="auth-container">
                    <div class="auth-logo">
                        <p class="registration-kicker">Личный кабинет и команда</p>
                        <h1>Регистрация</h1>
                        <p>Выберите, как вы хотите начать работу с «Азбукой Декора».</p>
                    </div>
                    <div class="auth-card">
                        <h2>Продолжить как</h2>
                        <div class="role-selection">
                            <a href="/quiz" class="role-card">
                                <span class="role-icon role-icon--client" aria-hidden="true"></span>
                                <span class="role-card__copy"><strong>Клиент</strong><span>Рассчитать событие и оставить заявку</span></span>
                                <span class="role-card__arrow" aria-hidden="true">→</span>
                            </a>
                            <a href="/register-worker.php?step=1" class="role-card">
                                <span class="role-icon role-icon--worker" aria-hidden="true"></span>
                                <span class="role-card__copy"><strong>Сотрудник</strong><span>Флорист, курьер, монтажник или помощник</span></span>
                                <span class="role-card__arrow" aria-hidden="true">→</span>
                            </a>
                            <?php if (strtolower((string)APP_ENV) !== 'production' && (bool)env('ALLOW_ORGANIZER_REGISTRATION', false)): ?>
                                <a href="/register-organizer.php?step=1" class="role-card">
                                    <span class="role-icon role-icon--organizer" aria-hidden="true"></span>
                                    <span class="role-card__copy"><strong>Организатор</strong><span>Создать рабочий профиль организатора</span></span>
                                    <span class="role-card__arrow" aria-hidden="true">→</span>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="auth-footer">
                            <p>Уже есть аккаунт? <a href="/login">Войти</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="<?php echo asset('assets/js/metrika-goals.min.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/theme-toggle.min.js'); ?>" defer></script>
</body>
</html>
