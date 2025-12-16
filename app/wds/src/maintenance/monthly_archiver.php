<?php
namespace WDS\maintenance;

use PDO;

/**
 * 月度归档压缩类
 * 负责将JSON文件按月归档压缩，减少文件数量
 */
class MonthlyArchiver {
    private PDO $pdo;
    private string $rawDir;
    private array $cfg;

    public function __construct(PDO $pdo, array $cfg) {
        $this->pdo = $pdo;
        $this->cfg = $cfg;
        $this->rawDir = APP_WDS . '/storage/raw';
    }

    /**
     * 执行月度归档流程
     * @param string $month YYYY-MM 格式
     * @return array
     */
    public function executeMonthlyArchive(string $month) : array {
        $results = [
            'month' => $month,
            'timestamp' => date('Y-m-d H:i:s'),
            'steps' => []
        ];

        try {
            // Step 1: 压缩预报JSON
            $results['steps']['compress_forecast'] = $this->compressForecastJSON($month);

            // Step 2: 压缩历史JSON
            $results['steps']['compress_archive'] = $this->compressArchiveJSON($month);

            // Step 3: 备份归档文件（可选）
            if ($this->cfg['backup_enabled'] ?? false) {
                $results['steps']['backup'] = $this->backupArchives($month);
            }

            // Step 4: 删除2个月前的原始JSON文件
            $twoMonthsAgo = date('Y-m', strtotime("{$month}-01 -2 months"));
            $results['steps']['cleanup'] = $this->cleanupOldJSON($twoMonthsAgo);

            $results['success'] = true;

        } catch (\Throwable $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
        }

        // 记录归档历史
        $this->logArchiveHistory($results);

        return $results;
    }

    /**
     * 压缩预报JSON文件
     */
    private function compressForecastJSON(string $month) : array {
        $sourceDir = "{$this->rawDir}/open_meteo/{$month}";

        if (!is_dir($sourceDir)) {
            return ['action' => 'skipped', 'reason' => 'directory_not_found'];
        }

        $archiveDir = "{$this->rawDir}/archives";
        ensure_dir($archiveDir);

        $archiveName = "forecast_{$month}.tar.gz";
        $archivePath = "{$archiveDir}/{$archiveName}";

        // 如果归档已存在，跳过
        if (file_exists($archivePath)) {
            return ['action' => 'skipped', 'reason' => 'already_exists', 'path' => $archivePath];
        }

        // 创建tar.gz归档
        $cmd = sprintf(
            'tar -czf %s -C %s .',
            escapeshellarg($archivePath),
            escapeshellarg($sourceDir)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException("Forecast compression failed for {$month}");
        }

        // 统计信息
        $files = glob("{$sourceDir}/*.json");
        $fileCount = count($files);
        $originalSize = 0;
        foreach ($files as $f) {
            if (file_exists($f)) {
                $originalSize += filesize($f);
            }
        }
        $compressedSize = filesize($archivePath);

        // 生成索引
        $this->generateIndex($month, 'forecast', $files, $archivePath);

        // 记录到数据库
        $this->recordArchive($month, 'forecast', $archivePath, $fileCount, $originalSize, $compressedSize);

        return [
            'action' => 'compressed',
            'path' => $archivePath,
            'files' => $fileCount,
            'original_mb' => round($originalSize / 1024 / 1024, 2),
            'compressed_mb' => round($compressedSize / 1024 / 1024, 2),
            'ratio' => $originalSize > 0 ? round((1 - $compressedSize/$originalSize) * 100, 1) . '%' : '0%'
        ];
    }

    /**
     * 压缩历史JSON文件
     */
    private function compressArchiveJSON(string $month) : array {
        $sourceDir = "{$this->rawDir}/open_meteo_archive/{$month}";

        if (!is_dir($sourceDir)) {
            return ['action' => 'skipped', 'reason' => 'directory_not_found'];
        }

        $archiveDir = "{$this->rawDir}/archives";
        ensure_dir($archiveDir);

        $archiveName = "archive_{$month}.tar.gz";
        $archivePath = "{$archiveDir}/{$archiveName}";

        if (file_exists($archivePath)) {
            return ['action' => 'skipped', 'reason' => 'already_exists', 'path' => $archivePath];
        }

        $cmd = sprintf(
            'tar -czf %s -C %s .',
            escapeshellarg($archivePath),
            escapeshellarg($sourceDir)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException("Archive compression failed for {$month}");
        }

        $files = glob("{$sourceDir}/*.json");
        $fileCount = count($files);
        $originalSize = 0;
        foreach ($files as $f) {
            if (file_exists($f)) {
                $originalSize += filesize($f);
            }
        }
        $compressedSize = filesize($archivePath);

        $this->generateIndex($month, 'archive', $files, $archivePath);
        $this->recordArchive($month, 'archive', $archivePath, $fileCount, $originalSize, $compressedSize);

        return [
            'action' => 'compressed',
            'path' => $archivePath,
            'files' => $fileCount,
            'original_mb' => round($originalSize / 1024 / 1024, 2),
            'compressed_mb' => round($compressedSize / 1024 / 1024, 2),
            'ratio' => $originalSize > 0 ? round((1 - $compressedSize/$originalSize) * 100, 1) . '%' : '0%'
        ];
    }

