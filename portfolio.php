<?php
require_once __DIR__ . '/config/config.php';
const PORTFOLIO_BASE_PATH = '/portfolio';
$projects = PublicContent::portfolio();
$slug = isset($_GET['slug']) && is_string($_GET['slug'])
    ? trim($_GET['slug'])
    : null;
$current = null;
$currentIndex = null;
foreach ($projects as $index => $project) {
    if ($project['slug'] === $slug) {
        $current = $project;
        $currentIndex = $index;
        break;
    }
}
if ($slug !== null && $slug !== '' && !$current) {
    http_response_code(404);
    require_once __DIR__ . '/404.php';
    exit;
}
$previousProject = null;
$nextProject = null;
if ($currentIndex !== null && count($projects) > 1) {
    $previousProject = $projects[($currentIndex - 1 + count($projects)) % count($projects)];
    $nextProject = $projects[($currentIndex + 1) % count($projects)];
}
$seoMeta = PublicSeo::portfolioMeta($current['slug'] ?? null, $current ?? []);
$pageTitle = $seoMeta['title'];
$pageDescription = $seoMeta['description'];
$portfolioLocation = 'Москва';
if ($current && !empty($current['location'])) {
    $portfolioLocation = $current['location'];
}
$pageKeywords = $current
    ? $current['title'] . ', свадебный декор ' . $portfolioLocation . ', ' . SITE_KEYWORDS
    : 'портфолио свадеб Москва, примеры декора свадьбы, свадебная флористика, ' . SITE_KEYWORDS;
