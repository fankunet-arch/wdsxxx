<?php
declare(strict_types=1);
$__tries=[__DIR__.'/../../../app/wds/bootstrap_compat.php',__DIR__.'/../../app/wds/bootstrap_compat.php',dirname(__DIR__,4).'/app/wds/bootstrap_compat.php'];
$__ok=false;foreach($__tries as $__p){if(is_file($__p)){require_once $__p;$__ok=true;break;}}if(!$__ok){http_response_code(500);echo"<!doctype html><meta charset='utf-8'><h1>WDS 引导未找到</h1><pre>".htmlspecialchars(implode("\n",$__tries))."</pre>";exit;}

$c=cfg();$pdo=db();$tz=$c['timezone_local']??'Europe/Madrid';
$back=isset($_GET['back'])?max(0,min(14,(int)$_GET['back'])):3;
$fwd =isset($_GET['fwd']) ?max(0,min(14,(int)$_GET['fwd'])) :2;

// [修复] 确保时隙与 Runner/Config 中定义的一致
$SLOTS = $c['auto_collect']['slots'] ?? ['01:15','07:15','11:15','13:15','19:15'];

function headx(){echo <<<H
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>WDS · 历史记录</title>
<style>:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--line:#1b2131;--ok:#4cd964;--warn:#ffcc00;--danger:#ff3b30}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,Helvetica Neue,Arial}
.wrap{max-width:1080px;margin:22px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px}
table{border-collapse:collapse;width:100%}th,td{border:1px solid #222b3d;padding:6px 8px;text-align:left}
small,.muted{color:var(--muted);font-size:12px}.ok{color:var(--ok)}.part{color:#e6a23c}.miss{color:var(--danger)}
.btn{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff;text-decoration:none}.btn:hover{background:#202842}
</style></head><body><div class="wrap"><div class="card">
H;}
function footx(){echo "</div></div></body></html>";}

function table_exists(PDO $pdo, string $name): bool {
  try {
    $q=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute([':t'=>$name]); return (bool)$q->fetchColumn();
  } catch(Throwable $e){ return false; }
}

headx();

$todayLoc=new DateTimeImmutable('now', new DateTimeZone($tz));
$start=$todayLoc->modify("-{$back} days")->format('Y-m-d');
$end  =$todayLoc->modify("+{$fwd} days")->format('Y-m-d');

echo "<h3>历史记录</h3><p class='muted'>范围：".htmlspecialchars($start)." ~ ".htmlspecialchars($end)."（本地时区：".htmlspecialchars($tz)."）</p>";

echo "<table><thead><tr><th>日期</th>";
foreach($SLOTS as $hm){ echo "<th>{$hm}</th>"; }
echo "<th>总览</th></tr></thead><tbody>";

$has_execlog = table_exists($pdo,'wds_exec_log');
$has_obs     = table_exists($pdo,'wds_weather_hourly_observed'); // [修复]

$cur=new DateTimeImmutable($start,new DateTimeZone($tz)); $endD=new DateTimeImmutable($end,new DateTimeZone($tz));
while($cur <= $endD){
  $ds=$cur->format('Y-m-d'); $total=0;$done=0; echo "<tr><td><b>{$ds}</b></td>";
  $slotData=array_fill_keys($SLOTS,['sum_total'=>0,'sum_done'=>0]);
  try{
    if($has_execlog){
      $q=$pdo->prepare("SELECT hm,SUM(total) sum_total,SUM(done) sum_done FROM wds_exec_log WHERE exec_date=:d GROUP BY hm");
      $q->execute([':d'=>$ds]);
      while($r=$q->fetch(PDO::FETCH_ASSOC)){ $hm=$r['hm']??''; if(isset($slotData[$hm])){ $slotData[$hm]['sum_total']=(int)($r['sum_total']??0); $slotData[$hm]['sum_done']=(int)($r['sum_done']??0); } }
    } elseif($has_obs) {
      // [修复] 退化策略：按是否有实况记录判断“完成”
      $q=$pdo->prepare("SELECT COUNT(*) FROM wds_weather_hourly_observed WHERE obs_time_utc BETWEEN :a AND :b"); // [修复]
      $q->execute([':a'=>$ds.' 00:00:00',':b'=>$ds.' 23:59:59']);
      $cnt=(int)$q->fetchColumn(); $slotData['07:15']['sum_total']=1; $slotData['07:15']['sum_done']=$cnt>0?1:0;
    }
  } catch(Throwable $e){ /* 忽略，保持页面可用 */ }

  foreach($SLOTS as $hm){
    $v=$slotData[$hm]; $total += (int)$v['sum_total']; $done += (int)$v['sum_done'];
    $cls = ($v['sum_total']>0) ? (($v['sum_done']>=$v['sum_total'])?'ok':($v['sum_done']>0?'part':'miss')) : 'miss';
    echo "<td><span class='{$cls}'>".$v['sum_done']."/".$v['sum_total']."</span></td>";
  }
  $clsAll = ($done>0 && $done>=$total)?'ok':(($done>0)?'part':'miss');
  echo "<td><span class='{$clsAll}'>{$done}/{$total}</span></td></tr>";
  $cur=$cur->modify('+1 day');
}
echo "</tbody></table><p style='margin-top:12px'><a class='btn' href='/wds/'>返回控制台</a></p>";

footx();