<?php
namespace WDS\maintenance;

use PDO;

/**
 * 数据库冷热分离归档类
 * 负责将热表中的旧数据迁移到压缩归档表
 */
class DatabaseArchiver {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * 将30天前的预报数据迁移到归档表
     * @param int $daysOld 多少天前的数据归档
     * @return array
     */
    public function archiveOldForecasts(int $daysOld = 30) : array {
        $startTime = microtime(true);
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        // 检查归档表是否存在
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'wds_weather_hourly_forecast_archive'");
            $tableExists = $stmt->fetch();
            $stmt->closeCursor(); // 关闭游标，释放连接

            if (!$tableExists) {
                return [
                    'success' => false,
                    'error' => 'Archive table does not exist. Please run: docs/wds_optimization_tables.sql'
                ];
            }
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Failed to check archive table: ' . $e->getMessage()
            ];
        }

        $transactionStarted = false;
        try {
            $this->pdo->beginTransaction();
            $transactionStarted = true;

            // 1. 复制到归档表（列出所有字段，archived_at自动填充）
            $insertSql = "
                INSERT INTO wds_weather_hourly_forecast_archive
                (location_id, run_time_utc, forecast_time_utc, temp_c, wmo_code,
                 precip_mm_tenths, precip_prob_pct, wind_kph_tenths, gust_kph_tenths,
                 created_at, updated_at, archived_at)
                SELECT
                    location_id, run_time_utc, forecast_time_utc, temp_c, wmo_code,
                    precip_mm_tenths, precip_prob_pct, wind_kph_tenths, gust_kph_tenths,
                    created_at, updated_at, UTC_TIMESTAMP(6)
                FROM wds_weather_hourly_forecast
                WHERE forecast_time_utc < :cutoff
                ON DUPLICATE KEY UPDATE
                  temp_c=VALUES(temp_c),
                  wmo_code=VALUES(wmo_code),
                  precip_mm_tenths=VALUES(precip_mm_tenths),
                  precip_prob_pct=VALUES(precip_prob_pct),
                  wind_kph_tenths=VALUES(wind_kph_tenths),
                  gust_kph_tenths=VALUES(gust_kph_tenths),
                  updated_at=VALUES(updated_at),
                  archived_at=UTC_TIMESTAMP(6)
            ";

            $stmt = $this->pdo->prepare($insertSql);
            $stmt->execute([':cutoff' => $cutoffDate]);
            $archivedRows = $stmt->rowCount();
            $stmt->closeCursor(); // 关闭游标

            // 2. 从热表删除
            $deleteSql = "
                DELETE FROM wds_weather_hourly_forecast
                WHERE forecast_time_utc < :cutoff
            ";

            $stmt = $this->pdo->prepare($deleteSql);
            $stmt->execute([':cutoff' => $cutoffDate]);
            $deletedRows = $stmt->rowCount();
            $stmt->closeCursor(); // 关闭游标

            // 提交事务
            $this->pdo->commit();
            $transactionStarted = false;

            // 3. 优化表（在事务外执行，因为DDL会导致隐式提交）
            try {
                $this->pdo->exec("OPTIMIZE TABLE wds_weather_hourly_forecast");
            } catch (\Throwable $optError) {
                // OPTIMIZE失败不影响归档结果，记录日志即可
                error_log("OPTIMIZE TABLE failed: " . $optError->getMessage());
            }

            $executionTime = round((microtime(true) - $startTime) * 1000); // 毫秒

            // 记录日志
            $this->logDBArchive($cutoffDate, $archivedRows, $deletedRows, $executionTime);

            return [
                'success' => true,
                'cutoff_date' => $cutoffDate,
                'archived_rows' => $archivedRows,
                'deleted_rows' => $deletedRows,
                'execution_time_ms' => $executionTime
            ];

        } catch (\Throwable $e) {
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    /**
     * 检查是否需要归档（根据热表行数）
     * @return bool
     */
    public function shouldArchive() : bool {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM wds_weather_hourly_forecast");
        $count = $stmt->fetchColumn();
        $stmt->closeCursor(); // 关闭游标

        // 如果热表超过10万行，建议归档
        return $count > 100000;
    }

    /**
     * 获取热表统计信息
     * @return array
     */
    public function getHotTableStats() : array {
        $sql = "SELECT
                    COUNT(*) as total_rows,
                    MIN(forecast_time_utc) as oldest_forecast,
                    MAX(forecast_time_utc) as newest_forecast,
                    COUNT(DISTINCT location_id) as location_count,
                    COUNT(DISTINCT run_time_utc) as run_count
                FROM wds_weather_hourly_forecast";

        $stmt = $this->pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor(); // 关闭游标
        return $result;
    }

    /**
     * 获取归档表统计信息
     * @return array
     */
    public function getArchiveTableStats() : array {
        // 检查归档表是否存在
        $stmt = $this->pdo->query("SHOW TABLES LIKE 'wds_weather_hourly_forecast_archive'");
        $tableExists = $stmt->fetch();
        $stmt->closeCursor(); // 关闭游标

        if (!$tableExists) {
            return [
                'total_rows' => 0,
                'oldest_forecast' => null,
                'newest_forecast' => null,
                'location_count' => 0,
                'run_count' => 0
            ];
        }

        $sql = "SELECT
                    COUNT(*) as total_rows,
                    MIN(forecast_time_utc) as oldest_forecast,
                    MAX(forecast_time_utc) as newest_forecast,
                    COUNT(DISTINCT location_id) as location_count,
                    COUNT(DISTINCT run_time_utc) as run_count
                FROM wds_weather_hourly_forecast_archive";

        $stmt = $this->pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor(); // 关闭游标
        return $result;
    }

    /**
     * 记录归档日志
     */
    private function logDBArchive(string $cutoffDate, int $archived, int $deleted, int $executionTime) : void {
        $sql = "INSERT INTO wds_db_archive_log
                (cutoff_date, archived_rows, deleted_rows, execution_time_ms, created_at)
                VALUES (:cd, :ar, :dr, :et, UTC_TIMESTAMP(6))";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':cd' => $cutoffDate,
            ':ar' => $archived,
            ':dr' => $deleted,
            ':et' => $executionTime
        ]);
        $stmt->closeCursor(); // 关闭游标
    }

    /**
     * 获取归档历史日志
     * @param int $limit
     * @return array
     */
    public function getArchiveHistory(int $limit = 10) : array {
        $sql = "SELECT * FROM wds_db_archive_log
                ORDER BY created_at DESC
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor(); // 关闭游标

        return $result;
    }
}
