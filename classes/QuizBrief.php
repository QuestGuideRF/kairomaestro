<?php
final class QuizBrief {
    private const TOTAL_BUDGETS = [
        'unsure' => 'Пока не определились',
        'under_500' => 'До 500 000 ₽',
        '500_1000' => '500 000 - 1 000 000 ₽',
        '1000_2500' => '1 000 000 - 2 500 000 ₽',
        'over_2500' => '2 500 000 ₽ и выше',
    ];

    public static function optionSets(): array {
        return [
            'event_type_choice' => [
                'Свадьба' => 'Свадьба',
                'Камерная свадьба' => 'Камерная свадьба',
                'Выездная церемония' => 'Выездная церемония',
                'День рождения' => 'День рождения',
                'Юбилей' => 'Юбилей',
                'Корпоратив' => 'Корпоратив',
                'Частный ужин' => 'Частный ужин',
                'Другое' => 'Другое',
            ],
            'event_time_status' => [
                'known' => 'Время известно',
                'unsure' => 'Пока не определились',
            ],
            'guests_range' => [
                'up_to_20' => 'До 20 гостей',
                '20_40' => '20-40 гостей',
                '40_70' => '40-70 гостей',
                '70_100' => '70-100 гостей',
                '100_150' => '100-150 гостей',
                'over_150' => 'Более 150 гостей',
            ],
            'event_format' => [
                'banquet' => 'Торжественный банкет',
                'intimate_dinner' => 'Камерный ужин',
                'buffet' => 'Фуршет',
                'party' => 'Вечеринка',
                'ceremony_banquet' => 'Церемония и банкет',
                'ceremony_only' => 'Только церемония',
                'unsure' => 'Пока не определились',
            ],
            'venue_status' => [
                'booked' => 'Площадка забронирована',
                'options' => 'Есть несколько вариантов',
                'choosing' => 'Пока выбираем',
                'need_help' => 'Нужна помощь с подбором',
            ],
            'venue_type' => [
                'restaurant' => 'Ресторан',
                'countryside' => 'Загородная площадка',
                'tent' => 'Шатёр',
                'loft' => 'Лофт',
                'estate' => 'Усадьба',
                'outdoor' => 'Открытая площадка',
                'hotel' => 'Отель',
                'private_dining' => 'Камерный зал',
                'other' => 'Другое',
            ],
            'ceremony_planned' => [
                'yes' => 'Да',
                'no' => 'Нет',
                'unsure' => 'Ещё не решили',
            ],
            'ceremony_location' => [
                'outdoors' => 'На улице',
                'indoors' => 'В помещении',
                'unsure' => 'Пока неизвестно',
            ],
            'ceremony_style' => [
                'classic_arch' => 'Классическая арка',
                'asymmetric' => 'Асимметричная композиция',
                'airy' => 'Воздушная конструкция',
                'geometric' => 'Минималистичная геометрия',
                'flower_wall' => 'Цветочная стена',
                'natural' => 'Природная церемония',
                'unsure' => 'Пока не знаем',
            ],
            'visual_styles' => [
                'classic' => 'Классика',
                'minimal' => 'Современный минимализм',
                'romantic' => 'Романтика',
                'garden' => 'Садовая эстетика',
                'italian' => 'Итальянский ужин',
                'colorful' => 'Современная яркая свадьба',
                'editorial' => 'Модная редакционная эстетика',
                'estate' => 'Загородная классика',
                'boho' => 'Бохо',
                'art' => 'Арт-концепция',
                'unsure' => 'Пока не знаем',
            ],
            'mood' => [
                'cozy' => 'Уютное',
                'ceremonial' => 'Торжественное',
                'romantic' => 'Романтичное',
                'light' => 'Лёгкое',
                'natural' => 'Природное',
                'modern' => 'Современное',
                'impressive' => 'Эффектное',
                'unusual' => 'Необычное',
                'minimal' => 'Минималистичное',
                'luxurious' => 'Роскошное',
                'warm' => 'Тёплое',
                'fun' => 'Весёлое',
                'intimate' => 'Камерное',
                'airy' => 'Воздушное',
            ],
            'decor_preferences' => [
                'flowers' => 'Много живых цветов',
                'candles' => 'Свечи',
                'textile' => 'Текстиль',
                'structures' => 'Декоративные конструкции',
                'light' => 'Красивый свет',
                'table_setting' => 'Сервировка',
                'furniture' => 'Необычная мебель',
                'minimalism' => 'Минимализм',
                'greenery' => 'Много зелени',
                'accents' => 'Акцентные детали',
            ],
            'palette' => [
                'milk' => 'Белая и молочная',
                'powder' => 'Пудровая',
                'green' => 'Зелёная',
                'pastel' => 'Пастельная',
                'natural' => 'Бежевая и природная',
                'berry' => 'Ягодная',
                'burgundy' => 'Красная и бордовая',
                'blue' => 'Голубая',
                'colorful' => 'Яркая цветная',
                'dark' => 'Тёмная и драматичная',
                'unsure' => 'Пока не решили',
            ],
            'floristry_styles' => [
                'airy' => 'Воздушная',
                'lush' => 'Пышная',
                'greenery' => 'С зеленью',
                'minimal' => 'Минималистичная',
                'meadow' => 'Полевая и естественная',
                'mono' => 'Монокомпозиции',
                'colorful' => 'Яркая',
                'pastel' => 'Нежная пастельная',
                'unsure' => 'Пока не знаем',
            ],
            'floristry_volume' => [
                'minimal' => 'Минимально',
                'moderate' => 'Умеренно',
                'lots' => 'Много',
                'maximal' => 'Максимально эффектно',
                'unsure' => 'Пока не знаем',
            ],
            'lighting_preferences' => [
                'many_candles' => 'Много свечей',
                'some_candles' => 'Немного свечей',
                'garlands' => 'Гирлянды и лампы',
                'decorative_light' => 'Декоративный свет',
                'no_candles' => 'Без свечей',
                'unsure' => 'Пока не знаем',
            ],
            'decor_zones' => [
                'ceremony' => 'Зона церемонии',
                'presidium' => 'Президиум',
                'guest_tables' => 'Столы гостей',
                'welcome' => 'Встреча гостей',
                'seating_plan' => 'План рассадки',
                'photo_zone' => 'Фотозона',
                'cake' => 'Зона торта',
                'entrance' => 'Входная группа',
                'stairs' => 'Лестница',
                'stage' => 'Сцена',
                'lounge' => 'Зона отдыха',
                'bar' => 'Бар',
                'ceiling' => 'Потолочное оформление',
                'navigation' => 'Навигация',
                'other' => 'Другое',
            ],
            'table_layout' => [
                'round' => 'Круглые столы',
                'rectangular' => 'Прямоугольные столы',
                'long_table' => 'Длинный общий стол',
                'mixed' => 'Смешанная рассадка',
                'unsure' => 'Пока неизвестно',
            ],
            'table_decor' => [
                'tall_flowers' => 'Высокие цветочные композиции',
                'low_flowers' => 'Низкие цветочные композиции',
                'single_flowers' => 'Одиночные цветы',
                'candles' => 'Свечи',
                'fruit' => 'Фрукты',
                'textile' => 'Текстиль',
                'tableware' => 'Декоративная посуда',
                'minimal' => 'Минималистичная сервировка',
            ],
            'presidium_style' => [
                'floral' => 'Цветочный',
                'minimal' => 'Минималистичный',
                'backdrop' => 'С фоном или конструкцией',
                'no_backdrop' => 'Без отдельного фона',
                'couple_only' => 'Стол только для пары',
                'shared_table' => 'Общий стол с гостями',
                'unsure' => 'Пока не знаем',
            ],
            'photo_zone_style' => [
                'floral' => 'Цветочная',
                'textile' => 'Тканевая',
                'structural' => 'Конструктивная',
                'minimal' => 'Минималистичная',
                'branded' => 'Брендированная',
                'art' => 'Необычная арт-зона',
                'unsure' => 'Пока не знаем',
            ],
            'stationery' => [
                'invitations' => 'Приглашения',
                'menu' => 'Меню',
                'place_cards' => 'Карточки рассадки',
                'table_numbers' => 'Номера столов',
                'seating_plan' => 'План рассадки',
                'welcome_sign' => 'Приветственная табличка',
                'guest_cards' => 'Индивидуальные карточки гостей',
                'none' => 'Не нужны',
                'unsure' => 'Пока не решили',
            ],
            'services_needed' => self::coreServices(),
            'already_booked' => [
                'venue' => 'Площадка',
                'host' => 'Ведущий',
                'photographer' => 'Фотограф',
                'videographer' => 'Видеограф',
                'planner' => 'Организатор',
                'decorator' => 'Декоратор',
                'florist' => 'Флорист',
                'technical_team' => 'Техническая команда',
                'nothing' => 'Пока ничего',
                'other' => 'Другое',
            ],
            'planning_stage' => [
                'starting' => 'Только начинаем',
                'date_selected' => 'Выбрали дату',
                'venue_selected' => 'Уже есть площадка',
                'contractors_selected' => 'Большая часть подрядчиков найдена',
                'decor_only' => 'Нужен только декор',
                'coordinator_only' => 'Нужен только координатор',
                'separate_tasks' => 'Почти всё готово, нужна помощь с отдельными задачами',
            ],
            'budget_range' => self::TOTAL_BUDGETS,
            'decor_budget' => self::TOTAL_BUDGETS,
            'budget_priorities' => [
                'floristry' => 'Флористика',
                'ceremony' => 'Церемония',
                'banquet_hall' => 'Банкетный зал',
                'table_setting' => 'Сервировка',
                'light' => 'Свет',
                'photo_zone' => 'Фотозона',
                'details' => 'Детали',
                'guest_comfort' => 'Комфорт гостей',
                'planning' => 'Организация',
                'unsure' => 'Пока не знаем',
            ],
            'budget_flexibility' => [
                'fixed' => 'Бюджет фиксированный',
                'small_adjustment' => 'Возможна небольшая корректировка',
                'important_details' => 'Готовы увеличить ради важных деталей',
                'show_options' => 'Сначала хотим увидеть варианты',
            ],
            'contact_method' => [
                'site_chat' => 'Чат на сайте',
                'telegram' => 'Сообщение в Telegram',
                'whatsapp' => 'Сообщение в WhatsApp',
                'call' => 'Телефонный звонок',
                'email' => 'Электронная почта',
            ],
            'contact_time' => [
                'morning' => 'Утром',
                'day' => 'Днём',
                'evening' => 'Вечером',
                'any' => 'В любое время',
            ],
            'source' => [
                'search' => 'Поиск Яндекса',
                'social' => 'Социальные сети',
                'telegram' => 'Telegram',
                'recommendation' => 'Рекомендация друзей',
                'partner' => 'Площадка или подрядчик',
                'advertising' => 'Реклама',
                'other' => 'Другое',
            ],
        ];
    }

