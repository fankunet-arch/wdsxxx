<?php
require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');
header('Content-Type: application/json; charset=utf-8');
try {
  $pdo = db();
  $cfg = cfg();
  $tzLocal = $cfg['timezone_local'] ?? 'Europe/Madrid';
  $slots = ['01:15','11:15','13:15','16:15','19:15'];
  $now = new DateTimeImmutable('now', new DateTimeZone($tzLocal));
  $out = [
    'now_local' => $now->format('Y-m-d H:i'),
    'now_epoch_ms' => (int)$now->format('U')*1000,
    'timezone' => $tzLocal,
    'slots' => []
  ];
  $q = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM wds_weather_hourly_forecast WHERE run_time_utc BETWEEN :u1 AND :u2 LIMIT 1) AS hit");
  foreach ($slots as $hm) {
    [$h,$m] = array_map('intval', explode(':', $hm));
    $cands = [
      $now->modify('-1 day')->setTime($h,$m,0),
      $now->setTime($h,$m,0),
      $now->modify('+1 day')->setTime($h,$m,0),
    ];
    $best=null;$bestDiff=PHP_INT_MAX;
    foreach ($cands as $dt){
      $d = abs(((int)$dt->format('U') - (int)$now->format('U')));
      if ($d < $bestDiff){ $best=$dt; $bestDiff=$d; }
    }
    $slotLocal = $best;
    $slotUtc = $slotLocal->setTimezone(new DateTimeZone('UTC'));
    $u1 = $slotUtc->modify('-30 minutes')->format('Y-m-d H:i:s');
    $u2 = $slotUtc->modify('+30 minutes')->format('Y-m-d H:i:s');
    $q->execute([':u1'=>$u1, ':u2'=>$u2]);
    $done = $q->fetchColumn() ? true : false;
    $out['slots'][] = [
      'hm'=>$hm,
      'target_local'=>$slotLocal->format('Y-m-d H:i'),
      'target_epoch_ms'=>(int)$slotLocal->format('U')*1000,
      'done'=>$done
    ];
  }
  echo json_encode($out);
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
