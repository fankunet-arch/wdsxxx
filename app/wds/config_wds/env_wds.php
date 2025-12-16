<?php
return [
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'mhdlmskp2kpxguj',
    'user' => 'mhdlmskp2kpxguj',
    'pass' => 'BWNrmksqMEqgbX37r3QNDJLGRrUka',
    'charset' => 'utf8mb4',
  ],
  'timezone_local' => 'Europe/Madrid',
  'api_token' => '3UsMvup5VdFWmFw7UcyfXs5FRJNumtzdqabS5Eepdzb77pWtUBbjGgc',  // 32~64 chars

  // 数据保留和归档配置
  'retention' => [
    'db_soft_gb' => 0.80,        // 数据库软阈值：800MB
    'db_hard_gb' => 0.95,        // 数据库硬阈值：950MB
    'db_archive_days' => 30,     // 热表保留天数：30天前数据迁移到冷表
    'json_keep_months' => 2,     // 原始JSON保留月数：保留最近2个月
  ],

  // 备份配置（可选）
  'backup_enabled' => false,           // 是否启用备份：false=关闭, true=开启
  'backup_path' => '/mnt/nas/wds_backups',  // 备份路径：NAS挂载点或其他存储路径
];