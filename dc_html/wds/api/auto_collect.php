<?php
require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');
use WDS\ingest\OpenMeteoIngest;
use WDS\maintenance\MonthlyArchiver;
use WDS\maintenance\DatabaseArchiver;

header('Content-Type: application/json; charset=utf-8');

try {
  $cfg = cfg(); $pdo = db();
  $expected = $cfg['api_token'] ?? null;
  $given = $_GET['token'] ?? null;
  if (!$given && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) { $given = trim($m[1]); }
  }
  if (!$expected || !$given || !hash_equals($expected, $given)) {
    http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
  }

  $tzLocal = $cfg['timezone_local'] ?? 'Europe/Madrid';
  $days = isset($_GET['days']) ? max(1, min(16, (int)$_GET['days'])) : 16;

  $SLOTS = ['01:15','11:15','13:15','16:15','19:15']; // 修改点
  $WINDOW_MIN = 10;

  $nowLocal = new \DateTimeImmutable('now', new \DateTimeZone($tzLocal));
  $todayLocal = \DateTimeImmutable::createFromFormat('Y-m-d', $nowLocal->format('Y-m-d'), new \DateTimeZone($tzLocal));

  $hitSlot = null;
  foreach ($SLOTS as $hm) {
    [$H,$M] = array_map('intval', explode(':', $hm));
    $slot = $todayLocal->setTime($H,$M,0);
    $winStart = $slot->modify("-{$WINDOW_MIN} minutes");
    $winEnd   = $slot->modify("+{$WINDOW_MIN} minutes");
    if ($nowLocal >= $winStart && $nowLocal <= $winEnd) { $hitSlot = ['hm'=>$hm,'start'=>$winStart,'end'=>$winEnd,'slot'=>$slot]; break; }
  }

  $base = ['ok'=>true,'now_local'=>$nowLocal->format('Y-m-d H:i:s'),'timezone'=>$tzLocal,'window_min'=>$WINDOW_MIN,'slots'=>$SLOTS]; // 修改点

  if ($hitSlot === null) { echo json_encode($base + ['in_window'=>false,'action'=>'noop','reason'=>'outside_window']); exit; }

  $u1 = $hitSlot['start']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  $u2 = $hitSlot['end']  ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

  $totalLoc = (int)$pdo->query("SELECT COUNT(*) FROM wds_locations WHERE is_active=1")->fetchColumn();
  if ($totalLoc === 0) { echo json_encode($base + ['in_window'=>true,'slot'=>['hm'=>$hitSlot['hm'],'window_local'=>[$hitSlot['start']->format('Y-m-d H:i'),$hitSlot['end']->format('Y-m-d H:i')]],'action'=>'noop','reason'=>'no_active_locations']); exit; }

  $q = $pdo->prepare("SELECT COUNT(DISTINCT location_id) FROM wds_weather_hourly_forecast WHERE run_time_utc BETWEEN :u1 AND :u2");
  $q->execute([':u1'=>$u1, ':u2'=>$u2]);
  $doneLoc = (int)$q->fetchColumn();

  if ($doneLoc >= $totalLoc) {
    echo json_encode($base + ['in_window'=>true,'slot'=>['hm'=>$hitSlot['hm'],'window_local'=>[$hitSlot['start']->format('Y-m-d H:i'),$hitSlot['end']->format('Y-m-d H:i')]],'locations_total'=>$totalLoc,'locations_done'=>$doneLoc,'action'=>'noop','reason'=>'already_collected']); exit;
  }

  $ing = new OpenMeteoIngest($pdo, $cfg);
  $rows = $ing->fetchForecast($days);

  // ========== 智能回填（仅01:15槽执行） ==========
  $archiveResult = null; $archiveStart = null; $archiveEnd = null;
  if ($hitSlot['hm'] === '01:15') {
    $archiveStart = $todayLocal->modify('-2 days')->format('Y-m-d');
    $archiveEnd   = $todayLocal->format('Y-m-d');

    try {
      // 使用智能回填方法（跳过已存在的数据）
      $archiveResult = $ing->fetchArchiveSmart($archiveStart, $archiveEnd, true);
    } catch (\Throwable $e2) {
      error_log("AutoCollect archive failed: " . $e2->getMessage());
      $archiveResult = ['error' => $e2->getMessage()];
    }
  }

  // ========== 维护任务（仅01:15槽执行） ==========
  $maintenanceResult = [];

  if ($hitSlot['hm'] === '01:15') {
    // 任务1：检查是否为每月1日，执行月度归档
    if ((int)$nowLocal->format('d') === 1) {
      try {
        $lastMonth = date('Y-m', strtotime('-1 month'));
        $archiver = new MonthlyArchiver($pdo, $cfg);
        $maintenanceResult['monthly_archive'] = $archiver->executeMonthlyArchive($lastMonth);
      } catch (\Throwable $e) {
        $maintenanceResult['monthly_archive'] = ['success' => false, 'error' => $e->getMessage()];
        error_log("Monthly archive failed: " . $e->getMessage());
      }
    }

    // 任务2：每天执行数据库归档检查
    try {
      $dbArchiver = new DatabaseArchiver($pdo);

      // 只有在需要时才归档（避免频繁操作）
      if ($dbArchiver->shouldArchive()) {
        $maintenanceResult['db_archive'] = $dbArchiver->archiveOldForecasts(30);
      } else {
        $maintenanceResult['db_archive'] = ['action' => 'skipped', 'reason' => 'threshold_not_met'];
      }
    } catch (\Throwable $e) {
      $maintenanceResult['db_archive'] = ['success' => false, 'error' => $e->getMessage()];
      error_log("DB archive failed: " . $e->getMessage());
    }
  }

  $prefix = rtrim(APP_WDS . '/storage/raw', '/');
  $rel = function($abs) use ($prefix){ $rel=preg_replace('#^'.preg_quote($prefix,'#').'#','',$abs); return $rel ?: basename($abs); };

  $saved=[]; if (is_array($rows)) { foreach ($rows as $r) { $saved[]=['location_id'=>(int)$r['location_id'],'snapshot'=>$rel($r['snapshot'])]; } }

  $resp = $base + [
    'in_window'=>true,
    'slot'=>['hm'=>$hitSlot['hm'],'window_local'=>[$hitSlot['start']->format('Y-m-d H:i'),$hitSlot['end']->format('Y-m-d H:i')]],
    'locations_total'=>$totalLoc,
    'locations_done'=>$doneLoc,
    'action'=>'collected',
    'days'=>$days,
    'saved'=>$saved
  ];

  // 添加回填结果
  if ($archiveResult !== null) {
    $resp['archive'] = [
      'start'=>$archiveStart,
      'end'=>$archiveEnd,
      'fetched'=>count($archiveResult['fetched'] ?? []),
      'skipped'=>count($archiveResult['skipped'] ?? []),
      'details'=>$archiveResult
    ];
  }

  // 添加维护任务结果
  if (!empty($maintenanceResult)) {
    $resp['maintenance'] = $maintenanceResult;
  }

  echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
