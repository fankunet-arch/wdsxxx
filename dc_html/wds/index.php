<?php
require_once(__DIR__ . '/../../app/wds/bootstrap/app.php');
$version = 'wds-0.3.3';
$c = cfg();

$tzLocal    = $c['timezone_local'] ?? 'Europe/Madrid';
$todayLocal = (new DateTimeImmutable('now', new DateTimeZone($tzLocal)))->format('Y-m-d');
$startLocal = (new DateTimeImmutable('now', new DateTimeZone($tzLocal)))->modify('-3 days')->format('Y-m-d');
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WDS 控制台</title>
  <link rel="stylesheet" href="/wds/assets/css/console.css">
  <script defer src="/wds/assets/js/console.js"></script>
</head>
<body>
  <header>
    <div class="wrap brand">
      <div class="logo"></div>
      <div>
        <div class="title">WDS 控制台</div>
        <div class="subtitle">版本 <?=$version?> ｜ UTC <span id="utcClock"></span> ｜ 健康：<span id="pingStatus">…</span></div>
      </div>
    </div>
  </header>

  <main>
    <div class="wrap">
      <div class="grid">
        <div class="card">
          <h2>采集</h2>

          <div class="notice" id="recBox" style="margin-bottom:10px">
            <div class="badges" style="margin-bottom:6px;align-items:center;gap:6px;flex-wrap:wrap">
              <div class="badge label">建议预报时间（Europe/Madrid）：</div>
              <div id="recTimes" class="inline-pills"></div>
              <div class="badge">窗口：±30 分钟</div>
              <div class="badge">频率：每日 2–4 次</div>
            </div>
            <div class="muted">当前状态：<span id="recStatus">计算中…</span></div>
          </div>

          <form method="post" action="/wds/console/run_forecast.php">
            <label>未来天数（默认16）：<input type="number" name="days" min="1" max="16" value="16"></label>
            <div class="badges">
              <div class="badge">营业时段 12–22</div>
              <div class="badge">近7天保留全部 run</div>
              <div class="badge">前23天保留每日最后一 run</div>
            </div>
            <p class="muted">仅写入营业时段（Europe/Madrid）映射到的 UTC 小时。</p>
            <button class="btn-daily" type="submit">拉取预报（每日）</button>
          </form>

          <hr style="border:0;border-top:1px solid var(--border);margin:12px 0">

          <div class="notice" style="margin-bottom:8px">
            <div class="badges" style="margin-bottom:6px">
              <div class="badge">回填频率：每日 1 次</div>
              <div class="badge">建议时间：09:30</div>
              <div class="badge">默认区间：今天-3 ~ 今天</div>
            </div>
            <div class="muted">用途：营业时段→按小时写入；非营业时段→当日 1 条汇总（兜底与验证）。</div>
          </div>

          <form method="post" action="/wds/console/run_archive.php" style="margin-bottom:10px">
            <input type="hidden" name="start" value="<?=$startLocal?>">
            <input type="hidden" name="end" value="<?=$todayLocal?>">
            <button class="btn-daily" type="submit">一键回填最近三天（t-3 ~ t）</button>
          </form>

          <form method="post" action="/wds/console/run_archive.php">
            <div class="row">
              <div class="tap-picker" style="flex:1">
                <label>开始日期：
                  <input type="date" name="start" required value="<?=$startLocal?>">
                </label>
              </div>
              <div class="tap-picker" style="flex:1">
                <label>结束日期：
                  <input type="date" name="end" required value="<?=$todayLocal?>">
                </label>
              </div>
            </div>
            <button class="btn-aux" type="submit">按所选日期回填</button>
          </form>
        </div>

        <div class="card">
          <h2>节假日</h2>
          <form method="post" action="/wds/console/update_holidays.php">
            <div class="tap-picker">
              <label>年份：
                <input type="number" name="year" value="<?=date('Y')?>" required>
              </label>
            </div>
            <p class="muted">来源：Nager.Date（国家范围）。一般每年年初更新一次。</p>
            <button class="btn-aux" type="submit">更新 ES 节假日</button>
          </form>
        </div>

        <div class="card">
          <h2>验证 & 报告</h2>
          <form method="post" action="/wds/console/run_verify.php">
            <label>回测天数（默认7）：<input type="number" name="days" value="7" min="1" max="30"></label>
            <p class="muted">只计算营业时段（12–22）的温度 MAE。</p>
            <button class="btn-aux" type="submit">计算温度 MAE</button>
          </form>
          <div class="notice" style="margin-top:10px">后续计划：降水命中率、POD/FAR/CSI、可信度分。</div>

          <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap">
            <a href="/wds/console/history.php"><button class="btn-aux" type="button">查看采集记录（过去 / 今天 / 未来）</button></a>
            <a href="/wds/console/view_weather.php"><button class="btn-aux" type="button">查看天气（图文视图）</button></a>
          </div>
        </div>

        <div class="card">
          <h2>运维</h2>
          <form method="post" action="/wds/console/db_size.php">
            <button class="btn-aux" type="submit">查看数据库体积</button>
          </form>
          <div class="badges">
            <div class="badge">软阈值 <?=$c['retention']['db_soft_gb']?> GB</div>
            <div class="badge">硬阈值 <?=$c['retention']['db_hard_gb']?> GB</div>
          </div>
          <form method="post" action="/wds/console/housekeeping.php" style="margin-top:10px">
            <button class="btn-weekly" type="submit">压缩归档 → 清理老快照（周）</button>
          </form>
        </div>
      </div>

      <footer class="wrap" style="padding:10px 0 0">
        <p>所有时间在数据库中使用 UTC 存储；营业时段按 Europe/Madrid 切片。</p>
      </footer>
    </div>
  </main>
</body>
</html>
