<?php
require_once __DIR__ . '/config/config.php';
const SERVICES_BASE_PATH = '/services';
$services = PublicContent::services();
$slug = isset($_GET['slug']) && is_string($_GET['slug'])
    ? trim($_GET['slug'])
    : null;
$current = null;
foreach ($services as $service) {
    if ($service['slug'] === $slug) {
        $current = $service;
        break;
    }
}
if ($slug !== null && $slug !== '' && !$current) {
    http_response_code(404);
    require_once __DIR__ . '/404.php';
    exit;
}
$seoMeta = PublicSeo::serviceMeta($current['slug'] ?? null, $current ?? []);
$pageTitle = $seoMeta['title'];
$pageDescription = $seoMeta['description'];
$pageKeywords = $current
    ? $current['title'] . ' Москва, ' . $current['title'] . ' Подмосковье, ' . SITE_KEYWORDS
    : SITE_KEYWORDS;
$pageCanonical = appUrl(
    $current ? SERVICES_BASE_PATH . '/' . $current['slug'] : SERVICES_BASE_PATH
);
$pageImage = $current['image'] ?? $services[0]['image'];
$pageImageAlt = $current['alt'] ?? $services[0]['alt'];
$pageImageWidth = (int)($current['width'] ?? $services[0]['width']);
$pageImageHeight = (int)($current['height'] ?? $services[0]['height']);
$breadcrumbs = [
    ['name' => 'Главная', 'url' => appUrl('/')],
    ['name' => 'Услуги', 'url' => appUrl(SERVICES_BASE_PATH)],
];
if ($current) {
    $breadcrumbs[] = ['name' => $current['title'], 'url' => $pageCanonical];
}
$pageNode = PublicSeo::webPageNode(
    $pageCanonical,
    $pageTitle,
    $pageDescription,
    PublicSeo::imageData(
        $pageImage,
        $pageImageAlt,
        $pageImageWidth,
        $pageImageHeight
    ),
    $current ? 'WebPage' : 'CollectionPage'
);
$pageSchemas = [
    $pageNode,
    PublicStructuredData::breadcrumbNode($breadcrumbs),
];
$serviceContent = null;
$serviceOverview = [];
if ($current) {
    $serviceContent = PublicSeo::serviceContent($current['slug']);
    $pageSchemas[0]['mainEntity'] = ['@id' => $pageCanonical . '#service'];
    $pageSchemas[] = PublicSeo::serviceNode(
        $pageCanonical,
        $current['title'],
        $pageDescription,
        PublicSeo::imageData(
            $pageImage,
            $pageImageAlt,
            $pageImageWidth,
            $pageImageHeight
        )
    );
} else {
    $pageSchemas[0]['mainEntity'] = ['@id' => $pageCanonical . '#items'];
    $pageSchemas[] = PublicStructuredData::itemListNode(
        $pageCanonical,
        'Услуги Азбуки Декора',
        $services,
        SERVICES_BASE_PATH
    );
    $serviceOverview = [
        [
            'title' => 'Декор и флористика',
            'eyebrow' => 'Визуальная концепция',
            'text' => 'Собираем палитру, флористику, текстиль и композицию пространства '
                . 'в цельный образ. Оформление поддерживает архитектуру площадки, '
                . 'сценарий события и выбранную атмосферу.',
            'slug' => 'dekor-i-floristika',
            'link_label' => 'Подробнее о декоре и флористике',
        ],
        [
            'title' => 'Координация',
            'eyebrow' => 'Спокойный день',
            'text' => 'Контролируем тайминг, готовность площадки, подрядчиков и работу '
                . 'команды. Клиенту не приходится самостоятельно решать организационные '
                . 'вопросы во время события.',
            'slug' => 'koordinatsiya-dnya',
            'link_label' => 'Подробнее о координации',
        ],
        [
            'title' => 'Выездные церемонии',
            'eyebrow' => 'Главный момент',
            'text' => 'Продумываем сценарий, маршрут гостей и оформление церемониальной '
                . 'зоны. Флористика и декор становятся частью пространства, а не '
                . 'отдельной декорацией.',
            'slug' => 'vyezdnaya-tseremoniya',
            'link_label' => 'Подробнее о выездной церемонии',
        ],
        [
            'title' => 'Монтаж и демонтаж',
            'eyebrow' => 'Реализация',
            'text' => 'Планируем доставку, сборку и расстановку элементов к согласованному '
                . 'времени. После события команда аккуратно демонтирует оформление '
                . 'и освобождает площадку.',
            'slug' => 'montazh-i-demontazh',
            'link_label' => 'Подробнее о монтаже и демонтаже',
        ],
    ];
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
<body>
<?php include_once __DIR__ . '/includes/public-header.php'; ?>
<main>
    <?php include_once __DIR__ . '/includes/breadcrumbs.php'; ?>
    <?php if ($current): ?>
        <section class="page-hero">
            <picture>
                <source type="image/webp" srcset="<?php echo escape($current['image_webp']); ?>">
                <img src="<?php echo escape($current['image']); ?>" alt="<?php echo escape($current['alt']); ?>" width="<?php echo (int)$current['width']; ?>" height="<?php echo (int)$current['height']; ?>" fetchpriority="high" decoding="async">
            </picture>
            <div class="container">
                <p class="eyebrow">Услуга</p>
                <h1><?php echo escape($current['title']); ?></h1>
                <p><?php echo escape($current['text']); ?></p>
                <a href="/quiz" class="btn btn-primary btn-lg">Рассчитать проект</a>
            </div>
        </section>
        <section class="section service-detail" aria-labelledby="service-detail-title">
            <div class="container">
                <div class="service-detail__intro">
                    <p class="eyebrow">Состав услуги</p>
                    <h2 id="service-detail-title"><?php echo escape($serviceContent['section_title']); ?></h2>
                    <p><?php echo escape($serviceContent['lead']); ?></p>
                </div>
                <ul class="service-detail__list">
                    <?php foreach ($serviceContent['includes'] as $index => $includedItem): ?>
                        <li>
                            <span><?php echo str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                            <strong><?php echo escape($includedItem); ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="service-detail__process">
                    <div>
                        <p class="eyebrow">Процесс</p>
                        <h2><?php echo escape($serviceContent['process_title']); ?></h2>
                        <p><?php echo escape($serviceContent['process']); ?></p>
                    </div>
                    <div>
                        <p class="eyebrow">Результат</p>
                        <h2>Что получает клиент</h2>
                        <p><?php echo escape($serviceContent['result']); ?></p>
                    </div>
                </div>
                <aside class="service-detail__links" aria-label="Связанные материалы">
                    <?php if ($serviceContent['portfolio_slug'] !== ''): ?>
                        <a href="/portfolio/<?php echo escape($serviceContent['portfolio_slug']); ?>">
                            Пример: <?php echo escape($serviceContent['portfolio_title']); ?>
                        </a>
                    <?php else: ?>
                        <a href="/portfolio">Посмотреть проекты</a>
                    <?php endif; ?>
                    <a href="/quiz">Рассчитать проект</a>
                </aside>
            </div>
        </section>
    <?php else: ?>
        <section class="page-title">
            <div class="container">
                <p class="eyebrow">Услуги</p>
                <h1>Свадьбы, декор, флористика и события под ключ</h1>
                <p>Работаем с полным циклом подготовки или отдельными задачами: от первой концепции и выбора формата до координации, монтажа и завершения события.</p>
            </div>
        </section>
        <section class="section" aria-labelledby="wedding-planning-title">
            <div class="container">
                <div class="section-heading section-heading--editorial">
                    <div>
                        <p class="eyebrow">Полный цикл</p>
                        <h2 id="wedding-planning-title">Организация свадеб</h2>
                    </div>
                    <p>
                        Собираем подготовку в единый процесс: от концепции, площадки и команды
                        до тайминга и завершения дня. Можно доверить нам
                        <a class="home-text-link" href="<?php echo SERVICES_BASE_PATH; ?>/svadba-pod-klyuch">свадьбу под ключ</a>
                        или выбрать отдельные этапы для
                        <a class="home-text-link" href="<?php echo SERVICES_BASE_PATH; ?>/kamernoe-meropriyatie">камерного события</a>.
                    </p>
                </div>
                <div class="service-detail__process">
                    <?php foreach ($serviceOverview as $overviewItem): ?>
                        <div>
                            <p class="eyebrow"><?php echo escape($overviewItem['eyebrow']); ?></p>
                            <h2><?php echo escape($overviewItem['title']); ?></h2>
                            <p>
                                <?php echo escape($overviewItem['text']); ?>
                                <a class="home-text-link" href="<?php echo SERVICES_BASE_PATH; ?>/<?php echo escape($overviewItem['slug']); ?>">
                                    <?php echo escape($overviewItem['link_label']); ?>
                                </a>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <section class="section section-soft" aria-labelledby="services-list-title">
            <div class="container">
                <div class="section-heading section-heading--editorial">
                    <div>
                        <p class="eyebrow">Все услуги</p>
                        <h2 id="services-list-title">Выберите подходящий формат работы</h2>
                    </div>
                    <p>Можно передать команде весь цикл подготовки или собрать отдельные услуги под уже выбранную площадку и сценарий.</p>
                </div>
                <div class="services-grid">
                    <?php foreach ($services as $service): ?>
                        <article class="image-card">
                            <picture>
                                <source type="image/webp" srcset="<?php echo escape($service['image_webp']); ?>">
                                <img src="<?php echo escape($service['image']); ?>" alt="<?php echo escape($service['alt']); ?>" width="<?php echo (int)$service['width']; ?>" height="<?php echo (int)$service['height']; ?>" loading="lazy" decoding="async">
                            </picture>
                            <div>
                                <h3><?php echo escape($service['title']); ?></h3>
                                <p><?php echo escape($service['text']); ?></p>
                                <a href="/services/<?php echo escape($service['slug']); ?>">Подробнее: <?php echo escape($service['title']); ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php include_once __DIR__ . '/includes/public-footer.php'; ?>
</body>
</html>