    public static function coreServices(): array {
        return [
            'организация под ключ' => 'Организация под ключ',
            'декор' => 'Декор',
            'флористика' => 'Флористика',
            'координация' => 'Координация дня',
            'выездная церемония' => 'Оформление церемонии',
            'монтаж' => 'Монтаж и демонтаж',
            'другое' => 'Нужна консультация',
        ];
    }

    public static function multipleLimits(): array {
        return [
            'visual_styles' => 3,
            'mood' => 4,
            'decor_preferences' => 5,
            'floristry_styles' => 3,
            'lighting_preferences' => 3,
            'decor_zones' => 15,
            'table_decor' => 6,
            'stationery' => 8,
            'services_needed' => 7,
            'already_booked' => 9,
            'budget_priorities' => 3,
        ];
    }

    public static function textLimits(): array {
        return [
            'client_name' => 190,
            'phone' => 40,
            'email' => 190,
            'telegram_username' => 120,
            'event_type_other' => 120,
            'event_date' => 10,
            'event_time' => 8,
            'guests_count' => 5,
            'location_city' => 120,
            'venue_name' => 190,
            'venue_address' => 255,
            'venue_url' => 500,
            'venue_options' => 1200,
            'custom_colors' => 255,
            'excluded_colors' => 800,
            'already_booked_other' => 500,
            'main_priority' => 3000,
            'dislikes' => 3000,
            'comment' => 5000,
            'contact_time_comment' => 500,
            'source_other' => 190,
        ];
    }

