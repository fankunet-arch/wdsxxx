<?php
require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');

$c   = cfg();
$pdo = db();

$tzLocal   = $c['timezone_local'] ?? 'Europe/Madrid';
$todayLoc  = new DateTimeImmutable('now', new DateTimeZone($tzLocal));

$back = isset($_GET['back']) ? max(0, min(14, (int)$_GET['back'])) : 3;
$fwd  = isset($_GET['fwd'])  ? max(0, min(14, (int)$_GET['fwd']))  : 2;

$SLOTS = ['01:15','11:15','13:15','16:15','19:15'];

$openHour  = (int)($pdo->query("SELECT open_hour_local FROM wds_business_hours LIMIT 1")->fetchColumn() ?: 12);
$closeHour = (int)($pdo->query("SELECT close_hour_local FROM wds_business_hours LIMIT 1")->fetchColumn() ?: 22);
$expected_hours = max(0, $closeHour - $openHour + 1);

$locs = $pdo->query("SELECT location_id, name FROM wds_locations WHERE is_active=1 ORDER BY location_id")->fetchAll();
if (!$locs) { echo "<!doctype html><meta charset='utf-8'><h1>未配置地点</h1><p><a href='/wds/'>返回</a></p>"; exit; }

$firstLocId = (int)$locs[0]['location_id'];
$jump_loc = isset($_GET['jump_loc']) ? (int)$_GET['jump_loc'] : $firstLocId;
$locIds = array_column($locs, 'location_id');
if (!in_array($jump_loc, $locIds, true)) $jump_loc = $firstLocId;
$jump_scope = isset($_GET['jump_scope']) && in_array($_GET['jump_scope'], ['biz','all'], true) ? $_GET['jump_scope'] : 'biz';

$toUtc = function(DateTimeImmutable $local) {
  return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
};

$days = [];
for ($d = -$back; $d <= $fwd; $d++) { $days[] = $todayLoc->modify(($d>=0?"+$d":"$d") . ' days'); }

$qFc = $pdo->prepare("SELECT COUNT(*) FROM wds_weather_hourly_forecast WHERE location_id=:loc AND run_time_utc BETWEEN :u1 AND :u2");
$qObs = $pdo->prepare("SELECT COUNT(*) FROM wds_weather_hourly_observed WHERE location_id=:loc AND obs_time_utc BETWEEN :u1 AND :u2");

$forecast_grid = [];
foreach ($days as $dtDay) {
  $ymd = $dtDay->format('Y-m-d');
  $forecast_grid[$ymd] = [];
  foreach ($SLOTS as $hm) {
    [$H,$M] = array_map('intval', explode(':', $hm));
    $slotLocal = $dtDay->setTime($H, $M, 0);
    // 修正点 1: 将 ±60 minutes 改为 ±30 minutes，与首页推荐时段保持一致
    $u1 = $toUtc($slotLocal->modify('-30 minutes'));
    $u2 = $toUtc($slotLocal->modify('+30 minutes'));
    $done=0; $total = max(1, count($locs));
    foreach ($locs as $loc) {
      $qFc->execute([':loc'=>$loc['location_id'], ':u1'=>$u1, ':u2'=>$u2]);
      if ((int)$qFc->fetchColumn() > 0) $done++;
    }
    $forecast_grid[$ymd][$hm] = ['done_count'=>$done,'total'=>$total];
  }
}

$cover_days = [];
for ($d = 0; $d <= 6; $d++) { $cover_days[] = $todayLoc->modify("-$d days"); }
$coverage = [];
foreach ($cover_days as $dtDay) {
  $ymd = $dtDay->format('Y-m-d');
  $startUtc = $toUtc($dtDay->setTime($openHour,0,0));
  $endUtc   = $toUtc($dtDay->setTime($closeHour,0,0));
  $sum_done=0; $sum_total=0; $per=[];
  foreach ($locs as $loc) {
    $qObs->execute([':loc'=>$loc['location_id'], ':u1'=>$startUtc, ':u2'=>$endUtc]);
    $done = (int)$qObs->fetchColumn();
    $sum_done += $done; $sum_total += $expected_hours;
    $per[] = ['id'=>$loc['location_id'],'name'=>$loc['name'],'done'=>$done,'total'=>$expected_hours];
  }
  $coverage[$ymd] = ['sum_done'=>$sum_done,'sum_total'=>$sum_total,'per_loc'=>$per];
}