$pageCanonical = appUrl(
    $current ? PORTFOLIO_BASE_PATH . '/' . $current['slug'] : PORTFOLIO_BASE_PATH
);
$pageImage = $current['image'] ?? $projects[0]['image'];
$pageImageAlt = $current['alt'] ?? $projects[0]['alt'];
$pageImageWidth = (int)($current['width'] ?? $projects[0]['width']);
$pageImageHeight = (int)($current['height'] ?? $projects[0]['height']);
$breadcrumbs = [
    ['name' => 'Главная', 'url' => appUrl('/')],
    ['name' => 'Портфолио', 'url' => appUrl(PORTFOLIO_BASE_PATH)],
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
if ($current) {
    $pageSchemas[0]['mainEntity'] = ['@id' => $pageCanonical . '#article'];
    $pageSchemas[] = PublicSeo::articleNode(
        $pageCanonical,
        $current,
        $pageDescription
    );
} else {
    $pageSchemas[0]['mainEntity'] = ['@id' => $pageCanonical . '#items'];
    $pageSchemas[] = PublicStructuredData::itemListNode(
        $pageCanonical,
        'Проекты Азбуки Декора',
        $projects,
        PORTFOLIO_BASE_PATH
    );
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
        <article class="portfolio-project-article">
        <section class="page-hero">
            <picture>
                <?php if ($current['image_webp'] !== $current['image']): ?>
                    <source type="image/webp" srcset="<?php echo escape($current['image_webp']); ?>">
                <?php endif; ?>
                <img src="<?php echo escape($current['image']); ?>" alt="<?php echo escape($current['alt']); ?>" width="<?php echo (int)$current['width']; ?>" height="<?php echo (int)$current['height']; ?>" fetchpriority="high" decoding="async">
            </picture>
            <div class="container">
                <p class="eyebrow"><?php echo !empty($current['is_reference']) ? 'Визуальное направление' : escape($current['style']); ?></p>
                <h1><?php echo escape($current['title']); ?></h1>
                <?php $heroMeta = implode(' · ', array_filter([
                    $current['location'] ?? '',
                    !empty($current['guests']) ? (int)$current['guests'] . ' гостей' : '',
                ])); ?>
                <?php if ($heroMeta !== ''): ?>
                    <p><?php echo escape($heroMeta); ?></p>
                <?php endif; ?>
            </div>
        </section>
        <section class="section portfolio-story">
            <div class="container portfolio-story__intro">
                <div class="portfolio-story__copy">
                    <p class="eyebrow">О направлении</p>
                    <h2><?php echo escape($current['concept_title'] ?? 'О проекте'); ?></h2>
                    <p class="portfolio-story__lead"><?php echo escape($current['text']); ?></p>
                    <?php foreach (($current['intro'] ?? []) as $paragraph): ?>
                        <p><?php echo escape($paragraph); ?></p>
                    <?php endforeach; ?>
                    <?php if (!empty($current['is_reference'])): ?>
                        <p class="portfolio-reference-note">
                            Изображение используется как визуальный референс. На встрече мы адаптируем
                            направление под вашу площадку, сезон, бюджет и формат события.
                        </p>
                    <?php endif; ?>
                </div>
                <?php if (!empty($current['brief'])): ?>
                    <dl class="portfolio-facts">
                        <?php foreach ($current['brief'] as $label => $value): ?>
                            <div>
                                <dt><?php echo escape($label); ?></dt>
                                <dd><?php echo escape($value); ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </section>
        <?php if (!empty($current['gallery'])): ?>
            <section class="section portfolio-gallery" aria-labelledby="portfolio-gallery-title">
                <div class="container">
                    <div class="portfolio-section-heading">
                        <div>
                            <p class="eyebrow">Галерея</p>
                            <h2 id="portfolio-gallery-title">Проект в деталях</h2>
                        </div>
                        <p>Общие планы и близкие ракурсы показывают композицию, фактуры и живую работу флористики в пространстве.</p>
                    </div>
                    <div class="portfolio-gallery__grid">
                        <?php foreach ($current['gallery'] as $galleryItem): ?>
                            <figure class="portfolio-gallery__item">
                                <img
                                    src="<?php echo escape($galleryItem['image']); ?>"
                                    alt="<?php echo escape($galleryItem['alt']); ?>"
                                    width="<?php echo (int)$galleryItem['width']; ?>"
                                    height="<?php echo (int)$galleryItem['height']; ?>"
                                    loading="lazy"
                                    decoding="async"
                                >
                            </figure>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        <?php if (!empty($current['concept_text']) || !empty($current['solution_text'])): ?>
            <section class="section portfolio-concept">
                <div class="container portfolio-concept__grid">
                    <div>
                        <p class="portfolio-section-number" aria-hidden="true">01</p>
                        <p class="eyebrow">Концепция</p>
                        <h2>Идея оформления</h2>
                        <p><?php echo escape($current['concept_text'] ?? ''); ?></p>
                    </div>
                    <div>
                        <p class="portfolio-section-number" aria-hidden="true">02</p>
                        <p class="eyebrow">Пространство</p>
                        <h2>Как собирается образ</h2>
                        <p><?php echo escape($current['solution_text'] ?? ''); ?></p>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        <?php if (!empty($current['highlights'])): ?>
            <section class="section portfolio-highlights">
                <div class="container">
                    <div class="portfolio-section-heading">
                        <div>
                            <p class="eyebrow">В деталях</p>
                            <h2>Ключевые элементы</h2>
                        </div>
                        <p>Каждый элемент поддерживает общую идею и остаётся удобным для гостей, команды и площадки.</p>
                    </div>
                    <ol class="portfolio-detail-list">
                        <?php foreach ($current['highlights'] as $detailIndex => $detail): ?>
                            <li>
                                <span><?php echo str_pad((string)($detailIndex + 1), 2, '0', STR_PAD_LEFT); ?></span>
                                <strong><?php echo escape($detail); ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            </section>
        <?php endif; ?>
        <?php if (!empty($current['palette']) || !empty($current['services'])): ?>
            <section class="section portfolio-specification">
                <div class="container portfolio-specification__grid">
                    <?php if (!empty($current['palette'])): ?>
                        <div>
                            <p class="eyebrow">Палитра</p>
                            <h2>Цвет и фактура</h2>
                            <ul class="portfolio-palette">
                                <?php foreach ($current['palette'] as $color): ?>
                                    <?php
                                    $swatchKey = strtolower(ltrim((string)($color['color'] ?? ''), '#'));
                                    if (!preg_match('/\A[0-9a-f]{6}\z/', $swatchKey)) {
                                        $swatchKey = 'default';
                                    }
                                    ?>
                                    <li>
                                        <span class="portfolio-swatch portfolio-swatch--<?php echo escape($swatchKey); ?>" aria-hidden="true"></span>
                                        <span><?php echo escape($color['name']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($current['services'])): ?>
                        <div>
                            <p class="eyebrow">Состав работ</p>
                            <h2>Что можно включить</h2>
                            <ul class="portfolio-service-list">
                                <?php foreach ($current['services'] as $service): ?>
                                    <li><?php echo escape($service); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
        <?php if (!empty($current['result'])): ?>
            <section class="portfolio-result">
                <div class="container">
                    <p class="eyebrow">Впечатление</p>
                    <p><?php echo escape($current['result']); ?></p>
                </div>
            </section>
        <?php endif; ?>
        <section class="section portfolio-project-footer">
            <div class="container">
                <div class="portfolio-cta">
                    <div>
                        <p class="eyebrow">Ваше событие</p>
                        <h2>Создадим направление специально для вашей истории</h2>
                        <p>Обсудим площадку, формат, приоритеты и подготовим индивидуальный расчёт.</p>
                    </div>
                    <a href="/quiz" class="btn btn-primary btn-lg">Рассчитать событие</a>
                </div>
                <aside aria-label="Связанные материалы">
                    <p class="portfolio-related-service">
                        По этому направлению:
                        <a href="/services/<?php echo escape($seoMeta['service_slug']); ?>">
                            <?php echo escape($seoMeta['service_title']); ?>
                        </a>
                    </p>
                    <?php if ($previousProject && $nextProject): ?>
                        <nav class="portfolio-project-nav" aria-label="Другие проекты">
                            <a href="/portfolio/<?php echo escape($previousProject['slug']); ?>">
                                <span>Предыдущее направление</span>
                                <strong><?php echo escape($previousProject['title']); ?></strong>
                            </a>
                            <a href="/portfolio/<?php echo escape($nextProject['slug']); ?>">
                                <span>Следующее направление</span>
                                <strong><?php echo escape($nextProject['title']); ?></strong>
                            </a>
                        </nav>
                    <?php endif; ?>
                </aside>
            </div>
        </section>
        </article>
    <?php else: ?>
        <section class="page-title">
            <div class="container">
                <p class="eyebrow">Портфолио</p>
                <h1>Свадьбы и события с настроением, деталями и точным монтажом</h1>
            </div>
        </section>
        <section class="section" aria-labelledby="portfolio-list-title">
            <div class="container">
                <div class="section-heading section-heading--editorial">
                    <div>
                        <p class="eyebrow">Избранные работы</p>
                        <h2 id="portfolio-list-title">Избранные проекты</h2>
                    </div>
                    <p>В каждой истории показываем композицию пространства, палитру, флористику и решения, которые формируют настроение события.</p>
                </div>
                <div class="portfolio-grid portfolio-grid-wide">
                    <?php foreach ($projects as $project): ?>
                        <article class="portfolio-card">
                            <a href="/portfolio/<?php echo escape($project['slug']); ?>">
                                <picture>
                                    <?php if ($project['image_webp'] !== $project['image']): ?>
                                        <source type="image/webp" srcset="<?php echo escape($project['image_webp']); ?>">
                                    <?php endif; ?>
                                    <img src="<?php echo escape($project['image']); ?>" alt="<?php echo escape($project['alt']); ?>" width="<?php echo (int)$project['width']; ?>" height="<?php echo (int)$project['height']; ?>" loading="lazy" decoding="async">
                                </picture>
                                <div class="portfolio-meta">
                                    <h3><?php echo escape($project['title']); ?></h3>
                                    <p><?php echo escape(implode(' · ', array_filter([
                                        $project['style'] ?? '',
                                        $project['location'] ?? '',
                                        !empty($project['guests']) ? (int)$project['guests'] . ' гостей' : '',
                                    ]))); ?></p>
                                </div>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <section class="section section-soft" aria-labelledby="portfolio-services-title">
            <div class="container">
                <div class="section-heading section-heading--editorial">
                    <div>
                        <p class="eyebrow">Наш подход</p>
                        <h2 id="portfolio-services-title">Декор, флористика и оформление событий</h2>
                    </div>
                    <p>
                        Каждый проект начинается с характера события и особенностей пространства.
                        Соединяем флористику, декор, сервировку и монтаж так, чтобы оформление
                        выглядело цельно и оставалось удобным для гостей.
                    </p>
                </div>
                <aside class="service-detail__links" aria-label="Услуги по оформлению событий">
                    <a href="/services/dekor-i-floristika">Декор и флористика</a>
                    <a href="/services/svadba-pod-klyuch">Организация свадьбы под ключ</a>
                    <a href="/services/montazh-i-demontazh">Монтаж и демонтаж</a>
                </aside>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php include_once __DIR__ . '/includes/public-footer.php'; ?>
</body>
</html>
