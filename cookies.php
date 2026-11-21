<?php
require_once __DIR__ . '/config/config.php';
$pageTitle = 'Файлы браузера и аналитика — ' . SITE_NAME;
$pageDescription = 'Какие служебные файлы и локальные настройки использует сайт Азбука Декора, зачем они нужны и как разрешить или отключить Яндекс Метрику.';
$pageCanonical = appUrl('/cookies');
$pageImage = appUrl('/assets/images/hero/home-hero-light-1440.webp');
$pageImageAlt = 'Свадебная церемония с цветочным оформлением';
$pageImageWidth = 1440;
$pageImageHeight = 810;
$sessionLifetimeDays = max(1, (int)ceil(SESSION_LIFETIME / 86400));
$breadcrumbs = [
    ['name' => 'Главная', 'url' => appUrl('/')],
    ['name' => 'Файлы браузера и аналитика', 'url' => $pageCanonical],
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
            <p class="eyebrow">Настройки сайта</p>
            <h1>Файлы браузера и аналитика</h1>
            <p>Необходимые технологии поддерживают работу кабинета и безопасность, а аналитика включается только после вашего согласия.</p>
        </div>
    </section>
    <section class="section">
        <article class="container content-narrow legal-document">
            <p class="legal-document__updated">Дата последнего обновления: 4 августа 2026 года.</p>
            <h2>Необходимые технологии</h2>
            <p>Сайт может использовать защищённый сессионный файл браузера PHPSESSID. Он нужен для авторизации, защиты форм, сохранения их состояния и безопасной работы личного кабинета. Максимальный срок хранения в текущей конфигурации — <?php echo $sessionLifetimeDays; ?> дн.; файл удаляется раньше при завершении сессии или очистке данных браузера. Отключение этого служебного файла может нарушить работу входа и форм.</p>
            <h2>Локальные настройки браузера</h2>
            <p>Во внутреннем хранилище браузера сохраняются выбранная светлая или тёмная тема и решение об аналитике. Эти значения не содержат заявку, пароль или переписку.</p>
            <h2>Яндекс Метрика</h2>
            <p>Метрика помогает понять, какие публичные страницы востребованы и где возникают технические сложности. Её код, сетевые соединения и аналитические файлы браузера не загружаются, пока пользователь не нажмёт «Принять».</p>
            <p>После отказа сайт устанавливает специальный запрет для счётчика и удаляет доступные ему служебные файлы Метрики. Подробнее о технологиях сервиса можно прочитать в <a href="https://yandex.ru/support/metrica/ru/general/cookie-usage" target="_blank" rel="noopener external">официальной документации Яндекс Метрики</a>.</p>
            <h2>Как изменить выбор</h2>
            <p>Нажмите «Настройки файлов браузера» внизу любой публичной страницы и выберите «Принять» или «Отклонить». Также настройки можно удалить вручную в браузере. До нового согласия аналитика останется отключённой.</p>
            <h2>Персональные данные</h2>
            <p>Правила работы с заявками, аккаунтами и анкетами описаны в <a href="/privacy">политике обработки персональных данных</a>.</p>
        </article>
    </section>
</main>
<?php include_once __DIR__ . '/includes/public-footer.php'; ?>
</body>
</html>
