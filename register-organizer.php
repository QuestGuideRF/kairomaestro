<?php
require_once __DIR__ . '/config/config.php';
if (strtolower((string)APP_ENV) === 'production' || !(bool)env('ALLOW_ORGANIZER_REGISTRATION', false)) {
    http_response_code(404);
    require_once __DIR__ . '/404.php';
    exit;
}
if (isLoggedIn()) {
    redirect(dashboardPathForRole(currentRole()));
}
$error = '';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($step < 1) {
    $step = 1;
}
if ($step > 4) {
    $step = 4;
}
if (!isset($_SESSION['org_registration'])) {
    $_SESSION['org_registration'] = ['role' => 'organizer'];
}
if (isset($_GET['reset'])) {
    unset($_SESSION['org_registration']);
    redirect('/register-organizer.php?step=1');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $currentStepData = $_POST;
    if ($step === 1) {
        if (empty($currentStepData['first_name'])) {
            $error = 'Пожалуйста, введите имя';
        } else {
            $_SESSION['org_registration']['first_name'] = trim($currentStepData['first_name']);
            redirect('/register-organizer.php?step=2');
            exit;
        }
    } elseif ($step === 2) {
        if (empty($_SESSION['org_registration']['first_name'])) {
            redirect('/register-organizer.php?step=1');
            exit;
        }
        if (empty($currentStepData['last_name'])) {
            $error = 'Пожалуйста, введите фамилию';
        } else {
            $_SESSION['org_registration']['last_name'] = trim($currentStepData['last_name']);
            redirect('/register-organizer.php?step=3');
            exit;
        }
    } elseif ($step === 3) {
        if (empty($_SESSION['org_registration']['first_name']) ||
            empty($_SESSION['org_registration']['last_name'])) {
            redirect('/register-organizer.php?step=1');
            exit;
        }
        $_SESSION['org_registration']['middle_name'] = trim($currentStepData['middle_name'] ?? '');
        redirect('/register-organizer.php?step=4');
        exit;
    } elseif ($step === 4 && isset($currentStepData['final_submit'])) {
        if (empty($_SESSION['org_registration']['first_name']) ||
            empty($_SESSION['org_registration']['last_name'])) {
            redirect('/register-organizer.php?step=1');
            exit;
        }
        $_SESSION['org_registration']['phone'] = trim($currentStepData['phone'] ?? '');
        $_SESSION['org_registration']['password'] = $currentStepData['password'] ?? '';
        $_SESSION['org_registration']['password_confirm'] = $currentStepData['password_confirm'] ?? '';
        $data = $_SESSION['org_registration'];
        $data['require_telegram_confirmation'] = true;
        if (empty($data['phone'])) {
            $error = 'Введите номер телефона';
        } elseif (empty($data['password'])) {
            $error = 'Введите пароль';
        } elseif (utf8Length($data['password']) < 8) {
            $error = 'Пароль должен содержать минимум 8 символов';
        } elseif ($data['password'] !== $data['password_confirm']) {
            $error = 'Пароли не совпадают';
        } else {
            $userObj = new User();
            $result = $userObj->register($data);
            if ($result['success']) {
                $userObj->login($data['phone'], $data['password']);
                unset($_SESSION['org_registration']);
                $_SESSION['telegram_confirmation_next'] = '/organizer/dashboard.php';
                redirect('/telegram-confirm.php');
            } else {
                $error = $result['error'];
            }
        }
    }
}
$regData = $_SESSION['org_registration'] ?? [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include_once __DIR__ . '/includes/registration-theme-head.php'; ?>
    <?php include_once __DIR__ . '/includes/yandex-metrika-head.php'; ?>
    <?php include_once __DIR__ . '/includes/public-meta.php'; ?>
    <title>Регистрация организатора - Азбука Декора</title>
    <meta name="robots" content="noindex,follow">
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
                        <p class="registration-kicker">Рабочее пространство</p>
                        <h1>Анкета организатора</h1>
                        <p>Создайте профиль для работы с заказами и командой.</p>
                    </div>
            <div class="auth-card">
                <h2>Регистрация организатора</h2>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo escape($error); ?></div>
                <?php endif; ?>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo ($step / 4) * 100; ?>%"></div>
                </div>
                <p class="step-indicator">Шаг <?php echo $step; ?> из 4</p>
                <form method="POST" action="/register-organizer.php?step=<?php echo $step; ?>" class="ym-hide-content">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="role" value="organizer">
                    <?php if ($step === 1): ?>
                        <div class="form-group">
                            <label for="first_name">Имя *</label>
                            <input type="text" id="first_name" name="first_name" class="form-control"
                                   value="<?php echo escape($regData['first_name'] ?? ''); ?>" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Далее</button>
                    <?php elseif ($step === 2): ?>
                        <div class="form-group">
                            <label for="last_name">Фамилия *</label>
                            <input type="text" id="last_name" name="last_name" class="form-control"
                                   value="<?php echo escape($regData['last_name'] ?? ''); ?>" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Далее</button>
                        <a href="/register-organizer.php?step=1" class="btn btn-secondary btn-block" style="margin-top: 1rem;">Назад к имени</a>
                    <?php elseif ($step === 3): ?>
                        <div class="form-group">
                            <label for="middle_name">Отчество</label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control"
                                   value="<?php echo escape($regData['middle_name'] ?? ''); ?>" autofocus>
                            <small>Необязательное поле, нажмите "Далее" чтобы пропустить</small>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Далее</button>
                        <a href="/register-organizer.php?step=2" class="btn btn-secondary btn-block" style="margin-top: 1rem;">Назад к фамилии</a>
                    <?php elseif ($step === 4): ?>
                        <div class="form-group">
                            <label for="phone">Номер телефона *</label>
                            <input type="tel" id="phone" name="phone" class="form-control"
                                   placeholder="+7 (___) ___-__-__"
                                   value="<?php echo escape($regData['phone'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Пароль *</label>
                            <input type="password" id="password" name="password" class="form-control"
                                   placeholder="Минимум 8 символов" minlength="8" required>
                        </div>
                        <div class="form-group">
                            <label for="password_confirm">Подтвердите пароль *</label>
                            <input type="password" id="password_confirm" name="password_confirm" class="form-control"
                                   placeholder="Повторите пароль" minlength="8" required>
                        </div>
                        <button type="submit" name="final_submit" class="btn btn-primary btn-block">Завершить регистрацию</button>
                        <a href="/register-organizer.php?step=3" class="btn btn-secondary btn-block" style="margin-top: 1rem;">Назад к отчеству</a>
                    <?php endif; ?>
                </form>
                <div class="auth-footer">
                    <p>Уже есть аккаунт? <a href="/login.php">Перейти ко входу</a></p>
                    <p><a href="/register-worker.php">Зарегистрироваться как сотрудник</a></p>
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
