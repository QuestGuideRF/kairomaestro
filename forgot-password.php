<?php
require_once __DIR__ . '/config/config.php';

$disablePublicAnalytics = true;
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$submitted = false;
$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireCsrf();
    $result = (new PasswordReset())->request((string)($_POST['identifier'] ?? ''));
    $submitted = true;
    $message = (string)$result['message'];
}

$pageTitle = 'Восстановление пароля - ' . SITE_NAME;
$pageDescription = 'Безопасное восстановление доступа к личному кабинету «Азбуки Декора».';
$pageCanonical = appUrl('/forgot-password');
$pageRobots = 'noindex,nofollow,noarchive';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <?php include_once __DIR__ . '/includes/public-meta.php'; ?>
    <title><?php echo escape($pageTitle); ?></title>
    <meta name="description" content="<?php echo escape($pageDescription); ?>">
    <link rel="canonical" href="<?php echo escape($pageCanonical); ?>">
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('theme');
                var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.setAttribute('data-theme', saved === 'dark' || saved === 'light' ? saved : (dark ? 'dark' : 'light'));
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/password-setup.css'); ?>">
</head>
<body class="password-setup-page">
<div class="password-setup">
    <header class="password-setup__header">
        <div class="password-setup__header-inner">
            <a class="password-setup__brand" href="/" aria-label="<?php echo escape(SITE_NAME); ?> - на главную">
                <span><?php echo escape(SITE_NAME); ?></span>
            </a>
            <div class="password-setup__header-actions">
                <a class="password-setup__home-link" href="/login"><span>Ко входу</span></a>
                <button class="password-setup__theme-toggle" id="passwordSetupThemeToggle" type="button" aria-label="Включить тёмную тему">
                    <svg class="password-setup__theme-icon password-setup__theme-icon--moon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                    <svg class="password-setup__theme-icon password-setup__theme-icon--sun" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>
                </button>
            </div>
        </div>
    </header>
    <main class="password-setup__main">
        <section class="password-setup__card" aria-labelledby="passwordResetRequestTitle">
            <div class="password-setup__ornament" aria-hidden="true"><span></span><i></i><span></span></div>
            <p class="password-setup__eyebrow">Безопасный доступ</p>
            <?php if ($submitted): ?>
                <div class="password-setup__state-icon is-success" aria-hidden="true">✓</div>
                <h1 id="passwordResetRequestTitle">Проверьте Telegram</h1>
                <p class="password-setup__lead"><?php echo escape($message); ?></p>
                <p class="password-setup__security-note">Ссылка отправляется только в ранее подтверждённый аккаунт Telegram и действует ограниченное время.</p>
                <a class="btn btn-primary password-setup__primary-action" href="/login">Вернуться ко входу</a>
            <?php else: ?>
                <h1 id="passwordResetRequestTitle">Восстановить пароль</h1>
                <p class="password-setup__lead">Укажите телефон, адрес электронной почты или имя пользователя Telegram, связанные с аккаунтом.</p>
                <form method="POST" class="password-setup__form" autocomplete="off">
                    <?php echo csrfField(); ?>
                    <div class="password-setup__field">
                        <label for="identifier">Данные аккаунта</label>
                        <div class="password-setup__input-wrap">
                            <input class="password-setup__input" type="text" id="identifier" name="identifier" maxlength="190" autocomplete="username" placeholder="+7, адрес почты или @username" required autofocus>
                        </div>
                    </div>
                    <button class="btn btn-primary password-setup__submit" type="submit">Получить ссылку</button>
                </form>
                <p class="password-setup__security-note">Ответ страницы одинаков для существующих и неизвестных аккаунтов. Это защищает данные пользователей.</p>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="<?php echo asset('assets/js/password-setup.js'); ?>" defer></script>
</body>
</html>