    public static function answerKeys(): array {
        return [
            'client_name', 'phone', 'email', 'telegram_username', 'contact_method',
            'contact_time', 'contact_time_comment', 'event_type', 'event_type_other',
            'event_date', 'event_time', 'event_time_status', 'guests_range', 'guests_count',
            'event_format', 'services_needed', 'venue_status', 'venue_name', 'venue_address',
            'venue_url', 'venue_options', 'location_city', 'venue_type', 'ceremony_planned',
            'ceremony_location', 'ceremony_style', 'visual_styles', 'mood', 'decor_preferences',
            'palette', 'custom_colors', 'excluded_colors', 'color_palette', 'floristry_styles',
            'floristry_volume', 'lighting_preferences', 'decor_zones', 'table_layout',
            'table_decor', 'presidium_style', 'photo_zone_style', 'stationery', 'already_booked',
            'already_booked_other', 'planning_stage', 'budget_range', 'decor_budget',
            'budget_priorities', 'budget_flexibility', 'main_priority', 'dislikes', 'comment',
            'source', 'source_other', 'style', 'urgency',
        ];
    }

    public static function labels(): array {
        return [
            'client_name' => 'Имя клиента', 'phone' => 'Телефон', 'email' => 'Электронная почта',
            'telegram_username' => 'Telegram', 'contact_method' => 'Удобный способ связи',
            'contact_time' => 'Когда связаться', 'contact_time_comment' => 'Комментарий ко времени связи',
            'event_type' => 'Тип события', 'event_type_other' => 'Какое именно событие',
            'event_date' => 'Дата события', 'event_time' => 'Время начала',
            'event_time_status' => 'Определились со временем', 'guests_range' => 'Диапазон гостей',
            'guests_count' => 'Точное число гостей', 'event_format' => 'Формат события',
            'services_needed' => 'Какие услуги нужны', 'venue_status' => 'Статус площадки',
            'venue_name' => 'Название площадки', 'venue_address' => 'Адрес площадки',
            'venue_url' => 'Ссылка на площадку', 'venue_options' => 'Варианты площадок',
            'location_city' => 'Город или район', 'venue_type' => 'Тип пространства',
            'ceremony_planned' => 'Выездная церемония', 'ceremony_location' => 'Где будет церемония',
            'ceremony_style' => 'Оформление церемонии', 'visual_styles' => 'Визуальное направление',
            'mood' => 'Атмосфера', 'decor_preferences' => 'Что нравится в оформлении',
            'palette' => 'Палитра', 'custom_colors' => 'Конкретные цвета',
            'excluded_colors' => 'Исключить цвета', 'color_palette' => 'Цветовая палитра',
            'floristry_styles' => 'Стиль флористики', 'floristry_volume' => 'Объём цветов',
            'lighting_preferences' => 'Свечи и декоративный свет', 'decor_zones' => 'Зоны оформления',
            'table_layout' => 'Рассадка', 'table_decor' => 'Оформление столов',
            'presidium_style' => 'Формат президиума', 'photo_zone_style' => 'Формат фотозоны',
            'stationery' => 'Печатное оформление', 'already_booked' => 'Что уже готово',
            'already_booked_other' => 'Что ещё уже готово', 'planning_stage' => 'Этап подготовки',
            'budget_range' => 'Общий бюджет', 'decor_budget' => 'Бюджет на декор и флористику',
            'budget_priorities' => 'На чём не экономить', 'budget_flexibility' => 'Гибкость бюджета',
            'main_priority' => 'Самое важное', 'dislikes' => 'Чего точно не должно быть',
            'comment' => 'Дополнительные пожелания', 'source' => 'Как узнали о нас',
            'source_other' => 'Другой источник', 'style' => 'Стиль события',
            'urgency' => 'Срочность подготовки',
        ];
    }

