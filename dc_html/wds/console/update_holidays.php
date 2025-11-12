<?php
declare(strict_types=1);
$__tries=[__DIR__.'/../../../app/wds/bootstrap_compat.php',__DIR__.'/../../app/wds/bootstrap_compat.php',dirname(__DIR__,4).'/app/wds/bootstrap_compat.php'];
$__ok=false;foreach($__tries as $__p){if(is_file($__p)){require_once $__p;$__ok=true;break;}}if(!$__ok){http_response_code(500);echo"<!doctype html><meta charset='utf-8'><h1>WDS 引导未找到</h1><pre>".htmlspecialchars(implode("\n",$__tries))."</pre>";exit;}

$c=cfg();$pdo=db();$year=isset($_POST['year'])?(int)$_POST['year']:(int)date('Y');
$want_json=(isset($_GET['format'])&&strtolower((string)$_GET['format'])==='json');
$COUNTRY_CODE = 'ES';
$SCOPE = 'national';
$SOURCE = 'nager';

function headx($t){echo <<<H
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t}</title>
<style>:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--primary:#6aa1ff;--ok:#4cd964;--line:#1b2131}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,Helvetica Neue,Arial}
.wrap{max-width:960px;margin:22px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
h1,h2,h3{margin:0 0 8px 0}small,.muted{color:var(--muted);font-size:12px}
.btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff;text-decoration:none}.btn:hover{background:#202842}
label{display:block;margin:8px 0}input{background:#0c0f15;border:1px solid var(--line);color:var(--text);padding:8px 10px;border-radius:8px}
.alert-ok{border:1px solid #204b2b;background:#102117;color:#b9f8c8;border-radius:12px;padding:14px}
.alert-err{border:1px solid #5b1f1f;background:#200f0f;color:#ffd3d3;border-radius:12px;padding:14px}
table{border-collapse:collapse;width:100%;margin-top:10px}th,td{border:1px solid #222b3d;padding:6px 8px;text-align:left}
</style></head><body><div class="wrap">
H;}
function footx(){echo '</div></body></html>';}

if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    $url="https://date.nager.at/api/v3/PublicHolidays/{$year}/{$COUNTRY_CODE}";
    $ctx=stream_context_create(['http'=>['timeout'=>10]]);
    $json=@file_get_contents($url,false,$ctx);
    $arr=json_decode($json??'',true);
    if(!is_array($arr)) throw new RuntimeException('无法获取节假日数据 (Nager API)');

    $pdo->beginTransaction();
    // [修复] 按新的 v2 schema (scope, code, date_local) 删除
    $pdo->prepare("DELETE FROM wds_holidays WHERE scope = :scope AND code = :code AND YEAR(date_local) = :y")
        ->execute([':scope' => $SCOPE, ':code' => $COUNTRY_CODE, ':y' => $year]);
    
    // [修复] 按新的 v2 schema 插入
    $ins=$pdo->prepare(
      "INSERT INTO wds_holidays(scope, code, date_local, name_local, name_en, is_public_holiday, source, ingested_at)
       VALUES(:scope, :code, :dl, :nl, :ne, 1, :src, utc_timestamp(6))"
    );
    $n=0;
    foreach($arr as $h){
      $ins->execute([
        ':scope' => $SCOPE,
        ':code'  => $COUNTRY_CODE,
        ':dl'    => $h['date'] ?? null,
        ':nl'    => $h['localName'] ?? '',
        ':ne'    => $h['name'] ?? '',
        ':src'   => $SOURCE
      ]);
      $n++;
    }
    $pdo->commit();
    $ok=true; $msg="已拉取并覆盖 {$year} 年 {$COUNTRY_CODE} ({$SCOPE}) 节假日，共 {$n} 条。";
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    $ok=false; $msg=$e->getMessage(); log_msg('update_holidays error: '.$msg);
  }

  if($want_json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['status'=>$ok?'ok':'error','message'=>$msg,'year'=>$year],JSON_UNESCAPED_UNICODE);exit;}

  headx('WDS · 节假日维护');
  echo '<div class="card">';
  echo $ok ? "<h3>操作成功</h3><div class='alert-ok'>".htmlspecialchars($msg)."</div>" : "<h3>操作失败</h3><div class='alert-err'>".htmlspecialchars($msg)."</div>";
  echo "<p><a class='btn' href='/wds/'>返回控制台</a>　<a class='btn' href='/wds/console/update_holidays.php'>再次提交</a></p>";
  echo '</div>'; footx(); exit;
}

headx('WDS · 节假日维护');
?>
<div class="card">
  <h3>WDS 节假日维护 (<?= htmlspecialchars($COUNTRY_CODE) ?>)</h3>
  <form method="post">
    <label>年份　<input type="number" name="year" value="<?= (int)$year ?>"></label>
    <p><button class="btn" type="submit">拉取并覆盖</button>　<a class="btn" href="/wds/">返回控制台</a></p>
  </form>
  <small class="muted">数据源：Nager.at (<?= htmlspecialchars($SCOPE) ?>) | 自动化：<code>?format=json</code> 返回 JSON。</small>
</div>
<?php footx(); ?>