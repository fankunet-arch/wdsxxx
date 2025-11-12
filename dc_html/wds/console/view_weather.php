<?php
declare(strict_types=1);
$__tries=[__DIR__.'/../../../app/wds/bootstrap_compat.php',__DIR__.'/../../app/wds/bootstrap_compat.php',dirname(__DIR__,4).'/app/wds/bootstrap_compat.php'];
$__ok=false;foreach($__tries as $__p){if(is_file($__p)){require_once $__p;$__ok=true;break;}}if(!$__ok){http_response_code(500);echo"<!doctype html><meta charset='utf-8'><h1>WDS 引导未找到</h1><pre>".htmlspecialchars(implode("\n",$__tries))."</pre>";exit;}

$c=cfg();$pdo=db();$tz=$c['timezone_local']??'Europe/Madrid';
$date=$_GET['date']??(new DateTimeImmutable('now',new DateTimeZone($tz)))->format('Y-m-d');
$loc =$_GET['location_id']??'';
$TABLE_NAME = 'wds_weather_hourly_observed'; // [修复]

function headx(){echo <<<H
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>WDS · 天气查看</title>
<style>:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--line:#1b2131}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,Helvetica Neue,Arial}
.wrap{max-width:1080px;margin:22px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px}
small,.muted{color:var(--muted);font-size:12px}.btn{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff;text-decoration:none}
.btn:hover{background:#202842}label{display:block;margin:8px 0}input,select{background:#0c0f15;border:1px solid var(--line);color:var(--text);padding:8px 10px;border-radius:8px}
table{border-collapse:collapse;width:100%;margin-top:10px}th,td{border:1px solid #222b3d;padding:6px 8px;text-align:left}
.empty{border:1px dashed #2b3755;border-radius:12px;padding:14px;color:#9fb4df;background:#101521}
</style></head><body><div class="wrap"><div class="card">
H;}
function footx(){echo "</div></div></body></html>";}

function table_exists(PDO $pdo,string $name):bool{
  try{$q=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
      $q->execute([':t'=>$name]); return (bool)$q->fetchColumn();}catch(Throwable $e){return false;}
}

headx();
echo "<h3>天气查看 (<code>{$TABLE_NAME}</code>)</h3>"; // [修复]
// 位置下拉（如果有 wds_locations）
$locOptions=[];
if(table_exists($pdo,'wds_locations')){
  try{
    // [修复] 使用 location_id 并正确拼接
    $r=$pdo->query("SELECT location_id, name, city FROM wds_locations WHERE is_active = 1 ORDER BY location_id");
    while($row=$r->fetch(PDO::FETCH_ASSOC)){
        $label = $row['name'] ? (trim($row['name'] . ' - ' . $row['city'])) : ('ID ' . $row['location_id']);
        $locOptions[(string)$row['location_id']] = $label;
    }
  }catch(Throwable $e){}
}

echo "<form method='get'><label>日期　<input type='date' name='date' value='".htmlspecialchars($date)."'></label>";
if($locOptions){
  echo "<label>地点　<select name='location_id'><option value=''>全部</option>";
  foreach($locOptions as $id=>$label){ $sel = ($id===(string)$loc)?' selected':''; echo "<option value='".htmlspecialchars($id)."'{$sel}>".htmlspecialchars($label)."</option>"; }
  echo "</select></label>";
}else{
  echo "<label>地点ID（可留空）　<input type='text' name='location_id' value='".htmlspecialchars($loc)."'></label>";
}
echo "<p><button class='btn' type='submit'>查询</button>　<a class='btn' href='/wds/'>返回控制台</a></p></form>";

$has_obs = table_exists($pdo, $TABLE_NAME);
if(!$has_obs){
  echo "<div class='empty'>当前库中找不到 <code>{$TABLE_NAME}</code> 表，无法展示小时实况。</div>";
  footx(); exit;
}

try{
  $sql="SELECT * FROM {$TABLE_NAME} WHERE obs_time_utc BETWEEN :a AND :b"; // [修复]
  $args=[':a'=>$date.' 00:00:00', ':b'=>$date.' 23:59:59'];
  if($loc!==''){
    $sql.=" AND location_id = :lid";
    $args[':lid']=$loc;
  }
  $sql.=" ORDER BY obs_time_utc"; // [修复]
  $st=$pdo->prepare($sql); $st->execute($args);
  $rows=$st->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){
  $rows=[]; // 出错也不 500，给提示
  echo "<div class='empty'>查询出错: ".htmlspecialchars($e->getMessage())."</div>";
}

if(!$rows){
  echo "<div class='empty'>未查询到数据。</div>"; footx(); exit;
}

$cols=array_keys($rows[0]); // 动态列
echo "<table><thead><tr>";
foreach($cols as $cname){ echo "<th>".htmlspecialchars($cname)."</th>"; }
echo "</tr></thead><tbody>";
foreach($rows as $r){
  echo "<tr>";
  foreach($cols as $cname){ $v=$r[$cname]; if($v===null)$v=''; echo "<td>".htmlspecialchars((string)$v)."</td>"; }
  echo "</tr>";
}
echo "</tbody></table><p class='muted'>共 ".count($rows)." 行。</p>";
footx();