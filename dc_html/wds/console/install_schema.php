<?php
declare(strict_types=1);

// Bootstrap
$__tries=[__DIR__.'/../../../app/wds/bootstrap_compat.php',__DIR__.'/../../app/wds/bootstrap_compat.php',dirname(__DIR__,4).'/app/wds/bootstrap_compat.php'];
$__ok=false;foreach($__tries as $__p){if(is_file($__p)){require_once $__p;$__ok=true;break;}}if(!$__ok){http_response_code(500);echo"<!doctype html><meta charset='utf-8'><h1>WDS 引导未找到</h1><pre>".htmlspecialchars(implode("\n",$__tries))."</pre>";exit;}

$pdo = db();

function headx(){echo <<<H
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>WDS · 初始化数据库</title>
<style>:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--line:#1b2131}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,Helvetica Neue,Arial}
.wrap{max-width:960px;margin:22px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px}
pre{background:#0c0f15;border:1px solid var(--line);border-radius:10px;padding:10px;overflow:auto}small,.muted{color:var(--muted);font-size:12px}
.btn{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff;text-decoration:none}.btn:hover{background:#202842}
.ok{color:#4cd964}.err{color:#ff3b30}
</style></head><body><div class="wrap"><div class="card">
H;}
function footx(){echo "</div></div></body></html>";}

headx();
echo "<h3>初始化数据库</h3><p class='muted'>重复执行是安全的（IF NOT EXISTS / ON DUPLICATE KEY）。</p>";

$stmts = [
"CREATE TABLE IF NOT EXISTS wds_exec_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exec_date DATE NOT NULL,
  hm CHAR(5) NOT NULL,
  action VARCHAR(32) NOT NULL,
  total INT NOT NULL DEFAULT 0,
  done INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_date_hm_action (exec_date, hm, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS wds_hourly_observed (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME NOT NULL,
  location_id INT NULL,
  temperature DECIMAL(5,2) NULL,
  precip_mm DECIMAL(6,2) NULL,
  wind_speed DECIMAL(6,2) NULL,
  source VARCHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_loc_ts_src (location_id, ts, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS wds_locations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NULL,
  city VARCHAR(64) NULL,
  lat DECIMAL(8,5) NOT NULL,
  lon DECIMAL(8,5) NOT NULL,
  timezone VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS wds_holidays (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  country CHAR(2) NOT NULL,
  holiday_date DATE NOT NULL,
  local_name VARCHAR(128) NULL,
  name VARCHAR(128) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_country_date (country, holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($stmts as $sql) {
  try { $pdo->exec($sql); echo "<p class='ok'>OK</p><pre>".htmlspecialchars($sql)."</pre>"; }
  catch (Throwable $e) { echo "<p class='err'>ERROR: ".htmlspecialchars($e->getMessage())."</p><pre>".htmlspecialchars($sql)."</pre>"; }
}

// 插入默认地点（若为空）
try {
  $cnt = (int)($pdo->query("SELECT COUNT(*) FROM wds_locations")->fetchColumn() ?: 0);
  if ($cnt === 0) {
    $ins = $pdo->prepare("INSERT INTO wds_locations(name, city, lat, lon, timezone) VALUES('Default','Madrid',40.4168,-3.7038,'Europe/Madrid')");
    $ins->execute();
    echo "<p class='ok'>已插入默认地点（Madrid）。</p>";
  }
} catch (Throwable $e) {
  echo "<p class='err'>插入默认地点失败：".htmlspecialchars($e->getMessage())."</p>";
}

echo "<p style='margin-top:10px'><a class='btn' href='/wds/'>返回控制台</a></p>";
footx();
