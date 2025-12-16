<?php
require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');
$pdo = db();

$pdo->beginTransaction();
try {
  $sql = "
    DELETE wf FROM wds_weather_hourly_forecast wf
    JOIN (
      SELECT location_id, DATE(forecast_time_utc) d, HOUR(forecast_time_utc) h, MAX(run_time_utc) keep_run
      FROM wds_weather_hourly_forecast
      WHERE forecast_time_utc < (UTC_TIMESTAMP() - INTERVAL 7 DAY) 
        AND forecast_time_utc >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)
      GROUP BY location_id, d, h
    ) t ON t.location_id = wf.location_id AND t.d = DATE(wf.forecast_time_utc) AND t.h = HOUR(wf.forecast_time_utc)
    WHERE wf.forecast_time_utc < (UTC_TIMESTAMP() - INTERVAL 7 DAY)
      AND wf.forecast_time_utc >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)
      AND wf.run_time_utc < t.keep_run
  ";
  $pdo->exec($sql);
  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo "<!doctype html><meta charset='utf-8'><h1>失败</h1><pre>".htmlspecialchars($e->getMessage())."</pre><p><a href='/wds/'>返回控制台</a></p>";
  exit;
}
?>
<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/wds/assets/css/console.css">
<div class="wrap"><div class="card"><h2>✅ 已执行压缩归档策略</h2><p class="muted">保留近 7 天所有 run；7–30 天保留每日每小时最后一次 run。</p><p><a href="/wds/"><button class="btn-weekly">返回控制台</button></a></p></div></div>
