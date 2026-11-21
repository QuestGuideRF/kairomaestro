<?php
final class PublicSeo
{
    private const ORGANIZATION_ID_PATH = '/#organization';
    private const WEBSITE_ID_PATH = '/#website';
    private const LANGUAGE = 'ru-RU';
    private const WEDDING_SERVICE_SLUG = 'svadba-pod-klyuch';
    private const DECOR_SERVICE_SLUG = 'dekor-i-floristika';
    private const DECOR_SERVICE_TITLE = 'Декор и флористика';
    private const CEREMONY_SERVICE_SLUG = 'vyezdnaya-tseremoniya';
    private const CEREMONY_SERVICE_TITLE = 'Выездная церемония';
    private const CHAMBER_SERVICE_SLUG = 'kamernoe-meropriyatie';
    private const CHAMBER_SERVICE_TITLE = 'Камерное мероприятие';
    private const MONTAGE_SERVICE_SLUG = 'montazh-i-demontazh';
    private const PROJECT_FALLBACK_TITLE = 'Проект';

    public static function serviceMeta(?string $slug, array $service = []): array
    {
        if ($slug === null || $slug === '') {
            return [
                'title' => 'Организация свадеб, декор и флористика в Москве — ' . SITE_NAME,
                'description' => 'Организация свадеб и камерных событий в Москве и Подмосковье: '
                    . 'концепция, декор, флористика, координация, выездные церемонии '
                    . 'и монтаж под ваш формат.',
            ];
        }

        $metadata = self::serviceMetadata();
        if (isset($metadata[$slug])) {
            return $metadata[$slug];
        }

        $title = trim((string)($service['title'] ?? 'Услуга'));
        $description = trim((string)($service['text'] ?? ''));
        return [
            'title' => $title . ' в Москве и Подмосковье — ' . SITE_NAME,
            'description' => $description . ' Уточним формат, состав работ и подготовим '
                . 'индивидуальный план проекта после заявки.',
        ];
    }

    public static function serviceContent(string $slug): array
    {
        return self::serviceContentMap()[$slug] ?? [
            'section_title' => 'Что входит в работу',
            'lead' => 'Состав проекта уточняется после заявки и зависит от формата, '
                . 'площадки, количества гостей и выбранных задач.',
            'includes' => [
                'Бриф и уточнение исходных данных',
                'План работ и согласование приоритетов',
                'Подбор команды под объём проекта',
                'Контроль сроков и ключевых договорённостей',
            ],
            'process_title' => 'Как проходит подготовка',
            'process' => 'После квиза команда уточняет детали, фиксирует объём работ и '
                . 'предлагает следующий шаг. Все решения согласуются до начала реализации.',
            'result' => 'Клиент получает понятный план, согласованный состав работ и '
                . 'ответственную команду на каждом этапе.',
            'portfolio_slug' => '',
            'portfolio_title' => 'Посмотреть проекты',
        ];
    }

    public static function portfolioMeta(?string $slug, array $project = []): array
    {
        if ($slug === null || $slug === '') {
            return [
                'title' => 'Портфолио свадебного декора и флористики — ' . SITE_NAME,
                'description' => 'Свадебный декор, флористика, церемонии и камерные ужины '
                    . 'в проектах Азбуки Декора. Смотрите палитры, композиции и детали '
                    . 'оформления для разных форматов событий.',
            ];
        }

        $metadata = self::portfolioMetadata();
        if (isset($metadata[$slug])) {
            return $metadata[$slug];
        }

        $title = trim((string)($project['title'] ?? self::PROJECT_FALLBACK_TITLE));
        $description = trim((string)($project['text'] ?? ''));
        return [
            'title' => $title . ': оформление события — ' . SITE_NAME,
            'description' => $description . ' Смотрите концепцию, палитру, ключевые '
                . 'элементы и детали оформления в портфолио Азбуки Декора.',
            'service_slug' => self::DECOR_SERVICE_SLUG,
            'service_title' => self::DECOR_SERVICE_TITLE,
        ];
    }

