<?php
require_once __DIR__ . '/config/config.php';
$pageTitle = 'Контакты свадебного агентства «' . SITE_NAME . '» в Москве';
$pageDescription = 'Свяжитесь с Азбукой Декора, чтобы обсудить свадьбу или камерное '
    . 'событие в Москве и Подмосковье, уточнить формат и перейти к расчёту проекта.';
$pageCanonical = appUrl('/contacts');
$pageImage = appUrl('/assets/images/cta/cta-wedding-01.jpg');
$pageImageAlt = 'Свадебное оформление для события от агентства Азбука Декора';
$pageImageWidth = 1000;
$pageImageHeight = 666;
$breadcrumbs = [
    ['name' => 'Главная', 'url' => appUrl('/')],
    ['name' => 'Контакты', 'url' => $pageCanonical],
];
$contactPageNode = PublicSeo::webPageNode(
    $pageCanonical,
    $pageTitle,
    $pageDescription,
    PublicSeo::imageData(
        $pageImage,
        $pageImageAlt,
        $pageImageWidth,
        $pageImageHeight
    ),
    'ContactPage'
);
$contactPageNode['mainEntity'] = ['@id' => appUrl('/#organization')];
$pageSchemas = [
    $contactPageNode,
    PublicStructuredData::breadcrumbNode($breadcrumbs),
];
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
<body>
<?php include_once __DIR__ . '/includes/public-header.php'; ?>
<main>
    <?php include_once __DIR__ . '/includes/breadcrumbs.php'; ?>
    <section class="page-title">
        <div class="container">
            <p class="eyebrow">Контакты</p>
            <h1>Расскажите о событии, а мы предложим следующий шаг</h1>
        </div>
    </section>
    <section class="section">
        <div class="container contact-grid">
            <div class="contact-card">
                <h2>Связь</h2>
                <?php if (PUBLIC_PHONE): ?><p><a href="<?php echo escape(phoneLink(PUBLIC_PHONE)); ?>"><?php echo str_replace(' ', '&nbsp;', escape(PUBLIC_PHONE)); ?></a></p><?php endif; ?>
                <?php if (PUBLIC_EMAIL && filter_var(PUBLIC_EMAIL, FILTER_VALIDATE_EMAIL)): ?><p><a href="mailto:<?php echo escape(PUBLIC_EMAIL); ?>"><?php echo escape(PUBLIC_EMAIL); ?></a></p><?php endif; ?>
                <?php $publicTelegram = preg_replace('/\W/', '', ltrim((string)env('TELEGRAM_BOT_USERNAME', ''), '@')); ?>
                <?php if ($publicTelegram): ?><p><a href="https://t.me/<?php echo escape($publicTelegram); ?>" target="_blank" rel="noopener">Написать в Telegram</a></p><?php endif; ?>
                <p>Москва и Подмосковье</p>
                <?php if (PUBLIC_LEGAL_NAME): ?><p><small><?php echo escape(PUBLIC_LEGAL_NAME); ?><?php echo PUBLIC_INN ? ' · ИНН ' . escape(PUBLIC_INN) : ''; ?></small></p><?php endif; ?>
                <?php if (!PUBLIC_PHONE && !PUBLIC_EMAIL && !$publicTelegram): ?><p><a href="/quiz">Написать через заявку</a></p><?php endif; ?>
            </div>
            <form class="contact-card" action="/quiz" method="get">
                <h2>Быстрый старт</h2>
                <p>Квиз занимает несколько минут и помогает сразу понять дату, стиль, бюджет и объем работ.</p>
                <button class="btn btn-primary btn-block" type="submit">Заполнить квиз</button>
            </form>
        </div>
    </section>
</main>
<?php include_once __DIR__ . '/includes/public-footer.php'; ?>
</body>
</html>