    public static function sections(): array {
        return [
            'event' => ['title' => 'Событие', 'keys' => [
                'event_type', 'event_type_other', 'event_date', 'event_time', 'guests_range',
                'guests_count', 'event_format', 'planning_stage',
            ]],
            'venue' => ['title' => 'Площадка и церемония', 'keys' => [
                'venue_status', 'venue_name', 'venue_address', 'venue_url', 'venue_options',
                'location_city', 'venue_type', 'ceremony_planned', 'ceremony_location', 'ceremony_style',
            ]],
            'visual' => ['title' => 'Визуальное направление', 'keys' => [
                'visual_styles', 'mood', 'decor_preferences',
            ]],
            'color' => ['title' => 'Цвет и флористика', 'keys' => [
                'palette', 'custom_colors', 'excluded_colors', 'floristry_styles',
                'floristry_volume', 'lighting_preferences',
            ]],
            'zones' => ['title' => 'Зоны и детали', 'keys' => [
                'decor_zones', 'table_layout', 'table_decor', 'presidium_style',
                'photo_zone_style', 'stationery',
            ]],
            'scope' => ['title' => 'Состав работ и бюджет', 'keys' => [
                'services_needed', 'already_booked', 'already_booked_other', 'budget_range',
                'decor_budget', 'budget_priorities', 'budget_flexibility',
            ]],
            'priorities' => ['title' => 'Пожелания и контакты', 'keys' => [
                'main_priority', 'dislikes', 'comment', 'contact_method', 'contact_time',
                'contact_time_comment', 'source', 'source_other',
            ]],
        ];
    }

