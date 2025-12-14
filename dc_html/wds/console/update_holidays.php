<?php
require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');

$year = isset($_POST['year']) ? max(2000, min(2100, (int)$_POST['year'])) : (int)date('Y');
$pdo = db();

function http_get_json($url){
  $ch = curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>true]);
  $body=curl_exec($ch); if($body===false){$e=curl_error($ch);curl_close($ch);throw new \RuntimeException($e);}
  $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch); if($code<200||$code>=300) throw new \RuntimeException("HTTP $code");
  $j=json_decode($body,true); if(!is_array($j)) throw new \RuntimeException("Bad JSON"); return $j;
}

try {
  $list = http_get_json("https://date.nager.at/api/v3/PublicHolidays/$year/ES");
  $ins = $pdo->prepare("
    INSERT INTO wds_holidays (scope_key, date, local_name, name_en, country_code, fixed, global, type, created_at, updated_at)
    VALUES (:k,:d,:ln,:en,'ES',:fx,:gl,:tp,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
    ON DUPLICATE KEY UPDATE local_name=VALUES(local_name), name_en=VALUES(name_en), fixed=VALUES(fixed), global=VALUES(global), type=VALUES(type), updated_at=UTC_TIMESTAMP(6)
  ");
  $pdo->beginTransaction();
  foreach($list as $h){
    $key = sprintf('national-ES-%s', $h['date']);
    $ins->execute([
      ':k'=>$key, ':d'=>$h['date'], ':ln'=>$h['localName'] ?? $h['name'], ':en'=>$h['name'],
      ':fx'=>!empty($h['fixed'])?1:0, ':gl'=>!empty($h['global'])?1:0, ':tp'=>($h['type'] ?? null),
    ]);
  }
  $pdo->commit();
} catch (\Throwable $e){
  http_response_code(500);
  echo "<!doctype html><meta charset='utf-8'><h1>失败</h1><pre>".htmlspecialchars($e->getMessage())."</pre><p><a href='/wds/'>返回控制台</a></p>";
  exit;
}
?>
<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/wds/assets/css/console.css">
<div class="wrap"><div class="card"><h2>✅ 节假日已更新</h2><p class="muted"><?=$year?> · 来源 Nager.Date</p><p><a href="/wds/"><button class="btn-aux">返回控制台</button></a></p></div></div>
