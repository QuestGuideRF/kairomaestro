<?php
require_once __DIR__ . '/config/config.php';
if (isLoggedIn() && !isClientRole()) {
    redirect(dashboardPathForRole(currentRole()));
}
$error = '';
if (empty($_SESSION['short_lead_submission_key'])) {
    $_SESSION['short_lead_submission_key'] = bin2hex(random_bytes(32));
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        $error = 'Сессия устарела. Обновите страницу и повторите отправку.';
    } else {
        $_POST['submission_key'] = $_SESSION['short_lead_submission_key'];
        $result = (new ShortLeadService())->submit($_POST);
        if (!empty($result['success'])) {
            $_SESSION['last_lead_id'] = (int)$result['lead_id'];
            $_SESSION['last_chat_id'] = (int)($result['chat_id'] ?? 0);
            $isNewClient = empty($result['client_existing']) && empty($result['duplicate']);
            $user = (new User())->getUserById((int)$result['client_id']);
            if ($isNewClient && $user) {
                (new LoginSecurity())->completeLogin($user);
                $setupToken = (new PasswordSetup())->issue((int)$user['id']);
                $_SESSION['password_setup_url'] = '/set-password?token=' . rawurlencode($setupToken);
            }
            unset($_SESSION['short_lead_submission_key']);
            $next = '/account/lead.php?id=' . (int)$result['lead_id'];
            if ($isNewClient && empty($user['telegram_user_id'])) {
                $_SESSION['telegram_confirmation_next'] = $next;
                redirect('/telegram-confirm.php');
            }
            redirect($next);
        }
        $error = (string)($result['error'] ?? 'Не удалось отправить заявку.');
    }
}
$pageRobots = 'noindex, follow';
$pageTitle = 'Короткая заявка — ' . SITE_NAME;
$pageDescription = 'Оставьте имя и удобный контакт. Команда уточнит детали события в разговоре.';
$pageCanonical = appUrl('/short-request');
$pageImage = appUrl('/assets/images/hero/home-hero-light-1440.webp');
$pageImageAlt = 'Свадебная церемония с цветочным оформлением';
$pageImageWidth = 1440;
$pageImageHeight = 810;
$telegramBot = preg_replace('/[^A-Za-z0-9_]/', '', ltrim((string)env('TELEGRAM_BOT_USERNAME', ''), '@'));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <?php include_once __DIR__ . '/includes/yandex-metrika-head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include_once __DIR__ . '/includes/public-meta.php'; ?>
    <title><?php echo escape($pageTitle); ?></title>
    <meta name="description" content="<?php echo escape($pageDescription); ?>">
    <link rel="canonical" href="<?php echo escape($pageCanonical); ?>">
    <?php include_once __DIR__ . '/includes/open-graph.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
</head>
<body>
<?php include_once __DIR__ . '/includes/public-header.php'; ?>
<main class="short-request-page">
    <section class="page-title short-request-hero">
        <div class="container short-request-hero__layout">
            <div>
                <p class="eyebrow">Первый разговор</p>
                <h1>Расскажите о событии в нескольких строках</h1>
                <p>Необязательно заполнять подробный бриф прямо сейчас. Оставьте удобный контакт, а формат, сроки и состав работ мы уточним вместе.</p>
                <div class="short-request-links">
                    <a class="btn btn-outline" href="/quiz">Заполнить подробный бриф</a>
                    <?php if ($telegramBot): ?><a class="btn btn-outline" href="https://t.me/<?php echo escape($telegramBot); ?>" target="_blank" rel="noopener">Написать в Telegram</a><?php endif; ?>
                    <?php if (PUBLIC_PHONE): ?><a class="btn btn-outline" href="tel:<?php echo escape(preg_replace('/[^\d+]/', '', PUBLIC_PHONE)); ?>">Позвонить</a><?php endif; ?>
                </div>
            </div>
            <form method="POST" action="/short-request" class="short-request-form">
                <?php echo csrfField(); ?>
                <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo escape($error); ?></div><?php endif; ?>
                <div class="form-group">
                    <label for="short-client-name">Ваше имя</label>
                    <input id="short-client-name" name="client_name" class="form-control" maxlength="190" autocomplete="name" value="<?php echo escape($_POST['client_name'] ?? ''); ?>" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="short-phone">Телефон</label>
                        <input id="short-phone" type="tel" name="phone" class="form-control" maxlength="40" autocomplete="tel" value="<?php echo escape($_POST['phone'] ?? ''); ?>" placeholder="+7 (___) ___-__-__">
                    </div>
                    <div class="form-group">
                        <label for="short-telegram">Telegram</label>
                        <input id="short-telegram" name="telegram_username" class="form-control" maxlength="120" value="<?php echo escape($_POST['telegram_username'] ?? ''); ?>" placeholder="@username">
                    </div>
                </div>
                <p class="form-hint">Достаточно телефона или имени пользователя в Telegram.</p>
                <div class="form-group">
                    <label for="short-event-type">Тип мероприятия <span class="muted">необязательно</span></label>
                    <input id="short-event-type" name="event_type" class="form-control" maxlength="120" value="<?php echo escape($_POST['event_type'] ?? ''); ?>" placeholder="Например, свадьба или семейный ужин">
                </div>
                <div class="form-group">
                    <label for="short-comment">Что уже известно</label>
                    <textarea id="short-comment" name="comment" class="form-control" rows="5" maxlength="10000" placeholder="Дата, площадка, число гостей или задача — можно написать только главное."><?php echo escape($_POST['comment'] ?? ''); ?></textarea>
                </div>
                <label class="checkbox-row" for="short-privacy">
                    <input id="short-privacy" type="checkbox" name="privacy_consent" value="1" required<?php echo !empty($_POST['privacy_consent']) ? ' checked' : ''; ?>>
                    <span>Согласен(на) на обработку данных для связи по заявке согласно <a href="/privacy" target="_blank" rel="noopener">политике</a>.</span>
                </label>
                <button class="btn btn-primary btn-lg" type="submit">Отправить короткую заявку</button>
            </form>
        </div>
    </section>
</main>
<?php include_once __DIR__ . '/includes/public-footer.php'; ?>
<script src="<?php echo asset('assets/js/phone-mask.js'); ?>"></script>
</body>
</html>
