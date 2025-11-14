<?php
require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');
$pdo = db();
$row = $pdo->query("SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) AS mb FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetch();
$mb = $row && $row['mb'] !== null ? $row['mb'] : '—';
?>
<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/wds/assets/css/console.css">
<div class="wrap"><div class="card"><h2>数据库体积</h2><table><tr><th>当前库大小</th><td><b><?=$mb?></b> MB</td></tr></table><p style="margin-top:12px"><a href="/wds/"><button class="btn-aux">返回控制台</button></a></p></div></div>