$version = 'wds-0.3.3';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>采集记录 · WDS</title>
<link rel="stylesheet" href="/wds/assets/css/console.css">
<style>
  .grid-table{width:100%;border-collapse:collapse}
  .grid-table th,.grid-table td{border-bottom:1px solid var(--border);padding:8px;text-align:center}
  .grid-table th:first-child,.grid-table td:first-child{text-align:left}
  .status{display:inline-block;min-width:58px;padding:4px 8px;border-radius:999px;font-weight:800;font-size:12px}
  .ok{background:linear-gradient(135deg,#66ffa8,#7ae6be);color:#06211a}
  .part{background:linear-gradient(135deg,#ffd166,#fecb6a);color:#291a00}
  .miss{background:linear-gradient(135deg,#ff7b7b,#ff6b6b);color:#2a0000}
  .muted2{color:var(--muted);font-size:12px}
  .head-flex{display:flex;justify-content:space-between;align-items:center;gap:10px}
  .nums{font-variant-numeric:tabular-nums}
  .pill{display:inline-flex;border:1px solid var(--border);border-radius:999px;padding:2px 8px;background:#0d1320;font-size:12px}
  .linkish{text-decoration:none}
  .linkish:hover{filter:brightness(1.07)}
  .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
</style>
</head>
<body>
  <div class="wrap">
    <div class="head-flex">
      <div>
        <div class="title">采集记录</div>
        <div class="subtitle">版本 <?=$version?></div>
      </div>
      <div><a href="/wds/"><button class="btn-aux" type="button">返回控制台</button></a></div>
    </div>

    <div class="card" style="margin-top:14px">
      <h2>查看偏好</h2>
      <form method="get" class="toolbar" action="/wds/console/history.php">
        <div><label>过去天数（back）：<input type="number" name="back" min="0" max="14" value="<?=$back?>"></label></div>
        <div><label>未来天数（fwd）：<input type="number" name="fwd" min="0" max="14" value="<?=$fwd?>"></label></div>
        <div><label>默认跳转地点：
          <select name="jump_loc"><?php foreach ($locs as $L): ?><option value="<?=$L['location_id']?>" <?=((int)$L['location_id']===$jump_loc?'selected':'')?>><?=htmlspecialchars($L['name'])?>（ID <?=$L['location_id']?>）</option><?php endforeach; ?></select>
        </label></div>
        <div><label>默认跳转范围：
          <select name="jump_scope">
            <option value="biz" <?=$jump_scope==='biz'?'selected':''?>>营业时段（<?=$openHour?>–<?=$closeHour?>）</option>
            <option value="all" <?=$jump_scope==='all'?'selected':''?>>全天（00–23）</option>
          </select></label>
        </div>
        <div><button class="btn-aux" type="submit">应用</button></div>
      </form>
      <p class="muted2" style="margin:6px 2px 0">提示：下面两张表的胶囊都可以点击，按上方的“默认跳转地点/范围”打开图文视图。</p>
    </div>

    <div class="card" style="margin-top:14px">
      <h2>预报执行情况</h2>
      <div class="muted2">
        展示区间：<span class="nums"><?=$days[0]->format('Y-m-d')?> → <?=end($days)->format('Y-m-d')?></span>，
        推荐时段：<?php foreach($SLOTS as $s){ echo "<span class='pill'>".$s."</span> "; } ?>（±30 分钟算命中）。
        单元格值：<b>x/y</b> = 已执行地点数 / 总地点数。
      </div>
      <table class="grid-table" style="margin-top:10px">
        <thead><tr><th style="width:18%">日期</th><?php foreach ($SLOTS as $s): ?><th><?=$s?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ($forecast_grid as $ymd => $cols): ?>
            <tr>
              <td class="nums">
                <a class="linkish" href="/wds/console/view_weather.php?loc=<?=$jump_loc?>&day=<?=$ymd?>&scope=<?=$jump_scope?>"><?=$ymd?></a>
              </td>
              <?php foreach ($SLOTS as $hm):
                $d=$cols[$hm]['done_count']; $t=$cols[$hm]['total'];
                $cls = ($d >= $t) ? 'ok' : (($d>0)?'part':'miss');
                $href = "/wds/console/view_weather.php?loc={$jump_loc}&day={$ymd}&scope={$jump_scope}";
              ?>
                <td><a class="linkish" href="<?=$href?>"><span class="status <?=$cls?>"><?=$d?>/<?=$t?></span></a></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card" style="margin-top:14px">
      <h2>历史回填覆盖（最近 7 天）</h2>
      <div class="muted2">
        营业时段：<b><?=$openHour?></b>–<b><?=$closeHour?></b>（共 <b><?=$expected_hours?></b> 小时），单元格为该日每地点的营业时段实况条数/期望小时数。
      </div>
      <table class="grid-table" style="margin-top:10px">
        <thead>
          <tr><th style="width:18%">日期</th>
            <?php foreach ($locs as $loc): ?><th><?=htmlspecialchars($loc['name'])?></th><?php endforeach; ?>
            <th>合计</th></tr>
        </thead>
        <tbody>
          <?php foreach ($coverage as $ymd => $val): ?>
            <tr>
              <td class="nums"><a class="linkish" href="/wds/console/view_weather.php?loc=<?=$jump_loc?>&day=<?=$ymd?>&scope=biz"><?=$ymd?></a></td>
              <?php foreach ($val['per_loc'] as $pl):
                $cls = ($pl['done'] >= $pl['total']) ? 'ok' : (($pl['done']>0)?'part':'miss');
                $href = "/wds/console/view_weather.php?loc={$pl['id']}&day=<?=$ymd?>&scope=biz";
              ?>
                <td><a class="linkish" href="<?=$href?>"><span class="status <?=$cls?>"><?=$pl['done']?>/<?=$pl['total']?></span></a></td>
              <?php endforeach; ?>
              <?php $clsAll = ($val['sum_done'] >= $val['sum_total']) ? 'ok' : (($val['sum_done']>0)?'part':'miss'); ?>
              <td><span class="status <?=$clsAll?>"><?=$val['sum_done']?>/<?=$val['sum_total']?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>