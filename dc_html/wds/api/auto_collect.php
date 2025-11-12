<?php
declare(strict_types=1);

/**
 * WDS · 采集触发控制（带窗口判断）
 * - 支持 ?format=html 人类可读模式
 * - 新增 token 鉴权：支持 Authorization: Bearer <token> 或 ?token=xxx
 *
 * [修复]
 * - 重构逻辑，调用 Runner::collectIfDue()
 * - Runner::collectIfDue() 内部包含“是否已执行”的判断，避免重复触发
 */

//
// 1) 稳健引导加载（兼容多种相对深度）
//
$__tries = [
    __DIR__ . '/../../../app/wds/bootstrap_compat.php',
    __DIR__ . '/../../../../app/wds/bootstrap_compat.php',
    dirname(__DIR__, 4) . '/app/wds/bootstrap_compat.php',
];
$__ok = false;
foreach ($__tries as $__p) {
    if (is_file($__p)) { require_once $__p; $__ok = true; break; }
}
if (!$__ok) {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo "bootstrap_compat.php not found.\nTried:\n" . implode("\n", $__tries);
    exit;
}

//
// 2) 业务依赖（Runner）
//
$__runners = [
    dirname(__DIR__, 4) . '/app/wds/ingest/Runner.php',
    dirname(__DIR__, 3) . '/app/wds/ingest/Runner.php',
];
$__runner_ok = false;
foreach ($__runners as $__p) {
    if (is_file($__p)) { require_once $__p; $__runner_ok = true; break; }
}

use WDS\ingest\Runner;

$c   = cfg();
$pdo = db();

$tz        = $c['timezone_local'] ?? 'Europe/Madrid';
$nowLocal  = new DateTimeImmutable('now', new DateTimeZone($tz));
$windowMin = (int)($c['auto_collect']['window_min'] ?? 10);
$slots     = $c['auto_collect']['slots'] ?? ['01:15','07:15','11:15','13:15','19:15'];
$format    = strtolower((string)($_GET['format'] ?? 'json'));

//
// 3) 鉴权（必需）
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
        header('Content-Type: text/html; charset=utf-8', true, 401);
        echo "<!doctype html><meta charset='utf-8'><title>401</title><p>Unauthorized：token 缺失或不匹配。</p>";
        exit;
    }
    header('Content-Type: application/json; charset=utf-8', true, 401);
    echo json_encode(['ok'=>false, 'error'=>'unauthorized', 'message'=>'token missing or invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

//
// 4) 业务逻辑：[修复] 调用 Runner::collectIfDue
//
$result = ['action'=>'noop', 'reason'=>'runner_not_found', 'slot'=>'n/a', 'diff_min'=>0];
$inWindow = false;

if ($__runner_ok) {
    try {
        $runner = new Runner($pdo, $c);
        // Runner 内部会计算时隙、窗口、是否已执行，并自动记录日志
        $result = $runner->collectIfDue($nowLocal, $windowMin, $slots);
        $inWindow = ($result['action'] === 'collect' || ($result['reason'] === 'inside_window' && $result['action'] === 'noop'));
    } catch (Throwable $e) {
        log_msg("auto_collect: Runner failed: " . $e->getMessage());
        $result = ['action'=>'noop', 'reason'=>'runner_exception', 'slot'=>'n/a', 'diff_min'=>0];
    }
} else {
    log_msg("auto_collect: Runner.php not found.");
}

$action = $result['action'];
$reason = $result['reason'];
$nearestSlot = $result['slot'];
$nearestDiff = $result['diff_min'];


//
// 5) 输出（HTML / JSON）
//
if ($format === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WDS · 采集窗口</title>
<style>
:root{--bg:#0f1115;--card:#151925;--muted:#aab0c3;--text:#e6eaf2;--ok:#4cd964;--warn:#ffcc00;--line:#1b2131}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.6 system-ui,-apple-system,Segoe UI,Roboto,Arial}
.wrap{max-width:880px;margin:24px auto;padding:0 16px}
.card{background:#151925;border:1px solid #1b2131;border-radius:16px;padding:18px}
.badge{display:inline-flex;gap:6px;align-items:center;border:1px solid #2b3755;border-radius:999px;padding:4px 10px;background:#151a26}
.dot{width:8px;height:8px;border-radius:50%}.ok{background:var(--ok)}
.kv{display:grid;grid-template-columns:160px 1fr;gap:6px}
.btn{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #25304a;background:#1a2031;color:#cfe1ff;text-decoration:none}
.btn:hover{background:#202842}
</style></head><body><div class="wrap"><div class="card">
<h3>采集窗口</h3>
<div class="kv">
  <div>本地时间：</div><div>{$nowLocal->format('Y-m-d H:i:s')}</div>
  <div>时区：</div><div>{$tz}</div>
  <div>窗口阈值：</div><div>{$windowMin} 分钟</div>
  <div>最近时隙：</div><div>{$nearestSlot}</div>
  <div>动作：</div><div><span class="badge"><span class="dot" style="background:{$inWindow?'var(--ok)':'#888'}"></span>{$action}</span></div>
  <div>原因：</div><div>{$reason}</div>
  <div>时隙集合：</div><div>{htmlspecialchars(implode(', ', $slots))}</div>
</div>
<p style="margin-top:12px"><a class="btn" href="?format=json">查看 JSON</a>　<a class="btn" href="/wds/">返回控制台</a></p>
</div></div></body></html>
HTML;
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'         => true,
    'now_local'  => $nowLocal->format('Y-m-d H:i:s'),
    'timezone'   => $tz,
    'window_min' => $windowMin,
    'slots'      => array_values($slots),
    'nearest'    => ['slot'=>$nearestSlot, 'diff_min'=>$nearestDiff],
    'in_window'  => $inWindow,
    'action'     => $action,
    'reason'     => $reason,
], JSON_UNESCAPED_UNICODE);