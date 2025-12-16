<?php
/**
 * 数据库表检查和诊断脚本
 */

require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');

header('Content-Type: text/plain; charset=utf-8');

echo "=== WDS 数据库诊断 ===\n\n";

try {
    $pdo = db();

    // 1. 检查所有WDS表
    echo "1. 检查所有WDS表：\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $wdsTables = array_filter($tables, fn($t) => strpos($t, 'wds_') === 0);

    foreach ($wdsTables as $table) {
        echo "   ✓ {$table}\n";
    }
    echo "\n";

    // 2. 检查归档表
    echo "2. 检查归档相关表：\n";
    $archiveTables = [
        'wds_weather_hourly_forecast_archive',
        'wds_monthly_archives',
        'wds_archive_history',
        'wds_db_archive_log'
    ];

    foreach ($archiveTables as $table) {
        $exists = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetch();
        if ($exists) {
            echo "   ✓ {$table} - 存在\n";
        } else {
            echo "   ✗ {$table} - 不存在！需要执行: docs/wds_optimization_tables.sql\n";
        }
    }
    echo "\n";

    // 3. 检查视图
    echo "3. 检查视图：\n";
    $viewExists = $pdo->query("SHOW FULL TABLES WHERE table_type = 'VIEW' AND Tables_in_mhdlmskp2kpxguj LIKE 'vw_%'")->fetchAll();
    if (!empty($viewExists)) {
        foreach ($viewExists as $view) {
            echo "   ✓ {$view[0]}\n";
        }
    } else {
        echo "   ✗ 没有找到视图\n";
    }
    echo "\n";

    // 4. 测试事务
    echo "4. 测试事务功能：\n";
    try {
        $pdo->beginTransaction();
        echo "   ✓ beginTransaction() 成功\n";

        $pdo->rollBack();
        echo "   ✓ rollBack() 成功\n";

        $pdo->beginTransaction();
        $pdo->commit();
        echo "   ✓ commit() 成功\n";
    } catch (\Exception $e) {
        echo "   ✗ 事务测试失败: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // 5. 检查热表数据
    echo "5. 检查热表数据：\n";
    $hotCount = $pdo->query("SELECT COUNT(*) FROM wds_weather_hourly_forecast")->fetchColumn();
    echo "   热表行数: " . number_format($hotCount) . "\n";

    if ($hotCount > 0) {
        $oldest = $pdo->query("SELECT MIN(forecast_time_utc) FROM wds_weather_hourly_forecast")->fetchColumn();
        $newest = $pdo->query("SELECT MAX(forecast_time_utc) FROM wds_weather_hourly_forecast")->fetchColumn();
        echo "   最早数据: {$oldest}\n";
        echo "   最新数据: {$newest}\n";

        // 检查30天前的数据
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
        $oldCount = $pdo->query("SELECT COUNT(*) FROM wds_weather_hourly_forecast WHERE forecast_time_utc < '{$cutoff}'")->fetchColumn();
        echo "   30天前数据: " . number_format($oldCount) . " 行\n";
    }
    echo "\n";

    // 6. 测试归档表查询（如果存在）
    $archiveTableExists = $pdo->query("SHOW TABLES LIKE 'wds_weather_hourly_forecast_archive'")->fetch();
    if ($archiveTableExists) {
        echo "6. 检查归档表：\n";
        $archiveCount = $pdo->query("SELECT COUNT(*) FROM wds_weather_hourly_forecast_archive")->fetchColumn();
        echo "   归档表行数: " . number_format($archiveCount) . "\n";
    } else {
        echo "6. 归档表不存在\n";
        echo "   需要执行: mysql -u mhdlmskp2kpxguj -p < docs/wds_optimization_tables.sql\n";
    }
    echo "\n";

    echo "=== 诊断完成 ===\n";

} catch (\Throwable $e) {
    echo "错误: " . $e->getMessage() . "\n";
    echo "跟踪: " . $e->getTraceAsString() . "\n";
}
