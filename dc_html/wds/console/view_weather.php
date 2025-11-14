<?php
require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');

$c   = cfg();
$pdo = db();

$tzLocal = $c['timezone_local'] ?? 'Europe/Madrid';

function ymd_valid($s){ return preg_match('/^\d{4}-\d{2}-\d{2}$/',$s); }
function has_col($pdo,$table,$col){
  $q=$pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name=:t AND column_name=:c LIMIT 1");
  $q->execute([':t'=>$table, ':c'=>$col]); return (bool)$q->fetchColumn();
}
function toUtcStr(DateTimeImmutable $local){ return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
function fmt_c($t){ if($t===null)return '—'; return number_format(((float)$t)/10.0,1); }
function fmt_mm($t){ if($t===null)return null; return number_format(((float)$t)/10.0,1).'mm'; }
function fmt_pct($v){ if($v===null)return null; return intval(round($v)).'%'; }
function wmo_label($code){
  if ($code===null) return [null,null];
  $m=[0=>['☀️','晴'],1=>['🌤️','多云间晴'],2=>['⛅','多云'],3=>['☁️','阴'],
      45=>['🌫️','雾'],48=>['🌫️','霜雾'],
      51=>['🌦️','毛毛雨小'],53=>['🌦️','毛毛雨中'],55=>['🌦️','毛毛雨大'],
      56=>['🌧️','冻毛毛雨'],57=>['🌧️','冻毛毛雨'],
      61=>['🌧️','小雨'],63=>['🌧️','中雨'],65=>['🌧️','大雨'],
      66=>['🌧️','冻雨小'],67=>['🌧️','冻雨大'],
      71=>['❄️','小雪'],73=>['❄️','中雪'],75=>['❄️','大雪'],77=>['🌨️','霰'],
      80=>['🌦️','阵雨小'],81=>['🌦️','阵雨中'],82=>['🌧️','阵雨大'],
      85=>['🌨️','阵雪小'],86=>['❄️','阵雪大'],
      95=>['⛈️','雷阵雨'],96=>['⛈️','雷阵雨伴小冰雹'],99=>['⛈️','雷阵雨伴大冰雹']];
  return $m[$code] ?? ['•',"现象 $code"];
}

$locs = $pdo->query("SELECT location_id,name FROM wds_locations WHERE is_active=1 ORDER BY location_id")->fetchAll();
if(!$locs){ echo "<!doctype html><meta charset='utf-8'><h1>未配置地点</h1><p><a href='/wds/'>返回</a></p>"; exit; }

$loc = isset($_GET['loc']) ? max(1,(int)$_GET['loc']) : (int)$locs[0]['location_id'];
$day = isset($_GET['day']) && ymd_valid($_GET['day']) ? $_GET['day'] : (new DateTimeImmutable('now', new DateTimeZone($tzLocal)))->format('Y-m-d');
$scope = isset($_GET['scope']) && in_array($_GET['scope'],['biz','all'],true) ? $_GET['scope'] : 'biz';

$openHour  = (int)($pdo->query("SELECT open_hour_local FROM wds_business_hours LIMIT 1")->fetchColumn() ?: 12);
$closeHour = (int)($pdo->query("SELECT close_hour_local FROM wds_business_hours LIMIT 1")->fetchColumn() ?: 22);

$baseDay = DateTimeImmutable::createFromFormat('Y-m-d',$day,new DateTimeZone($tzLocal));
if(!$baseDay){ $baseDay = new DateTimeImmutable('now', new DateTimeZone($tzLocal)); }
if($scope==='biz'){ $startLocal=$baseDay->setTime($openHour,0,0); $endLocal=$baseDay->setTime($closeHour,0,0); }
else { $startLocal=$baseDay->setTime(0,0,0); $endLocal=$baseDay->setTime(23,0,0); }
$u1 = toUtcStr($startLocal); $u2 = toUtcStr($endLocal);

$opt_fc = [
  'wmo_code' => has_col($pdo,'wds_weather_hourly_forecast','wmo_code'),
  'precip_mm_tenths' => has_col($pdo,'wds_weather_hourly_forecast','precip_mm_tenths'),
  'precip_prob_pct'  => has_col($pdo,'wds_weather_hourly_forecast','precip_prob_pct'),
  'wind_kph_tenths'  => has_col($pdo,'wds_weather_hourly_forecast','wind_kph_tenths'),
  'gust_kph_tenths'  => has_col($pdo,'wds_weather_hourly_forecast','gust_kph_tenths'),
];
$opt_ob = ['wmo_code' => has_col($pdo,'wds_weather_hourly_observed','wmo_code')];

$sel_fc = ["wf.forecast_time_utc","wf.temp_c AS temp_tenths"];
if($opt_fc['wmo_code'])         $sel_fc[]="wf.wmo_code";
if($opt_fc['precip_mm_tenths']) $sel_fc[]="wf.precip_mm_tenths";
if($opt_fc['precip_prob_pct'])  $sel_fc[]="wf.precip_prob_pct";
if($opt_fc['wind_kph_tenths'])  $sel_fc[]="wf.wind_kph_tenths";
if($opt_fc['gust_kph_tenths'])  $sel_fc[]="wf.gust_kph_tenths";
$sql_fc = "
  SELECT ".implode(',', $sel_fc)."
  FROM wds_weather_hourly_forecast wf
  JOIN (
    SELECT location_id, forecast_time_utc, MAX(run_time_utc) AS max_run
    FROM wds_weather_hourly_forecast
    WHERE location_id=:loc AND forecast_time_utc BETWEEN :u1 AND :u2
    GROUP BY location_id, forecast_time_utc
  ) t ON t.location_id=wf.location_id AND t.forecast_time_utc=wf.forecast_time_utc AND t.max_run=wf.run_time_utc
  WHERE wf.location_id=:loc
  ORDER BY wf.forecast_time_utc ASC";
$st_fc = $pdo->prepare($sql_fc);
$st_fc->execute([':loc'=>$loc,':u1'=>$u1,':u2'=>$u2]);
$fc_rows=$st_fc->fetchAll();

$sel_ob = ["obs_time_utc","temp_c AS temp_tenths"];
if($opt_ob['wmo_code']) $sel_ob[]="wmo_code";
$sql_ob = "SELECT ".implode(',',$sel_ob)." FROM wds_weather_hourly_observed WHERE location_id=:loc AND obs_time_utc BETWEEN :u1 AND :u2 ORDER BY obs_time_utc ASC";
$st_ob = $pdo->prepare($sql_ob);
$st_ob->execute([':loc'=>$loc,':u1'=>$u1,':u2'=>$u2]);
$ob_rows=$st_ob->fetchAll();

$map_fc=[]; foreach($fc_rows as $r){ $dtLoc=(new DateTimeImmutable($r['forecast_time_utc'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tzLocal)); $map_fc[$dtLoc->format('H:00')]=$r; }
$map_ob=[]; foreach($ob_rows as $r){ $dtLoc=(new DateTimeImmutable($r['obs_time_utc'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tzLocal)); $map_ob[$dtLoc->format('H:00')]=$r; }

$hours=[]; $h0=(int)$startLocal->format('H'); $h1=(int)$endLocal->format('H'); for($h=$h0; $h<=$h1; $h++){ $hours[]=sprintf('%02d:00',$h); }
$loc_name=null; foreach($locs as $L){ if((int)$L['location_id']===$loc){ $loc_name=$L['name']; break; } }
$version='wds-0.3.3';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>天气 · 图文视图 · WDS</title>
<link rel="stylesheet" href="/wds/assets/css/console.css">
<style>
  .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
  .kpi{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0}
  .chip{border:1px solid var(--border);border-radius:999px;padding:4px 10px;font-size:12px;background:#0d1320;color:var(--fg)}
  .wx{display:flex;gap:6px;align-items:center}
  .wx i{font-style:normal}
  .delta-pos{color:#7ef0b0;font-weight:800}
  .delta-neg{color:#ff8b8b;font-weight:800}
  .muted2{color:var(--muted);font-size:12px}
  .wxcell{display:flex;flex-direction:column;gap:4px}
  .wxline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .sep{opacity:.4}
</style>
</head>
<body>
  <div class="wrap">
    <div class="head-flex" style="margin-bottom:8px">
      <div><div class="title">天气 · 图文视图</div><div class="subtitle">版本 <?=$version?> ｜ 本地时区 <?=$tzLocal?></div></div>
      <div><a href="/wds/"><button class="btn-aux" type="button">返回控制台</button></a></div>
    </div>

    <div class="card">
      <div class="toolbar">
        <form method="get" action="/wds/console/view_weather.php" class="row" style="gap:10px;align-items:end">
          <div style="min-width:220px"><label>地点：
            <select name="loc"><?php foreach($locs as $L): ?><option value="<?=$L['location_id']?>" <?=((int)$L['location_id']===$loc?'selected':'')?>><?=htmlspecialchars($L['name'])?></option><?php endforeach; ?></select>
          </label></div>
          <div class="tap-picker" style="min-width:180px"><label>日期：<input type="date" name="day" value="<?=$day?>"></label></div>
          <div><label>范围：
            <select name="scope">
              <option value="biz" <?=$scope==='biz'?'selected':''?>>营业时段（<?=$openHour?>–<?=$closeHour?>）</option>
              <option value="all" <?=$scope==='all'?'selected':''?>>全天（00–23）</option>
            </select>
          </label></div>
          <div><button class="btn-aux" type="submit">查看</button></div>
          <div><a href="/wds/console/view_weather.php?loc=<?=$loc?>&day=<?=$baseDay->modify('-1 day')->format('Y-m-d')?>&scope=<?=$scope?>"><button type="button" class="btn-aux">← 前一天</button></a></div>
          <div><a href="/wds/console/view_weather.php?loc=<?=$loc?>&day=<?= (new DateTimeImmutable('now', new DateTimeZone($tzLocal)))->format('Y-m-d')?>&scope=<?=$scope?>"><button type="button" class="btn-aux">今 天</button></a></div>
          <div><a href="/wds/console/view_weather.php?loc=<?=$loc?>&day=<?=$baseDay->modify('+1 day')->format('Y-m-d')?>&scope=<?=$scope?>"><button type="button" class="btn-aux">明 天 →</button></a></div>
        </form>
      </div>

      <div class="kpi">
        <span class="chip">地点：<?=htmlspecialchars($loc_name)?>（ID <?=$loc?>）</span>
        <span class="chip">日期：<?=$baseDay->format('Y-m-d')?>（<?=$scope==='biz'?'营业时段':'全天'?>）</span>
        <span class="chip">本地→UTC：<?=htmlspecialchars($startLocal->format('H:i'))?> → <?=htmlspecialchars($endLocal->format('H:i'))?></span>
      </div>

      <table class="grid-table" style="margin-top:10px">
        <thead><tr><th style="width:10%">时间</th><th style="width:45%">预报（最新 run）</th><th style="width:35%">实况</th><th style="width:10%">Δ (℃)</th></tr></thead>
        <tbody>
          <?php foreach ($hours as $h):
            $F=$map_fc[$h]??null; $O=$map_ob[$h]??null;
            $f_t = $F ? fmt_c($F['temp_tenths']) : '—';
            $o_t = $O ? fmt_c($O['temp_tenths']) : '—';
            $delta = (is_numeric($f_t)&&is_numeric($o_t)) ? number_format(((float)$F['temp_tenths']-(float)$O['temp_tenths'])/10.0,1) : '—';
            $f_icon=null;$f_desc=null; if($F && isset($F['wmo_code'])){ [$f_icon,$f_desc] = wmo_label((int)$F['wmo_code']); }
            $o_icon=null;$o_desc=null; if($O && isset($O['wmo_code'])){ [$o_icon,$o_desc] = wmo_label((int)$O['wmo_code']); }
            $f_prec = ($F && array_key_exists('precip_mm_tenths',$F)) ? fmt_mm($F['precip_mm_tenths']) : null;
            $f_pop  = ($F && array_key_exists('precip_prob_pct',$F))  ? fmt_pct($F['precip_prob_pct']) : null;
            $f_wind = ($F && array_key_exists('wind_kph_tenths',$F))  ? (number_format(((float)$F['wind_kph_tenths'])/10.0, 1).'km/h') : null;
            $f_gust = ($F && array_key_exists('gust_kph_tenths',$F))  ? (number_format(((float)$F['gust_kph_tenths'])/10.0, 1).'km/h') : null;
          ?>
          <tr>
            <td><?=$h?></td>
            <td>
              <div class="wxcell">
                <div class="wxline"><span class="wx"><i><?=$f_icon?:'•'?></i> <b><?=$f_t?></b>℃ <?=$f_desc?('· '.$f_desc):''?></span></div>
                <div class="wxline muted2">
                  <?php if ($f_pop): ?><span>概率 <?=$f_pop?></span><span class="sep">·</span><?php endif; ?>
                  <?php if ($f_prec): ?><span>降水 <?=$f_prec?></span><span class="sep">·</span><?php endif; ?>
                  <?php if ($f_wind): ?><span>风 <?=$f_wind?></span><?php endif; ?>
                  <?php if ($f_gust): ?><span class="sep">·</span><span>阵风 <?=$f_gust?></span><?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <div class="wxcell">
                <div class="wxline"><span class="wx"><i><?=$o_icon?:'•'?></i> <b><?=$o_t?></b>℃ <?=$o_desc?('· '.$o_desc):''?></span></div>
              </div>
            </td>
            <td><?php if($delta==='—'){ echo '—'; } else { ?><span class="<?=((float)$delta>=0)?'delta-pos':'delta-neg'?>"><?=$delta?></span><?php } ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p class="muted2" style="margin-top:8px">注：温度为 0.1℃ 粒度（已换算显示）；预报为当小时的最新一次 run。</p>
    </div>
  </div>
</body>
</html>
