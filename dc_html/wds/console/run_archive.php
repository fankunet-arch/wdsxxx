<?php
require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');
use WDS\ingest\OpenMeteoIngest;


<?php
function _wds_rel_snap(string $abs) : string {
  $prefix = rtrim(APP_WDS . '/storage/raw', '/');
  $rel = preg_replace('#^' . preg_quote($prefix, '#') . '#', '', $abs);
  return $rel ?: basename($abs);
}
?>

$start = $_POST['start'] ?? null;
$end   = $_POST['end'] ?? null;
if (!$start || !$end) { http_response_code(400); echo "<!doctype html><meta charset='utf-8'><h1>参数缺失</h1><p>需要 start / end</p><p><a href='/wds/'>返回控制台</a></p>"; exit; }

try {
  $pdo = db();
  $ing = new OpenMeteoIngest($pdo, cfg());
  $rows = $ing->fetchArchive($start, $end);
} catch (Throwable $e) {
  http_response_code(500);
  echo "<!doctype html><meta charset='utf-8'><h1>失败</h1><pre>" . htmlspecialchars($e->getMessage()) . "</pre><p><a href='/wds/'>返回控制台</a></p>";
  exit;
}

$count = is_array($rows) ? count($rows) : 0;
$version = 'wds-0.3.3';
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>历史回填 · 结果</title><link rel="stylesheet" href="/wds/assets/css/console.css"></head>
<body>
  <div class="wrap">
    <div class="card">
      <h2 style="font-size:20px">✅ 历史回填完成</h2>
      <p class="muted">版本 <?=$version?> · 区间：<?=htmlspecialchars($start)?> → <?=htmlspecialchars($end)?> · 本次更新地点数：<b><?=$count?></b></p>
      <?php if ($count): ?>
      <table><thead><tr><th>location_id</th><th>快照（相对路径）</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td><?= (int)$r['location_id'] ?></td><td><?= htmlspecialchars(_wds_rel_snap($r['snapshot'])) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
      <p style="margin-top:12px"><a href="/wds/"><button class="btn-aux" type="button">返回控制台</button></a></p>
    </div>
  </div>
</body>
</html>
