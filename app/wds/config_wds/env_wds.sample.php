<?php
return [
  'db' => [
    'host' => 'YOUR_MYSQL_HOST',
    'name' => 'YOUR_DB_NAME',
    'user' => 'YOUR_DB_USER',
    'pass' => 'YOUR_DB_PASS',
    'charset' => 'utf8mb4',
  ],
  'timezone_local' => 'Europe/Madrid',
  'api_token' => 'REPLACE_WITH_SECURE_RANDOM',  // 32~64 chars
  'retention' => ['db_soft_gb'=>0.80, 'db_hard_gb'=>0.95],
];
