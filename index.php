<?php
require_once __DIR__ . '/config/config.php';
$alphabet = PublicContent::alphabet();
$publishedReviews = dbTableExists('reviews') ? (new OrderReview())->published(6) : [];
$publicStylesheet = asset('assets/css/home.min.css');
$pageTitle = SITE_NAME . ' — организация свадеб, декор и флористика в Москве';
$pageDescription = SITE_DESCRIPTION;
$pageKeywords = SITE_KEYWORDS . ', оформление мероприятий, монтаж свадебного декора, камерные свадьбы Москва';
$pageCanonical = appUrl('/');
$pageImage = appUrl('/assets/images/hero/home-hero-light-1440.webp');
$pageImageAlt = 'Свадебная церемония у озера с цветочной аркой';
$pageImageWidth = 1440;
$pageImageHeight = 810;
$faqItems = [
    [
        'question' => 'С чего начинается работа над событием?',
        'answer' => 'Заполните короткий квиз: укажите формат, дату, количество '
            . 'гостей, ориентир бюджета и нужные услуги. После отправки команда '
            . 'получит заявку и свяжется с вами для уточнения деталей.',
    ],
    [
        'question' => 'Какие задачи можно передать команде?',
        'answer' => 'Можно выбрать полное сопровождение или отдельные направления: '
            . 'концепцию, флористику, декор пространства, координацию дня, '
            . 'выездную церемонию, монтаж и демонтаж.',
    ],
    [
        'question' => 'Можно ли приложить фотографии и референсы?',
        'answer' => 'Да. В квизе можно загрузить изображения или PDF с примерами, '
            . 'которые помогут точнее понять желаемую атмосферу, палитру и детали.',
    ],
    [
        'question' => 'Где вы работаете?',
        'answer' => 'Основной регион работы — Москва и Подмосковье. Возможность '
            . 'работы с конкретной площадкой уточняется после получения заявки.',
    ],
    [
        'question' => 'Выездная церемония заменяет регистрацию в ЗАГСе?',
        'answer' => 'Команда может подготовить сценарий, оформление и координацию '
            . 'выездной церемонии. Государственная регистрация брака оформляется '
            . 'отдельно через уполномоченный орган.',
        'source_url' => 'https://www.gosuslugi.ru/600362/1/form',
        'source_label' => 'Официальная информация на Госуслугах',
    ],
];
$pageSchemas = [
    PublicSeo::organizationNode($pageDescription),
    PublicSeo::websiteNode(),
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
    PublicStructuredData::faqNode($pageCanonical, $faqItems),
];
$heroCards = [
    [
        'href' => '/services',
        'icon' => 'flower-2',
        'title' => 'Услуги',
        'text' => 'Декор, флористика и оформление событий.',
    ],
    [
        'href' => '/portfolio',
        'icon' => 'images',
        'title' => 'Портфолио',
        'text' => 'Наши проекты и реальные события.',
    ],
    [
        'href' => '/quiz',
        'icon' => 'calculator',
        'title' => 'Рассчитать событие',
        'text' => 'Рассчитать формат и стоимость мероприятия.',
    ],
    [
        'href' => '/contacts',
        'icon' => 'mail',
        'title' => 'Связь',
        'text' => 'Связаться с командой удобным способом.',
    ],
];
$responsibilities = [
    'Концепция события',
    'Флористика',
    'Декор пространства',
    'Подбор команды',
    'Тайминг',
    'Координация дня',
];
$buildSrcset = static function (array $variants): string {
    return implode(', ', array_map(
        static fn(array $variant): string => asset($variant[0]) . ' ' . $variant[1] . 'w',
        $variants
    ));
};
$themeImage = static function (string $base, array $widths) use ($buildSrcset): array {
    $avifVariants = [];
    $webpVariants = [];
    foreach ($widths as $width) {
        $avifVariants[] = [$base . '-' . $width . '.avif', $width];
        $webpVariants[] = [$base . '-' . $width . '.webp', $width];
    }
    $fallbackWidth = max($widths);
    return [
        'avif' => $buildSrcset($avifVariants),
        'webp' => $buildSrcset($webpVariants),
        'preload' => asset($base . '-' . $fallbackWidth . '.avif'),
        'fallback' => asset($base . '-' . $fallbackWidth . '.webp'),
    ];
};
$heroImage = $themeImage(
    'assets/images/hero/home-hero-light',
    [480, 768, 960, 1440, 1672]
);
$homeImages = [
    'process' => [
        'light' => $themeImage('assets/images/home/home-process-light', [480, 512, 640, 768, 1000]),
        'dark' => $themeImage('assets/images/home/home-process-dark', [480, 512, 640, 768, 1000]),
    ],
    'cta' => [
        'light' => $themeImage('assets/images/cta/home-cta-light', [480, 768, 960, 1440, 1672]),
        'dark' => $themeImage('assets/images/cta/home-cta-dark', [480, 768, 960, 1440, 1672]),
    ],
];
$processImageSizes = '(max-width: 680px) calc(100vw - 24px), (max-width: 980px) min(680px, calc(100vw - 32px)), (max-width: 1212px) calc(41vw - 13px), 484px';
$transparentPixel = 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preload"
          as="image"
          href="<?php echo escape($heroImage['preload']); ?>"
          type="image/avif"
          imagesrcset="<?php echo escape($heroImage['avif']); ?>"
          imagesizes="100vw"
          fetchpriority="high">
    <script>
        (function () {
            var theme = 'light';
            try {
                var saved = localStorage.getItem('theme');
                var preferred = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                    ? 'dark'
                    : 'light';
                theme = saved === 'dark' || saved === 'light' ? saved : preferred;
            } catch (error) {
                theme = 'light';
            }
            document.documentElement.setAttribute('data-theme', theme);
            window.applyThemePicture = function (picture, requestedTheme) {
                if (!picture) {
                    return;
                }
                var activeTheme = requestedTheme === 'dark' ? 'dark' : 'light';
                if (picture.getAttribute('data-active-theme') === activeTheme) {
                    return;
                }
                picture.querySelectorAll('source[data-light-srcset]').forEach(function (source) {
                    source.setAttribute('srcset', source.getAttribute('data-' + activeTheme + '-srcset'));
                });
                var image = picture.querySelector('img[data-light-src]');
                if (image) {
                    image.setAttribute('src', image.getAttribute('data-' + activeTheme + '-src'));
                }
                picture.setAttribute('data-active-theme', activeTheme);
            };
            window.applyThemePictures = function (requestedTheme) {
                document.querySelectorAll('picture[data-theme-picture]').forEach(function (picture) {
                    window.applyThemePicture(picture, requestedTheme);
                });
            };
        })();
    </script>
    <?php
    $criticalCssPath = __DIR__ . '/assets/css/critical-home.min.css';
    if (is_file($criticalCssPath)) {
        echo '<style>' . file_get_contents($criticalCssPath) . '</style>';
    }
    ?>
    <link rel="preload" as="style" href="<?php echo escape($publicStylesheet); ?>">
    <link rel="stylesheet" href="<?php echo escape($publicStylesheet); ?>" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="<?php echo escape($publicStylesheet); ?>"></noscript>
    <?php include_once __DIR__ . '/includes/public-meta.php'; ?>
    <title><?php echo escape($pageTitle); ?></title>
    <meta name="description" content="<?php echo escape($pageDescription); ?>">
    <link rel="canonical" href="<?php echo escape($pageCanonical); ?>">
    <?php include_once __DIR__ . '/includes/open-graph.php'; ?>
    <?php include_once __DIR__ . '/includes/yandex-metrika-head.php'; ?>
    <?php include_once __DIR__ . '/includes/structured-data.php'; ?>
