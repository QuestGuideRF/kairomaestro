<?php
require_once __DIR__ . '/config/config.php';
$leadId = (int)($_GET['lead'] ?? 0);
$canShow = $leadId && ($_SESSION['last_lead_id'] ?? 0) == $leadId;
$chatId = (int)($_SESSION['last_chat_id'] ?? 0);
$passwordSetupUrl = (string)($_SESSION['password_setup_url'] ?? '');
$telegramBotUsername = preg_replace('/\W/', '', ltrim((string)env('TELEGRAM_BOT_USERNAME', ''), '@'));
$trackQuizSuccess = $canShow && (int)($_SESSION['metrika_quiz_success_lead_id'] ?? 0) === $leadId;
$pageRobots = 'noindex, nofollow';
$pageTitle = 'Бриф получен — ' . SITE_NAME;
$pageDescription = 'Команда «Азбуки Декора» получила бриф события и референсы. Менеджер изучит ответы и свяжется удобным способом.';
$pageCanonical = appUrl('/thanks');
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
if ($trackQuizSuccess) {
    unset($_SESSION['metrika_quiz_success_lead_id']);
}
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
    <?php include_once __DIR__ . '/includes/structured-data.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
</head>
<body<?php echo $trackQuizSuccess ? ' data-metrika-quiz-success="1"' : ''; ?>>
<?php include_once __DIR__ . '/includes/public-header.php'; ?>
<main>
    <section class="page-title">
        <div class="container">
            <p class="eyebrow">Бриф получен</p>
            <h1>Спасибо, мы получили ваши ответы и референсы</h1>
            <p>Менеджер изучит формат, визуальные предпочтения и приоритеты, затем свяжется выбранным способом. Личный кабинет и заявка уже созданы.</p>
            <div class="hero-actions">
                <?php if (isLoggedIn() && isClientRole()): ?>
                    <a href="/account/lead.php?id=<?php echo $leadId; ?>" class="btn btn-primary btn-lg">Открыть мою заявку</a>
                    <a href="<?php echo $chatId ? '/chat.php?id=' . $chatId : '/chat.php'; ?>" class="btn btn-outline btn-lg">Открыть чат с менеджером</a>
                    <?php if ($passwordSetupUrl): ?><a href="<?php echo escape($passwordSetupUrl); ?>" class="btn btn-outline btn-lg">Задать пароль</a><?php endif; ?>
                <?php else: ?>
                    <a href="/login" class="btn btn-primary btn-lg">Войти в кабинет</a>
                    <a href="/contacts" class="btn btn-outline btn-lg">Контакты</a>
                <?php endif; ?>
                <?php if ($telegramBotUsername): ?><a href="https://t.me/<?php echo escape($telegramBotUsername); ?>" class="btn btn-outline btn-lg" target="_blank" rel="noopener">Продолжить в Telegram</a><?php endif; ?>
            </div>
        </div>
    </section>
</main>
<?php include_once __DIR__ . '/includes/public-footer.php'; ?>
</body>
</html>
