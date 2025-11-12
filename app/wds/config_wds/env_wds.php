<?php
/**
 * ABCABC-WDS 环境配置（独立）
 * 注意：如需区分 dev/prod，可另备 env_wds.dev.php 并在 bootstrap 中做选择。
 */
return [
    'app_env'   => 'prod',
    'app_debug' => false,
    'timezone_app'   => 'UTC',
    'timezone_local' => 'Europe/Madrid',

    // 数据库（与 CP 同库或独立库均可）
    'db' => [
        'host'    => 'mhdlmskp2kpxguj.mysql.db',
        'port'    => 3306,
        'name'    => 'mhdlmskp2kpxguj',
        'user'    => 'mhdlmskp2kpxguj',
        'pass'    => 'BWNrmksqMEqgbX37r3QNDJLGRrUka',
        'charset' => 'utf8mb4',
    ],

    // API 访问令牌（用于 /dc_html/wds/api/* ）
    'api_token' => 'change-me-please-64hex',

    // 数据保留与容量阈值示例（按你后续需要再调）
    'retention' => [
        'obs_hourly_months'     => 24,
        'offhours_daily_months' => 36,
        'fc_hot_days'           => 7,
        'fc_cold_days'          => 23,
        'verify_hourly_days'    => 30,
        'daily_months'          => 36,
        'db_soft_gb'            => 0.80,
        'db_hard_gb'            => 0.95,
    ],
];