</head>
<body class="brand-home">
    <?php include_once __DIR__ . '/includes/public-header.php'; ?>
    <main>
        <section class="home-hero" aria-labelledby="homeHeroTitle">
            <picture class="home-hero__media">
                <source type="image/avif"
                        srcset="<?php echo escape($heroImage['avif']); ?>"
                        sizes="100vw">
                <img src="<?php echo escape($heroImage['fallback']); ?>"
                     srcset="<?php echo escape($heroImage['webp']); ?>"
                     sizes="100vw"
                     alt="Свадебная церемония у озера с цветочной аркой"
                     width="1672"
                     height="940"
                     fetchpriority="high"
                     loading="eager"
                     decoding="async">
            </picture>
            <div class="container home-hero__content">
                <div class="home-hero__title-group">
                    <div class="home-ornament" aria-hidden="true"><span></span><i></i><span></span></div>
                    <h1 id="homeHeroTitle"><?php echo escape(SITE_NAME); ?></h1>
                </div>
                <p class="home-hero__lead">Помогаем создавать свадьбы и события под ключ — от концепции и флористики до организации команды и спокойного проведения дня.</p>
                <nav class="home-directory" aria-label="Основные разделы">
                    <?php foreach ($heroCards as $card): ?>
                        <a class="home-directory__card" href="<?php echo escape($card['href']); ?>">
                            <span class="home-directory__icon home-directory__icon--<?php echo escape($card['icon']); ?>" aria-hidden="true"></span>
                            <h2><?php echo escape($card['title']); ?></h2>
                            <p><?php echo escape($card['text']); ?></p>
                            <span class="home-directory__arrow" aria-hidden="true">→</span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </section>
        <section class="section home-alphabet" data-reveal>
            <div class="container home-section-heading">
                <p class="eyebrow">Принципы работы</p>
                <h2>У нас своя азбука декора и организации</h2>
                <p>Каждый этап построен понятно: от первой идеи до финального дня события. Мы соединяем творчество, организацию и командную работу.</p>
            </div>
            <div class="container home-alphabet__grid" data-reveal-group>
                <?php foreach ($alphabet as $item): ?>
                    <article class="home-alphabet__item" data-reveal-item>
                        <span><?php echo escape($item['letter']); ?></span>
                        <h3><?php echo escape($item['title']); ?></h3>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="section home-responsibilities" data-reveal>
            <div class="container home-section-heading">
                <p class="eyebrow">Полное сопровождение</p>
                <h2>Что берём на себя</h2>
                <p>Собираем творческие и организационные задачи в один управляемый процесс, чтобы день события оставался лёгким.</p>
            </div>
            <div class="container home-responsibilities__grid" data-reveal-group>
                <?php foreach ($responsibilities as $index => $title): ?>
                    <article class="home-responsibility" data-reveal-item>
                        <span><?php echo str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                        <h3><?php echo escape($title); ?></h3>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="section home-editorial" aria-labelledby="homeEditorialTitle" data-reveal>
            <div class="container home-editorial__layout">
                <div>
                    <p class="eyebrow">Единая команда</p>
                    <h2 id="homeEditorialTitle">Организация, декор и флористика как один проект</h2>
                </div>
                <div class="home-editorial__copy">
                    <p>Работа над свадьбой начинается с понятного брифа: мы уточняем формат события, дату, площадку, количество гостей, пожелания и задачи, которые важно передать команде. Это помогает сразу связать творческую идею с реальными сроками, логистикой и возможностями пространства.</p>
                    <p>После заявки менеджер собирает недостающие данные и формирует заказ для организатора. Организатор становится главным контактом клиента, ведёт концепцию и подготовку, согласовывает ключевые решения и распределяет задачи между флористами, монтажниками, курьерами и помощниками.</p>
                    <p>Так декор, флористика, тайминг, доставка и монтаж не существуют отдельно друг от друга. Все этапы складываются в единый план, а клиент видит понятный путь от первого разговора до завершения события.</p>
                </div>
            </div>
        </section>
        <section class="section home-process" data-reveal>
            <div class="container home-process__layout">
                <picture class="home-process__media" data-theme-picture>
                    <source type="image/avif"
                            data-light-srcset="<?php echo escape($homeImages['process']['light']['avif']); ?>"
                            data-dark-srcset="<?php echo escape($homeImages['process']['dark']['avif']); ?>"
                            sizes="<?php echo escape($processImageSizes); ?>">
                    <source type="image/webp"
                            data-light-srcset="<?php echo escape($homeImages['process']['light']['webp']); ?>"
                            data-dark-srcset="<?php echo escape($homeImages['process']['dark']['webp']); ?>"
                            sizes="<?php echo escape($processImageSizes); ?>">
                    <img src="<?php echo $transparentPixel; ?>"
                         data-light-src="<?php echo escape($homeImages['process']['light']['fallback']); ?>"
                         data-dark-src="<?php echo escape($homeImages['process']['dark']['fallback']); ?>"
                         alt="Флорист с объёмным белым свадебным букетом"
                         width="1000"
                         height="1250"
                         loading="lazy"
                         decoding="async">
                </picture>
                <script>window.applyThemePicture(document.currentScript.previousElementSibling, document.documentElement.getAttribute('data-theme'));</script>
                <div class="home-process__copy">
                    <p class="eyebrow">Спокойствие в деталях</p>
                    <h2>Красота события начинается с ясного процесса</h2>
                    <p>Мы фиксируем задачи, сроки и договорённости, чтобы каждый участник понимал свою роль.</p>
                    <a class="home-text-link" href="/stages">Посмотреть этапы работы <span aria-hidden="true">→</span></a>
                </div>
            </div>
        </section>
        <?php if ($publishedReviews): ?>
        <section class="section home-stories" aria-labelledby="homeStoriesTitle" data-reveal>
            <div class="container home-section-heading home-stories__heading">
                <p class="eyebrow">Истории наших клиентов</p>
                <h2 id="homeStoriesTitle">События, о которых хочется помнить</h2>
                <p>Здесь только отзывы, которые клиенты разрешили опубликовать после завершения проекта.</p>
            </div>
            <div class="container home-stories__grid" data-reveal-group>
                <?php foreach ($publishedReviews as $review): ?>
                <article class="home-story" data-reveal-item>
                    <?php if (!empty($review['photo_id'])): ?>
                    <div class="home-story__photo">
                        <img src="/review-image.php?id=<?php echo (int)$review['photo_id']; ?>"
                             alt="Фотография события из отзыва клиента"
                             width="360" height="240" loading="lazy" decoding="async">
                    </div>
                    <?php endif; ?>
                    <div class="home-story__rating" aria-label="Оценка <?php echo (int)$review['rating']; ?> из 5">
                        <?php echo str_repeat('★', (int)$review['rating']); ?>
                    </div>
                    <blockquote><?php echo nl2br(escape($review['text'])); ?></blockquote>
                    <footer>
                        <strong><?php echo escape($review['display_name']); ?></strong>
                        <span>
                            <?php echo escape($review['order_title'] ?: ($review['event_type'] ?: $review['order_event_type'])); ?>
                            <?php if (!empty($review['venue'])): ?> · <?php echo escape($review['venue']); ?><?php endif; ?>
                        </span>
                        <time datetime="<?php echo escape(substr((string)$review['submitted_at'], 0, 10)); ?>">
                            <?php echo escape(formatRussianDate($review['submitted_at'], 'date_only')); ?>
                        </time>
                    </footer>
                </article>
                <?php endforeach; ?>
            </div>
            <div class="container home-stories__actions">
                <a class="btn btn-outline" href="/portfolio">Посмотреть наши проекты</a>
                <a class="home-text-link" href="/short-request">Обсудить ваше событие <span aria-hidden="true">→</span></a>
            </div>
        </section>
        <?php endif; ?>
        <section class="section home-faq" id="faq" data-reveal>
            <div class="container home-faq__layout">
                <div class="home-faq__heading">
                    <p class="eyebrow">Частые вопросы</p>
                    <h2>Главное перед первой встречей</h2>
                    <p>Коротко о заявке, составе работ и подготовке события.</p>
                </div>
                <div class="home-faq__list">
                    <?php foreach ($faqItems as $faqIndex => $faqItem): ?>
                        <details<?php echo $faqIndex === 0 ? ' open' : ''; ?>>
                            <summary><?php echo escape($faqItem['question']); ?></summary>
                            <div class="home-faq__answer">
                                <p><?php echo escape($faqItem['answer']); ?></p>
                                <?php if (!empty($faqItem['source_url'])): ?>
                                    <a
                                        href="<?php echo escape($faqItem['source_url']); ?>"
                                        target="_blank"
                                        rel="noopener external"
                                    >
                                        <?php echo escape($faqItem['source_label']); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <section class="home-cta" data-reveal>
            <picture class="home-cta__media" data-theme-picture>
                <source type="image/avif"
                        data-light-srcset="<?php echo escape($homeImages['cta']['light']['avif']); ?>"
                        data-dark-srcset="<?php echo escape($homeImages['cta']['dark']['avif']); ?>"
                        sizes="100vw">
                <source type="image/webp"
                        data-light-srcset="<?php echo escape($homeImages['cta']['light']['webp']); ?>"
                        data-dark-srcset="<?php echo escape($homeImages['cta']['dark']['webp']); ?>"
                        sizes="100vw">
                <img src="<?php echo $transparentPixel; ?>"
                     data-light-src="<?php echo escape($homeImages['cta']['light']['fallback']); ?>"
                     data-dark-src="<?php echo escape($homeImages['cta']['dark']['fallback']); ?>"
                     alt="Свадебная сервировка с цветами и свечами"
                     width="1672"
                     height="940"
                     loading="lazy"
                     decoding="async">
            </picture>
            <script>window.applyThemePicture(document.currentScript.previousElementSibling, document.documentElement.getAttribute('data-theme'));</script>
            <div class="container home-cta__content">
                <p class="eyebrow">Начнём с вашего замысла</p>
                <h2>Рассчитаем ваш формат события</h2>
                <a class="btn btn-primary btn-lg" href="/quiz">Рассчитать событие</a>
            </div>
        </section>
    </main>
    <?php include_once __DIR__ . '/includes/public-footer.php'; ?>
</body>
</html>