    public static function visualAssets(): array {
        return [
            'visual_styles' => [
                'classic' => 'styles/classic', 'minimal' => 'styles/minimal',
                'romantic' => 'styles/romantic', 'garden' => 'styles/garden',
                'italian' => 'styles/italian', 'colorful' => 'styles/colorful',
                'editorial' => 'styles/editorial', 'estate' => 'styles/estate',
                'boho' => 'styles/boho', 'art' => 'styles/art',
            ],
            'venue_type' => [
                'restaurant' => 'venues/restaurant', 'countryside' => 'venues/countryside',
                'tent' => 'venues/tent', 'loft' => 'venues/loft', 'estate' => 'venues/estate',
                'outdoor' => 'venues/outdoor', 'hotel' => 'venues/hotel',
                'private_dining' => 'venues/private-dining',
            ],
            'ceremony_style' => [
                'classic_arch' => 'ceremonies/classic-arch', 'asymmetric' => 'ceremonies/asymmetric',
                'airy' => 'ceremonies/airy', 'geometric' => 'ceremonies/geometric',
                'flower_wall' => 'ceremonies/flower-wall', 'natural' => 'ceremonies/natural',
            ],
            'floristry_styles' => [
                'airy' => 'floristry/airy', 'lush' => 'floristry/lush',
                'greenery' => 'floristry/greenery', 'minimal' => 'floristry/minimal',
                'meadow' => 'floristry/meadow', 'mono' => 'floristry/mono',
                'colorful' => 'floristry/colorful', 'pastel' => 'floristry/pastel',
            ],
            'decor_preferences' => [
                'flowers' => 'floristry/lush', 'candles' => 'styles/italian',
                'textile' => 'styles/romantic', 'structures' => 'ceremonies/geometric',
                'light' => 'ceremonies/airy', 'table_setting' => 'venues/private-dining',
                'furniture' => 'venues/loft', 'minimalism' => 'styles/minimal',
                'greenery' => 'floristry/greenery', 'accents' => 'styles/colorful',
            ],
            'lighting_preferences' => [
                'many_candles' => 'styles/italian', 'some_candles' => 'styles/romantic',
                'garlands' => 'venues/tent', 'decorative_light' => 'venues/loft',
            ],
            'table_decor' => [
                'tall_flowers' => 'floristry/lush', 'low_flowers' => 'floristry/airy',
                'single_flowers' => 'floristry/minimal', 'candles' => 'styles/italian',
                'fruit' => 'styles/italian', 'textile' => 'styles/romantic',
                'tableware' => 'venues/private-dining', 'minimal' => 'floristry/minimal',
            ],
            'presidium_style' => [
                'floral' => 'floristry/lush', 'minimal' => 'styles/minimal',
                'backdrop' => 'ceremonies/geometric', 'no_backdrop' => 'venues/private-dining',
                'couple_only' => 'styles/classic', 'shared_table' => 'styles/italian',
            ],
            'photo_zone_style' => [
                'floral' => 'floristry/lush', 'textile' => 'styles/romantic',
                'structural' => 'ceremonies/geometric', 'minimal' => 'styles/minimal',
                'branded' => 'styles/editorial', 'art' => 'styles/art',
            ],
        ];
    }

