<?php
require_once __DIR__ . '/config/config.php';
$pageTitle = 'О нас: организация, декор и флористика — ' . SITE_NAME;
$pageDescription = 'Азбука Декора организует свадьбы и события в Москве и Подмосковье: '
    . 'концепция, декор, флористика, координация, монтаж и работа команды.';
$pageCanonical = appUrl('/about');
$pageImage = appUrl('/assets/images/about/about-team-01.jpg');
$pageImageAlt = 'Команда Азбуки Декора за разработкой концепции события';
$pageImageWidth = 729;
$pageImageHeight = 876;
$breadcrumbs = [
    ['name' => 'Главная', 'url' => appUrl('/')],
    ['name' => 'О нас', 'url' => $pageCanonical],
];
$publicTeam = (new PublicTeamProfile())->published(8);
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
        ),
        'AboutPage'
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
            <p class="eyebrow">О нас</p>
            <h1>Организация, декор и флористика для событий</h1>
            <p>Азбука Декора сопровождает свадьбы и камерные события в Москве и Подмосковье: от первой заявки и концепции до координации команды, монтажа и завершения проекта.</p>
        </div>
    </section>
    <section class="section">
        <div class="container why-grid">
            <div class="image-panel">
                <picture>
                    <source type="image/avif"
                            srcset="<?php echo asset('assets/images/about/about-team-01-480.avif'); ?> 480w, <?php echo asset('assets/images/about/about-team-01-640.avif'); ?> 640w, <?php echo asset('assets/images/about/about-team-01-729.avif'); ?> 729w"
                            sizes="(max-width: 760px) calc(100vw - 32px), 50vw">
                    <source type="image/webp"
                            srcset="<?php echo asset('assets/images/about/about-team-01-480.webp'); ?> 480w, <?php echo asset('assets/images/about/about-team-01-640.webp'); ?> 640w, <?php echo asset('assets/images/about/about-team-01-729.webp'); ?> 729w"
                            sizes="(max-width: 760px) calc(100vw - 32px), 50vw">
                    <img src="<?php echo asset('assets/images/about/about-team-01-729.webp'); ?>" alt="Команда Азбуки Декора за разработкой концепции события" width="729" height="876" loading="lazy" decoding="async">
                </picture>
            </div>
            <div class="why-list">
                <p class="eyebrow">Подход</p>
                <h2>Как мы работаем над событием</h2>
                <p>Начинаем с заявки и разговора: уточняем формат, дату, площадку, количество гостей, пожелания и рамки бюджета. После согласования проект получает организатор, который ведёт подготовку до завершения события.</p>
                <p>Клиент согласовывает концепцию и ключевые решения с одним ответственным специалистом. Организатор координирует документы, сроки, команду, доставку, монтаж и тайминг, чтобы внутренние задачи не становились заботой клиента.</p>
                <a href="/quiz" class="btn btn-primary">Рассчитать событие</a>
            </div>
        </div>
    </section>
    <?php if ($publicTeam): ?>
    <section class="section about-team" aria-labelledby="aboutTeamTitle">
        <div class="container section-heading section-heading--editorial">
            <div>
                <p class="eyebrow">Люди проекта</p>
                <h2 id="aboutTeamTitle">Команда, которая держит детали вместе</h2>
            </div>
            <p>Показываем только те сведения, которые участники команды разрешили использовать на публичном сайте.</p>
        </div>
        <div class="container about-team__grid">
            <?php foreach ($publicTeam as $member): ?>
            <article class="about-team__member">
                <div class="about-team__portrait">
                    <?php if (!empty($member['avatar'])): ?>
                        <img src="/uploads/avatars/<?php echo escape(basename((string)$member['avatar'])); ?>"
                             alt="<?php echo escape($member['first_name'] . ' — ' . $member['public_role']); ?>"
                             width="560" height="700" loading="lazy" decoding="async">
                    <?php else: ?>
                        <span aria-hidden="true"><?php echo escape(utf8Upper(utf8Substring($member['first_name'], 0, 1))); ?></span>
                    <?php endif; ?>
                </div>
                <div class="about-team__copy">
                    <p class="eyebrow"><?php echo escape($member['public_role']); ?></p>
                    <h3><?php echo escape($member['first_name']); ?></h3>
                    <?php if (!empty($member['specialization'])): ?><p class="about-team__specialization"><?php echo escape($member['specialization']); ?></p><?php endif; ?>
                    <p><?php echo escape($member['public_description']); ?></p>
                    <?php if ((float)$member['experience_years'] > 0): ?><p class="muted">Опыт: <?php echo escape(number_format((float)$member['experience_years'], 1, ',', ' ')); ?> г.</p><?php endif; ?>
                    <?php if (!empty($member['public_quote'])): ?><blockquote>«<?php echo escape($member['public_quote']); ?>»</blockquote><?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    <section class="section section-soft" aria-labelledby="aboutScopeTitle">
        <div class="container">
            <div class="section-heading section-heading--editorial">
                <div>
                    <p class="eyebrow">Состав работы</p>
                    <h2 id="aboutScopeTitle">Что берёт на себя Азбука Декора</h2>
                </div>
                <p>Состав проекта зависит от задачи. Можно обратиться за полным сопровождением события или выбрать отдельные направления работы.</p>
            </div>
            <div class="package-grid">
                <article class="package-card">
                    <p class="eyebrow">01</p>
                    <h3>Организация</h3>
                    <p class="muted">Собираем исходные данные, выстраиваем этапы подготовки, сроки, документы и общий план события.</p>
                </article>
                <article class="package-card">
                    <p class="eyebrow">02</p>
                    <h3>Декор и флористика</h3>
                    <p class="muted">Разрабатываем визуальную концепцию, палитру, оформление пространства и цветочные решения.</p>
                </article>
                <article class="package-card">
                    <p class="eyebrow">03</p>
                    <h3>Координация</h3>
                    <p class="muted">Сводим тайминг, задачи и участников проекта, контролируем подготовку и работу команды в день события.</p>
                </article>
                <article class="package-card">
                    <p class="eyebrow">04</p>
                    <h3>Монтаж и команда</h3>
                    <p class="muted">Распределяем задачи между флористами, монтажниками, курьерами и помощниками, организуем установку и демонтаж.</p>
                </article>
            </div>
        </div>
    </section>
    <section class="section" aria-labelledby="aboutRegionTitle">
        <div class="container">
            <div class="section-heading section-heading--editorial">
                <div>
                    <p class="eyebrow">География работы</p>
                    <h2 id="aboutRegionTitle">Москва и Подмосковье</h2>
                </div>
                <p>Работаем с городскими и загородными площадками. Логистику, время монтажа и состав команды рассчитываем после уточнения адреса, формата события и требований площадки.</p>
            </div>
            <nav class="service-detail__links" aria-label="Полезные разделы">
                <a href="/services">Посмотреть услуги</a>
                <a href="/portfolio">Изучить портфолио</a>
                <a href="/quiz">Рассчитать событие</a>
            </nav>
        </div>
    </section>
</main>
<?php include_once __DIR__ . '/includes/public-footer.php'; ?>
</body>
</html>
