<?php
require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');
use WDS\ingest\OpenMeteoIngest;

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

  // 01:15 采集时，同时回填最近2天（t-2 ~ t）
  $archiveRows = null; $archiveStart = null; $archiveEnd = null;
  if ($hitSlot['hm'] === '01:15') {
    $archiveStart = $todayLocal->modify('-2 days')->format('Y-m-d');
    $archiveEnd   = $todayLocal->format('Y-m-d');
    try {
      $archiveRows = $ing->fetchArchive($archiveStart, $archiveEnd);
    } catch (\Throwable $e2) {
      error_log("AutoCollect archive failed: " . $e2->getMessage());
    }
  }

  $prefix = rtrim(APP_WDS . '/storage/raw', '/');
  $rel = function($abs) use ($prefix){ $rel=preg_replace('#^'.preg_quote($prefix,'#').'#','',$abs); return $rel ?: basename($abs); };

  $saved=[]; if (is_array($rows)) { foreach ($rows as $r) { $saved[]=['location_id'=>(int)$r['location_id'],'snapshot'=>$rel($r['snapshot'])]; } }

  $savedArchive=[];
  if (is_array($archiveRows)) {
    foreach ($archiveRows as $r) {
      $savedArchive[]=['location_id'=>(int)$r['location_id'],'snapshot'=>$rel($r['snapshot'])];
    }
  }

  $resp = $base + [
    'in_window'=>true,
    'slot'=>['hm'=>$hitSlot['hm'],'window_local'=>[$hitSlot['start']->format('Y-m-d H:i'),$hitSlot['end']->format('Y-m-d H:i')]],
    'locations_total'=>$totalLoc,
    'locations_done'=>$doneLoc,
    'action'=>'collected',
    'days'=>$days,
    'saved'=>$saved
  ];
  if ($archiveRows !== null) {
    $resp['archive'] = [
      'start'=>$archiveStart,
      'end'=>$archiveEnd,
      'saved'=>$savedArchive
    ];
  }
  echo json_encode($resp);

} catch (\Throwable $e) {
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
