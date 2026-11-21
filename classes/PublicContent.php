<?php
class PublicContent {
    private const INSTALLATION = 'Монтаж';
    private const INSTALLATION_AND_DISMANTLING = 'Монтаж и демонтаж';
    private const WHITE = 'Белый';
    private const WEBP_EXTENSION = '.webp';
    private const REMOVED_PORTFOLIO_SLUGS = [
        'sadovaya-svadba-v-podmoskove',
        'minimalistichnaya-svadba-v-moskve',
        'desertnyy-stol-s-tsvetami',
        'belaya-shatrovaya-svadba',
        'tsvetochnaya-arka',
        'yarkaya-floristika-na-stole',
        'vecherniy-banket',
        'kamernaia-zona-molodyh',
        'lawn-ceremony',
    ];
    public static function services() {
        $items = [
            [
                'slug' => 'svadba-pod-klyuch',
                'title' => 'Свадьба под ключ',
                'text' => 'От первой идеи до финального демонтажа: концепция, площадка, подрядчики, команда, тайминг и спокойный день свадьбы.',
                'image_base' => 'assets/images/services/service-ceremony-01',
                'width' => 1000,
                'height' => 666,
            ],
            [
                'slug' => 'dekor-i-floristika',
                'title' => 'Декор и флористика',
                'text' => 'Арки, столы, фотозоны, букеты, флористические инсталляции и визуальная концепция пространства.',
                'image_base' => 'assets/images/services/service-floristics-01',
                'width' => 1000,
                'height' => 666,
            ],
            [
                'slug' => 'koordinatsiya-dnya',
                'title' => 'Координация дня',
                'text' => 'Контроль тайминга, команды, подрядчиков, монтажа и всех маленьких деталей, которые нельзя отпустить.',
                'image_base' => 'assets/images/services/service-coordination-01',
                'width' => 1000,
                'height' => 666,
            ],
            [
                'slug' => 'kamernoe-meropriyatie',
                'title' => 'Камерное мероприятие',
                'text' => 'Семейные ужины, дни рождения, корпоративы и выездные церемонии с персональным сценарием.',
                'image_base' => 'assets/images/services/service-chamber-01',
                'width' => 1000,
                'height' => 666,
            ],
            [
                'slug' => 'vyezdnaya-tseremoniya',
                'title' => 'Выездная церемония',
                'text' => 'Маршрут гостей, зона церемонии, арка, звук, координация и точная работа с эмоцией момента.',
                'image_base' => 'assets/images/services/service-decor-01',
                'width' => 1000,
                'height' => 666,
            ],
            [
                'slug' => 'montazh-i-demontazh',
                'title' => self::INSTALLATION_AND_DISMANTLING,
                'text' => 'Команда флористов, монтажников, курьеров и помощников с понятными задачами и контролем сроков.',
                'image_base' => 'assets/images/services/service-montage-01',
                'width' => 1000,
                'height' => 666,
            ],
        ];
        $fallback = array_map([self::class, 'localImage'], $items);
        if (!dbTableExists('services')) {
            return $fallback;
        }
        $rows = Database::getInstance()->getConnection()
            ->query('SELECT * FROM services WHERE is_active=1 ORDER BY sort_order, id')
            ->fetchAll();
        if (!$rows) {
            return $fallback;
        }
        $fallbackBySlug = array_column($fallback, null, 'slug');
        return array_map(static function ($row) use ($fallbackBySlug) {
            $base = $fallbackBySlug[$row['slug']] ?? [];
            $text = $row['short_description'] ?: $row['description'];
            $item = array_merge($base, [
                'slug' => $row['slug'],
                'title' => $row['title'],
                'text' => $text ?: '',
            ]);
            return self::withStoredImage($item, $row['image_url'] ?? null);
        }, $rows);
    }
    public static function packages() {
        $fallback = [
            [
                'title' => 'Легкий старт',
                'text' => 'Для пары, которой нужен понятный план подготовки.',
                'features' => ['бриф', 'карта задач', 'подбор первых решений', 'предварительная смета'],
                'price_note' => 'Расчёт после брифа',
            ],
            [
                'title' => 'Декор и флористика',
                'text' => 'Для выбранной площадки и сильной визуальной концепции.',
                'features' => ['moodboard', 'цветы', 'арка и столы', 'монтаж и демонтаж'],
                'price_note' => 'Индивидуальная смета',
            ],
            [
                'title' => 'Свадьба под ключ',
                'text' => 'Полное сопровождение подготовки и дня мероприятия.',
                'features' => ['концепция', 'подрядчики', 'смета', 'тайминг', 'команда дня'],
                'price_note' => 'По объёму работ',
            ],
            [
                'title' => 'Премиум-сопровождение',
                'text' => 'Расширенная команда, больше контроля и персональных материалов.',
                'features' => ['личный менеджер', 'договоры', 'кабинет клиента', 'финальный отчет'],
                'price_note' => 'По запросу',
            ],
        ];
        if (!dbTableExists('service_packages')) {
            return $fallback;
        }
        $rows = Database::getInstance()->getConnection()
            ->query('SELECT * FROM service_packages WHERE is_active=1 ORDER BY sort_order, id')
            ->fetchAll();
        if (!$rows) {
            return $fallback;
        }
        return array_map(static function ($row) {
            $features = json_decode((string)($row['features'] ?? '[]'), true);
            return [
                'title' => $row['title'],
                'text' => $row['description'] ?: '',
                'features' => is_array($features) ? $features : [],
                'price_note' => $row['price_note'] ?: 'Расчёт после брифа',
            ];
        }, $rows);
    }
    public static function portfolio() {
        $items = self::storedPortfolio();
        if (!$items) {
            $items = self::referencePortfolio();
        } else {
            $knownSlugs = array_fill_keys(array_column($items, 'slug'), true);
            $realizedProjects = array_map(
                [self::class, 'localImage'],
                PublicRealizedPortfolio::items()
            );
            foreach ($realizedProjects as $project) {
                if (!isset($knownSlugs[$project['slug']])) {
                    $items[] = $project;
                }
            }
        }

        return array_map([self::class, 'enrichPortfolioItem'], $items);
    }
    private static function enrichPortfolioItem(array $item): array {
        $details = self::portfolioDetails();
        return array_merge($item, $details[$item['slug']] ?? []);
    }
    private static function portfolioDetails(): array {
        return self::realizedPortfolioDetails();
    }
    private static function realizedPortfolioDetails(): array {
        return [
            'zelenaya-floristicheskaya-stsena' => [
                'intro' => [
                    'Белый текстиль становится спокойным фоном для двух асимметричных композиций. Высокие травы задают вертикальный ритм, а лаймовые и белые соцветия собирают нижний объём.',
                    'Живая зелень, мох и свободные линии стеблей смягчают архитектуру пространства. Тёплое дерево пола добавляет природной палитре глубину и делает светлую сцену выразительнее.',
                ],
                'brief' => [
                    'Формат' => 'Флористическая интерьерная сцена',
                    'Палитра' => 'Белый, лаймовый и глубокий зелёный',
                    'Фактуры' => 'Текстиль, дерево, мох и живая зелень',
                    'Главный акцент' => 'Две асимметричные композиции',
                ],
                'concept_title' => 'Ботаническая композиция с большим количеством воздуха',
                'concept_text' => 'Плотный объём сосредоточен у пола, а лёгкие травы поднимают взгляд вверх. Благодаря разнице высот оформление выглядит живым и не превращается в сплошную цветочную стену.',
                'solution_text' => 'Белая драпировка отделяет композицию от интерьера, а свободный центр сохраняет визуальную паузу. Зелёные соцветия связывают обе стороны, не делая их зеркально одинаковыми.',
                'highlights' => [
                    'Высокие травы с естественным силуэтом',
                    'Лаймовые соцветия в глубокой зелени',
                    'Лёгкие белые цветы в нижнем ярусе',
                    'Мягкая белая драпировка',
                    'Тёплая деревянная фактура пола',
                ],
                'services' => [
                    'Разработка флористической композиции',
                    'Подбор живых цветов и зелени',
                    'Текстильный фон',
                    self::INSTALLATION_AND_DISMANTLING,
                ],
                'palette' => [
                    ['name' => 'Молочный текстиль', 'color' => '#E9E4DA'],
                    ['name' => 'Лаймовый', 'color' => '#91A94D'],
                    ['name' => 'Лесной зелёный', 'color' => '#34472F'],
                    ['name' => 'Тёплое дерево', 'color' => '#8B5A35'],
                ],
                'result' => 'Получилась спокойная флористическая сцена, в которой естественная асимметрия и сложная зелёная палитра работают выразительнее плотного декора.',
            ],
            'pudrovyy-yubileynyy-uzhin' => [
                'intro' => [
                    'Длинный общий стол собран в белой и пудрово-розовой гамме. Мягкий текстиль проходит через всю сервировку, связывая цветы, свечи и индивидуальные места гостей.',
                    'Воздушные композиции остаются ниже линии взгляда, а тонкие свечи добавляют вертикальный ритм. Жемчужные нити и мерцающий фон поддерживают праздничный характер юбилейного вечера.',
                ],
                'brief' => [
                    'Формат' => 'Юбилейный ужин',
                    'Композиция' => 'Длинный общий стол',
                    'Настроение' => 'Нежное и торжественное',
                    'Главный акцент' => 'Флористика, свечи и мерцающий фон',
                ],
                'concept_title' => 'Пудровая классика в современной сервировке',
                'concept_text' => 'Белая основа сохраняет свет и чистоту композиции, а пыльно-розовый текстиль добавляет цвет без излишней сладости. Цветы разных высот создают плавный ритм вдоль всего стола.',
                'solution_text' => 'Свечи, прозрачное стекло и жемчужные детали распределены небольшими группами. Так длинный стол выглядит цельным, но каждый фрагмент остаётся аккуратным и удобным для гостей.',
                'highlights' => [
                    'Единая флористическая линия вдоль стола',
                    'Пудровый текстиль с мягкими складками',
                    'Тонкие свечи двух оттенков',
                    'Прозрачное стекло и жемчужные детали',
                    'Мерцающая юбилейная фотозона',
                ],
                'services' => [
                    'Концепция сервировки',
                    'Флористика стола',
                    'Текстиль и свечи',
                    'Оформление акцентного фона',
                    self::INSTALLATION,
                ],
                'palette' => [
                    ['name' => self::WHITE, 'color' => '#F3F0EB'],
                    ['name' => 'Пыльная роза', 'color' => '#B9868E'],
                    ['name' => 'Розовый кварц', 'color' => '#D7B0B4'],
                    ['name' => 'Жемчужный', 'color' => '#D9D0C7'],
                ],
                'result' => 'Оформление получилось праздничным и светлым: выразительным в общем кадре и аккуратным в каждой детали сервировки.',
            ],
            'belaya-tseremoniya-yagodnyy-banket' => [
                'intro' => [
                    'Проект соединяет два разных настроения. Церемониальная зона и букет построены на белой флористике, мягком текстиле и естественном зелёном фоне.',
                    'В банкетной части палитра становится насыщеннее: бордовые, красные и оранжевые цветы сочетаются с фруктами, цветным стеклом и свободно уложенным белым текстилем.',
                ],
                'brief' => [
                    'Формат' => 'Свадебная церемония и банкет',
                    'Контраст' => 'Белая церемония и насыщенная сервировка',
                    'Фактуры' => 'Живые цветы, фрукты, стекло и текстиль',
                    'Главный акцент' => 'Ягодно-оранжевая флористика стола',
                ],
                'concept_title' => 'Два настроения одной свадебной истории',
                'concept_text' => 'Для церемонии выбрана почти монохромная белая композиция, которая спокойно читается на фоне зелени. Банкет раскрывает более эмоциональную палитру и становится ярким продолжением дня.',
                'solution_text' => 'Цвет связывает длинные столы повторяющимися группами флористики и фруктов. Разная высота композиций сохраняет движение, а белый текстиль не даёт насыщенным оттенкам перегрузить пространство.',
                'highlights' => [
                    'Белый букет с разными формами лепестков',
                    'Лёгкая церемониальная драпировка среди деревьев',
                    'Бордовая и оранжевая флористика',
                    'Фрукты как часть настольной композиции',
                    'Цветное стекло и свободный белый текстиль',
                ],
                'services' => [
                    'Флористика церемонии',
                    'Букет',
                    'Флористика банкетных столов',
                    'Сервировка и текстиль',
                    self::INSTALLATION,
                ],
                'palette' => [
                    ['name' => self::WHITE, 'color' => '#F2F0E8'],
                    ['name' => 'Гранатовый', 'color' => '#8A2432'],
                    ['name' => 'Терракотовый', 'color' => '#C65F35'],
                    ['name' => 'Сливовый', 'color' => '#5A233B'],
                ],
                'result' => 'Сдержанная белая церемония и выразительный банкет создают цельный проект с заметной сменой настроения в течение события.',
            ],
        ];
    }
    private static function storedPortfolio(): array {
        $items = [];
        if (dbTableExists('portfolio_projects')) {
            $db = Database::getInstance()->getConnection();
            $imageJoin = self::portfolioImageSubquery();
            $rows = $db->query("
                SELECT project.*,
                       COALESCE(project.cover_image, {$imageJoin}) AS public_image
                FROM portfolio_projects project
                WHERE project.is_published=1
                ORDER BY project.sort_order, project.id
            ")->fetchAll();
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => !in_array(
                    (string)($row['slug'] ?? ''),
                    self::REMOVED_PORTFOLIO_SLUGS,
                    true
                )
            ));
            $items = array_map(
                [self::class, 'storedPortfolioItem'],
                $rows
            );
        }

