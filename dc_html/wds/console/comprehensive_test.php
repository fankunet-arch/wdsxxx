<?php
/**
 * WDS优化系统 - 完整测试套件
 * 测试所有功能模块，确保系统健壮性
 */

require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');

use WDS\ingest\OpenMeteoIngest;
use WDS\maintenance\MonthlyArchiver;
use WDS\maintenance\DatabaseArchiver;

header('Content-Type: text/html; charset=utf-8');

$pdo = db();
$cfg = cfg();

?>
<!DOCTYPE html>
<html>
<head>
    <title>WDS 完整测试套件</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; background: white; padding: 20px; }
        h1 { color: #333; border-bottom: 3px solid #007bff; }
        h2 { color: #666; margin-top: 30px; border-left: 4px solid #007bff; padding-left: 10px; }
        .test-case { margin: 15px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #6c757d; }
        .test-pass { border-left-color: #28a745; }
        .test-fail { border-left-color: #dc3545; }
        .test-warning { border-left-color: #ffc107; }
        .status { display: inline-block; padding: 3px 8px; border-radius: 3px; font-weight: bold; margin-right: 10px; }
        .pass { background: #28a745; color: white; }
        .fail { background: #dc3545; color: white; }
        .warning { background: #ffc107; color: black; }
        .skip { background: #6c757d; color: white; }
        pre { background: #272822; color: #f8f8f2; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        .summary { background: #e9ecef; padding: 20px; margin: 20px 0; border-radius: 8px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔬 WDS 优化系统 - 完整测试套件</h1>
    <p>执行时间: <?= date('Y-m-d H:i:s') ?></p>

    <?php
    $testResults = [
        'total' => 0,
        'passed' => 0,
        'failed' => 0,
        'warnings' => 0,
        'skipped' => 0
    ];

    function runTest($name, $callable, &$results) {
        $results['total']++;
        echo "<div class='test-case";

        try {
            $result = $callable();

            if ($result['status'] === 'pass') {
                echo " test-pass'>";
                echo "<span class='status pass'>✓ PASS</span>";
                $results['passed']++;
            } elseif ($result['status'] === 'warning') {
                echo " test-warning'>";
                echo "<span class='status warning'>⚠ WARNING</span>";
                $results['warnings']++;
            } elseif ($result['status'] === 'skip') {
                echo " test-case'>";
                echo "<span class='status skip'>⊘ SKIP</span>";
                $results['skipped']++;
            } else {
                echo " test-fail'>";
                echo "<span class='status fail'>✗ FAIL</span>";
                $results['failed']++;
            }

            echo "<strong>{$name}</strong><br>";
            echo "<div style='margin-top:10px'>{$result['message']}</div>";

            if (!empty($result['details'])) {
                echo "<pre>" . htmlspecialchars($result['details']) . "</pre>";
            }

        } catch (\Throwable $e) {
            echo " test-fail'>";
            echo "<span class='status fail'>✗ FAIL</span>";
            echo "<strong>{$name}</strong><br>";
            echo "<div style='margin-top:10px; color: #dc3545;'>异常: " . htmlspecialchars($e->getMessage()) . "</div>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            $results['failed']++;
        }

        echo "</div>";
    }

    // ============= 1. 数据库表检查 =============
    echo "<h2>1️⃣ 数据库表结构检查</h2>";

    runTest("检查主业务表", function() use ($pdo) {
        $requiredTables = [
            'wds_locations',
            'wds_business_hours',
            'wds_weather_hourly_forecast',
            'wds_weather_hourly_observed'
        ];

        $missing = [];
        foreach ($requiredTables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            $exists = $stmt->fetch();
            $stmt->closeCursor(); // 关闭游标
            if (!$exists) {
                $missing[] = $table;
            }
        }

        if (empty($missing)) {
            return ['status' => 'pass', 'message' => '所有主业务表都存在'];
        } else {
            return ['status' => 'fail', 'message' => '缺失表: ' . implode(', ', $missing)];
        }
    }, $testResults);

    runTest("检查优化方案表", function() use ($pdo) {
        $optimizationTables = [
            'wds_weather_hourly_forecast_archive',
            'wds_monthly_archives',
            'wds_archive_history',
            'wds_db_archive_log'
        ];

        $missing = [];
        foreach ($optimizationTables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            $exists = $stmt->fetch();
            $stmt->closeCursor(); // 关闭游标
            if (!$exists) {
                $missing[] = $table;
            }
        }

        if (empty($missing)) {
            return ['status' => 'pass', 'message' => '所有优化表都已创建'];
        } else {
            return [
                'status' => 'warning',
                'message' => '部分优化表未创建',
                'details' => "缺失表:\n" . implode("\n", $missing) . "\n\n需要执行: mysql -u USER -p < docs/wds_optimization_tables.sql"
            ];
        }
    }, $testResults);

    runTest("检查视图", function() use ($pdo) {
        $views = ['vw_weather_forecast_all'];

        $missing = [];
        foreach ($views as $view) {
            // 使用 SHOW FULL TABLES 检查视图，不依赖数据库名
            $stmt = $pdo->query("SHOW FULL TABLES LIKE '{$view}'");
            $result = $stmt->fetch();
            $stmt->closeCursor(); // 关闭游标
            if (!$result) {
                $missing[] = $view;
            } elseif (isset($result[1]) && strtoupper($result[1]) !== 'VIEW') {
                // 存在但不是视图
                $missing[] = "{$view} (exists but is not a VIEW)";
            }
        }

        if (empty($missing)) {
            return ['status' => 'pass', 'message' => '所有视图都已创建'];
        } else {
            return ['status' => 'warning', 'message' => '缺失视图: ' . implode(', ', $missing)];
        }
    }, $testResults);

    // ============= 2. 类加载测试 =============
    echo "<h2>2️⃣ 类加载测试</h2>";

    runTest("OpenMeteoIngest 类加载", function() {
        if (class_exists('WDS\\ingest\\OpenMeteoIngest')) {
            return ['status' => 'pass', 'message' => 'OpenMeteoIngest 类加载成功'];
        } else {
            return ['status' => 'fail', 'message' => 'OpenMeteoIngest 类无法加载'];
        }
    }, $testResults);

    runTest("MonthlyArchiver 类加载", function() {
        if (class_exists('WDS\\maintenance\\MonthlyArchiver')) {
            return ['status' => 'pass', 'message' => 'MonthlyArchiver 类加载成功'];
        } else {
            return ['status' => 'fail', 'message' => 'MonthlyArchiver 类无法加载'];
        }
    }, $testResults);

    runTest("DatabaseArchiver 类加载", function() {
        if (class_exists('WDS\\maintenance\\DatabaseArchiver')) {
            return ['status' => 'pass', 'message' => 'DatabaseArchiver 类加载成功'];
        } else {
            return ['status' => 'fail', 'message' => 'DatabaseArchiver 类无法加载'];
        }
    }, $testResults);

    // ============= 3. 数据库归档功能测试 =============
    echo "<h2>3️⃣ DatabaseArchiver 功能测试</h2>";

    runTest("DatabaseArchiver 实例化", function() use ($pdo) {
        $archiver = new DatabaseArchiver($pdo);
        return ['status' => 'pass', 'message' => 'DatabaseArchiver 实例化成功'];
    }, $testResults);

    runTest("检查归档表存在性检测", function() use ($pdo) {
        $archiver = new DatabaseArchiver($pdo);
        $result = $archiver->archiveOldForecasts(30);

        if (isset($result['error']) && strpos($result['error'], 'does not exist') !== false) {
            return [
                'status' => 'warning',
                'message' => '归档表不存在（这是正常的，需要先创建表）',
                'details' => $result['error']
            ];
        } elseif ($result['success']) {
            return ['status' => 'pass', 'message' => '归档功能正常'];
        } else {
            return [
                'status' => 'fail',
                'message' => '归档功能异常',
                'details' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ];
        }
    }, $testResults);

    runTest("getHotTableStats 方法", function() use ($pdo) {
        $archiver = new DatabaseArchiver($pdo);
        $stats = $archiver->getHotTableStats();

        if (is_array($stats) && isset($stats['total_rows'])) {
            return [
                'status' => 'pass',
                'message' => '热表统计获取成功',
                'details' => json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ];
        } else {
            return ['status' => 'fail', 'message' => '无法获取热表统计'];
        }
    }, $testResults);

    runTest("getArchiveTableStats 方法", function() use ($pdo) {
        $archiver = new DatabaseArchiver($pdo);
        $stats = $archiver->getArchiveTableStats();

        if (is_array($stats)) {
            return [
                'status' => 'pass',
                'message' => '归档表统计获取成功',
                'details' => json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ];
        } else {
            return ['status' => 'fail', 'message' => '无法获取归档表统计'];
        }
    }, $testResults);

    // ============= 4. 月度归档功能测试 =============
    echo "<h2>4️⃣ MonthlyArchiver 功能测试</h2>";

    runTest("MonthlyArchiver 实例化", function() use ($pdo, $cfg) {
        $archiver = new MonthlyArchiver($pdo, $cfg);
        return ['status' => 'pass', 'message' => 'MonthlyArchiver 实例化成功'];
    }, $testResults);

    // ============= 5. 智能回填功能测试 =============
    echo "<h2>5️⃣ OpenMeteoIngest 智能回填测试</h2>";

    runTest("OpenMeteoIngest 实例化", function() use ($pdo, $cfg) {
        $ingest = new OpenMeteoIngest($pdo, $cfg);
        return ['status' => 'pass', 'message' => 'OpenMeteoIngest 实例化成功'];
    }, $testResults);

    runTest("智能回填方法存在性", function() use ($pdo, $cfg) {
        $ingest = new OpenMeteoIngest($pdo, $cfg);

        if (method_exists($ingest, 'fetchArchiveSmart')) {
            return ['status' => 'pass', 'message' => 'fetchArchiveSmart 方法存在'];
        } else {
            return ['status' => 'fail', 'message' => 'fetchArchiveSmart 方法不存在'];
        }
    }, $testResults);

    // ============= 6. 事务处理测试 =============
    echo "<h2>6️⃣ 数据库事务处理测试</h2>";

    runTest("基本事务功能", function() use ($pdo) {
        try {
            // 测试 beginTransaction
            $pdo->beginTransaction();
            $inTransaction1 = $pdo->inTransaction();

            // 测试 rollback
            $pdo->rollBack();
            $inTransaction2 = $pdo->inTransaction();

            // 测试 commit
            $pdo->beginTransaction();
            $pdo->commit();
            $inTransaction3 = $pdo->inTransaction();

            if ($inTransaction1 && !$inTransaction2 && !$inTransaction3) {
                return ['status' => 'pass', 'message' => '事务功能正常'];
            } else {
                return [
                    'status' => 'fail',
                    'message' => '事务状态异常',
                    'details' => "inTransaction after begin: " . ($inTransaction1 ? 'true' : 'false') . "\n" .
                                "inTransaction after rollback: " . ($inTransaction2 ? 'true' : 'false') . "\n" .
                                "inTransaction after commit: " . ($inTransaction3 ? 'true' : 'false')
                ];
            }
        } catch (\Exception $e) {
            return ['status' => 'fail', 'message' => '事务测试异常: ' . $e->getMessage()];
        }
    }, $testResults);

    runTest("重复 rollback 错误测试", function() use ($pdo) {
        try {
            $pdo->beginTransaction();
            $pdo->rollBack();

            // 尝试第二次 rollback（应该抛出异常）
            try {
                $pdo->rollBack();
                return ['status' => 'fail', 'message' => '重复rollback没有抛出异常（不符合预期）'];
            } catch (\PDOException $e) {
                return ['status' => 'pass', 'message' => '重复rollback正确抛出异常'];
            }
        } catch (\Exception $e) {
            return ['status' => 'fail', 'message' => '测试异常: ' . $e->getMessage()];
        }
    }, $testResults);

    // ============= 7. 配置检查 =============
    echo "<h2>7️⃣ 配置检查</h2>";

    runTest("配置文件加载", function() use ($cfg) {
        $required = ['db', 'timezone_local', 'api_token'];
        $missing = [];

        foreach ($required as $key) {
            if (!isset($cfg[$key])) {
                $missing[] = $key;
            }
        }

        if (empty($missing)) {
            return ['status' => 'pass', 'message' => '所有必需配置都存在'];
        } else {
            return ['status' => 'fail', 'message' => '缺失配置: ' . implode(', ', $missing)];
        }
    }, $testResults);

    runTest("归档配置检查", function() use ($cfg) {
        if (isset($cfg['retention'])) {
            return [
                'status' => 'pass',
                'message' => '归档配置存在',
                'details' => json_encode($cfg['retention'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ];
        } else {
            return ['status' => 'warning', 'message' => '归档配置不存在'];
        }
    }, $testResults);

    // ============= 测试摘要 =============
    echo "<div class='summary'>";
    echo "<h2>📊 测试摘要</h2>";
    echo "<p><strong>总测试数:</strong> {$testResults['total']}</p>";
    echo "<p><strong><span style='color: #28a745'>✓ 通过:</span></strong> {$testResults['passed']}</p>";
    echo "<p><strong><span style='color: #dc3545'>✗ 失败:</span></strong> {$testResults['failed']}</p>";
    echo "<p><strong><span style='color: #ffc107'>⚠ 警告:</span></strong> {$testResults['warnings']}</p>";
    echo "<p><strong><span style='color: #6c757d'>⊘ 跳过:</span></strong> {$testResults['skipped']}</p>";

    $passRate = $testResults['total'] > 0 ? round(($testResults['passed'] / $testResults['total']) * 100, 1) : 0;
    echo "<p><strong>通过率:</strong> {$passRate}%</p>";

    if ($testResults['failed'] === 0 && $testResults['warnings'] === 0) {
        echo "<p style='color: #28a745; font-size: 18px; font-weight: bold;'>✅ 所有测试通过！系统状态良好。</p>";
    } elseif ($testResults['failed'] === 0) {
        echo "<p style='color: #ffc107; font-size: 18px; font-weight: bold;'>⚠️ 所有测试通过，但有警告需要关注。</p>";
    } else {
        echo "<p style='color: #dc3545; font-size: 18px; font-weight: bold;'>❌ 有{$testResults['failed']}个测试失败，需要修复。</p>";
    }
    echo "</div>";
    ?>

    <hr>
    <p style="color: #999; text-align: center;">WDS 优化系统测试套件 v1.0</p>
</div>
</body>
</html>
