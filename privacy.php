<?php
require_once __DIR__ . '/config/config.php';
$pageTitle = 'Политика обработки персональных данных — ' . SITE_NAME;
$pageDescription = 'Политика обработки и защиты персональных данных пользователей сайта Азбука Декора, заявок на события и анкет кандидатов.';
$pageCanonical = appUrl('/privacy');
$pageImage = appUrl('/assets/images/hero/home-hero-light-1440.webp');
$pageImageAlt = 'Свадебная церемония с цветочным оформлением';
$pageImageWidth = 1440;
$pageImageHeight = 810;
$operatorName = PUBLIC_LEGAL_NAME !== '' ? PUBLIC_LEGAL_NAME : SITE_NAME;
$breadcrumbs = [
    ['name' => 'Главная', 'url' => appUrl('/')],
    ['name' => 'Политика обработки данных', 'url' => $pageCanonical],
];
$pageSchemas = [
    PublicSeo::webPageNode(
        $pageCanonical,
        $pageTitle,
        $pageDescription,
        PublicSeo::imageData($pageImage, $pageImageAlt, $pageImageWidth, $pageImageHeight)
    ),
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
            <p class="eyebrow">Публичный документ</p>
            <h1>Политика обработки персональных данных</h1>
            <p>Здесь описано, какие данные получает сайт, для чего они используются и как можно обратиться по вопросам их обработки.</p>
        </div>
    </section>
    <section class="section">
        <article class="container content-narrow legal-document">
            <p class="legal-document__updated">Дата последнего обновления: 4 августа 2026 года.</p>
            <h2>1. Общие положения</h2>
            <p>Оператором персональных данных является <?php echo escape($operatorName); ?><?php echo PUBLIC_INN !== '' ? ', ИНН ' . escape(PUBLIC_INN) : ''; ?>. Политика применяется к данным, которые пользователь передаёт через сайт azbukadecor.ru, личный кабинет, формы заявок и анкеты для сотрудничества.</p>
            <h2>2. Какие данные могут обрабатываться</h2>
            <ul>
                <li>имя, телефон, электронная почта и имя пользователя Telegram;</li>
                <li>сведения о событии, пожелания, комментарии и визуальные референсы;</li>
                <li>данные анкеты кандидата и прикреплённые им материалы;</li>
                <li>технические сведения о сессии, действиях в кабинете и безопасности входа;</li>
                <li>данные посещения сайта, если пользователь согласился на Яндекс Метрику.</li>
            </ul>
            <h2>3. Цели обработки</h2>
            <p>Данные используются для ответа на заявку, подготовки предложения, создания и сопровождения заказа, предоставления доступа к кабинету, связи с пользователем, рассмотрения анкеты кандидата, защиты аккаунтов и выполнения обязанностей, установленных законом.</p>
            <h2>4. Основания и сроки</h2>
            <p>Обработка выполняется на основании согласия пользователя и иных предусмотренных законом оснований. Данные хранятся не дольше, чем это необходимо для заявленных целей и обязательных сроков хранения. Пользователь может отозвать согласие через опубликованные контакты.</p>
            <h2>5. Передача и защита данных</h2>
            <p>Доступ к данным получают только специалисты и технические сервисы, которым он необходим для работы сайта и выполнения заказа. Оператор применяет организационные и технические меры защиты. Аналитика Яндекс Метрики включается только после отдельного выбора пользователя.</p>
            <h2>6. Права субъекта персональных данных</h2>
            <p>Пользователь вправе получить сведения об обработке своих персональных данных, потребовать их уточнения, блокирования или удаления, если данные неполные, устаревшие, неточные, получены незаконно либо больше не нужны для заявленной цели.</p>
            <p>Когда обработка основана на согласии, его можно отозвать. Пользователь также вправе обжаловать действия или бездействие оператора в Роскомнадзоре либо в суде. Отзыв согласия не отменяет обработку, которую оператор обязан или вправе продолжать по другому законному основанию.</p>
            <h2>7. Как направить обращение</h2>
            <p>Запросить доступ, уточнение, блокирование, удаление данных или отозвать согласие можно через <a href="/contacts">страницу контактов</a><?php if (PUBLIC_EMAIL !== '' && filter_var(PUBLIC_EMAIL, FILTER_VALIDATE_EMAIL)): ?> или по адресу <a href="mailto:<?php echo escape(PUBLIC_EMAIL); ?>"><?php echo escape(PUBLIC_EMAIL); ?></a><?php endif; ?>. В обращении нужно указать сведения, позволяющие подтвердить личность и связь с заявкой или аккаунтом. Оператор отвечает и выполняет обоснованные требования в сроки, установленные законодательством Российской Федерации.</p>
            <h2>8. Файлы браузера и аналитика</h2>
            <p>Сведения о необходимых технологиях сайта и управлении аналитикой опубликованы на странице <a href="/cookies">«Файлы браузера и аналитика»</a>.</p>
        </article>
    </section>
</main>
<?php include_once __DIR__ . '/includes/public-footer.php'; ?>
</body>
</html>
