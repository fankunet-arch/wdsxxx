<?php
declare(strict_types=1);

/**
 * ABCABC-WDS Bootstrap（独立配置/日志；向后兼容旧 /app_wds 代码）
 */
if (!defined('WDS_APP_ROOT')) {
    define('WDS_APP_ROOT', realpath(__DIR__));
}
define('WDS_PROJECT_ROOT', dirname(WDS_APP_ROOT));               // /html/abcabc_net/app
define('WDS_STORAGE_PATH', WDS_APP_ROOT . '/storage_wds');       // 日志/缓存等
define('WDS_CONFIG_DIR',   WDS_APP_ROOT . '/config_wds');
define('WDS_ENV_FILE',     WDS_CONFIG_DIR . '/env_wds.php');

// 旧目录兼容（如旧代码仍在 /app_wds 下）
define('WDS_LEGACY_ROOT',  dirname(WDS_PROJECT_ROOT) . '/app_wds'); // /html/abcabc_net/app_wds

@is_dir(WDS_STORAGE_PATH) || @mkdir(WDS_STORAGE_PATH, 0775, true);
@is_dir(WDS_STORAGE_PATH . '/logs_wds') || @mkdir(WDS_STORAGE_PATH . '/logs_wds', 0775, true);

function wds_cfg(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = file_exists(WDS_ENV_FILE) ? require WDS_ENV_FILE : [];
    if (!is_array($cfg)) $cfg = [];
    $cfg['app_debug']      = $cfg['app_debug']      ?? false;
    $cfg['timezone_app']   = $cfg['timezone_app']   ?? 'UTC';
    $cfg['timezone_local'] = $cfg['timezone_local'] ?? 'Europe/Madrid';

    date_default_timezone_set($cfg['timezone_app']);
    ini_set('display_errors', $cfg['app_debug'] ? '1' : '0');
    error_reporting(E_ALL);
    return $cfg;
}

// PSR-4（优先新目录 /app/wds/src/，找不到再回落旧目录 /app_wds/src/）
spl_autoload_register(function ($class) {
    $prefix = 'WDS\\';
    $len = strlen($prefix);
    if (strncmp($class, $prefix, $len) !== 0) return;
    $relative = str_replace('\\', '/', substr($class, $len));
    $try = [
        WDS_APP_ROOT . '/src/' . $relative . '.php',
        WDS_LEGACY_ROOT . '/src/' . $relative . '.php',
    ];
    foreach ($try as $f) {
        if (is_file($f)) { require $f; return; }
    }
});

// PDO（UTC）
function wds_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = wds_cfg();
    $db = $c['db'] ?? [];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'] ?? 'localhost',
        (int)($db['port'] ?? 3306),
        $db['name'] ?? '',
        $db['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->query("SET time_zone = '+00:00'");
    return $pdo;
}

// 存储/日志
function wds_storage_path(string $rel): string {
    $p = WDS_STORAGE_PATH . '/' . ltrim($rel, '/');
    $dir = dirname($p);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $p;
}
function wds_log(string $message): void {
    $file = WDS_STORAGE_PATH . '/logs_wds/wds-' . gmdate('Ymd') . '.log';
    $ip = $_SERVER['REMOTE_ADDR']      ?? '-';
    $ua = $_SERVER['HTTP_USER_AGENT']  ?? '-';
    @file_put_contents($file, sprintf("%s\t%s\t%s\t%s\n", gmdate('c'), $ip, $ua, $message), FILE_APPEND);
}