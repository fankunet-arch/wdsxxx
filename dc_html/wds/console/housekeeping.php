<?php
declare(strict_types=1);
$__tries=[__DIR__.'/../../../app/wds/bootstrap_compat.php',__DIR__.'/../../app/wds/bootstrap_compat.php',dirname(__DIR__,4).'/app/wds/bootstrap_compat.php'];
$__ok=false;foreach($__tries as $__p){if(is_file($__p)){require_once $__p;$__ok=true;break;}}if(!$__ok){http_response_code(500);echo"<!doctype html><meta charset='utf-8'><h1>WDS 引导未找到</h1><pre>".htmlspecialchars(implode("\n",$__tries))."</pre>";exit;}
$c=cfg();$ret=$c['retention']??[]; $val=function(string $k)use($ret){return isset($ret[$k])?(string)$ret[$k]:'n/a';};
?>
<!doctype html><html lang="zh-CN"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>WDS · 清理与保留策略</title>
<style>:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--line:#1b2131}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,Helvetica Neue,Arial}
.wrap{max-width:920px;margin:22px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px}
small,.muted{color:var(--muted);font-size:12px}.btn{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff;text-decoration:none}.btn:hover{background:#202842}
table{border-collapse:collapse;width:100%;margin-top:10px}th,td{border:1px solid #222b3d;padding:6px 8px;text-align:left}
</style></head><body><div class="wrap"><div class="card">
  <h3>清理与保留策略</h3>
  <table><tr><th>Key</th><th>Value</th></tr>
    <tr><td>forecast_hot_days</td><td><?= htmlspecialchars($val('fc_hot_days')) ?></td></tr>
    <tr><td>forecast_cold_days</td><td><?= htmlspecialchars($val('fc_cold_days')) ?></td></tr>
    <tr><td>verify_hourly_days</td><td><?= htmlspecialchars($val('verify_hourly_days')) ?></td></tr>
    <tr><td>raw_snapshot_months</td><td><?= htmlspecialchars($val('raw_snapshot_months')) ?></td></tr>
  </table>
  <p class="muted">仅展示配置；实际清理任务由 Cron/CLI 执行。</p>
  <p><a class="btn" href="/wds/">返回控制台</a></p>
</div></div></body></html>
