<?php
declare(strict_types=1);
$__tries=[__DIR__.'/../../../app/wds/bootstrap_compat.php',__DIR__.'/../../app/wds/bootstrap_compat.php',dirname(__DIR__,4).'/app/wds/bootstrap_compat.php'];
$__ok=false;foreach($__tries as $__p){if(is_file($__p)){require_once $__p;$__ok=true;break;}}if(!$__ok){http_response_code(500);echo"<!doctype html><meta charset='utf-8'><h1>WDS 引导未找到</h1><pre>".htmlspecialchars(implode("\n",$__tries))."</pre>";exit;}

$c=cfg();$tz=$c['timezone_local']??'Europe/Madrid';
$start=$_POST['start']??($_GET['start']??gmdate('Y-m-d',strtotime('-1 day')));
$end  =$_POST['end']  ??($_GET['end']  ??gmdate('Y-m-d'));
$want_json=(isset($_GET['format'])&&strtolower((string)$_GET['format'])==='json');

function headx($t){echo <<<H
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t}</title>
<style>:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--primary:#6aa1ff;--ok:#4cd964;--warn:#ffcc00;--danger:#ff3b30;--line:#1b2131}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,Helvetica Neue,Arial}
.wrap{max-width:960px;margin:22px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
h1,h2,h3{margin:0 0 8px 0}small,.muted{color:var(--muted);font-size:12px}
.btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff;text-decoration:none}.btn:hover{background:#202842}.btn-warn{background:rgba(255,204,0,.12);border-color:#ffcc00;color:#fff0b3}
label{display:block;margin:8px 0}input{background:#0c0f15;border:1px solid var(--line);color:var(--text);padding:8px 10px;border-radius:8px}
.alert-ok{border:1px solid #204b2b;background:#102117;color:#b9f8c8;border-radius:12px;padding:14px}
.alert-err{border:1px solid #5b1f1f;background:#200f0f;color:#ffd3d3;border-radius:12px;padding:14px}
</style></head><body><div class="wrap">
H;}
function footx(){echo '</div></body></html>';}

if($_SERVER['REQUEST_METHOD']==='POST'){
  $ok=true;$msg='核验任务已受理';
  try{ log_msg("run_verify submit: {$start} ~ {$end}"); /* TODO: 实际核验逻辑 */ }
  catch(Throwable $e){ $ok=false;$msg=$e->getMessage(); log_msg('run_verify error: '.$msg); }

  if($want_json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['status'=>$ok?'ok':'error','message'=>$msg,'start'=>$start,'end'=>$end],JSON_UNESCAPED_UNICODE);exit;}

  headx('WDS · 预报核验');
  echo '<div class="card">';
  echo $ok ? "<h3>操作成功</h3><div class='alert-ok'><b>{$msg}</b>。</div>" : "<h3>操作失败</h3><div class='alert-err'>".htmlspecialchars($msg)."</div>";
  echo "<p class='muted'>范围：".htmlspecialchars($start)." ~ ".htmlspecialchars($end)."</p>";
  echo "<p><a class='btn' href='/wds/'>返回控制台</a>　<a class='btn btn-warn' href='/wds/console/run_verify.php'>再次提交</a></p></div>";
  footx(); exit;
}

headx('WDS · 预报核验');
?>
<div class="card">
  <h3>预报核验</h3>
  <form method="post">
    <label>开始日期 <input type="date" name="start" value="<?=htmlspecialchars($start)?>"></label>
    <label>结束日期 <input type="date" name="end" value="<?=htmlspecialchars($end)?>"></label>
    <p><button class="btn btn-warn" type="submit">提交</button>　<a class="btn" href="/wds/">返回控制台</a></p>
  </form>
  <small class="muted">自动化：在 URL 后加 <code>?format=json</code> 可返回 JSON。</small>
</div>
<?php footx(); ?>
