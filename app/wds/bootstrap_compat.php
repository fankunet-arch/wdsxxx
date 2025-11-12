<?php
declare(strict_types=1);

/**
 * WDS 兼容引导层（为旧代码提供 cfg()/db()/log_msg() 别名 + 常量映射）
 * - 仅负责加载新引导并提供函数与常量别名
 * - 不输出任何 HTML
 */

//
// 1) 加载新引导
//
require_once __DIR__ . '/bootstrap.php';

//
// 2) 常量别名（部分老代码可能依赖这些名义常量）
//
if (!defined('WDS_PROJECT_ROOT')) {
    // 兜底：/html/abcabc_net/app
    define('WDS_PROJECT_ROOT', dirname(__DIR__, 1));
}
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', WDS_PROJECT_ROOT);
}
if (!defined('WDS_LEGACY_ROOT')) {
    // 旧工程中常把 legacy root 指向 /app_wds；此处仅占位，避免引用时报未定义
    define('WDS_LEGACY_ROOT', WDS_PROJECT_ROOT . '/app_wds');
}
if (!defined('WDS_STORAGE_PATH')) {
    define('WDS_STORAGE_PATH', WDS_PROJECT_ROOT . '/app/wds/storage_wds');
}
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', WDS_STORAGE_PATH);
}

//
// 3) 函数别名（旧口径 → 新口径）
//
if (!function_exists('cfg')) {
    function cfg(): array { return wds_cfg(); }
}
if (!function_exists('db')) {
    function db(): PDO { return wds_db(); }
}
if (!function_exists('log_msg')) {
    function log_msg(string $m): void { wds_log($m); }
}