<?php
declare(strict_types=1);

/**
 * WDS API · 小时实况
 * - 默认 JSON；?format=html 提供简洁可读界面
 * - 新增 token 鉴权：Authorization: Bearer <token> 或 ?token=xxx
 *
 * [修复]
 * - wds_hourly_observed -> wds_weather_hourly_observed
 * - ts -> obs_time_utc
 */

$__tries = [
  __DIR__ . '/../../../../app/wds/bootstrap_compat.php',
  __DIR__ . '/../../../app/wds/bootstrap_compat.php',
  dirname(__DIR__, 5) . '/app/wds/bootstrap_compat.php',
];
$__ok=false; foreach($__tries as $__p){ if(is_file($__p)){ require_once $__p; $__ok=true; break; } }
if (!$__ok) { header('Content-Type:text/plain; charset=utf-8',true,500); echo "bootstrap_compat.php not found"; exit; }

$c   = cfg();
$pdo = db();

$format  = strtolower((string)($_GET['format'] ?? 'json'));
$from    = $_GET['from'] ?? gmdate('Y-m-d 00:00:00');
$to      = $_GET['to']   ?? gmdate('Y-m-d 23:59:59');
$location_id = $_GET['location_id'] ?? null;

function html_head(string $title): void {
  echo <<<HTML
<!doctype html><html lang="zh-CN"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--line:#1b2131}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Arial}
.wrap{max-width:1080px;margin:22px auto;padding:0 16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
small,.muted{color:var(--muted);font-size:12px}
.btn{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff;text-decoration:none}
.btn:hover{background:#202842}
label{display:block;margin:8px 0}
input,select{background:#0c0f15;border:1px solid var(--line);color:var(--text);padding:8px 10px;border-radius:8px}
table{border-collapse:collapse;width:100%;margin-top:10px}
th,td{border:1px solid #222b3d;padding:6px 8px;text-align:left}
.empty{border:1px dashed #2b3755;border-radius:12px;padding:14px;color:#9fb4df;background:#101521;margin-top:10px}
.row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
@media (max-width:900px){.row{grid-template-columns:1fr}}
</style></head><body><div class="wrap"><div class="card">
HTML;
}
function html_foot(): void { echo "</div></div></body></html>"; }

//
// 鉴权
//
function read_bearer_token(): ?string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
  if ($h && stripos($h, 'bearer ') === 0) return trim(substr($h, 7));
  return null;
}
$cfgToken = (string)($c['api_token'] ?? '');
$reqToken = (string)($_GET['token'] ?? '') ?: (read_bearer_token() ?? '');
if ($cfgToken !== '' && $reqToken !== $cfgToken) {
  if ($format === 'html') {
    header('Content-Type:text/html; charset=utf-8', true, 401);
    html_head('WDS · 小时实况');
    echo "<h3>401 未授权</h3><div class='empty'>token 缺失或不匹配。</div>";
    echo "<p><a class='btn' href='/wds/'>返回控制台</a></p>";
    html_foot(); exit;
  }
  header('Content-Type: application/json; charset=utf-8', true, 401);
  echo json_encode(['status'=>'error','error'=>'unauthorized','message'=>'token missing or invalid'], JSON_UNESCAPED_UNICODE); exit;
}

//
// 参数校验
//
if (strtotime($from) === false || strtotime($to) === false) {
  $msg = '参数 from/to 非法';
  if ($format === 'html') {
    header('Content-Type:text/html; charset=utf-8');
    html_head('WDS · 小时实况');
    echo "<h3>参数错误</h3><div class='empty'>".htmlspecialchars($msg)."</div><p><a class='btn' href='/wds/'>返回控制台</a></p>";
    html_foot(); exit;
  }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['status'=>'error','message'=>$msg], JSON_UNESCAPED_UNICODE); exit;
}

//
// 查询
//
$TABLE_NAME = 'wds_weather_hourly_observed'; // [修复]

try {
  // 表存在性
  $q=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
  $q->execute([':t'=>$TABLE_NAME]);
  $hasTable = (bool)$q->fetchColumn();
  if (!$hasTable) throw new RuntimeException("表 {$TABLE_NAME} 不存在");

  $sql  = "SELECT * FROM {$TABLE_NAME} WHERE obs_time_utc BETWEEN :a AND :b"; // [修复]
  $args = [':a'=>$from, ':b'=>$to];

  if ($location_id !== null && $location_id !== '') {
    // 容错检测列是否存在 (location_id 必然存在于此表)
    $sql .= " AND location_id = :lid";
    $args[':lid'] = $location_id;
  }

  $sql .= " ORDER BY obs_time_utc"; // [修复]
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  if ($format === 'html') {
    header('Content-Type:text/html; charset=utf-8');
    html_head('WDS · 小时实况');
    echo "<h3>查询失败</h3><div class='empty'>".htmlspecialchars($e->getMessage())."</div><p><a class='btn' href='/wds/'>返回控制台</a></p>";
    html_foot(); exit;
  }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['status'=>'error','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE); exit;
}

//
// 输出
//
if ($format === 'html') {
  header('Content-Type:text/html; charset=utf-8');
  html_head('WDS · 小时实况');
  $qs = http_build_query(['from'=>$from,'to'=>$to,'location_id'=>$location_id,'format'=>'json']);
  echo "<h3>小时实况 (<code>{$TABLE_NAME}</code>)</h3>"; // [修复]
  echo "<form method='get' class='row' style='margin-top:6px'>
          <label>起始时间（UTC）<input name='from' value='".htmlspecialchars($from)."'></label>
          <label>结束时间（UTC）<input name='to' value='".htmlspecialchars($to)."'></label>
          <label>location_id（可选）<input name='location_id' value='".htmlspecialchars((string)$location_id)."'></label>
          <button class='btn' type='submit'>查询</button>
        </form>";
  if (!$rows) {
    echo "<div class='empty'>无数据</div>";
    html_foot(); exit;
  }
  $cols = array_keys($rows[0]);
  echo "<table><thead><tr>";
  foreach ($cols as $cname) echo "<th>".htmlspecialchars($cname)."</th>";
  echo "</tr></thead><tbody>";
  foreach ($rows as $r) {
    echo "<tr>";
    foreach ($cols as $cname) {
      $v = $r[$cname]; $v = $v === null ? '' : (string)$v;
      echo "<td>".htmlspecialchars($v)."</td>";
    }
    echo "</tr>";
  }
  echo "</tbody></table>";
  html_foot(); exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'status' => 'ok',
  'query'  => ['from'=>$from,'to'=>$to,'location_id'=>$location_id],
  'count'  => count($rows),
  'rows'   => $rows,
], JSON_UNESCAPED_UNICODE);