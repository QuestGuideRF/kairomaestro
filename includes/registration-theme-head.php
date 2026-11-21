<?php
$themePictureSrcset = static function (string $base, array $widths, string $extension): string {
    return implode(', ', array_map(
        static fn (int $width): string => asset($base . '-' . $width . '.' . $extension) . ' ' . $width . 'w',
        $widths
    ));
};

$themePictureConfig ??= [
    'light' => [
        'base' => 'assets/images/auth/registration-light',
        'widths' => [480, 768, 1122],
        'fallback_width' => 1122,
        'width' => 1122,
        'height' => 1402,
        'sizes' => '(max-width: 900px) 100vw, 55vw',
    ],
    'dark' => [
        'base' => 'assets/images/auth/registration-dark',
        'widths' => [480, 768, 960, 1440, 1672],
        'fallback_width' => 1672,
        'width' => 1672,
        'height' => 941,
        'sizes' => '(max-width: 900px) 100vw, max(55vw, 178vh)',
    ],
];

$themePictureImages = [];
foreach ($themePictureConfig as $themeName => $imageConfig) {
    $base = $imageConfig['base'];
    $fallbackWidth = $imageConfig['fallback_width'];
    $themePictureImages[$themeName] = [
        'avif' => $themePictureSrcset($base, $imageConfig['widths'], 'avif'),
        'webp' => $themePictureSrcset($base, $imageConfig['widths'], 'webp'),
        'fallback' => asset($base . '-' . $fallbackWidth . '.webp'),
        'preload' => asset($base . '-' . $fallbackWidth . '.avif'),
        'sizes' => $imageConfig['sizes'],
        'width' => $imageConfig['width'],
        'height' => $imageConfig['height'],
    ];
}
?>
<script>
    (function (images) {
        var theme = 'light';
        try {
            var savedTheme = localStorage.getItem('theme');
            var systemTheme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                ? 'dark'
                : 'light';
            theme = savedTheme === 'dark' || savedTheme === 'light' ? savedTheme : systemTheme;
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
                source.setAttribute('sizes', source.getAttribute('data-' + activeTheme + '-sizes'));
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

        var selectedImage = images[theme];
        var preload = document.createElement('link');
        preload.rel = 'preload';
        preload.as = 'image';
        preload.type = 'image/avif';
        preload.href = selectedImage.preload;
        preload.setAttribute('imagesrcset', selectedImage.avif);
        preload.setAttribute('imagesizes', selectedImage.sizes);
        preload.setAttribute('fetchpriority', 'high');
        document.head.appendChild(preload);
    })(<?php echo json_encode($themePictureImages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>);
</script>
