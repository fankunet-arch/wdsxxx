<?php
require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');

$days = isset($_POST['days']) ? max(1, min(30, (int)$_POST['days'])) : 7;
$pdo = db();
$c = cfg();
$tzLocal = $c['timezone_local'] ?? 'Europe/Madrid';

$row = $pdo->query("SELECT open_hour_local, close_hour_local FROM wds_business_hours LIMIT 1")->fetch();
$open = isset($row['open_hour_local']) ? (int)$row['open_hour_local'] : 12;
$close = isset($row['close_hour_local']) ? (int)$row['close_hour_local'] : 22;

$endLocal = new DateTimeImmutable('now', new DateTimeZone($tzLocal));
$startLocal = $endLocal->modify('-'.($days-1).' days')->setTime($open,0,0);

$u1 = $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$u2 = $endLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$sql = "
SELECT COUNT(*) AS n, AVG(ABS((fc.t_fc - ob.t_ob)/10.0)) AS mae_c
FROM (
  SELECT wf.location_id, wf.forecast_time_utc, wf.temp_c AS t_fc
  FROM wds_weather_hourly_forecast wf
  JOIN (
    SELECT location_id, forecast_time_utc, MAX(run_time_utc) AS max_run
    FROM wds_weather_hourly_forecast
    WHERE forecast_time_utc BETWEEN :u1 AND :u2
    GROUP BY location_id, forecast_time_utc
  ) lr ON lr.location_id=wf.location_id AND lr.forecast_time_utc=wf.forecast_time_utc AND lr.max_run=wf.run_time_utc
) fc
JOIN wds_weather_hourly_observed ob
  ON ob.location_id=fc.location_id AND ob.obs_time_utc=fc.forecast_time_utc
WHERE ob.obs_time_utc BETWEEN :u1 AND :u2
";
$st = $pdo->prepare($sql);
$st->execute([':u1'=>$u1, ':u2'=>$u2]);
$row = $st->fetch();
$n = (int)($row['n'] ?? 0);
$mae = $row['mae_c'] !== null ? number_format((float)$row['mae_c'], 2) : '—';
$version = 'wds-0.3.3';
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>验证 · MAE</title><link rel="stylesheet" href="/wds/assets/css/console.css"></head>
<body>
  <div class="wrap">
    <div class="card">
      <h2 style="font-size:20px">验证 · 温度 MAE</h2>
      <p class="muted">版本 <?=$version?> · 样本小时数：<?=$n?> · 营业时段 <?=$open?>–<?=$close?></p>
      <table><thead><tr><th>指标</th><th>值</th></tr></thead>
        <tbody><tr><td>MAE (℃)</td><td><b><?=$mae?></b></td></tr></tbody></table>
      <p style="margin-top:12px"><a href="/wds/"><button class="btn-aux" type="button">返回控制台</button></a></p>
    </div>
  </div>
</body>
</html>
