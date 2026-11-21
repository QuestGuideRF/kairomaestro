<?php
require_once __DIR__ . '/config/config.php';
$stages = PublicContent::stages();
$pageTitle = 'Как проходит подготовка свадьбы: этапы — ' . SITE_NAME;
$pageDescription = 'Разбираем, как проходит подготовка свадьбы в Москве и Подмосковье: '
    . 'от первой заявки и концепции до команды, монтажа и координации дня.';
$pageCanonical = appUrl('/stages');
$pageImage = appUrl('/assets/images/hero/hero-wedding-01.jpg');
$pageImageAlt = 'Подготовленная свадебная церемония с цветочным оформлением';
$pageImageWidth = 1000;
$pageImageHeight = 666;
$breadcrumbs = [
    ['name' => 'Главная', 'url' => appUrl('/')],
    ['name' => 'Этапы работы', 'url' => $pageCanonical],
];
$pageSchemas = [
    PublicSeo::webPageNode(
        $pageCanonical,
        $pageTitle,
        $pageDescription,
        PublicSeo::imageData(
            $pageImage,
            $pageImageAlt,
            $pageImageWidth,
            $pageImageHeight
        )
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
            <p class="eyebrow">Этапы работы</p>
            <h1>Как проходит подготовка свадьбы: от заявки до дня события</h1>
            <p>Показываем последовательность работы над проектом, чтобы с самого начала было понятно, какие решения принимаются и что происходит дальше.</p>
        </div>
    </section>
    <section class="section" aria-labelledby="stages-start-title">
        <div class="container">
            <div class="section-heading section-heading--editorial">
                <div>
                    <p class="eyebrow">Первый шаг</p>
                    <h2 id="stages-start-title">С чего начать подготовку к свадьбе</h2>
                </div>
                <p>Для первой заявки не нужны готовая концепция и точная смета. Достаточно определить примерную дату, формат события, количество гостей, ориентир по бюджету и рассказать, что для вас особенно важно.</p>
            </div>
            <nav class="service-detail__links" aria-label="Начало подготовки">
                <a href="/quiz">Заполнить короткий квиз</a>
                <a href="/services/svadba-pod-klyuch">Узнать об организации свадьбы</a>
            </nav>
        </div>
    </section>
    <section class="section section-soft" aria-labelledby="stages-list-title">
        <div class="container">
            <div class="section-heading section-heading--editorial">
                <div>
                    <p class="eyebrow">Последовательность</p>
                    <h2 id="stages-list-title">Как проходит организация свадьбы</h2>
                </div>
                <p>Этапы идут последовательно, но их наполнение зависит от даты, площадки и состава услуг. Организатор связывает решения по концепции, документам, команде и таймингу в единый план.</p>
            </div>
            <div class="package-grid">
                <?php foreach ($stages as $index => $stage): ?>
                    <article class="package-card">
                        <p class="eyebrow">Этап <?php echo str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT); ?></p>
                        <h3><?php echo escape($stage['title']); ?></h3>
                        <p class="muted"><?php echo escape($stage['description']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <section class="section" aria-labelledby="stages-brief-title">
        <div class="container">
            <div class="section-heading section-heading--editorial">
                <div>
                    <p class="eyebrow">Перед знакомством</p>
                    <h2 id="stages-brief-title">Что подготовить к первому разговору</h2>
                </div>
                <p>Можно прийти даже с приблизительными ответами. Они нужны не для окончательного решения, а чтобы быстрее определить подходящий формат подготовки и список следующих шагов.</p>
            </div>
            <ul class="service-detail__list">
                <li><span>01</span><strong>Желаемая дата или удобный период</strong></li>
                <li><span>02</span><strong>Формат свадьбы или другого события</strong></li>
                <li><span>03</span><strong>Примерное количество гостей</strong></li>
                <li><span>04</span><strong>Выбранная площадка или пожелания к ней</strong></li>
                <li><span>05</span><strong>Ориентир по бюджету проекта</strong></li>
                <li><span>06</span><strong>Референсы и детали, которые нравятся или не нравятся</strong></li>
            </ul>
            <nav class="service-detail__links" aria-label="Следующие шаги">
                <a href="/services">Посмотреть все услуги</a>
                <a href="/portfolio">Посмотреть реализованные проекты</a>
                <a href="/quiz">Начать подготовку</a>
            </nav>
        </div>
    </section>
</main>
<?php include_once __DIR__ . '/includes/public-footer.php'; ?>
</body>
</html>
