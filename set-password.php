<?php
require_once __DIR__ . '/config/config.php';
$disablePublicAnalytics = true;
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        $error = 'Сессия устарела. Обновите страницу и попробуйте снова.';
    } else {
        $result = (new PasswordSetup())->complete(
            $token,
            (string)($_POST['password'] ?? ''),
            (string)($_POST['password_confirmation'] ?? '')
        );
        $success = (bool)$result['success'];
        $error = $result['error'] ?? '';
        if ($success) {
            unset($_SESSION['password_setup_url']);
        }
    }
}
$tokenHasValidFormat = preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1;
$linkError = '';
$passwordError = '';
$confirmationError = '';
$generalError = '';
if (!$success) {
    if (!$tokenHasValidFormat
        || in_array($error, [
            'Ссылка для установки пароля недействительна.',
            'Ссылка устарела или уже была использована.',
        ], true)) {
        $linkError = 'Похоже, срок действия ссылки закончился или она уже была использована.';
    } elseif ($error === 'Пароли не совпадают.') {
        $confirmationError = $error;
    } elseif (str_starts_with($error, 'Пароль должен содержать')) {
        $passwordError = $error;
    } else {
        $generalError = $error;
    }
}
if ($success) {
    $pageState = 'success';
} elseif ($linkError !== '') {
    $pageState = 'invalid';
} else {
    $pageState = 'form';
}
$pageTitle = ($success ? 'Пароль создан' : 'Создайте пароль') . ' — ' . SITE_NAME;
$pageDescription = $success
    ? 'Пароль для личного кабинета «Азбуки Декора» успешно создан. Теперь можно безопасно войти в свой аккаунт.'
    : 'Безопасная установка нового пароля для личного кабинета «Азбуки Декора» по персональной одноразовой ссылке с проверкой срока действия.';