    public static function organizationNode(string $description): array
    {
        $organization = [
            '@type' => 'Organization',
            '@id' => appUrl(self::ORGANIZATION_ID_PATH),
            'name' => SITE_NAME,
            'url' => appUrl('/'),
            'description' => $description,
            'logo' => self::imageObject(
                appUrl('/android-chrome-512x512.png'),
                'Логотип ' . SITE_NAME,
                512,
                512
            ),
            'areaServed' => self::areaServed(),
            'hasOfferCatalog' => [
                '@type' => 'OfferCatalog',
                'name' => 'Услуги по организации и оформлению событий',
                'itemListElement' => array_map(
                    static fn(string $name): array => [
                        '@type' => 'Offer',
                        'itemOffered' => [
                            '@type' => 'Service',
                            'name' => $name,
                        ],
                    ],
                    [
                        'Свадьба под ключ',
                        self::DECOR_SERVICE_TITLE,
                        'Координация дня',
                        self::CHAMBER_SERVICE_TITLE,
                        self::CEREMONY_SERVICE_TITLE,
                        'Монтаж и демонтаж',
                    ]
                ),
            ],
        ];

        $contactPoint = [
            '@type' => 'ContactPoint',
            'contactType' => 'customer service',
            'availableLanguage' => 'ru',
            'areaServed' => ['RU'],
        ];
        if (PUBLIC_PHONE !== '') {
            $contactPoint['telephone'] = PUBLIC_PHONE;
        }
        if (PUBLIC_EMAIL !== '' && filter_var(PUBLIC_EMAIL, FILTER_VALIDATE_EMAIL)) {
            $contactPoint['email'] = PUBLIC_EMAIL;
        }
        if (isset($contactPoint['telephone']) || isset($contactPoint['email'])) {
            $organization['contactPoint'] = $contactPoint;
        }

        return $organization;
    }

