<?php
declare(strict_types=1);

// === Bootstrap ===
$__tries=[__DIR__.'/../../../app/wds/bootstrap_compat.php',__DIR__.'/../../app/wds/bootstrap_compat.php',dirname(__DIR__,4).'/app/wds/bootstrap_compat.php'];
$__ok=false;foreach($__tries as $__p){if(is_file($__p)){require_once $__p;$__ok=true;break;}}if(!$__ok){http_response_code(500);echo"<!doctype html><meta charset='utf-8'><h1>WDS 引导未找到</h1><pre>".htmlspecialchars(implode("\n",$__tries))."</pre>";exit;}
// Runner
$__runners=[dirname(__DIR__,4).'/app/wds/ingest/Runner.php',dirname(__DIR__,3).'/app/wds/ingest/Runner.php'];
foreach($__runners as $__p){ if(is_file($__p)){ require_once $__p; break; } }

use WDS\ingest\Runner;

$c = cfg();
$tz = $c['timezone_local'] ?? 'Europe/Madrid';
$date = $_POST['date'] ?? ($_GET['date'] ?? (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d'));
$slot = $_POST['slot'] ?? ($_GET['slot'] ?? 'auto');
$want_json = (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json');

function theme_head(string $title): void {
  echo <<<HTML
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--primary:#6aa1ff;--ok:#4cd964;--warn:#ffcc00;--danger:#ff3b30;--line:#1b2131}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,Helvetica Neue,Arial}
.wrap{max-width:960px;margin:22px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
h1,h2,h3{margin:0 0 8px 0}small,.muted{color:var(--muted);font-size:12px}
.btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff;text-decoration:none}.btn:hover{background:#202842}.btn-ok{background:rgba(76,217,100,.12);border-color:#4cd964;color:#c2f0cd}
label{display:block;margin:8px 0}input,select{background:#0c0f15;border:1px solid var(--line);color:var(--text);padding:8px 10px;border-radius:8px}
.alert-ok{border:1px solid #204b2b;background:#102117;color:#b9f8c8;border-radius:12px;padding:14px}
.alert-err{border:1px solid #5b1f1f;background:#200f0f;color:#ffd3d3;border-radius:12px;padding:14px}
</style></head><body><div class="wrap">
HTML;
}
function theme_foot(): void { echo '</div></body></html>'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $ok = true; $msg = '预报任务已受理'; $slotUsed = $slot;
  try {
    $runner = class_exists(Runner::class) ? new Runner(db(), cfg()) : null;
    if ($runner) {
      $ret = $runner->forecast($date, $slot);
      $slotUsed = (string)($ret['slot'] ?? $slot);
    } else {
      log_msg("run_forecast fallback: {$date} {$slot}");
    }
  } catch (Throwable $e) {
    $ok = false; $msg = $e->getMessage(); log_msg('run_forecast error: '.$msg);
  }

  if ($want_json) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>$ok?'ok':'error','message'=>$msg,'date'=>$date,'slot'=>$slotUsed], JSON_UNESCAPED_UNICODE); exit;
  }

  theme_head('WDS · 运行预报');
  echo '<div class="card">';
  echo $ok ? "<h3>操作成功</h3><div class='alert-ok'><b>{$msg}</b>。</div>" : "<h3>操作失败</h3><div class='alert-err'>".htmlspecialchars($msg)."</div>";
  echo "<p class='muted'>日期：".htmlspecialchars($date)."　时段：".htmlspecialchars($slotUsed)."</p>";
  echo "<p><a class='btn btn-ok' href='/wds/'>返回控制台</a>　<a class='btn' href='/wds/console/run_forecast.php'>再次提交</a></p></div>";
  theme_foot(); exit;
}

// GET：显示表单
theme_head('WDS · 运行预报');
?>
<div class="card">
  <h3>运行预报</h3>
  <form method="post">
    <label>日期 <input type="date" name="date" value="<?=htmlspecialchars($date)?>"></label>
    <label>时段
      <select name="slot">
        <?php foreach (['auto','07:15','11:15','13:15','19:15','01:15'] as $o): ?>
          <option value="<?=$o?>" <?=$o===$slot?'selected':''?>><?=$o?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <p><button class="btn btn-ok" type="submit">提交</button>　<a class="btn" href="/wds/">返回控制台</a></p>
  </form>
  <small class="muted">自动化：<code>?format=json</code> 返回 JSON。</small>
</div>
<?php theme_foot(); ?>
