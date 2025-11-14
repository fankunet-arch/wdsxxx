<?php
declare(strict_types=1);
// WDS bootstrap — /app/wds + env at /app/wds/config_wds/env_wds.php

define('PROJECT_ROOT', realpath(__DIR__ . '/../../../'));
define('APP_WDS',      realpath(__DIR__ . '/..'));
define('APP_ROOT',     realpath(__DIR__ . '/../../'));

define('WDS_ENV_FILE', APP_ROOT . '/wds/config_wds/env_wds.php');
define('WDS_ENV_SAMPLE_FILE', APP_ROOT . '/wds/config_wds/env_wds.sample.php');

// Namespace autoload for WDS\*, mapping CamelCase class to snake_case file
spl_autoload_register(function(string $class){
    $prefix = 'WDS\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $rel = substr($class, strlen($prefix));
    $rel = str_replace('\\', DIRECTORY_SEPARATOR, $rel);
    $parts = explode(DIRECTORY_SEPARATOR, $rel);
    $file = array_pop($parts);
    $snake = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $file));
    $path = APP_WDS . '/src/' . implode(DIRECTORY_SEPARATOR, $parts);
    $full = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $snake . '.php';
    if (is_file($full)) require_once $full;
});

function cfg() : array {
    static $c = null; if ($c !== null) return $c;
    if (is_file(WDS_ENV_FILE)) {
        $c = require WDS_ENV_FILE;
        if (!is_array($c)) $c = [];
    } elseif (is_file(WDS_ENV_SAMPLE_FILE)) {
        $c = require WDS_ENV_SAMPLE_FILE;
        if (!is_array($c)) $c = [];
    } else {
        $c = [];
    }
    $c += [
        'db' => ['host'=>'localhost','name'=>'','user'=>'','pass'=>'','charset'=>'utf8mb4'],
        'timezone_local' => 'Europe/Madrid',
        'api_token' => 'REPLACE_WITH_SECURE_RANDOM',
        'retention' => ['db_soft_gb'=>0.80,'db_hard_gb'=>0.95],
    ];
    return $c;
}

function db() : PDO {
    static $pdo = null; if ($pdo) return $pdo;
    $c = cfg(); $d = $c['db'];
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $d['host'], $d['name'], $d['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function ensure_dir(string $p) : void { if (!is_dir($p)) @mkdir($p, 0775, true); }
