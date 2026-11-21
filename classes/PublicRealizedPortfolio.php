<?php
final class PublicRealizedPortfolio
{
    public static function items(): array
    {
        return [
            self::greenFloralScene(),
            self::powderJubilee(),
            self::whiteCeremony(),
        ];
    }

    private static function greenFloralScene(): array
    {
        return [
            'slug' => 'zelenaya-floristicheskaya-stsena',
            'title' => 'Флористическая сцена в зелёных тонах',
            'style' => 'ботаническая флористика',
            'location' => '',
            'guests' => 0,
            'image_base' => 'assets/images/portfolio/portfolio-green-floral-scene-cover',
            'width' => 1500,
            'height' => 1000,
            'alt' => 'Зелёные и белые цветочные композиции на фоне белой драпировки',
            'text' => 'Белый текстиль, высокая зелень, лаймовые соцветия и лёгкие '
                . 'белые цветы собраны в асимметричную интерьерную композицию.',
            'gallery' => [
                [
                    'image_base' => 'assets/images/portfolio/portfolio-green-floral-scene-01',
                    'width' => 900,
                    'height' => 1600,
                    'alt' => 'Общий вид флористической сцены с белой драпировкой',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-green-floral-scene-02',
                    'width' => 900,
                    'height' => 1600,
                    'alt' => 'Левая композиция с высокой зеленью и белыми цветами',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-green-floral-scene-03',
                    'width' => 900,
                    'height' => 1600,
                    'alt' => 'Лаймовые соцветия и воздушные травы в цветочной композиции',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-green-floral-scene-04',
                    'width' => 900,
                    'height' => 1600,
                    'alt' => 'Асимметричная флористика на фоне белого текстиля и дерева',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-green-floral-scene-05',
                    'width' => 900,
                    'height' => 1600,
                    'alt' => 'Боковой ракурс зелёной флористической сцены',
                ],
            ],
            'is_reference' => false,
        ];
    }

    private static function powderJubilee(): array
    {
        return [
            'slug' => 'pudrovyy-yubileynyy-uzhin',
            'title' => 'Юбилейный ужин в пудровой палитре',
            'style' => 'пудровая классика',
            'location' => '',
            'guests' => 0,
            'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-cover',
            'width' => 1500,
            'height' => 1000,
            'alt' => 'Длинный юбилейный стол с пудровой флористикой и свечами',
            'text' => 'Длинный стол с воздушными цветами, пудровым текстилем, '
                . 'тонкими свечами и мерцающим юбилейным фоном.',
            'gallery' => [
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-01',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Юбилейный стол и мерцающий фон с числом 50',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-02',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Пудровая цветочная композиция у юбилейного фона',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-03',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Пудровый текстиль, жемчужные нити, цветы и свеча на столе',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-04',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Флористическая линия вдоль длинного юбилейного стола',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-05',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Сервировка длинного стола в белых и пудровых оттенках',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-06',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Индивидуальная сервировка с пудровой салфеткой и цветами',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-07',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Свечи и воздушная флористика в центре банкетного стола',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-08',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Общий вид юбилейного стола с рядами свечей',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-09',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Тарелка с пудровой салфеткой и карточкой гостя',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-10',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Розовая свеча и нежные цветы в деталях сервировки',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-11',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Белая скатерть, пудровые салфетки и цветочная линия стола',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-12',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Юбилейный фон за длинным оформленным столом',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-13',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Ритм цветов, свечей и бокалов вдоль банкетного стола',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-14',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Полная сервировка юбилейного стола в пудровой гамме',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-15',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Длинный стол и мерцающий акцентный фон',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-16',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Цветочные композиции и жемчужные детали крупным планом',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-powder-jubilee-table-17',
                    'width' => 1200,
                    'height' => 1600,
                    'alt' => 'Нежная флористика на фоне розовых мерцающих панелей',
                ],
            ],
            'is_reference' => false,
        ];
    }

    private static function whiteCeremony(): array
    {
        return [
            'slug' => 'belaya-tseremoniya-yagodnyy-banket',
            'title' => 'Белая церемония и ягодный банкет',
            'style' => 'контрастная флористика',
            'location' => '',
            'guests' => 0,
            'image_base' => 'assets/images/portfolio/portfolio-white-ceremony-berry-banquet-cover',
            'width' => 720,
            'height' => 480,
            'alt' => 'Банкетный стол с бордовой флористикой, фруктами и цветным стеклом',
            'text' => 'Спокойная белая церемония переходит в выразительный банкет '
                . 'с бордовыми цветами, фруктами и цветным стеклом.',
            'gallery' => [
                [
                    'image_base' => 'assets/images/portfolio/portfolio-white-ceremony-berry-banquet-01',
                    'width' => 720,
                    'height' => 1280,
                    'alt' => 'Белый банкетный стол с бордовыми цветами и гранатами',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-white-ceremony-berry-banquet-02',
                    'width' => 720,
                    'height' => 1280,
                    'alt' => 'Высокая красно-оранжевая композиция в центре стола',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-white-ceremony-berry-banquet-03',
                    'width' => 720,
                    'height' => 1280,
                    'alt' => 'Бордовая и оранжевая флористика на белом текстиле',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-white-ceremony-berry-banquet-04',
                    'width' => 720,
                    'height' => 1280,
                    'alt' => 'Длинная банкетная композиция с красным стеклом и фруктами',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-white-ceremony-berry-banquet-05',
                    'width' => 720,
                    'height' => 1280,
                    'alt' => 'Фрукты и выразительные красные цветы в сервировке',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-white-ceremony-berry-banquet-06',
                    'width' => 720,
                    'height' => 1280,
                    'alt' => 'Высокая ягодно-оранжевая флористическая композиция',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-white-ceremony-berry-banquet-07',
                    'width' => 720,
                    'height' => 1280,
                    'alt' => 'Бордовые розы и фрукты в настольной композиции',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-white-ceremony-berry-banquet-08',
                    'width' => 720,
                    'height' => 1280,
                    'alt' => 'Белая церемониальная драпировка и цветы среди деревьев',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-white-ceremony-berry-banquet-09',
                    'width' => 720,
                    'height' => 1280,
                    'alt' => 'Невеста с объёмным белым свадебным букетом',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-white-ceremony-berry-banquet-10',
                    'width' => 736,
                    'height' => 920,
                    'alt' => 'Белый букет из роз, ранункулюсов и воздушных цветов',
                ],
                [
                    'image_base' => 'assets/images/portfolio/portfolio-white-ceremony-berry-banquet-11',
                    'width' => 720,
                    'height' => 1280,
                    'alt' => 'Белый свадебный букет с антуриумами и орхидеями',
                ],
            ],
            'is_reference' => false,
        ];
    }
}