        return $items;
    }
    private static function portfolioImageSubquery(): string {
        return dbTableExists('portfolio_images')
            ? '(SELECT image_url FROM portfolio_images '
                . 'WHERE project_id=project.id '
                . 'ORDER BY sort_order,id LIMIT 1)'
            : 'NULL';
    }
    private static function storedPortfolioItem(array $row): array {
        $item = [
            'slug' => $row['slug'],
            'title' => $row['title'],
            'style' => $row['style'] ?: '',
            'location' => $row['location'] ?: '',
            'guests' => (int)($row['guests_count'] ?? 0),
            'text' => $row['description'] ?: '',
            'alt' => $row['alt_text'] ?: $row['title'],
            'width' => 1200,
            'height' => 800,
            'is_reference' => false,
        ];

        $item = self::withStoredImage($item, $row['public_image'] ?? null);
        $item['gallery'] = self::storedPortfolioGallery(
            (int)$row['id'],
            (string)$row['title']
        );
        return $item;
    }
    private static function storedPortfolioGallery(
        int $projectId,
        string $projectTitle
    ): array {
        if (!dbTableExists('portfolio_images')) {
            return [];
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            'SELECT image_url,alt_text
             FROM portfolio_images
             WHERE project_id=?
             ORDER BY sort_order,id'
        );
        $stmt->execute([$projectId]);
        return array_map(
            static function (array $row) use ($projectTitle): array {
                return self::withStoredImage(
                    [
                        'alt' => $row['alt_text'] ?: $projectTitle,
                        'width' => 1200,
                        'height' => 800,
                    ],
                    $row['image_url'] ?? null
                );
            },
            $stmt->fetchAll()
        );
    }
    private static function referencePortfolio(): array {
        return array_map([self::class, 'localImage'], PublicRealizedPortfolio::items());
    }
    public static function alphabet() {
        return [
            ['letter' => 'А', 'title' => 'Аккуратность в деталях'],
            ['letter' => 'Б', 'title' => 'Бережная подготовка'],
            ['letter' => 'В', 'title' => 'Визуальная концепция'],
            ['letter' => 'Г', 'title' => 'Гармония пространства'],
            ['letter' => 'Д', 'title' => 'Доверие на каждом этапе'],
            ['letter' => 'И', 'title' => 'Индивидуальный сценарий'],
        ];
    }
    public static function stages() {
        return [
            [
                'title' => 'Заявка',
                'description' => 'Клиент сообщает тип события, желаемую дату, количество гостей, ориентир по бюджету и основные пожелания.',
            ],
            [
                'title' => 'Знакомство и бриф',
                'description' => 'Уточняем формат, площадку, состав услуг, визуальные предпочтения и вопросы, которые важно учесть в проекте.',
            ],
            [
                'title' => 'Концепция',
                'description' => 'Формируем общее направление оформления: атмосферу, палитру, флористику, декор и ключевые зоны пространства.',
            ],
            [
                'title' => 'Смета',
                'description' => 'Собираем согласованный состав работ и расходов, чтобы объём проекта и стоимость были понятны до начала подготовки.',
            ],
            [
                'title' => 'Договор',
                'description' => 'Фиксируем услуги, порядок работы, ответственность сторон, условия оплаты и важные договорённости по событию.',
            ],
            [
                'title' => 'Предоплата',
                'description' => 'После заключения договора и оплаты по его условиям проект переходит к детальной подготовке.',
            ],
            [
                'title' => 'Подготовка',
                'description' => 'Организатор собирает команду, ставит задачи, согласовывает материалы, документы, логистику и общий тайминг.',
            ],
            [
                'title' => 'Монтаж',
                'description' => 'Доставляем материалы на площадку, устанавливаем конструкции, декор и флористику по утверждённому плану.',
            ],
            [
                'title' => 'День события',
                'description' => 'Организатор координирует команду и последовательность работ, контролируя готовность площадки и ключевые этапы.',
            ],
            [
                'title' => 'Завершение проекта',
                'description' => 'После события организуем демонтаж, закрываем задачи и сохраняем историю проекта и согласованных материалов.',
            ],
        ];
    }
    private static function localImage($item) {
        if (!empty($item['image_base'])) {
            $item['image'] = asset($item['image_base'] . '.jpg');
            $item['image_webp'] = asset($item['image_base'] . self::WEBP_EXTENSION);
            $item['alt'] = $item['alt'] ?? $item['title'];
        }
        if (!empty($item['gallery'])) {
            $item['gallery'] = array_map(static function (array $image): array {
                $image['image'] = asset($image['image_base'] . self::WEBP_EXTENSION);
                return $image;
            }, $item['gallery']);
        }
        return $item;
    }
    private static function withStoredImage($item, $imagePath) {
        $path = trim((string)$imagePath);
        if ($path === '') {
            if (!isset($item['image'])) {
                $item['image_base'] = 'assets/images/hero/hero-wedding-01';
                $item = self::localImage($item);
            }
            return $item;
        }
        if (preg_match('#\Ahttps?://#i', $path)) {
            $item['image_base'] = 'assets/images/hero/hero-wedding-01';
            return self::localImage($item);
        }
        $path = ltrim($path, '/');
        $item['image'] = asset($path);
        $absolutePath = __DIR__ . '/../' . $path;
        $dimensions = is_file($absolutePath) ? @getimagesize($absolutePath) : false;
        if ($dimensions) {
            $item['width'] = (int)$dimensions[0];
            $item['height'] = (int)$dimensions[1];
        }
        $webpPath = preg_replace('/\.(?:jpe?g|png)\z/i', self::WEBP_EXTENSION, $path);
        $item['image_webp'] = $webpPath !== $path && is_file(__DIR__ . '/../' . $webpPath)
            ? asset($webpPath)
            : $item['image'];
        return $item;
    }
}
