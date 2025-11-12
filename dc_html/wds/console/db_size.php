<?php
declare(strict_types=1);
$__tries=[__DIR__.'/../../../app/wds/bootstrap_compat.php',__DIR__.'/../../app/wds/bootstrap_compat.php',dirname(__DIR__,4).'/app/wds/bootstrap_compat.php'];
$__ok=false;foreach($__tries as $__p){if(is_file($__p)){require_once $__p;$__ok=true;break;}}if(!$__ok){http_response_code(500);echo"<!doctype html><meta charset='utf-8'><h1>WDS 引导未找到</h1><pre>".htmlspecialchars(implode("\n",$__tries))."</pre>";exit;}
$pdo=db();$q=$pdo->prepare("SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) AS mb FROM information_schema.TABLES WHERE table_schema = DATABASE()");
$q->execute();$mb=(float)($q->fetchColumn()?:0);
?>
<!doctype html><html lang="zh-CN"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>WDS · 数据库体积</title>
<style>:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--line:#1b2131}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,Helvetica Neue,Arial}
.wrap{max-width:720px;margin:22px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px}
small,.muted{color:var(--muted);font-size:12px}.btn{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff;text-decoration:none}.btn:hover{background:#202842}
.value{font-size:32px;font-weight:800}
</style></head><body><div class="wrap"><div class="card">
  <h3>数据库体积</h3>
  <p class="value"><?= number_format($mb,2) ?> MB</p>
  <p><a class="btn" href="/wds/">返回控制台</a></p>
</div></div></body></html>
