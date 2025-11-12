<?php
declare(strict_types=1);
$__tries=[__DIR__.'/../../../app/wds/bootstrap_compat.php',__DIR__.'/../../app/wds/bootstrap_compat.php',dirname(__DIR__,4).'/app/wds/bootstrap_compat.php'];
$__ok=false;foreach($__tries as $__p){if(is_file($__p)){require_once $__p;$__ok=true;break;}}if(!$__ok){http_response_code(500);echo"<!doctype html><meta charset='utf-8'><h1>WDS 引导未找到</h1><pre>".htmlspecialchars(implode("\n",$__tries))."</pre>";exit;}
use WDS\ingest\OpenMeteoIngest;

function headx($t){echo <<<H
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t}</title>
<style>:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--line:#1b2131}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,Helvetica Neue,Arial}
.wrap{max-width:960px;margin:22px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px}
small,.muted{color:var(--muted);font-size:12px}.btn{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff;text-decoration:none}.btn:hover{background:#202842}
table{border-collapse:collapse;width:100%;margin-top:10px}th,td{border:1px solid #222b3d;padding:6px 8px;text-align:left}
.alert-err{border:1px solid #5b1f1f;background:#200f0f;color:#ffd3d3;border-radius:12px;padding:14px}
.alert-ok{border:1px solid #204b2b;background:#102117;color:#b9f8c8;border-radius:12px;padding:14px}
pre{background:#0c0f15;border:1px solid #1b2131;border-radius:10px;padding:12px;color:#cfe1ff;overflow:auto}
</style></head><body><div class="wrap"><div class="card">
H;}
function footx(){echo "</div></div></body></html>";}

$start=$_POST['start'] ?? null;
$end  =$_POST['end']   ?? null;
$log = [];

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!$start || !$end){
    headx('WDS · 历史归档'); echo "<h3>参数缺失</h3><div class='alert-err'>需要 start/end 日期</div><p><a class='btn' href='/wds/'>返回控制台</a>　<a class='btn' href='/wds/console/run_archive.php'>返回表单</a></p>"; footx(); exit;
  }
  
  $results=[];
  $pdo=db();
  
  try{
    // [升级] 从 wds_locations 读取地点
    $locations = $pdo->query("SELECT location_id, name, lat, lon FROM wds_locations WHERE is_active = 1")->fetchAll();
    if (!$locations) {
        $log[] = "[ERROR] wds_locations 中没有找到 'is_active = 1' 的地点。";
    }

    $ingest = class_exists(OpenMeteoIngest::class) ? new OpenMeteoIngest($pdo,cfg()) : null;

    foreach ($locations as $loc) {
        $loc_id = $loc['location_id'];
        $log[] = "[INFO] 开始处理地点 #{$loc_id} ({$loc['name']}) [{$loc['lat']}, {$loc['lon']}]...";
        if($ingest){
          // $rows=$ing->fetchArchive($start,$end); // TODO: 您的真实 Ingest 逻辑应传入地点
          // 模拟
          $results[] = ['location_id' => $loc_id, 'snapshot' => "{$start}~{$end} 模拟抓取 " . rand(100, 200) . " 条"];
          $log[] = "[OK] 地点 #{$loc_id} 处理完毕。";
        } else {
          $log_msg = "stub run_archive: Loc #{$loc_id} {$start}~{$end}";
          log_msg($log_msg);
          $results[] = ['location_id' => $loc_id, 'snapshot' => $log_msg];
          $log[] = "[WARN] 地点 #{$loc_id} OpenMeteoIngest 未加载，仅记录 stub log。";
        }
    }

  }catch(Throwable $e){
      headx('WDS · 历史归档');
      echo "<h3>执行失败</h3><div class='alert-err'>".htmlspecialchars($e->getMessage())."</div>";
      echo "<pre>".htmlspecialchars(implode("\n", $log))."</pre>";
      echo "<p><a class='btn' href='/wds/'>返回控制台</a></p>";
      footx();
      exit;
  }

  headx('WDS · 历史归档结果');
  echo "<h3>历史归档结果</h3><small class='muted'>范围：".htmlspecialchars($start)." ~ ".htmlspecialchars($end)."</small>";
  
  if($log){
      echo "<h4>执行日志</h4><pre>".htmlspecialchars(implode("\n", $log))."</pre>";
  }
  
  if(!$results){ echo "<p class='muted' style='margin-top:10px'>无数据。</p>"; }
  else{
    echo "<h4>归档摘要</h4><table><thead><tr><th>location_id</th><th>snapshot</th></tr></thead><tbody>";
    foreach($results as $r){ echo "<tr><td>".(int)($r['location_id']??0)."</td><td>".htmlspecialchars((string)($r['snapshot']??'')) ."</td></tr>"; }
    echo "</tbody></table>";
  }
  echo "<p><a class='btn' href='/wds/'>返回控制台</a>　<a class='btn' href='/wds/console/run_archive.php'>继续</a></p>";
  footx(); exit;
}

headx('WDS · 历史归档');
?>
<h3>历史归档</h3>
<p class="muted">此操作将为 `wds_locations` 中所有 <kbd>is_active = 1</kbd> 的地点，抓取指定日期范围的历史观测数据。</p>
<form method="post">
  <label>开始日期 (UTC)　<input type="date" name="start" required></label>
  <label>结束日期 (UTC)　<input type="date" name="end" required></label>
  <p><button class="btn" type="submit">执行</button>　<a class="btn" href="/wds/">返回控制台</a></p>
</form>
<?php footx(); ?>