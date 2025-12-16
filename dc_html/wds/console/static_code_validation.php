<?php
/**
 * 静态代码验证脚本
 * 无需数据库连接，验证代码结构、语法和逻辑完整性
 */

// 彩色输出
function colorOutput($text, $color = 'green') {
    $colors = [
        'green' => "\033[32m",
        'red' => "\033[31m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'reset' => "\033[0m"
    ];
    return $colors[$color] . $text . $colors['reset'];
}

echo colorOutput("=================================================", 'blue') . PHP_EOL;
echo colorOutput("    WDS 代码静态验证 - Static Code Validation    ", 'blue') . PHP_EOL;
echo colorOutput("=================================================", 'blue') . PHP_EOL . PHP_EOL;

$issues = [];
$passed = [];

// ============= 1. 文件存在性检查 =============
echo colorOutput("1️⃣  文件存在性检查", 'blue') . PHP_EOL;

$requiredFiles = [
    'app/wds/bootstrap/app.php' => '核心引导文件',
    'app/wds/src/ingest/open_meteo_ingest.php' => 'OpenMeteoIngest类',
    'app/wds/src/maintenance/monthly_archiver.php' => 'MonthlyArchiver类',
    'app/wds/src/maintenance/database_archiver.php' => 'DatabaseArchiver类',
    'docs/wds_optimization_tables.sql' => '数据库表结构',
    'dc_html/wds/console/comprehensive_test.php' => '综合测试脚本',
    'dc_html/wds/console/test_optimization.php' => '优化测试页面',
];

foreach ($requiredFiles as $file => $desc) {
    $fullPath = "/home/user/wdsxxx/{$file}";
    if (file_exists($fullPath)) {
        echo colorOutput("  ✓ ", 'green') . "{$desc}: {$file}" . PHP_EOL;
        $passed[] = "文件存在: {$file}";
    } else {
        echo colorOutput("  ✗ ", 'red') . "{$desc}: {$file} - 文件不存在!" . PHP_EOL;
        $issues[] = "文件缺失: {$file}";
    }
}
echo PHP_EOL;

// ============= 2. PHP语法检查 =============
echo colorOutput("2️⃣  PHP语法检查", 'blue') . PHP_EOL;

$phpFiles = [
    'app/wds/bootstrap/app.php',
    'app/wds/src/ingest/open_meteo_ingest.php',
    'app/wds/src/maintenance/monthly_archiver.php',
    'app/wds/src/maintenance/database_archiver.php',
    'dc_html/wds/console/comprehensive_test.php',
    'dc_html/wds/console/test_optimization.php',
];

foreach ($phpFiles as $file) {
    $fullPath = "/home/user/wdsxxx/{$file}";
    if (!file_exists($fullPath)) continue;

    $output = [];
    $returnCode = 0;
    exec("php -l " . escapeshellarg($fullPath) . " 2>&1", $output, $returnCode);

    if ($returnCode === 0) {
        echo colorOutput("  ✓ ", 'green') . basename($file) . " - 语法正确" . PHP_EOL;
        $passed[] = "语法检查通过: {$file}";
    } else {
        echo colorOutput("  ✗ ", 'red') . basename($file) . " - 语法错误!" . PHP_EOL;
        echo "     " . implode(PHP_EOL . "     ", $output) . PHP_EOL;
        $issues[] = "语法错误: {$file}";
    }
}
echo PHP_EOL;

// ============= 3. 类定义检查 =============
echo colorOutput("3️⃣  类定义和自动加载检查", 'blue') . PHP_EOL;

require_once('/home/user/wdsxxx/app/wds/bootstrap/app.php');

$classes = [
    'WDS\\ingest\\OpenMeteoIngest' => 'app/wds/src/ingest/open_meteo_ingest.php',
    'WDS\\maintenance\\MonthlyArchiver' => 'app/wds/src/maintenance/monthly_archiver.php',
    'WDS\\maintenance\\DatabaseArchiver' => 'app/wds/src/maintenance/database_archiver.php',
];

foreach ($classes as $class => $expectedFile) {
    if (class_exists($class)) {
        echo colorOutput("  ✓ ", 'green') . "{$class} - 类加载成功" . PHP_EOL;
        $passed[] = "类加载成功: {$class}";

        // 检查方法
        $reflection = new ReflectionClass($class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        echo "     方法: " . count($methods) . "个 [";
        $methodNames = array_map(fn($m) => $m->getName(), array_slice($methods, 0, 5));
        echo implode(', ', $methodNames);
        if (count($methods) > 5) echo ", ...";
        echo "]" . PHP_EOL;
    } else {
        echo colorOutput("  ✗ ", 'red') . "{$class} - 类无法加载!" . PHP_EOL;
        $issues[] = "类加载失败: {$class}";
    }
}
echo PHP_EOL;

// ============= 4. 方法签名检查 =============
echo colorOutput("4️⃣  关键方法签名检查", 'blue') . PHP_EOL;

$methodChecks = [
    'WDS\\ingest\\OpenMeteoIngest' => [
        'fetchForecast' => ['days' => true],
        'fetchArchive' => ['startYmd' => true, 'endYmd' => true],
        'fetchArchiveSmart' => ['startYmd' => true, 'endYmd' => true, 'skipIfExists' => true],
    ],
    'WDS\\maintenance\\DatabaseArchiver' => [
        'archiveOldForecasts' => ['daysOld' => true],
        'shouldArchive' => [],
        'getHotTableStats' => [],
        'getArchiveTableStats' => [],
        'getArchiveHistory' => ['limit' => true],
    ],
    'WDS\\maintenance\\MonthlyArchiver' => [
        'executeMonthlyArchive' => ['month' => true],
    ],
];

foreach ($methodChecks as $class => $methods) {
    if (!class_exists($class)) continue;

    $reflection = new ReflectionClass($class);
    foreach ($methods as $method => $params) {
        if ($reflection->hasMethod($method)) {
            $methodRef = $reflection->getMethod($method);
            $paramCount = $methodRef->getNumberOfParameters();
            echo colorOutput("  ✓ ", 'green') . "{$class}::{$method}() - 存在 ({$paramCount}个参数)" . PHP_EOL;
            $passed[] = "方法存在: {$class}::{$method}";
        } else {
            echo colorOutput("  ✗ ", 'red') . "{$class}::{$method}() - 方法不存在!" . PHP_EOL;
            $issues[] = "方法缺失: {$class}::{$method}";
        }
    }
}
echo PHP_EOL;

// ============= 5. SQL文件语法检查 =============
echo colorOutput("5️⃣  SQL文件结构检查", 'blue') . PHP_EOL;

$sqlFile = '/home/user/wdsxxx/docs/wds_optimization_tables.sql';
if (file_exists($sqlFile)) {
    $sqlContent = file_get_contents($sqlFile);

    $expectedTables = [
        'wds_monthly_archives',
        'wds_archive_history',
        'wds_weather_hourly_forecast_archive',
        'wds_db_archive_log',
    ];

    $expectedViews = [
        'vw_weather_forecast_all',
    ];

    foreach ($expectedTables as $table) {
        if (stripos($sqlContent, "CREATE TABLE IF NOT EXISTS {$table}") !== false ||
            stripos($sqlContent, "CREATE TABLE {$table}") !== false) {
            echo colorOutput("  ✓ ", 'green') . "表定义存在: {$table}" . PHP_EOL;
            $passed[] = "SQL表定义: {$table}";
        } else {
            echo colorOutput("  ✗ ", 'red') . "表定义缺失: {$table}" . PHP_EOL;
            $issues[] = "SQL表缺失: {$table}";
        }
    }

    foreach ($expectedViews as $view) {
        if (stripos($sqlContent, "CREATE VIEW {$view}") !== false) {
            echo colorOutput("  ✓ ", 'green') . "视图定义存在: {$view}" . PHP_EOL;
            $passed[] = "SQL视图定义: {$view}";
        } else {
            echo colorOutput("  ✗ ", 'red') . "视图定义缺失: {$view}" . PHP_EOL;
            $issues[] = "SQL视图缺失: {$view}";
        }
    }
} else {
    echo colorOutput("  ✗ ", 'red') . "SQL文件不存在!" . PHP_EOL;
    $issues[] = "SQL文件缺失";
}
echo PHP_EOL;

// ============= 6. 代码质量检查 =============
echo colorOutput("6️⃣  代码质量和安全检查", 'blue') . PHP_EOL;

// 检查 SQL 注入防护
$dbArchiverContent = file_get_contents('/home/user/wdsxxx/app/wds/src/maintenance/database_archiver.php');
if (strpos($dbArchiverContent, 'prepare(') !== false && strpos($dbArchiverContent, 'execute(') !== false) {
    echo colorOutput("  ✓ ", 'green') . "DatabaseArchiver使用预处理语句" . PHP_EOL;
    $passed[] = "使用PDO预处理语句（防SQL注入）";
} else {
    echo colorOutput("  ⚠ ", 'yellow') . "DatabaseArchiver可能存在SQL注入风险" . PHP_EOL;
    $issues[] = "潜在SQL注入风险";
}

// 检查事务处理
if (strpos($dbArchiverContent, 'beginTransaction()') !== false &&
    strpos($dbArchiverContent, 'commit()') !== false &&
    strpos($dbArchiverContent, 'rollBack()') !== false) {
    echo colorOutput("  ✓ ", 'green') . "DatabaseArchiver使用事务处理" . PHP_EOL;
    $passed[] = "事务处理完整";
} else {
    echo colorOutput("  ✗ ", 'red') . "DatabaseArchiver事务处理不完整" . PHP_EOL;
    $issues[] = "事务处理缺失";
}

// 检查 OPTIMIZE TABLE 是否在事务外
if (preg_match('/commit\(\).*?OPTIMIZE TABLE/s', $dbArchiverContent)) {
    echo colorOutput("  ✓ ", 'green') . "OPTIMIZE TABLE在事务外执行（正确）" . PHP_EOL;
    $passed[] = "OPTIMIZE TABLE位置正确";
} elseif (strpos($dbArchiverContent, 'OPTIMIZE TABLE') !== false) {
    if (preg_match('/beginTransaction\(\).*?OPTIMIZE TABLE.*?commit\(\)/s', $dbArchiverContent)) {
        echo colorOutput("  ✗ ", 'red') . "OPTIMIZE TABLE在事务内（会导致隐式提交）" . PHP_EOL;
        $issues[] = "OPTIMIZE TABLE在事务内";
    } else {
        echo colorOutput("  ✓ ", 'green') . "OPTIMIZE TABLE位置安全" . PHP_EOL;
        $passed[] = "OPTIMIZE TABLE位置检查通过";
    }
}

// 检查文件操作安全性
$monthlyArchiverContent = file_get_contents('/home/user/wdsxxx/app/wds/src/maintenance/monthly_archiver.php');
if (strpos($monthlyArchiverContent, 'escapeshellarg(') !== false) {
    echo colorOutput("  ✓ ", 'green') . "MonthlyArchiver使用escapeshellarg防护" . PHP_EOL;
    $passed[] = "Shell命令转义正确";
} else {
    echo colorOutput("  ⚠ ", 'yellow') . "MonthlyArchiver可能缺少shell转义" . PHP_EOL;
    $issues[] = "潜在命令注入风险";
}

// 检查错误处理
if (preg_match_all('/try\s*\{/', $dbArchiverContent, $matches) > 0) {
    echo colorOutput("  ✓ ", 'green') . "DatabaseArchiver使用异常处理" . PHP_EOL;
    $passed[] = "异常处理完整";
} else {
    echo colorOutput("  ⚠ ", 'yellow') . "DatabaseArchiver缺少异常处理" . PHP_EOL;
}

echo PHP_EOL;

// ============= 7. 配置文件检查 =============
echo colorOutput("7️⃣  配置和目录结构检查", 'blue') . PHP_EOL;

$requiredDirs = [
    'app/wds/storage/raw/open_meteo',
    'app/wds/storage/raw/open_meteo_archive',
    'app/wds/storage/raw/archives',
];

foreach ($requiredDirs as $dir) {
    $fullPath = "/home/user/wdsxxx/{$dir}";
    if (is_dir($fullPath)) {
        $perms = substr(sprintf('%o', fileperms($fullPath)), -4);
        echo colorOutput("  ✓ ", 'green') . "目录存在: {$dir} (权限: {$perms})" . PHP_EOL;
        $passed[] = "目录存在: {$dir}";
    } else {
        echo colorOutput("  ⚠ ", 'yellow') . "目录不存在: {$dir} (首次运行时自动创建)" . PHP_EOL;
    }
}

// 检查配置文件
$configFiles = [
    'app/wds/config_wds/env_wds.php',
    'app/wds/config_wds/env_wds.sample.php',
];

foreach ($configFiles as $config) {
    $fullPath = "/home/user/wdsxxx/{$config}";
    if (file_exists($fullPath)) {
        echo colorOutput("  ✓ ", 'green') . "配置文件存在: {$config}" . PHP_EOL;
        $passed[] = "配置文件: {$config}";
    } else {
        echo colorOutput("  ⚠ ", 'yellow') . "配置文件不存在: {$config}" . PHP_EOL;
    }
}

echo PHP_EOL;

// ============= 8. 文档完整性检查 =============
echo colorOutput("8️⃣  文档完整性检查", 'blue') . PHP_EOL;

$docs = [
    'docs/WDS_OPTIMIZATION_SYSTEM_DOCUMENTATION.md' => '系统文档',
    'docs/QUICK_START_GUIDE.md' => '快速开始指南',
    'README.md' => 'README文件',
];

foreach ($docs as $doc => $desc) {
    $fullPath = "/home/user/wdsxxx/{$doc}";
    if (file_exists($fullPath)) {
        $size = filesize($fullPath);
        echo colorOutput("  ✓ ", 'green') . "{$desc}: {$doc} (" . round($size/1024, 1) . " KB)" . PHP_EOL;
        $passed[] = "文档存在: {$doc}";
    } else {
        echo colorOutput("  ⚠ ", 'yellow') . "{$desc}: {$doc} - 文件不存在" . PHP_EOL;
    }
}

echo PHP_EOL;

// ============= 汇总 =============
echo colorOutput("=================================================", 'blue') . PHP_EOL;
echo colorOutput("                    验证汇总                      ", 'blue') . PHP_EOL;
echo colorOutput("=================================================", 'blue') . PHP_EOL . PHP_EOL;

echo colorOutput("✓ 通过项: " . count($passed), 'green') . PHP_EOL;
echo colorOutput("✗ 问题项: " . count($issues), count($issues) > 0 ? 'red' : 'green') . PHP_EOL . PHP_EOL;

if (count($issues) > 0) {
    echo colorOutput("需要修复的问题:", 'red') . PHP_EOL;
    foreach ($issues as $i => $issue) {
        echo "  " . ($i+1) . ". {$issue}" . PHP_EOL;
    }
    echo PHP_EOL;
    echo colorOutput("❌ 代码验证未完全通过，请修复上述问题", 'red') . PHP_EOL;
    exit(1);
} else {
    echo colorOutput("✅ 所有静态验证项通过！", 'green') . PHP_EOL;
    echo colorOutput("💡 下一步: 在生产环境运行 comprehensive_test.php 进行完整测试", 'blue') . PHP_EOL;
    exit(0);
}