$pageCanonical = appUrl('/set-password');
$pageRobots = 'noindex,nofollow,noarchive';
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
    <meta name="referrer" content="no-referrer">
    <?php include_once __DIR__ . '/includes/public-meta.php'; ?>
    <title><?php echo escape($pageTitle); ?></title>
    <meta name="description" content="<?php echo escape($pageDescription); ?>">
    <link rel="canonical" href="<?php echo escape($pageCanonical); ?>">
    <?php include_once __DIR__ . '/includes/open-graph.php'; ?>
    <?php include_once __DIR__ . '/includes/structured-data.php'; ?>
    <script>
        (function () {
            try {
                var savedTheme = localStorage.getItem('theme');
                var prefersDark = window.matchMedia
                    && window.matchMedia('(prefers-color-scheme: dark)').matches;
                var theme;
                if (savedTheme === 'dark' || savedTheme === 'light') {
                    theme = savedTheme;
                } else if (prefersDark) {
                    theme = 'dark';
                } else {
                    theme = 'light';
                }
                document.documentElement.setAttribute('data-theme', theme);
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/password-setup.css'); ?>">
</head>
<body class="password-setup-page" data-page-state="<?php echo escape($pageState); ?>">
<div class="password-setup">
    <header class="password-setup__header">
        <div class="password-setup__header-inner">
            <a class="password-setup__brand" href="/" aria-label="<?php echo escape(SITE_NAME); ?> — на главную">
                <span class="password-setup__brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M12 5a3 3 0 1 1 3 3m-3-3a3 3 0 1 0-3 3m3-3v1M9 8a3 3 0 1 0 3 3M9 8h1m5 0a3 3 0 1 1-3 3m3-3h-1m-2 3v-1"/>
                        <circle cx="12" cy="8" r="2"/>
                        <path d="M12 10v12M12 22c4.2 0 7-1.667 7-5-4.2 0-7 1.667-7 5ZM12 22c-4.2 0-7-1.667-7-5 4.2 0 7 1.667 7 5Z"/>
                    </svg>
                </span>
                <span><?php echo escape(SITE_NAME); ?></span>
            </a>
            <div class="password-setup__header-actions">
                <a class="password-setup__home-link" href="/">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="m15 18-6-6 6-6"/>
                    </svg>
                    <span>На главную</span>
                </a>
                <button
                    class="password-setup__theme-toggle"
                    id="passwordSetupThemeToggle"
                    type="button"
                    aria-label="Включить тёмную тему"
                >
                    <svg class="password-setup__theme-icon password-setup__theme-icon--moon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
                    </svg>
                    <svg class="password-setup__theme-icon password-setup__theme-icon--sun" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"/>
                        <path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/>
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <main class="password-setup__main">
        <section class="password-setup__card" aria-labelledby="passwordSetupTitle">
            <div class="password-setup__ornament" aria-hidden="true">
                <span></span>
                <i></i>
                <span></span>
            </div>

            <?php if ($success): ?>
                <div class="password-setup__state-icon is-success" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="m5 12 4 4L19 6"/>
                    </svg>
                </div>
                <p class="password-setup__eyebrow">Личный кабинет</p>
                <h1 id="passwordSetupTitle">Пароль создан</h1>
                <p class="password-setup__lead">
                    Пароль сохранён. Теперь войдите в личный кабинет.
                </p>
                <a class="btn btn-primary password-setup__primary-action" href="/login">
                    Войти
                </a>
            <?php elseif ($linkError !== ''): ?>
                <div class="password-setup__state-icon is-error" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9"/>
                        <path d="M12 7v6M12 17h.01"/>
                    </svg>
                </div>
                <p class="password-setup__eyebrow">Личный кабинет</p>
                <h1 id="passwordSetupTitle">Ссылка недействительна</h1>
                <p class="password-setup__lead"><?php echo escape($linkError); ?></p>
                <a class="btn btn-primary password-setup__primary-action" href="/">
                    Перейти на главную
                </a>
                <p class="password-setup__secondary-note">
                    Если пароль уже был создан, <a href="/login">войдите в аккаунт</a>.
                </p>
            <?php else: ?>
                <p class="password-setup__eyebrow">Личный кабинет</p>
                <h1 id="passwordSetupTitle">Создайте пароль</h1>
                <p class="password-setup__lead">
                    Придумайте пароль для входа в личный кабинет.
                </p>

                <?php if ($generalError !== ''): ?>
                    <div class="password-setup__alert" role="alert">
                        <?php echo htmlspecialchars($generalError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="password-setup__form" id="passwordSetupForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

                    <div class="password-setup__field">
                        <label for="password">Новый пароль</label>
                        <div class="password-setup__input-wrap">
                            <input
                                class="password-setup__input"
                                type="password"
                                id="password"
                                name="password"
                                minlength="10"
                                maxlength="1024"
                                autocomplete="new-password"
                                placeholder="Введите новый пароль"
                                aria-describedby="passwordHint passwordError"
                                aria-invalid="<?php echo $passwordError !== '' ? 'true' : 'false'; ?>"
                                autofocus
                                required
                            >
                            <button
                                class="password-setup__password-toggle"
                                type="button"
                                data-password-toggle="password"
                                aria-controls="password"
                                aria-label="Показать новый пароль"
                                aria-pressed="false"
                            >
                                <svg class="password-setup__eye password-setup__eye--show" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M2.1 12s3.6-6 9.9-6 9.9 6 9.9 6-3.6 6-9.9 6-9.9-6-9.9-6Z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg class="password-setup__eye password-setup__eye--hide" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="m3 3 18 18M10.6 10.7a2 2 0 0 0 2.7 2.7M9.9 4.2A10.8 10.8 0 0 1 12 4c6.3 0 9.9 8 9.9 8a18 18 0 0 1-2.2 3.2M6.6 6.6C3.6 8.5 2.1 12 2.1 12s3.6 8 9.9 8a9.8 9.8 0 0 0 4.1-.9"/>
                                </svg>
                            </button>
                        </div>
                        <p class="password-setup__hint" id="passwordHint">
                            От 10 до 1024 символов.
                        </p>
                        <p class="password-setup__field-error" id="passwordError" aria-live="polite">
                            <?php echo htmlspecialchars($passwordError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </p>
                    </div>

                    <div class="password-setup__field">
                        <label for="password_confirmation">Повторите пароль</label>
                        <div class="password-setup__input-wrap">
                            <input
                                class="password-setup__input"
                                type="password"
                                id="password_confirmation"
                                name="password_confirmation"
                                minlength="10"
                                maxlength="1024"
                                autocomplete="new-password"
                                placeholder="Введите пароль ещё раз"
                                aria-describedby="passwordMatchHint passwordConfirmationError"
                                aria-invalid="<?php echo $confirmationError !== '' ? 'true' : 'false'; ?>"
                                required
                            >
                            <button
                                class="password-setup__password-toggle"
                                type="button"
                                data-password-toggle="password_confirmation"
                                aria-controls="password_confirmation"
                                aria-label="Показать повтор пароля"
                                aria-pressed="false"
                            >
                                <svg class="password-setup__eye password-setup__eye--show" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M2.1 12s3.6-6 9.9-6 9.9 6 9.9 6-3.6 6-9.9 6-9.9-6-9.9-6Z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg class="password-setup__eye password-setup__eye--hide" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="m3 3 18 18M10.6 10.7a2 2 0 0 0 2.7 2.7M9.9 4.2A10.8 10.8 0 0 1 12 4c6.3 0 9.9 8 9.9 8a18 18 0 0 1-2.2 3.2M6.6 6.6C3.6 8.5 2.1 12 2.1 12s3.6 8 9.9 8a9.8 9.8 0 0 0 4.1-.9"/>
                                </svg>
                            </button>
                        </div>
                        <p class="password-setup__hint" id="passwordMatchHint">
                            Оба значения должны совпадать.
                        </p>
                        <p class="password-setup__field-error" id="passwordConfirmationError" aria-live="polite">
                            <?php echo htmlspecialchars($confirmationError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </p>
                    </div>

                    <div class="password-setup__checks" aria-live="polite">
                        <p data-password-check="length">
                            <span aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none"><path d="m5 12 4 4L19 6"/></svg>
                            </span>
                            Достаточная длина
                        </p>
                        <p data-password-check="match">
                            <span aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none"><path d="m5 12 4 4L19 6"/></svg>
                            </span>
                            Пароли совпадают
                        </p>
                    </div>

                    <button class="btn btn-primary password-setup__submit" type="submit">
                        <span data-submit-label>Создать пароль</span>
                        <span data-loading-label hidden>Сохраняем пароль…</span>
                    </button>

                    <p class="password-setup__security-note">
                        Не передавайте эту персональную ссылку другим людям.
                    </p>
                </form>
            <?php endif; ?>
        </section>
    </main>

</div>
<script src="<?php echo asset('assets/js/password-setup.js'); ?>" defer></script>
</body>
</html>
