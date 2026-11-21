<?php
require_once __DIR__ . '/env.php';
defined('DB_HOST') || define('DB_HOST', env('DB_HOST', 'localhost'));
defined('DB_PORT') || define('DB_PORT', (int)env('DB_PORT', 3306));
defined('DB_NAME') || define('DB_NAME', env('DB_NAME', 'azbuka_decora'));
defined('DB_USER') || define('DB_USER', env('DB_USER', 'azbuka_user'));
defined('DB_PASS') || define('DB_PASS', env('DB_PASS', ''));
defined('DB_CHARSET') || define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));
class Database {
    private static $instance = null;
    private $pdo;
    private function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die('Database connection failed: ' . $e->getMessage());
            }
            die('Database connection failed. Check .env settings.');
        }
    }
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    public function getConnection() {
        return $this->pdo;
    }
}