    public static function paletteMeta(): array {
        return [
            'milk' => ['#fbf8f1', '#eee7db', '#d7c8b5', '#ffffff'],
            'powder' => ['#f3dfdf', '#dcb8bb', '#b9868e', '#fff5f1'],
            'green' => ['#edf0e3', '#a9b792', '#667859', '#34472f'],
            'pastel' => ['#f2dce5', '#d8e5ec', '#e8e2c4', '#d8cfe8'],
            'natural' => ['#eee8dc', '#cdbda6', '#9c8569', '#69725c'],
            'berry' => ['#efe0e3', '#bd6e7f', '#8a2432', '#5a233b'],
            'burgundy' => ['#ead9d7', '#b85d54', '#7d2529', '#401a1d'],
            'blue' => ['#eef3f4', '#b8d2df', '#7897aa', '#455e70'],
            'colorful' => ['#f4c849', '#e76f51', '#d64e86', '#4c8c83'],
            'dark' => ['#c4a684', '#66545a', '#34282d', '#171316'],
        ];
    }

    public static function normalizeSubmission(array $data, bool $telegram = false): array {
        $sets = self::optionSets();
        $normalized = [];
        foreach (self::textLimits() as $key => $limit) {
            if (array_key_exists($key, $data) && !is_array($data[$key])) {
                $normalized[$key] = self::truncate(trim((string)$data[$key]), $limit);
            }
        }
        foreach ($sets as $key => $options) {
            if (isset(self::multipleLimits()[$key])) {
                $values = is_array($data[$key] ?? null) ? $data[$key] : [$data[$key] ?? ''];
                $values = array_values(array_unique(array_filter(array_map('strval', $values))));
                $values = array_values(array_intersect($values, array_keys($options)));
                if (in_array('unsure', $values, true)) {
                    $values = ['unsure'];
                } elseif (in_array('none', $values, true)) {
                    $values = ['none'];
                } elseif (in_array('nothing', $values, true)) {
                    $values = ['nothing'];
                }
                $normalized[$key] = array_slice($values, 0, self::multipleLimits()[$key]);
            } elseif (array_key_exists($key, $data) && !is_array($data[$key])) {
                $value = (string)$data[$key];
                if (array_key_exists($value, $options)) {
                    $normalized[$key] = $value;
                }
            }
        }
        $normalized['privacy_consent'] = empty($data['privacy_consent']) ? '' : '1';
        $normalized['event_type'] = self::normalizedEventType($data, $normalized, $telegram);
        $normalized['budget_range'] = self::normalizedBudget($data, $normalized, 'budget_range', $telegram);
        foreach (['style' => 190, 'color_palette' => 255, 'urgency' => 40] as $key => $limit) {
            if ($telegram && isset($data[$key]) && !is_array($data[$key])) {
                $normalized[$key] = self::truncate(trim((string)$data[$key]), $limit);
            }
        }
        if ($telegram && ($data['source'] ?? '') === 'telegram_bot') {
            $normalized['source'] = 'telegram_bot';
        }
        if (!$telegram) {
            $styleLabels = self::translatedValues('visual_styles', $normalized['visual_styles'] ?? []);
            $normalized['style'] = self::truncate(implode(', ', $styleLabels), 190);
            $normalized['color_palette'] = $normalized['custom_colors'] ?? '';
            if ($normalized['color_palette'] === '' && !empty($normalized['palette'])) {
                $normalized['color_palette'] = $sets['palette'][$normalized['palette']] ?? '';
            }
            $normalized['urgency'] = 'planning';
        }
        $normalized['venue'] = $normalized['venue_name'] ?? self::truncate(trim((string)($data['venue'] ?? '')), 190);
        $normalized['location_details'] = $normalized['venue_address']
            ?? self::truncate(trim((string)($data['location_details'] ?? '')), 500);
        if (!$telegram) {
            $normalized = self::clearConditionalValues($normalized);
        }
        foreach (['submission_key', 'draft_token', 'referer_url', 'csrf_token'] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) {
                $normalized[$key] = self::truncate((string)$data[$key], $key === 'referer_url' ? 500 : 128);
            }
        }
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) {
                $normalized[$key] = self::truncate(trim((string)$data[$key]), 190);
            }
        }
        if (!empty($data['_telegram_identity_verified'])) {
            $normalized['_telegram_identity_verified'] = true;
        }
        return $normalized;
    }

    public static function filterDraft(array $data): array {
        $filtered = [];
        foreach (self::textLimits() as $key => $limit) {
            if (array_key_exists($key, $data) && !is_array($data[$key])) {
                $filtered[$key] = self::truncate((string)$data[$key], $limit);
            }
        }
        foreach (self::optionSets() as $key => $options) {
            if (isset(self::multipleLimits()[$key])) {
                $values = is_array($data[$key] ?? null) ? $data[$key] : [$data[$key] ?? ''];
                $values = array_values(array_unique(array_intersect(
                    array_map('strval', $values),
                    array_keys($options)
                )));
                $filtered[$key] = array_slice($values, 0, self::multipleLimits()[$key]);
            } elseif (isset($data[$key]) && !is_array($data[$key])
                && array_key_exists((string)$data[$key], $options)) {
                $filtered[$key] = (string)$data[$key];
            }
        }
        $filtered['privacy_consent'] = empty($data['privacy_consent']) ? '' : '1';
        $filtered['quiz_current_step'] = max(0, min(6, (int)($data['quiz_current_step'] ?? 0)));
        return $filtered;
    }

    public static function translatedValues(string $key, $values): array {
        $options = self::optionSets()[$key] ?? [];
        $items = is_array($values) ? $values : [$values];
        return array_values(array_filter(array_map(
            static fn($value) => $options[(string)$value] ?? trim((string)$value),
            $items
        ), static fn($value) => $value !== ''));
    }

    private static function normalizedEventType(array $data, array $normalized, bool $telegram): string {
        $choice = (string)($normalized['event_type_choice'] ?? '');
        if ($choice !== '') {
            if ($choice === 'Другое') {
                $other = trim((string)($normalized['event_type_other'] ?? ''));
                return $other !== '' ? $other : $choice;
            }
            return $choice;
        }
        $value = self::truncate(trim((string)($data['event_type'] ?? '')), 120);
        $telegramTypes = [
            'Свадьба', 'Камерная свадьба', 'Камерное мероприятие', 'Выездная церемония',
            'День рождения', 'Юбилей', 'Корпоратив', 'Частный ужин', 'Выпускной', 'Другое',
        ];
        return ($telegram && in_array($value, $telegramTypes, true)) ? $value : '';
    }

    private static function normalizedBudget(array $data, array $normalized, string $key, bool $telegram): string {
        $value = (string)($normalized[$key] ?? '');
        if ($value !== '') {
            return self::TOTAL_BUDGETS[$value] ?? $value;
        }
        $rawValue = self::truncate(trim((string)($data[$key] ?? '')), 120);
        if (in_array($rawValue, self::TOTAL_BUDGETS, true)) {
            return $rawValue;
        }
        return $telegram ? $rawValue : '';
    }

    private static function clearConditionalValues(array $data): array {
        $weddingTypes = ['Свадьба', 'Камерная свадьба', 'Выездная церемония'];
        if (!in_array($data['event_type'] ?? '', $weddingTypes, true)) {
            foreach (['ceremony_planned', 'ceremony_location', 'ceremony_style'] as $key) {
                unset($data[$key]);
            }
        } elseif (($data['ceremony_planned'] ?? '') !== 'yes') {
            unset($data['ceremony_location'], $data['ceremony_style']);
        }
        if (($data['event_time_status'] ?? '') !== 'known') {
            $data['event_time'] = '';
        }
        if (($data['venue_status'] ?? '') !== 'booked') {
            unset($data['venue_name'], $data['venue_address'], $data['venue_url']);
            $data['venue'] = '';
            $data['location_details'] = '';
        }
        if (($data['venue_status'] ?? '') !== 'options') {
            unset($data['venue_options']);
        }
        $services = $data['services_needed'] ?? [];
        $hasFloralWork = (bool)array_intersect($services, ['декор', 'флористика', 'выездная церемония']);
        if (!$hasFloralWork) {
            foreach (['floristry_styles', 'floristry_volume', 'lighting_preferences', 'decor_budget'] as $key) {
                unset($data[$key]);
            }
        }
        $zones = $data['decor_zones'] ?? [];
        if (!in_array('guest_tables', $zones, true)) {
            unset($data['table_layout'], $data['table_decor']);
        }
        if (!in_array('presidium', $zones, true)) {
            unset($data['presidium_style']);
        }
        if (!in_array('photo_zone', $zones, true)) {
            unset($data['photo_zone_style']);
        }
        if (!in_array('other', $data['already_booked'] ?? [], true)) {
            unset($data['already_booked_other']);
        }
        if (($data['source'] ?? '') !== 'other') {
            unset($data['source_other']);
        }
        return $data;
    }

    private static function truncate(string $value, int $limit): string {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $limit, 'UTF-8')
            : substr($value, 0, $limit);
    }
}
