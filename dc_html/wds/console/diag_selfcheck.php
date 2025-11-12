<?php
declare(strict_types=1);

// Bootstrap
$__tries=[__DIR__.'/../../../app/wds/bootstrap_compat.php',__DIR__.'/../../app/wds/bootstrap_compat.php',dirname(__DIR__,4).'/app/wds/bootstrap_compat.php'];
$__ok=false;foreach($__tries as $__p){if(is_file($__p)){require_once $__p;$__ok=true;break;}}if(!$__ok){http_response_code(500);echo"<!doctype html><meta charset='utf-8'><h1>WDS 引导未找到</h1><pre>".htmlspecialchars(implode("\n",$__tries))."</pre>";exit;}

$c=cfg(); $pdoOk=true; $pdoErr=null;
try { $pdo=db(); $pdo->query("SELECT 1"); } catch (Throwable $e) { $pdoOk=false; $pdoErr=$e->getMessage(); }

function headx(){echo <<<H
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>WDS · 自检</title>
<style>:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--ok:#4cd964;--warn:#ffcc00;--danger:#ff3b30;--line:#1b2131}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,Helvetica Neue,Arial}
.wrap{max-width:960px;margin:22px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px}
.kv{display:grid;grid-template-columns:200px 1fr;gap:8px}
.badge{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;border:1px solid #2b3755;background:#151a26}
.badge .dot{width:8px;height:8px;border-radius:50%}.ok .dot{background:var(--ok)} .warn .dot{background:var(--warn)} .danger .dot{background:var(--danger)}
table{border-collapse:collapse;width:100%;margin-top:10px}th,td{border:1px solid #222b3d;padding:6px 8px;text-align:left}
.btn{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff;text-decoration:none}.btn:hover{background:#202842}
small,.muted{color:#aab0c3;font-size:12px}
</style></head><body><div class="wrap"><div class="card">
H;}
function footx(){echo "</div></div></body></html>";}

function table_exists(PDO $pdo,string $name):bool{
  try{$q=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
      $q->execute([':t'=>$name]); return (bool)$q->fetchColumn();}catch(Throwable $e){return false;}
}

headx();
echo "<h3>自检</h3>";
echo "<div class='kv'>
        <div>PHP</div><div>".phpversion()."</div>
        <div>时区（本地）</div><div>".htmlspecialchars($c['timezone_local'] ?? 'Europe/Madrid')."</div>
        <div>扩展</div><div>pdo=". (extension_loaded('pdo')?'yes':'no') .", pdo_mysql=". (extension_loaded('pdo_mysql')?'yes':'no') .", json=". (extension_loaded('json')?'yes':'no') .", curl=". (extension_loaded('curl')?'yes':'no') ."</div>
        <div>数据库连接</div><div>".($pdoOk?'<span class="badge ok"><span class="dot"></span>OK</span>':'<span class="badge danger"><span class="dot"></span>ERROR</span> '.htmlspecialchars((string)$pdoErr))."</div>
      </div>";

if ($pdoOk) {
  $pdo=db();
  // [修复] 更新为 v2 数据库中的所有表
  $tables=[
      'wds_locations',
      'wds_business_hours',
      'wds_exec_log',
      'wds_holidays',
      'wds_weather_hourly_observed',
      'wds_weather_hourly_forecast',
      'wds_weather_daily',
      'wds_weather_features_hourly',
      'wds_weather_offhours_daily',
      'wds_forecast_verification_matches',
      'wds_forecast_verification_daily',
      'wds_forecast_calibration_profiles'
  ];
  echo "<h4 style='margin-top:14px'>关键表 (v2 Schema)</h4><table><thead><tr><th>表名</th><th>存在</th></tr></thead><tbody>";
  foreach($tables as $t){
    $ok = table_exists($pdo,$t);
    echo "<tr><td><code>{$t}</code></td><td>".($ok?'<span class="ok">✅ 存在</span>':'<span class="danger">❌ 缺失</span>')."</td></tr>";
  }
  echo "</tbody></table>";
}
echo "<p style='margin-top:12px'><a class='btn' href='/wds/'>返回控制台</a>　<a class='btn' href='/wds/console/install_schema.php'>运行初始化</a></p>";
footx();