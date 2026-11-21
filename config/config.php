<?php
require_once __DIR__ . '/env.php';
defined('APP_ENV') || define('APP_ENV', env('APP_ENV', 'production'));
defined('APP_DEBUG') || define('APP_DEBUG', (bool)env('APP_DEBUG', false));
defined('APP_KEY') || define('APP_KEY', (string)env('APP_KEY', ''));
defined('SITE_NAME') || define('SITE_NAME', env('SITE_NAME', 'Азбука Декора'));
$configuredAppUrl = rtrim(trim((string)env('APP_URL', '')), '/');
defined('SITE_URL') || define('SITE_URL', $configuredAppUrl !== '' ? $configuredAppUrl : 'http://localhost');
$canonicalHost = strtolower((string)parse_url(SITE_URL, PHP_URL_HOST));
if ($canonicalHost === 'www.azbukadecor.ru') {
    $canonicalHost = 'azbukadecor.ru';
}
$requestHost = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
if (PHP_SAPI !== 'cli' && $canonicalHost !== '' && $requestHost === 'www.' . $canonicalHost) {
    $canonicalScheme = strtolower((string)parse_url(SITE_URL, PHP_URL_SCHEME)) === 'http' ? 'http' : 'https';
    $requestUri = str_replace(["\r", "\n"], '', (string)($_SERVER['REQUEST_URI'] ?? '/'));
    header('Location: ' . $canonicalScheme . '://' . $canonicalHost . ($requestUri ?: '/'), true, 301);
    exit;
}
defined('SITE_DESCRIPTION') || define(
    'SITE_DESCRIPTION',
    env(
        'SITE_DESCRIPTION',
        'Организуем свадьбы и камерные события в Москве и Подмосковье: '
            . 'разрабатываем концепцию, декор и флористику, координируем команду '
            . 'и монтаж без лишней суеты.'
    )
);
$configuredSiteKeywords = trim((string)env(
    'SITE_KEYWORDS',
    'Азбука Декора, организация свадеб Москва, свадьба под ключ, '
        . 'свадебное агентство, декор свадьбы, свадебная флористика, '
        . 'оформление свадеб, выездная церемония, координация свадьбы, '
        . 'свадебный декор Подмосковье, камерные мероприятия, '
        . 'организация свадьбы, оформление свадьбы Москва, '
        . 'декор мероприятий Москва, флористика на свадьбу, '
        . 'свадебный координатор, организация частных мероприятий'
));
$siteKeywordAliases = 'Азбука Декора официальный сайт, Азбука Декора Москва, '
    . 'Азбука Декор, Аздука Декора, Азбука Дикора, '
    . 'Azbuka Dekora, Azbuka Decor, azbukadecor';
