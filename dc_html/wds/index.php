<?php
declare(strict_types=1);

/**
 * WDS 控制台首页（含“自检 / 初始化数据库”入口）
 */

(function () {
    $docroot   = dirname(__DIR__);
    $app_guess = dirname($docroot) . '/app/wds/bootstrap_compat.php';
    $tries = [
        $app_guess,
        dirname(__DIR__, 3) . '/app/wds/bootstrap_compat.php',
        __DIR__ . '/../../app/wds/bootstrap_compat.php',
    ];
    foreach ($tries as $p) {
        if (is_file($p)) { require_once $p; return; }
    }
    http_response_code(500);
    echo "<!doctype html><meta charset='utf-8'><h1>WDS bootstrap_compat 未找到</h1><pre>"
        . htmlspecialchars(implode("\n", $tries)) . "</pre>";
    exit;
})();

$version = 'wds-0.3.2';
$c = cfg();

$tzLocal    = $c['timezone_local'] ?? 'Europe/Madrid';
$todayLocal = (new DateTimeImmutable('now', new DateTimeZone($tzLocal)))->format('Y-m-d');
$startLocal = (new DateTimeImmutable('now', new DateTimeZone($tzLocal)))->modify('-3 days')->format('Y-m-d');

$fromParam = rawurlencode($startLocal . ' 00:00:00');
$toParam   = rawurlencode($todayLocal . ' 23:59:59');
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>WDS 控制台</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--primary:#6aa1ff;--ok:#4cd964;--warn:#ffcc00;--danger:#ff3b30;--grid:#1d2232;}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,Helvetica Neue,Arial,sans-serif}
a{color:var(--primary);text-decoration:none}
.wrap{max-width:1100px;margin:0 auto;padding:18px}
h1,h2,h3{margin:0 0 10px 0}
.card{background:var(--card);border-radius:16px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.35);border:1px solid #1b2131}
.row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
@media (max-width:900px){.row{grid-template-columns:1fr}}
.kpi{display:flex;flex-direction:column;gap:4px;padding:12px;border:1px solid #1f2435;border-radius:12px;background:rgba(255,255,255,.02)}
.kpi .v{font-size:22px;font-weight:700}
.small,.muted{font-size:12px;color:var(--muted)}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff}
.btn:hover{background:#202842}
.btn-ok{background:rgba(76,217,100,.12);border-color:#4cd964;color:#c2f0cd}
.btn-warn{background:rgba(255,204,0,.12);border-color:#ffcc00;color:#fff0b3}
.btn-danger{background:rgba(255,59,48,.12);border-color:#ff3b30;color:#ffd1cc}
.btn-ghost{background:transparent;border-color:#2b3755}
.btn-sm{padding:6px 10px;border-radius:8px}
.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}
.col-6{grid-column:span 6}
.col-12{grid-column:span 12}
.section{border-top:1px dashed #2b3755;margin:22px 0 10px;padding-top:16px}
pre{background:#0c0f15;border:1px solid #1b2131;border-radius:10px;padding:12px;color:#cfe1ff;overflow:auto}
code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
.badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 10px;background:#1a2031;border:1px solid #2b3755}
.badge .dot{width:8px;height:8px;border-radius:50%}
.badge.ok .dot{background:var(--ok)}
.badge.warn .dot{background:var(--warn)}
.badge.danger .dot{background:var(--danger)}
.card h3{display:flex;align-items:center;justify-content:space-between}
hr{height:1px;border:0;background:#1b2131;margin:14px 0}
.tag{display:inline-block;padding:3px 8px;border-radius:8px;border:1px solid #2b3755;background:#151a26;color:#9fb4df}
.list{display:flex;flex-direction:column;gap:10px}
.item{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:12px;background:#121623;border:1px solid #1b2131}
.item .l{display:flex;align-items:center;gap:10px}
.item .r{color:#9fb4df}
kbd{background:#1b2131;border:1px solid #2b3755;border-bottom-color:#1a2236;border-radius:6px;padding:1px 6px;font-size:12px}
.callout{border:1px dashed #2b3755;border-radius:12px;padding:10px;background:#121623}
</style>
</head>
<body>
  <header class="wrap" style="padding:16px 18px 0 18px">
    <h1>WDS 控制台 <span class="small">v<?= htmlspecialchars($version) ?></span></h1>
    <div class="small muted">本地时区：<?= htmlspecialchars($tzLocal) ?>　今日：<?= htmlspecialchars($todayLocal) ?></div>
  </header>

  <main class="wrap" style="padding:16px 18px 28px 18px">
    <div class="grid">
      <div class="col-12">
        <div class="card">
          <h3>数据看板 <span class="tag">近 3 日：<?= htmlspecialchars($startLocal) ?> ~ <?= htmlspecialchars($todayLocal) ?></span></h3>
          <div class="row" style="margin-top:10px">
            <div class="kpi">
              <div class="small muted">数据源</div><div class="v">AEMET</div><div class="small">公开接口 · 免费</div>
            </div>
            <div class="kpi">
              <div class="small muted">入库策略</div><div class="v">分钟级去重</div><div class="small">以 UTC 存储</div>
            </div>
          </div>

          <div class="section">
            <div class="small muted">快速操作</div>
            <div class="list" style="margin-top:10px">
              <div class="item"><div class="l"><span class="badge ok"><span class="dot"></span>拉取预报</span><span class="small muted">触发 forecast ingestion</span></div>
                <div class="r"><form method="post" action="/wds/console/run_forecast.php"><button class="btn btn-ok btn-sm" type="submit">立即执行</button></form></div></div>
              <div class="item"><div class="l"><span class="badge"><span class="dot" style="background:#9fb4df"></span>拉取历史归档</span><span class="small muted">抓取历史 observed 数据</span></div>
                <div class="r"><form method="post" action="/wds/console/run_archive.php"><button class="btn btn-ghost btn-sm" type="submit">立即执行</button></form></div></div>
              <div class="item"><div class="l"><span class="badge warn"><span class="dot"></span>预报核验</span><span class="small muted">对比预报与实况误差</span></div>
                <div class="r"><form method="post" action="/wds/console/run_verify.php"><button class="btn btn-warn btn-sm" type="submit">立即执行</button></form></div></div>
              <div class="item"><div class="l"><span class="badge danger"><span class="dot"></span>清理快照</span><span class="small muted">压缩归档 → 删除老数据</span></div>
                <div class="r"><form method="post" action="/wds/console/housekeeping.php"><button class="btn btn-danger btn-sm" type="submit">立即执行</button></form></div></div>
            </div>
          </div>

          <div class="section">
            <div class="small muted">API</div>
            <div class="list" style="margin-top:10px">
              <div class="item"><div class="l"><span class="badge"><span class="dot" style="background:#9fb4df"></span>小时实况</span><span class="small muted">/wds/api/v1/hourly_observed.php?format=html</span></div>
                <div class="r"><a class="btn btn-sm" href="/wds/api/v1/hourly_observed.php?format=html" target="_blank">打开</a></div></div>
              <div class="item"><div class="l"><span class="badge"><span class="dot" style="background:#9fb4df"></span>自动采集</span><span class="small muted">cron / 外部触发（HTML）</span></div>
                <div class="r"><a class="btn btn-sm" href="/wds/api/auto_collect.php?format=html" target="_blank">打开</a></div></div>
            </div>
          </div>

          <div class="section">
            <div class="small muted">工具</div>
            <div class="list" style="margin-top:10px">
              <div class="item"><div class="l"><span class="badge"><span class="dot" style="background:#9fb4df"></span>天气查看</span><span class="small muted">按日期与区域查看天气</span></div>
                <div class="r"><a class="btn btn-sm" href="/wds/console/view_weather.php" target="_blank">打开</a></div></div>

              <div class="item"><div class="l"><span class="badge"><span class="dot" style="background:#9fb4df"></span>数据库容量</span><span class="small muted">查看占用与清理建议</span></div>
                <div class="r"><a class="btn btn-sm" href="/wds/console/db_size.php" target="_blank">打开</a></div></div>

              <div class="item"><div class="l"><span class="badge"><span class="dot" style="background:#9fb4df"></span>节假日维护</span><span class="small muted">西/中节假日合并</span></div>
                <div class="r"><a class="btn btn-sm" href="/wds/console/update_holidays.php" target="_blank">打开</a></div></div>

              <div class="item"><div class="l"><span class="badge"><span class="dot" style="background:#9fb4df"></span>历史记录</span><span class="small muted">查看采集与核验历史</span></div>
                <div class="r"><a class="btn btn-sm" href="/wds/console/history.php" target="_blank">打开</a></div></div>

              <div class="item"><div class="l"><span class="badge"><span class="dot" style="background:#9fb4df"></span>诊断自检</span><span class="small muted">PHP/扩展/DB/表检测</span></div>
                <div class="r"><a class="btn btn-sm" href="/wds/console/diag_selfcheck.php" target="_blank">打开</a></div></div>

              <div class="item"><div class="l"><span class="badge"><span class="dot" style="background:#9fb4df"></span>初始化数据库</span><span class="small muted">创建关键表（幂等）</span></div>
                <div class="r"><a class="btn btn-sm" href="/wds/console/install_schema.php" target="_blank">打开</a></div></div>
            </div>
          </div>

          <div class="section">
            <div class="small muted">示例：API 请求（JSON）</div>
            <pre><code>curl -sS "https://dc.abcabc.net/wds/api/v1/hourly_observed.php?from=<?= $fromParam ?>&amp;to=<?= $toParam ?>"</code></pre>
          </div>
        </div>
      </div>

      <footer class="wrap" style="padding:10px 0 0">
        <p>所有时间在数据库中使用 UTC 存储；营业时段按 Europe/Madrid 切片。</p>
      </footer>
    </div>
  </main>
</body>
</html>