    public static function websiteNode(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => appUrl(self::WEBSITE_ID_PATH),
            'url' => appUrl('/'),
            'name' => SITE_NAME,
            'inLanguage' => self::LANGUAGE,
            'publisher' => ['@id' => appUrl(self::ORGANIZATION_ID_PATH)],
        ];
    }

    public static function imageData(
        string $url,
        string $alt,
        int $width,
        int $height
    ): array {
        return [
            'url' => $url,
            'alt' => $alt,
            'width' => $width,
            'height' => $height,
        ];
    }

    public static function webPageNode(
        string $canonical,
        string $title,
        string $description,
        array $image,
        string $type = 'WebPage'
    ): array {
        return [
            '@type' => $type,
            '@id' => $canonical . '#webpage',
            'url' => $canonical,
            'name' => $title,
            'description' => $description,
            'inLanguage' => self::LANGUAGE,
            'isPartOf' => ['@id' => appUrl(self::WEBSITE_ID_PATH)],
            'about' => ['@id' => appUrl(self::ORGANIZATION_ID_PATH)],
            'primaryImageOfPage' => self::imageObject(
                self::absoluteUrl((string)($image['url'] ?? '')),
                (string)($image['alt'] ?? ''),
                (int)($image['width'] ?? 0),
                (int)($image['height'] ?? 0)
            ),
        ];
    }

    public static function serviceNode(
        string $canonical,
        string $name,
        string $description,
        array $image
    ): array {
        return [
            '@type' => 'Service',
            '@id' => $canonical . '#service',
            'url' => $canonical,
            'name' => $name,
            'serviceType' => $name,
            'description' => $description,
            'provider' => ['@id' => appUrl(self::ORGANIZATION_ID_PATH)],
            'areaServed' => self::areaServed(),
            'image' => self::imageObject(
                self::absoluteUrl((string)($image['url'] ?? '')),
                (string)($image['alt'] ?? ''),
                (int)($image['width'] ?? 0),
                (int)($image['height'] ?? 0)
            ),
        ];
    }

    public static function articleNode(
        string $canonical,
        array $project,
        string $description
    ): array {
        $node = [
            '@type' => 'Article',
            '@id' => $canonical . '#article',
            'url' => $canonical,
            'mainEntityOfPage' => ['@id' => $canonical . '#webpage'],
            'headline' => trim((string)($project['title'] ?? self::PROJECT_FALLBACK_TITLE)),
            'name' => trim((string)($project['title'] ?? self::PROJECT_FALLBACK_TITLE)),
            'description' => $description,
            'inLanguage' => self::LANGUAGE,
            'isPartOf' => ['@id' => appUrl(self::WEBSITE_ID_PATH)],
            'author' => ['@id' => appUrl(self::ORGANIZATION_ID_PATH)],
            'publisher' => ['@id' => appUrl(self::ORGANIZATION_ID_PATH)],
            'image' => self::imageObject(
                self::absoluteUrl((string)($project['image'] ?? '')),
                (string)($project['alt'] ?? $project['title'] ?? ''),
                (int)($project['width'] ?? 0),
                (int)($project['height'] ?? 0)
            ),
        ];
        $style = trim((string)($project['style'] ?? ''));
        if ($style !== '') {
            $node['articleSection'] = $style;
        }
        $location = trim((string)($project['location'] ?? ''));
        if ($location !== '' && empty($project['is_reference'])) {
            $node['contentLocation'] = [
                '@type' => 'Place',
                'name' => $location,
            ];
        }
        return $node;
    }

    private static function serviceMetadata(): array
    {
        return [
            self::WEDDING_SERVICE_SLUG => [
                'title' => 'Свадьба под ключ в Москве: организация и декор — ' . SITE_NAME,
                'description' => 'Организация свадьбы под ключ в Москве и Подмосковье: '
                    . 'концепция, площадка, подрядчики, смета, тайминг, команда, '
                    . 'координация и демонтаж на площадке.',
            ],
            self::DECOR_SERVICE_SLUG => [
                'title' => 'Декор и флористика свадьбы в Москве — ' . SITE_NAME,
                'description' => 'Разрабатываем декор и флористику свадьбы в Москве и '
                    . 'Подмосковье: палитру, арки, столы, букеты, фотозоны и монтаж '
                    . 'оформления, контролируем реализацию.',
            ],
            'koordinatsiya-dnya' => [
                'title' => 'Координация свадебного дня в Москве — ' . SITE_NAME,
                'description' => 'Координация свадебного дня в Москве и Подмосковье: '
                    . 'контроль тайминга, подрядчиков, команды, монтажа и важных '
                    . 'деталей до завершения события на площадке.',
            ],
            self::CHAMBER_SERVICE_SLUG => [
                'title' => 'Камерные мероприятия в Москве под ключ — ' . SITE_NAME,
                'description' => 'Организуем камерные свадьбы, семейные ужины и частные '
                    . 'события в Москве и Подмосковье: сценарий, оформление, флористика, команда и координация.',
            ],
            self::CEREMONY_SERVICE_SLUG => [
                'title' => 'Выездная свадебная церемония в Москве — ' . SITE_NAME,
                'description' => 'Создаём выездные свадебные церемонии в Москве и '
                    . 'Подмосковье: сценарий, маршрут гостей, арка, флористика, звук, монтаж и координация момента.',
            ],
            self::MONTAGE_SERVICE_SLUG => [
                'title' => 'Монтаж и демонтаж свадебного декора — ' . SITE_NAME,
                'description' => 'Организуем монтаж и демонтаж свадебного декора в Москве '
                    . 'и Подмосковье: логистика, сборка конструкций, расстановка деталей и контроль сроков.',
            ],
        ];
    }

    private static function serviceContentMap(): array
    {
        return [
            self::WEDDING_SERVICE_SLUG => [
                'section_title' => 'Организация от концепции до финала',
                'lead' => 'Берём на себя связанный процесс подготовки, чтобы решения по '
                    . 'площадке, оформлению, подрядчикам и таймингу работали как единое целое.',
                'includes' => [
                    'Бриф, концепция и план подготовки',
                    'Работа с площадкой и подрядчиками',
                    'Смета, договорённости и контроль сроков',
                    'Команда, тайминг и координация дня',
                    'Монтаж, проведение события и демонтаж',
                ],
                'process_title' => 'Как строится подготовка',
                'process' => 'После квиза менеджер уточняет исходные данные и формирует '
                    . 'заказ. Организатор принимает проект, знакомится с историей клиента и ведёт '
                    . 'подготовку до завершения события.',
                'result' => 'Пара получает одного ответственного организатора, прозрачные '
                    . 'этапы подготовки и команду, которая знает свои задачи.',
                'portfolio_slug' => 'belaya-tseremoniya-yagodnyy-banket',
                'portfolio_title' => 'Белая церемония и ягодный банкет',
            ],
            self::DECOR_SERVICE_SLUG => [
                'section_title' => 'Визуальная концепция пространства',
                'lead' => 'Собираем палитру, флористику, материалы и композицию зон так, '
                    . 'чтобы оформление поддерживало площадку и характер события.',
                'includes' => [
                    'Moodboard, палитра и подбор фактур',
                    'Флористика столов и церемониальной зоны',
                    'Букет, фотозона и акцентные композиции',
                    'Текстиль, свечи и детали сервировки',
                    'Производство, доставка, монтаж и демонтаж',
                ],
                'process_title' => 'От идеи к монтажу',
                'process' => 'Сначала фиксируем пожелания и ограничения площадки, затем '
                    . 'согласуем эскизы, состав флористики и технический план. Перед событием '
                    . 'команда готовит материалы и собирает оформление на месте.',
                'result' => 'Все зоны выглядят частью одной истории, а решения остаются '
                    . 'реализуемыми по срокам, логистике и бюджету проекта.',
                'portfolio_slug' => 'zelenaya-floristicheskaya-stsena',
                'portfolio_title' => 'Флористическая сцена в зелёных тонах',
            ],
            'koordinatsiya-dnya' => [
                'section_title' => 'Контроль дня без лишней суеты',
                'lead' => 'Координатор держит общий тайминг, связь с подрядчиками и '
                    . 'готовность площадки, пока клиент и гости проживают событие.',
                'includes' => [
                    'Финальная проверка тайминга и контактов',
                    'Контроль прибытия команды и подрядчиков',
                    'Синхронизация монтажа, программы и подачи',
                    'Решение организационных вопросов на площадке',
                    'Контроль завершения и передачи задач на демонтаж',
                ],
                'process_title' => 'Подготовка координатора',
                'process' => 'До события координатор получает согласованный план, список '
                    . 'контактов и критические точки дня. На площадке он последовательно проверяет '
                    . 'готовность зон и передаёт команде актуальные изменения.',
                'result' => 'Участники процесса понимают, что делать дальше, а клиенту не '
                    . 'приходится самостоятельно контролировать логистику и сроки.',
                'portfolio_slug' => '',
                'portfolio_title' => 'Посмотреть проекты',
            ],
            self::CHAMBER_SERVICE_SLUG => [
                'section_title' => 'Личный формат с точными деталями',
                'lead' => 'Камерный ужин, юбилей или небольшая свадьба требуют не меньше '
                    . 'внимания: здесь особенно заметны свет, сервировка и атмосфера общения.',
                'includes' => [
                    'Сценарий и логика пространства',
                    'Декор, флористика и сервировка',
                    'Подбор команды под формат события',
                    'Тайминг, музыка и координация',
                    'Монтаж и аккуратное завершение вечера',
                ],
                'process_title' => 'Собираем атмосферу',
                'process' => 'Обсуждаем состав гостей, повод и желаемое настроение, затем '
                    . 'предлагаем визуальное и организационное решение без избыточных элементов.',
                'result' => 'Получается цельное событие, в котором пространство поддерживает '
                    . 'общение, а каждая деталь работает на общий замысел.',
                'portfolio_slug' => 'pudrovyy-yubileynyy-uzhin',
                'portfolio_title' => 'Юбилейный ужин в пудровой палитре',
            ],
            self::CEREMONY_SERVICE_SLUG => [
                'section_title' => 'Церемония как самостоятельная сцена',
                'lead' => 'Проектируем маршрут гостей, композицию прохода и церемониальную '
                    . 'зону с учётом ландшафта, света и сценария момента.',
                'includes' => [
                    'Сценарий и последовательность церемонии',
                    'Арка, проход и посадка гостей',
                    'Флористика и декоративные акценты',
                    'Звук, технические точки и план на погоду',
                    'Монтаж, координация и демонтаж зоны',
                ],
                'process_title' => 'Учитываем площадку и погоду',
                'process' => 'После осмотра или разбора площадки команда определяет '
                    . 'ориентацию зоны, технические ограничения и резервный сценарий. Все решения '
                    . 'согласуются до выезда на монтаж.',
                'result' => 'Церемония сохраняет эмоциональность момента и остаётся '
                    . 'понятной для гостей, ведущего и технической команды.',
                'portfolio_slug' => 'belaya-tseremoniya-yagodnyy-banket',
                'portfolio_title' => 'Белая церемония и ягодный банкет',
            ],
            self::MONTAGE_SERVICE_SLUG => [
                'section_title' => 'Точная реализация на площадке',
                'lead' => 'Организуем доставку, сборку и расстановку элементов так, чтобы '
                    . 'оформление было готово к согласованному времени и безопасно для гостей.',
                'includes' => [
                    'Технический план и очередность работ',
                    'Логистика материалов и конструкций',
                    'Сборка зон и расстановка декора',
                    'Проверка готовности перед открытием площадки',
                    'Демонтаж, упаковка и возврат материалов',
                ],
                'process_title' => 'Команда работает по плану',
                'process' => 'Организатор распределяет задачи между монтажниками, курьерами, '
                    . 'флористами и помощниками. Статусы и сроки фиксируются в рабочей системе проекта.',
                'result' => 'Площадка открывается вовремя, элементы установлены аккуратно, '
                    . 'а после события пространство возвращается в согласованное состояние.',
                'portfolio_slug' => 'zelenaya-floristicheskaya-stsena',
                'portfolio_title' => 'Флористическая сцена в зелёных тонах',
            ],
        ];
    }

    private static function portfolioMetadata(): array
    {
        return self::realizedPortfolioMetadata();
    }

    private static function realizedPortfolioMetadata(): array
    {
        return [
            'zelenaya-floristicheskaya-stsena' => [
                'title' => 'Флористическая сцена в зелёных тонах — ' . SITE_NAME,
                'description' => 'Флористическая сцена в зелёных тонах: белая драпировка, '
                    . 'высокая зелень, лаймовые соцветия и воздушные цветы собраны в асимметричную композицию.',
                'service_slug' => self::DECOR_SERVICE_SLUG,
                'service_title' => self::DECOR_SERVICE_TITLE,
            ],
            'pudrovyy-yubileynyy-uzhin' => [
                'title' => 'Юбилейный ужин в пудровой палитре: декор — ' . SITE_NAME,
                'description' => 'Юбилейный ужин в пудровой палитре: длинный стол, '
                    . 'воздушные цветы, тонкие свечи и мерцающий фон создают цельное оформление камерного праздника.',
                'service_slug' => self::CHAMBER_SERVICE_SLUG,
                'service_title' => self::CHAMBER_SERVICE_TITLE,
            ],
            'belaya-tseremoniya-yagodnyy-banket' => [
                'title' => 'Белая церемония и ягодный банкет: декор — ' . SITE_NAME,
                'description' => 'Белая церемония переходит в контрастный ягодный банкет: '
                    . 'бордовые и оранжевые цветы, фрукты, цветное стекло и белый текстиль создают насыщенный образ.',
                'service_slug' => self::CEREMONY_SERVICE_SLUG,
                'service_title' => self::CEREMONY_SERVICE_TITLE,
            ],
        ];
    }

    private static function imageObject(
        string $url,
        string $caption,
        int $width,
        int $height
    ): array {
        $image = [
            '@type' => 'ImageObject',
            'url' => $url,
            'contentUrl' => $url,
        ];
        if ($caption !== '') {
            $image['caption'] = $caption;
        }
        if ($width > 0) {
            $image['width'] = $width;
        }
        if ($height > 0) {
            $image['height'] = $height;
        }
        return $image;
    }

    private static function areaServed(): array
    {
        return [
            [
                '@type' => 'City',
                'name' => 'Москва',
            ],
            [
                '@type' => 'AdministrativeArea',
                'name' => 'Московская область',
            ],
        ];
    }

    private static function absoluteUrl(string $url): string
    {
        if ($url === '') {
            return appUrl('/');
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return appUrl($url);
    }
}
