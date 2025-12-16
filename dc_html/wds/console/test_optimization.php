<?php
/**
 * 数据优化功能测试脚本
 * 用于测试月度归档、数据库归档等功能
 */

require_once(__DIR__ . '/../../../app/wds/bootstrap/app.php');
use WDS\maintenance\MonthlyArchiver;
use WDS\maintenance\DatabaseArchiver;

header('Content-Type: text/html; charset=utf-8');

$pdo = db();
$cfg = cfg();

// 获取操作参数
$action = $_GET['action'] ?? 'status';
$month = $_GET['month'] ?? date('Y-m', strtotime('-1 month'));

?>
<!DOCTYPE html>
<html>
<head>
    <title>WDS 优化功能测试</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        h2 { color: #666; margin-top: 30px; }
        .action-buttons { margin: 20px 0; }
        .btn { display: inline-block; padding: 10px 20px; margin: 5px; background: #007bff; color: white;
               text-decoration: none; border-radius: 4px; border: none; cursor: pointer; }
        .btn:hover { background: #0056b3; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .result { background: #f8f9fa; padding: 15px; margin: 10px 0; border-left: 4px solid #007bff; }
        .success { border-left-color: #28a745; }
        .error { border-left-color: #dc3545; }
        .stats-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        .stats-table th, .stats-table td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        .stats-table th { background: #007bff; color: white; }
        pre { background: #272822; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
<div class="container">
    <h1>🚀 WDS 数据优化功能测试</h1>

    <div class="action-buttons">
        <a href="?action=status" class="btn">📊 查看状态</a>
        <a href="?action=test_monthly&month=<?= $month ?>" class="btn btn-success">🗜️ 测试月度归档</a>
        <a href="?action=test_db" class="btn btn-success">💾 测试数据库归档</a>
        <a href="?action=stats" class="btn">📈 统计信息</a>
    </div>

    <?php
    try {
        switch ($action) {
            case 'status':
                echo '<h2>📊 系统状态</h2>';

                // 数据库大小
                $dbSize = $pdo->query("
                    SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS mb
                    FROM information_schema.TABLES
                    WHERE table_schema = DATABASE()
                ")->fetch();

                // 热表行数
                $hotRows = $pdo->query("SELECT COUNT(*) FROM wds_weather_hourly_forecast")->fetchColumn();

                // 归档表行数（如果存在）
                $archiveExists = $pdo->query("SHOW TABLES LIKE 'wds_weather_hourly_forecast_archive'")->fetch();
                $archiveRows = 0;
                if ($archiveExists) {
                    $archiveRows = $pdo->query("SELECT COUNT(*) FROM wds_weather_hourly_forecast_archive")->fetchColumn();
                }

                // JSON文件统计
                $jsonForecastCount = 0;
                $jsonArchiveCount = 0;
                $rawDir = APP_WDS . '/storage/raw';

                if (is_dir("{$rawDir}/open_meteo")) {
                    $jsonForecastCount = count(glob("{$rawDir}/open_meteo/*/*.json"));
                }
                if (is_dir("{$rawDir}/open_meteo_archive")) {
                    $jsonArchiveCount = count(glob("{$rawDir}/open_meteo_archive/*/*.json"));
                }

                // 归档文件统计
                $archiveFiles = is_dir("{$rawDir}/archives") ? count(glob("{$rawDir}/archives/*.tar.gz")) : 0;

                echo '<div class="result success">';
                echo '<table class="stats-table">';
                echo '<tr><th>项目</th><th>值</th></tr>';
                echo "<tr><td>数据库总大小</td><td>{$dbSize['mb']} MB</td></tr>";
                echo "<tr><td>热表行数 (forecast)</td><td>" . number_format($hotRows) . " 行</td></tr>";
                echo "<tr><td>冷表行数 (archive)</td><td>" . number_format($archiveRows) . " 行</td></tr>";
                echo "<tr><td>预报JSON文件数</td><td>" . number_format($jsonForecastCount) . " 个</td></tr>";
                echo "<tr><td>历史JSON文件数</td><td>" . number_format($jsonArchiveCount) . " 个</td></tr>";
                echo "<tr><td>归档压缩文件数</td><td>" . number_format($archiveFiles) . " 个</td></tr>";
                echo '</table>';
                echo '</div>';
                break;

            case 'test_monthly':
                echo '<h2>🗜️ 测试月度归档</h2>';
                echo "<p>归档月份: <strong>{$month}</strong></p>";

                $archiver = new MonthlyArchiver($pdo, $cfg);
                $result = $archiver->executeMonthlyArchive($month);

                echo '<div class="result ' . ($result['success'] ? 'success' : 'error') . '">';
                echo '<pre>' . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                echo '</div>';
                break;

            case 'test_db':
                echo '<h2>💾 测试数据库归档</h2>';

                $dbArchiver = new DatabaseArchiver($pdo);

                // 显示归档前统计
                $beforeStats = $dbArchiver->getHotTableStats();
                echo '<h3>归档前热表统计</h3>';
                echo '<div class="result">';
                echo '<pre>' . json_encode($beforeStats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                echo '</div>';

                // 执行归档
                $result = $dbArchiver->archiveOldForecasts(30);

                echo '<h3>归档结果</h3>';
                echo '<div class="result ' . ($result['success'] ? 'success' : 'error') . '">';
                echo '<pre>' . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                echo '</div>';

                // 显示归档后统计
                $afterStats = $dbArchiver->getHotTableStats();
                echo '<h3>归档后热表统计</h3>';
                echo '<div class="result">';
                echo '<pre>' . json_encode($afterStats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                echo '</div>';
                break;

            case 'stats':
                echo '<h2>📈 详细统计信息</h2>';

                $dbArchiver = new DatabaseArchiver($pdo);

                // 热表统计
                $hotStats = $dbArchiver->getHotTableStats();
                echo '<h3>热表统计</h3>';
                echo '<div class="result">';
                echo '<table class="stats-table">';
                foreach ($hotStats as $key => $value) {
                    echo "<tr><td>{$key}</td><td>{$value}</td></tr>";
                }
                echo '</table>';
                echo '</div>';

                // 冷表统计
                $archiveStats = $dbArchiver->getArchiveTableStats();
                echo '<h3>冷表统计</h3>';
                echo '<div class="result">';
                echo '<table class="stats-table">';
                foreach ($archiveStats as $key => $value) {
                    echo "<tr><td>{$key}</td><td>{$value}</td></tr>";
                }
                echo '</table>';
                echo '</div>';

                // 归档历史
                $history = $dbArchiver->getArchiveHistory(5);
                if (!empty($history)) {
                    echo '<h3>最近5次归档历史</h3>';
                    echo '<div class="result">';
                    echo '<table class="stats-table">';
                    echo '<tr><th>时间</th><th>截止日期</th><th>归档行数</th><th>删除行数</th><th>执行时间(ms)</th></tr>';
                    foreach ($history as $h) {
                        echo "<tr>";
                        echo "<td>{$h['created_at']}</td>";
                        echo "<td>{$h['cutoff_date']}</td>";
                        echo "<td>" . number_format($h['archived_rows']) . "</td>";
                        echo "<td>" . number_format($h['deleted_rows']) . "</td>";
                        echo "<td>{$h['execution_time_ms']}</td>";
                        echo "</tr>";
                    }
                    echo '</table>';
                    echo '</div>';
                }

                // 月度归档记录
                $monthlyArchives = $pdo->query("
                    SELECT * FROM wds_monthly_archives
                    ORDER BY month DESC
                    LIMIT 10
                ")->fetchAll();

                if (!empty($monthlyArchives)) {
                    echo '<h3>月度归档记录</h3>';
                    echo '<div class="result">';
                    echo '<table class="stats-table">';
                    echo '<tr><th>月份</th><th>类型</th><th>文件数</th><th>原始大小(MB)</th><th>压缩后(MB)</th><th>压缩率</th></tr>';
                    foreach ($monthlyArchives as $ma) {
                        $origMB = round($ma['original_size_bytes'] / 1024 / 1024, 2);
                        $compMB = round($ma['compressed_size_bytes'] / 1024 / 1024, 2);
                        echo "<tr>";
                        echo "<td>{$ma['month']}</td>";
                        echo "<td>{$ma['archive_type']}</td>";
                        echo "<td>{$ma['file_count']}</td>";
                        echo "<td>{$origMB}</td>";
                        echo "<td>{$compMB}</td>";
                        echo "<td>{$ma['compression_ratio']}%</td>";
                        echo "</tr>";
                    }
                    echo '</table>';
                    echo '</div>';
                }
                break;

            default:
                echo '<div class="result error">未知操作</div>';
        }

    } catch (\Throwable $e) {
        echo '<div class="result error">';
        echo '<strong>错误：</strong>' . htmlspecialchars($e->getMessage());
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        echo '</div>';
    }
    ?>

    <hr>
    <p style="color: #999; text-align: center;">WDS 数据优化系统 v1.0 | <?= date('Y-m-d H:i:s') ?></p>
</div>
</body>
</html>
