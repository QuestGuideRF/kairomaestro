<?php
require_once __DIR__ . '/config/env.php';
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
$baseUrl = 'https://azbukadecor.ru';
$routes = [
    ['path' => '/', 'changefreq' => 'weekly', 'priority' => '1.0'],
    ['path' => '/services', 'changefreq' => 'monthly', 'priority' => '0.9'],
    ['path' => '/services/svadba-pod-klyuch', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['path' => '/services/dekor-i-floristika', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['path' => '/services/koordinatsiya-dnya', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['path' => '/services/kamernoe-meropriyatie', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['path' => '/services/vyezdnaya-tseremoniya', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['path' => '/services/montazh-i-demontazh', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['path' => '/portfolio', 'changefreq' => 'monthly', 'priority' => '0.9'],
    ['path' => '/portfolio/zelenaya-floristicheskaya-stsena', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['path' => '/portfolio/pudrovyy-yubileynyy-uzhin', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['path' => '/portfolio/belaya-tseremoniya-yagodnyy-banket', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['path' => '/stages', 'changefreq' => 'monthly', 'priority' => '0.7'],
    ['path' => '/about', 'changefreq' => 'monthly', 'priority' => '0.7'],
    ['path' => '/contacts', 'changefreq' => 'monthly', 'priority' => '0.7'],
    ['path' => '/careers', 'changefreq' => 'monthly', 'priority' => '0.5'],
    ['path' => '/privacy', 'changefreq' => 'yearly', 'priority' => '0.3'],
    ['path' => '/cookies', 'changefreq' => 'yearly', 'priority' => '0.3'],
    ['path' => '/quiz', 'changefreq' => 'monthly', 'priority' => '0.8'],
];
$removedPortfolioSlugs = [
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
if (class_exists('PDO')) {
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string)env('DB_HOST', 'localhost'),
            (int)env('DB_PORT', 3306),
            (string)env('DB_NAME', ''),
            (string)env('DB_CHARSET', 'utf8mb4')
        );
        $pdo = new PDO($dsn, (string)env('DB_USER', ''), (string)env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 2,
        ]);
        foreach ($pdo->query('SELECT slug FROM services WHERE is_active = 1') as $row) {
            $slug = trim((string)($row['slug'] ?? ''));
            if (preg_match('/^(?=.*[a-z])[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                $routes[] = ['path' => '/services/' . $slug, 'changefreq' => 'monthly', 'priority' => '0.8'];
            }
        }
        foreach ($pdo->query('SELECT slug FROM portfolio_projects WHERE is_published = 1') as $row) {
            $slug = trim((string)($row['slug'] ?? ''));
            if (
                preg_match('/^(?=.*[a-z])[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)
                && !in_array($slug, $removedPortfolioSlugs, true)
            ) {
                $routes[] = ['path' => '/portfolio/' . $slug, 'changefreq' => 'monthly', 'priority' => '0.8'];
            }
        }
    } catch (Throwable $exception) {
        error_log('Azbuka Decora sitemap: database content is unavailable; static routes were used.');
    }
}
$uniqueRoutes = [];
foreach ($routes as $route) {
    $uniqueRoutes[$route['path']] = $route;
}
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($uniqueRoutes as $route) {
    $url = $baseUrl . ($route['path'] === '/' ? '/' : $route['path']);
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
    echo '    <changefreq>' . $route['changefreq'] . "</changefreq>\n";
    echo '    <priority>' . $route['priority'] . "</priority>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";
