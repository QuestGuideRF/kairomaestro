<?php
require_once __DIR__ . '/config/config.php';

$disablePublicAnalytics = true;
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenFormatValid = preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1;
$reset = $tokenFormatValid ? new PasswordReset() : null;
$success = false;
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireCsrf();
    $result = $reset
        ? $reset->complete(
            $token,
            (string)($_POST['password'] ?? ''),
            (string)($_POST['password_confirmation'] ?? '')
        )
        : ['success' => false, 'error' => 'Ссылка для восстановления недействительна.'];
    $success = (bool)$result['success'];
    $error = (string)($result['error'] ?? '');
}

$validLink = false;
if (!$success && $reset) {
    try {
        $validLink = (bool)$reset->inspect($token);
    } catch (Throwable $exception) {
        error_log('Password reset link inspection failed: ' . $exception->getMessage());
    }
}
$linkError = !$success && !$validLink;
$pageTitle = ($success ? 'Пароль изменён' : 'Новый пароль') . ' - ' . SITE_NAME;
$pageDescription = 'Установка нового пароля для личного кабинета «Азбуки Декора» по одноразовой ссылке.';
$pageCanonical = appUrl('/reset-password');
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
            } catch (themeError) {
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
            <a class="password-setup__brand" href="/" aria-label="<?php echo escape(SITE_NAME); ?> - на главную"><span><?php echo escape(SITE_NAME); ?></span></a>
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
        <section class="password-setup__card" aria-labelledby="passwordResetTitle">
            <div class="password-setup__ornament" aria-hidden="true"><span></span><i></i><span></span></div>
            <?php if ($success): ?>
                <div class="password-setup__state-icon is-success" aria-hidden="true">✓</div>
                <p class="password-setup__eyebrow">Личный кабинет</p>
                <h1 id="passwordResetTitle">Пароль изменён</h1>
                <p class="password-setup__lead">Все ранее открытые сеансы завершены. Войдите с новым паролем.</p>
                <a class="btn btn-primary password-setup__primary-action" href="/login">Войти</a>
            <?php elseif ($linkError): ?>
                <div class="password-setup__state-icon is-error" aria-hidden="true">!</div>
                <p class="password-setup__eyebrow">Личный кабинет</p>
                <h1 id="passwordResetTitle">Ссылка недействительна</h1>
                <p class="password-setup__lead">Срок действия ссылки закончился или она уже была использована.</p>
                <a class="btn btn-primary password-setup__primary-action" href="/forgot-password">Запросить новую ссылку</a>
            <?php else: ?>
                <p class="password-setup__eyebrow">Личный кабинет</p>
                <h1 id="passwordResetTitle">Новый пароль</h1>
                <p class="password-setup__lead">Придумайте новый пароль. После сохранения все активные сеансы будут завершены.</p>
                <?php if ($error !== ''): ?><div class="password-setup__alert" role="alert"><?php echo escape($error); ?></div><?php endif; ?>
                <form method="POST" class="password-setup__form" id="passwordSetupForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="token" value="<?php echo escape($token); ?>">
                    <div class="password-setup__field">
                        <label for="password">Новый пароль</label>
                        <div class="password-setup__input-wrap">
                            <input class="password-setup__input" type="password" id="password" name="password" minlength="10" maxlength="1024" autocomplete="new-password" aria-describedby="passwordHint passwordError" required autofocus>
                            <button class="password-setup__password-toggle" type="button" data-password-toggle="password" aria-controls="password" aria-label="Показать новый пароль" aria-pressed="false">
                                <svg class="password-setup__eye password-setup__eye--show" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.1 12s3.6-6 9.9-6 9.9 6 9.9 6-3.6 6-9.9 6-9.9-6-9.9-6Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="password-setup__eye password-setup__eye--hide" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m3 3 18 18M10.6 10.7a2 2 0 0 0 2.7 2.7M9.9 4.2A10.8 10.8 0 0 1 12 4c6.3 0 9.9 8 9.9 8a18 18 0 0 1-2.2 3.2M6.6 6.6C3.6 8.5 2.1 12 2.1 12s3.6 8 9.9 8a9.8 9.8 0 0 0 4.1-.9"/></svg>
                            </button>
                        </div>
                        <p class="password-setup__hint" id="passwordHint">От 10 до 1024 символов.</p>
                        <p class="password-setup__field-error" id="passwordError" aria-live="polite"></p>
                    </div>
                    <div class="password-setup__field">
                        <label for="password_confirmation">Повторите пароль</label>
                        <div class="password-setup__input-wrap">
                            <input class="password-setup__input" type="password" id="password_confirmation" name="password_confirmation" minlength="10" maxlength="1024" autocomplete="new-password" aria-describedby="passwordMatchHint passwordConfirmationError" required>
                            <button class="password-setup__password-toggle" type="button" data-password-toggle="password_confirmation" aria-controls="password_confirmation" aria-label="Показать повтор пароля" aria-pressed="false">
                                <svg class="password-setup__eye password-setup__eye--show" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.1 12s3.6-6 9.9-6 9.9 6 9.9 6-3.6 6-9.9 6-9.9-6-9.9-6Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="password-setup__eye password-setup__eye--hide" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m3 3 18 18M10.6 10.7a2 2 0 0 0 2.7 2.7M9.9 4.2A10.8 10.8 0 0 1 12 4c6.3 0 9.9 8 9.9 8a18 18 0 0 1-2.2 3.2M6.6 6.6C3.6 8.5 2.1 12 2.1 12s3.6 8 9.9 8a9.8 9.8 0 0 0 4.1-.9"/></svg>
                            </button>
                        </div>
                        <p class="password-setup__hint" id="passwordMatchHint">Оба значения должны совпадать.</p>
                        <p class="password-setup__field-error" id="passwordConfirmationError" aria-live="polite"></p>
                    </div>
                    <div class="password-setup__checks" aria-live="polite">
                        <p data-password-check="length"><span aria-hidden="true">✓</span> Не менее 10 символов</p>
                        <p data-password-check="match"><span aria-hidden="true">✓</span> Пароли совпадают</p>
                    </div>
                    <button class="btn btn-primary password-setup__submit" type="submit"><span data-submit-label>Сохранить пароль</span><span data-loading-label hidden>Сохраняем...</span></button>
                </form>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="<?php echo asset('assets/js/password-setup.js'); ?>" defer></script>
</body>
</html>
