<?php
declare(strict_types=1);

require_once(__DIR__ . '/../../../app/wds/bootstrap_compat.php');

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    $c = cfg();
    $tzName = $c['timezone_local'] ?? 'Europe/Madrid';
    $tz = new DateTimeZone($tzName);
    $now = new DateTimeImmutable('now', $tz);
    $todayDate = $now->format('Y-m-d');
    
    // 从配置或 Runner 默认值获取时隙
    $slotsConfig = $c['auto_collect']['slots'] ?? ['01:15','07:15','11:15','13:15','19:15'];
    
    // 检查今天的执行状态 (仅检查 'forecast' action)
    $doneSlots = [];
    $q = $pdo->prepare("SELECT hm FROM wds_exec_log WHERE exec_date = :d AND done > 0 AND action = 'forecast'");
    $q->execute([':d' => $todayDate]);
    while ($hm = $q->fetchColumn()) {
        $doneSlots[$hm] = true;
    }

    $slots = [];
    $nowEpochMs = $now->getTimestamp() * 1000;

    foreach ($slotsConfig as $hm) {
        $targetTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $todayDate . ' ' . $hm, $tz);
        if (!$targetTime) continue;
        
        $slots[] = [
            'hm' => $hm,
            'target_epoch_ms' => $targetTime->getTimestamp() * 1000,
            'done' => isset($doneSlots[$hm]), // 检查此特定时隙是否已完成
        ];
    }

    echo json_encode([
        'ok' => true,
        'now_epoch_ms' => $nowEpochMs,
        'timezone' => $tzName,
        'slots' => $slots,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'now_epoch_ms' => (new DateTimeImmutable('now'))->getTimestamp() * 1000,
        'slots' => [],
    ], JSON_UNESCAPED_UNICODE);
}