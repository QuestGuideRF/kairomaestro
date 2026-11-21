<?php
require_once __DIR__ . '/config/config.php';
if (isLoggedIn()) {
    redirect(dashboardPathForRole(currentRole()));
}
$error = '';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($step < 1) {
    $step = 1;
}
if ($step > 7) {
    $step = 7;
}
if (!isset($_SESSION['worker_registration'])) {
    $_SESSION['worker_registration'] = ['role' => 'worker'];
}
if (isset($_GET['reset'])) {
    unset($_SESSION['worker_registration']);
    redirect('/register-worker.php?step=1');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $currentStepData = $_POST;
    if ($step === 1) {
        if (empty($currentStepData['first_name'])) {
            $error = 'Пожалуйста, введите имя';
        } elseif (empty($currentStepData['privacy_consent'])) {
            $error = 'Подтвердите согласие на обработку персональных данных';
        } else {
            $_SESSION['worker_registration']['first_name'] = trim($currentStepData['first_name']);
            $_SESSION['worker_registration']['privacy_consent'] = true;
            $_SESSION['worker_registration']['privacy_policy_version'] = PRIVACY_POLICY_VERSION;
            $_SESSION['worker_registration']['privacy_consent_at'] = date(DATE_ATOM);
            redirect('/register-worker.php?step=2');
            exit;
        }
    } elseif ($step === 2) {
        if (empty($_SESSION['worker_registration']['first_name'])) {
            redirect('/register-worker.php?step=1');
            exit;
        }
        if (empty($currentStepData['last_name'])) {
            $error = 'Пожалуйста, введите фамилию';
        } else {
            $_SESSION['worker_registration']['last_name'] = trim($currentStepData['last_name']);
            redirect('/register-worker.php?step=3');
            exit;
        }
    } elseif ($step === 3) {
        if (empty($_SESSION['worker_registration']['first_name']) ||
            empty($_SESSION['worker_registration']['last_name'])) {
            redirect('/register-worker.php?step=1');
            exit;
        }
        $_SESSION['worker_registration']['middle_name'] = trim($currentStepData['middle_name'] ?? '');
        redirect('/register-worker.php?step=4');
        exit;
    } elseif ($step === 4) {
        if (empty($_SESSION['worker_registration']['first_name']) ||
            empty($_SESSION['worker_registration']['last_name'])) {
            redirect('/register-worker.php?step=1');
            exit;
        }
        if (empty($currentStepData['specialty'])) {
            $error = 'Пожалуйста, выберите специальность';
        } else {
            $_SESSION['worker_registration']['specialty'] = $currentStepData['specialty'];
            redirect('/register-worker.php?step=5');
            exit;
        }
    } elseif ($step === 5) {
        if (empty($_SESSION['worker_registration']['specialty'])) {
            redirect('/register-worker.php?step=4');
            exit;
        }
        $_SESSION['worker_registration']['has_car'] = $currentStepData['has_car'] ?? 0;
        $_SESSION['worker_registration']['car_brand'] = trim($currentStepData['car_brand'] ?? '');
        $_SESSION['worker_registration']['car_number'] = trim($currentStepData['car_number'] ?? '');
        redirect('/register-worker.php?step=6');
        exit;
    } elseif ($step === 6) {
        if (empty($_SESSION['worker_registration']['specialty'])) {
            redirect('/register-worker.php?step=4');
            exit;
        }
        $_SESSION['worker_registration']['hourly_rate'] = $currentStepData['hourly_rate'] ?? 0;
        $_SESSION['worker_registration']['additional_info'] = trim($currentStepData['additional_info'] ?? '');
        redirect('/register-worker.php?step=7');
        exit;
    } elseif ($step === 7 && isset($currentStepData['final_submit'])) {
        if (empty($_SESSION['worker_registration']['first_name']) ||
            empty($_SESSION['worker_registration']['last_name']) ||
            empty($_SESSION['worker_registration']['specialty']) ||
            empty($_SESSION['worker_registration']['privacy_consent']) ||
            ($_SESSION['worker_registration']['privacy_policy_version'] ?? '') !== PRIVACY_POLICY_VERSION) {
            redirect('/register-worker.php?step=1');
            exit;
        }
        $_SESSION['worker_registration']['phone'] = trim($currentStepData['phone'] ?? '');
        $_SESSION['worker_registration']['password'] = $currentStepData['password'] ?? '';
        $_SESSION['worker_registration']['password_confirm'] = $currentStepData['password_confirm'] ?? '';
        $data = $_SESSION['worker_registration'];
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
                unset($_SESSION['worker_registration']);
                $_SESSION['telegram_confirmation_next'] = '/worker/dashboard.php';
                redirect('/telegram-confirm.php');
            } else {
                $error = $result['error'];
            }
        }
    }
}
$regData = $_SESSION['worker_registration'] ?? [];
$pageTitle = 'Регистрация сотрудника — ' . SITE_NAME;
$pageDescription = 'Пошаговая регистрация специалистов для сотрудничества с командой «Азбуки Декора»: флористов, декораторов, курьеров, монтажников и помощников.';
$pageCanonical = appUrl('/register-worker.php');
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
                        <p class="registration-kicker">Сотрудничество с командой</p>
                        <h1>Анкета сотрудника</h1>
                        <p>Заполните данные по шагам. После регистрации потребуется подтвердить Telegram.</p>
                    </div>
            <div class="auth-card">
                <h2>Регистрация работника</h2>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo escape($error); ?></div>
                <?php endif; ?>
                <div class="progress-bar">
                    <div class="progress-fill progress-fill--step-<?php echo $step; ?>"></div>
                </div>
                <p class="step-indicator">Шаг <?php echo $step; ?> из 7</p>
                <form method="POST" action="/register-worker.php?step=<?php echo $step; ?>" class="ym-hide-content">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="role" value="worker">
                    <?php if ($step === 1): ?>
                        <div class="form-group">
                            <label for="first_name">Имя *</label>
                            <input type="text" id="first_name" name="first_name" class="form-control"
                                   value="<?php echo escape($regData['first_name'] ?? ''); ?>" required autofocus>
                        </div>
                        <label class="auth-consent">
                            <input type="checkbox" name="privacy_consent" value="1" required
                                   <?php echo !empty($_POST['privacy_consent']) || !empty($regData['privacy_consent']) ? 'checked' : ''; ?>>
                            <span>Я согласен(на) на обработку персональных данных для регистрации и сотрудничества с командой в соответствии с <a href="/privacy" target="_blank" rel="noopener">политикой обработки персональных данных</a>.</span>
                        </label>
                        <button type="submit" class="btn btn-primary btn-block">Далее</button>
                    <?php elseif ($step === 2): ?>
                        <div class="form-group">
                            <label for="last_name">Фамилия *</label>
                            <input type="text" id="last_name" name="last_name" class="form-control"
                                   value="<?php echo escape($regData['last_name'] ?? ''); ?>" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Далее</button>
                        <a href="/register-worker.php?step=1" class="btn btn-secondary btn-block registration-back">Назад к имени</a>
                    <?php elseif ($step === 3): ?>
                        <div class="form-group">
                            <label for="middle_name">Отчество</label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control"
                                   value="<?php echo escape($regData['middle_name'] ?? ''); ?>" autofocus>
                            <small>Необязательное поле, нажмите "Далее" чтобы пропустить</small>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Далее</button>
                        <a href="/register-worker.php?step=2" class="btn btn-secondary btn-block registration-back">Назад к фамилии</a>
                    <?php elseif ($step === 4): ?>
                        <div class="form-group">
                            <label for="specialty">Специальность *</label>
                            <select id="specialty" name="specialty" class="form-control" required>
                                <option value="">Выберите специальность</option>
                                <option value="Флорист" <?php echo ($regData['specialty'] ?? '') === 'Флорист' ? 'selected' : ''; ?>>Флорист</option>
                                <option value="Курьер" <?php echo ($regData['specialty'] ?? '') === 'Курьер' ? 'selected' : ''; ?>>Курьер</option>
                                <option value="Монтажник" <?php echo ($regData['specialty'] ?? '') === 'Монтажник' ? 'selected' : ''; ?>>Монтажник</option>
                                <option value="Помощник" <?php echo ($regData['specialty'] ?? '') === 'Помощник' ? 'selected' : ''; ?>>Помощник</option>
                                <option value="Декоратор" <?php echo ($regData['specialty'] ?? '') === 'Декоратор' ? 'selected' : ''; ?>>Декоратор</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Далее</button>
                        <a href="/register-worker.php?step=3" class="btn btn-secondary btn-block registration-back">Назад к отчеству</a>
                    <?php elseif ($step === 5): ?>
                        <div class="form-group">
                            <span class="form-label" id="registration-car-label">Есть ли автомобиль?</span>
                            <div class="radio-group">
                                <label class="radio-label" for="registration-has-car-yes">
                                    <input id="registration-has-car-yes" type="radio" name="has_car" value="1"
                                           <?php echo ($regData['has_car'] ?? '0') == '1' ? 'checked' : ''; ?>
                                           onchange="toggleCarDetails()">
                                    Да
                                </label>
                                <label class="radio-label" for="registration-has-car-no">
                                    <input id="registration-has-car-no" type="radio" name="has_car" value="0"
                                           <?php echo ($regData['has_car'] ?? '0') == '0' ? 'checked' : ''; ?>
                                           onchange="toggleCarDetails()">
                                    Нет
                                </label>
                            </div>
                        </div>
                        <div class="car-details" id="carDetails"<?php echo ($regData['has_car'] ?? '0') == '1' ? '' : ' hidden'; ?>>
                            <div class="form-group">
                                <label for="car_brand">Марка автомобиля</label>
                                <input type="text" id="car_brand" name="car_brand" class="form-control"
                                       value="<?php echo escape($regData['car_brand'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="car_number">Госномер</label>
                                <input type="text" id="car_number" name="car_number" class="form-control"
                                       value="<?php echo escape($regData['car_number'] ?? ''); ?>">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Далее</button>
                        <a href="/register-worker.php?step=4" class="btn btn-secondary btn-block registration-back">Назад к специальности</a>
                    <?php elseif ($step === 6): ?>
                        <div class="form-group">
                            <label for="hourly_rate">Ставка за час (₽)</label>
                            <input type="number" id="hourly_rate" name="hourly_rate" class="form-control"
                                   value="<?php echo escape($regData['hourly_rate'] ?? ''); ?>" min="0" step="50">
                            <small>Необязательное поле</small>
                        </div>
                        <div class="form-group">
                            <label for="additional_info">Дополнительная информация</label>
                            <textarea id="additional_info" name="additional_info" class="form-control" rows="4"><?php echo escape($regData['additional_info'] ?? ''); ?></textarea>
                            <small>Опыт работы, пожелания и другая информация</small>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Далее</button>
                        <a href="/register-worker.php?step=5" class="btn btn-secondary btn-block registration-back">Назад к автомобилю</a>
                    <?php elseif ($step === 7): ?>
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
                        <a href="/register-worker.php?step=6" class="btn btn-secondary btn-block registration-back">Назад к условиям работы</a>
                    <?php endif; ?>
                </form>
                <div class="auth-footer">
                    <p>Уже есть аккаунт? <a href="/login">Перейти ко входу</a></p>
                </div>
                </div>
                </div>
            </div>
        </main>
    </div>
    <script src="<?php echo asset('assets/js/metrika-goals.min.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/theme-toggle.min.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/phone-mask.js'); ?>" defer></script>
    <script>
        function toggleCarDetails() {
            const hasCar = document.querySelector('input[name="has_car"]:checked');
            const carDetails = document.getElementById('carDetails');
            carDetails.hidden = !hasCar || hasCar.value !== '1';
        }
    </script>
</body>
</html>