defined('SITE_KEYWORDS') || define(
    'SITE_KEYWORDS',
    rtrim($configuredSiteKeywords, ', ') . ', ' . $siteKeywordAliases
);
defined('PUBLIC_PHONE') || define('PUBLIC_PHONE', trim((string)env('PUBLIC_PHONE', '')));
defined('PUBLIC_EMAIL') || define('PUBLIC_EMAIL', trim((string)env('PUBLIC_EMAIL', '')));
defined('PUBLIC_LEGAL_NAME') || define('PUBLIC_LEGAL_NAME', trim((string)env('PUBLIC_LEGAL_NAME', '')));
defined('PUBLIC_INN') || define('PUBLIC_INN', trim((string)env('PUBLIC_INN', '')));
defined('PRIVACY_POLICY_VERSION') || define(
    'PRIVACY_POLICY_VERSION',
    trim((string)env('PRIVACY_POLICY_VERSION', '2026-08-04'))
);
defined('UPLOAD_DIR') || define('UPLOAD_DIR', __DIR__ . '/../uploads/');
defined('UPLOAD_URL') || define('UPLOAD_URL', SITE_URL . '/uploads/');
defined('UPLOAD_LIMIT_BYTES') || define('UPLOAD_LIMIT_BYTES', (int)env('UPLOAD_LIMIT', 10485760));
defined('SESSION_LIFETIME') || define('SESSION_LIFETIME', (int)env('SESSION_LIFETIME', 2592000));
defined('STORAGE_DIR') || define('STORAGE_DIR', __DIR__ . '/../tmp/');
defined('SESSION_DIR') || define(
    'SESSION_DIR',
    rtrim((string)(env('SESSION_SAVE_PATH', '') ?: STORAGE_DIR . 'sessions'), '/\\') . DIRECTORY_SEPARATOR
);
defined('LOGIN_PATH') || define('LOGIN_PATH', '/login.php');
date_default_timezone_set(env('APP_TIMEZONE', 'Europe/Moscow'));
@ini_set('log_errors', '1');
@ini_set('display_errors', APP_DEBUG ? '1' : '0');
if (session_status() === PHP_SESSION_NONE) {
    $configuredSessionPath = trim((string)env('SESSION_SAVE_PATH', ''));
    $sessionCandidates = array_values(array_unique(array_filter([
        $configuredSessionPath,
        STORAGE_DIR . 'sessions',
        rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'azbuka-decora-sessions-' . substr(hash('sha256', __DIR__), 0, 12),
    ])));
    $sessionDirectorySelected = false;
    foreach ($sessionCandidates as $candidate) {
        $candidate = rtrim((string)$candidate, '/\\');
        if ($candidate === '' || strpos($candidate, "\0") !== false) {
            continue;
        }
        if (!is_dir($candidate) && !@mkdir($candidate, 0700, true) && !is_dir($candidate)) {
            continue;
        }
        if (!is_writable($candidate)) {
            continue;
        }
        if (@session_save_path($candidate) !== false) {
            $sessionDirectorySelected = true;
            break;
        }
    }
    if (!$sessionDirectorySelected) {
        error_log('Azbuka Decora: custom session directory is unavailable; using the PHP default session.save_path.');
    }
    if (!headers_sent($headersFile, $headersLine)) {
        $sameSite = ucfirst(strtolower((string)env('SESSION_SAME_SITE', 'Lax')));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }
        @ini_set('session.cookie_lifetime', (string)SESSION_LIFETIME);
        @ini_set('session.cookie_path', '/');
        @ini_set('session.cookie_secure', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', $sameSite);
        @ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
        @ini_set('session.gc_probability', (string)env('SESSION_GC_PROBABILITY', 0));
        @ini_set('session.gc_divisor', '1000');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.lazy_write', '1');
        if (!@session_start()) {
            error_log('Azbuka Decora: session_start() failed. Check session.save_path permissions.');
        }
    } else {
        error_log(sprintf(
            'Azbuka Decora: session was not started because output began in %s on line %d.',
            $headersFile ?: 'unknown file',
            (int)$headersLine
        ));
    }
}
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/../classes/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
        return;
    }
    if (in_array($class, [
        'ValidationException', 'AccessDeniedException', 'EntityNotFoundException',
        'ConflictException', 'FileStorageException', 'AuthenticationException',
        'NotificationException', 'ConfigurationException', 'PersistenceException',
        'IntegrationException',
    ], true)) {
        require_once __DIR__ . '/../classes/AppException.php';
    }
});
function escape($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}
function redirect($url) {
    header('Location: ' . $url);
    exit;
}
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}
function currentRole() {
    return $_SESSION['user_role'] ?? null;
}
function workerRoles() {
    return ['worker', 'florist', 'courier', 'installer', 'helper'];
}
function crmRoles() {
    return ['manager', 'organizer', 'admin'];
}
function allRoles() {
    return array_merge(['client'], crmRoles(), workerRoles());
}
function isWorkerRole($role = null) {
    return in_array($role ?? currentRole(), workerRoles(), true);
}
function isCrmRole($role = null) {
    return in_array($role ?? currentRole(), crmRoles(), true);
}
function isClientRole($role = null) {
    return ($role ?? currentRole()) === 'client';
}
function isAdminRole($role = null) {
    return ($role ?? currentRole()) === 'admin';
}
function dashboardPathForRole($role) {
    return match (true) {
        $role === 'client' => '/account/',
        $role === 'manager' => '/manager/dashboard.php',
        $role === 'organizer' => '/organizer/dashboard.php',
        $role === 'admin' => '/admin/',
        in_array($role, workerRoles(), true) => '/worker/dashboard.php',
        default => LOGIN_PATH,
    };
}
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}
function telegramConfirmationRequired() {
    if (!isLoggedIn() || !dbColumnExists('users', 'telegram_status')) {
        return false;
    }
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare(
        "SELECT 1 FROM users
         WHERE id=? AND telegram_status='required' AND telegram_user_id IS NULL
         LIMIT 1"
    );
    $stmt->execute([(int)$_SESSION['user_id']]);
    return (bool)$stmt->fetchColumn();
}
function isTelegramConfirmationRequest() {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return str_ends_with($script, '/telegram-confirm.php')
        || str_ends_with($script, '/logout.php');
}
function requireLogin() {
    if (!isLoggedIn()) {
        redirect(LOGIN_PATH);
    }
    if (telegramConfirmationRequired() && !isTelegramConfirmationRequest()) {
        redirect('/telegram-confirm.php');
    }
}
function requireWorkerPanel() {
    requireLogin();
    if (!isWorkerRole()) {
        redirect(LOGIN_PATH);
    }
}
function requireClientPanel() {
    requireLogin();
    if (!isClientRole()) {
        redirect(LOGIN_PATH);
    }
}
function requireCrmPanel($roles = null) {
    $allowed = $roles ?: crmRoles();
    requireLogin();
    if (!in_array(currentRole(), $allowed, true)) {
        redirect(LOGIN_PATH);
    }
}
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . escape(csrfToken()) . '">';
}
function clientIp() {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}
function requirePermission($permission) {
    AccessControl::requirePermission($permission);
}
function can($permission, $role = null) {
    return AccessControl::allows($permission, $role);
}
function jsonResponse($data, $status = 200) {
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function verifyCsrfToken($token) {
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}
function requireCsrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        die('Сессия устарела. Обновите страницу и попробуйте снова.');
    }
}
function asset($path) {
    $path = ltrim((string)$path, '/');
    $file = __DIR__ . '/../' . $path;
    $version = is_file($file) ? filemtime($file) : null;
    return '/' . $path . ($version ? '?v=' . $version : '');
}
function dbTableExists($table) {
    static $cache = [];
    $key = (string)$table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        $cache[$key] = (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}
function dbColumnExists($table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}
function ensureUploadDirectories() {
    foreach (['avatars', 'chat', 'materials', 'references', 'receipts', 'media', 'finance', 'timeline', 'concepts', 'reviews', 'candidate'] as $dir) {
        $path = UPLOAD_DIR . $dir;
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            error_log('Azbuka Decora: cannot create upload directory: ' . $path);
        }
    }
}
function appUrl($path = '') {
    return SITE_URL . '/' . ltrim($path, '/');
}
function phoneLink($phone) {
    $normalized = preg_replace('/[^0-9+]/', '', (string)$phone);
    return $normalized !== '' ? 'tel:' . $normalized : '';
}
function truncateText($text, $length = 120) {
    $text = trim((string)$text);
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $length) {
        return mb_substr($text, 0, $length, 'UTF-8') . '...';
    }
    if (!function_exists('mb_strlen') && strlen($text) > $length) {
        return substr($text, 0, $length) . '...';
    }
    return $text;
}
ensureUploadDirectories();
if (isLoggedIn() && class_exists('LoginSecurity')) {
    LoginSecurity::validateCurrentSession();
}