    /**
     * 生成归档索引文件
     */
    private function generateIndex(string $month, string $type, array $files, string $archivePath) : void {
        $index = [
            'month' => $month,
            'type' => $type,
            'created_at' => date('Y-m-d H:i:s'),
            'file_count' => count($files),
            'archive_path' => basename($archivePath),
            'files' => []
        ];

        foreach ($files as $f) {
            if (file_exists($f)) {
                $basename = basename($f);
                $index['files'][] = [
                    'name' => $basename,
                    'size' => filesize($f),
                    'mtime' => date('Y-m-d H:i:s', filemtime($f))
                ];
            }
        }

        $indexPath = str_replace('.tar.gz', '_index.json', $archivePath);
        file_put_contents($indexPath, json_encode($index, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    }

    /**
     * 记录归档到数据库
     */
    private function recordArchive(string $month, string $type, string $path, int $fileCount, int $originalSize, int $compressedSize) : void {
        $sql = "INSERT INTO wds_monthly_archives
                (month, archive_type, file_path, file_count, original_size_bytes, compressed_size_bytes, created_at)
                VALUES (:m, :t, :p, :fc, :os, :cs, UTC_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE
                  file_path=VALUES(file_path),
                  file_count=VALUES(file_count),
                  original_size_bytes=VALUES(original_size_bytes),
                  compressed_size_bytes=VALUES(compressed_size_bytes),
                  created_at=UTC_TIMESTAMP(6)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':m' => $month,
            ':t' => $type,
            ':p' => $path,
            ':fc' => $fileCount,
            ':os' => $originalSize,
            ':cs' => $compressedSize
        ]);
        $stmt->closeCursor(); // 关闭游标
    }

    /**
     * 删除旧的原始JSON文件（保留最近2个月）
     */
    private function cleanupOldJSON(string $month) : array {
        $deleted = [
            'forecast' => 0,
            'archive' => 0
        ];

        // 检查归档是否存在
        $forecastArchive = "{$this->rawDir}/archives/forecast_{$month}.tar.gz";
        $archiveArchive = "{$this->rawDir}/archives/archive_{$month}.tar.gz";

        // 只有在归档存在的情况下才删除原始文件
        if (file_exists($forecastArchive)) {
            $forecastDir = "{$this->rawDir}/open_meteo/{$month}";
            if (is_dir($forecastDir)) {
                $files = glob("{$forecastDir}/*.json");
                foreach ($files as $f) {
                    if (unlink($f)) $deleted['forecast']++;
                }
                // 如果目录为空，删除目录
                $remaining = scandir($forecastDir);
                if (count($remaining) == 2) { // 只有 . 和 ..
                    rmdir($forecastDir);
                }
            }
        }

        if (file_exists($archiveArchive)) {
            $archiveDir = "{$this->rawDir}/open_meteo_archive/{$month}";
            if (is_dir($archiveDir)) {
                $files = glob("{$archiveDir}/*.json");
                foreach ($files as $f) {
                    if (unlink($f)) $deleted['archive']++;
                }
                $remaining = scandir($archiveDir);
                if (count($remaining) == 2) {
                    rmdir($archiveDir);
                }
            }
        }

        return [
            'month' => $month,
            'deleted_files' => $deleted,
            'total' => $deleted['forecast'] + $deleted['archive']
        ];
    }

    /**
     * 备份归档文件到NAS或其他位置
     */
    private function backupArchives(string $month) : array {
        $backupDir = $this->cfg['backup_path'] ?? '/mnt/nas/wds_backups';

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $archives = [
            "{$this->rawDir}/archives/forecast_{$month}.tar.gz",
            "{$this->rawDir}/archives/archive_{$month}.tar.gz"
        ];

        $backed = [];

        foreach ($archives as $source) {
            if (file_exists($source)) {
                $dest = $backupDir . '/' . basename($source);
                if (copy($source, $dest)) {
                    $backed[] = basename($source);
                }
            }
        }

        return [
            'backup_path' => $backupDir,
            'files' => $backed,
            'count' => count($backed)
        ];
    }

    /**
     * 记录归档历史日志
     */
    private function logArchiveHistory(array $results) : void {
        $sql = "INSERT INTO wds_archive_history
                (month, success, steps_json, error_message, created_at)
                VALUES (:m, :s, :j, :e, UTC_TIMESTAMP(6))";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':m' => $results['month'],
            ':s' => $results['success'] ? 1 : 0,
            ':j' => json_encode($results, JSON_UNESCAPED_UNICODE),
            ':e' => $results['error'] ?? null
        ]);
        $stmt->closeCursor(); // 关闭游标
    }
}
