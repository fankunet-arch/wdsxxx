-- ============================================================
-- WDS 数据优化方案所需数据库表
-- 创建时间: 2025-12-16
-- 说明: 用于支持JSON归档、数据库冷热分离等优化功能
-- ============================================================

-- 1. 月度归档追踪表
-- 用途: 记录每月JSON文件归档的元数据
CREATE TABLE IF NOT EXISTS wds_monthly_archives (
    archive_id BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
    month VARCHAR(7) NOT NULL COMMENT '归档月份 YYYY-MM',
    archive_type ENUM('forecast', 'archive') NOT NULL COMMENT '归档类型: forecast=预报数据, archive=历史数据',
    file_path VARCHAR(255) NOT NULL COMMENT '归档文件路径',
    file_count INT(11) NOT NULL DEFAULT 0 COMMENT '包含文件数量',
    original_size_bytes BIGINT(20) NOT NULL DEFAULT 0 COMMENT '原始大小(字节)',
    compressed_size_bytes BIGINT(20) NOT NULL DEFAULT 0 COMMENT '压缩后大小(字节)',
    compression_ratio DECIMAL(5,2) GENERATED ALWAYS AS (
        ROUND((1 - compressed_size_bytes / NULLIF(original_size_bytes, 0)) * 100, 2)
    ) STORED COMMENT '压缩率(%)',
    created_at DATETIME(6) NOT NULL COMMENT '创建时间',
    UNIQUE KEY uk_month_type (month, archive_type),
    INDEX idx_month (month),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='月度JSON归档记录表';

-- 2. 归档历史日志表
-- 用途: 记录每次归档操作的详细执行情况
CREATE TABLE IF NOT EXISTS wds_archive_history (
    history_id BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
    month VARCHAR(7) NOT NULL COMMENT '归档月份',
    success TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否成功: 1=成功, 0=失败',
    steps_json TEXT COMMENT '执行步骤详情JSON',
    error_message TEXT COMMENT '错误信息',
    created_at DATETIME(6) NOT NULL COMMENT '执行时间',
    INDEX idx_month (month),
    INDEX idx_created (created_at),
    INDEX idx_success (success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='归档操作历史日志表';

-- 3. 预报数据归档表（冷表）
-- 用途: 存储30天前的预报数据，使用压缩格式节省空间
CREATE TABLE IF NOT EXISTS wds_weather_hourly_forecast_archive (
    location_id BIGINT(20) NOT NULL,
    run_time_utc DATETIME(6) NOT NULL COMMENT '预报运行时间',
    forecast_time_utc DATETIME(6) NOT NULL COMMENT '预报目标时间',
    temp_c INT(11) DEFAULT NULL COMMENT '温度×10',
    wmo_code INT(11) DEFAULT NULL COMMENT 'WMO天气代码',
    precip_mm_tenths INT(11) DEFAULT NULL COMMENT '降水量×10',
    precip_prob_pct INT(11) DEFAULT NULL COMMENT '降水概率%',
    wind_kph_tenths INT(11) DEFAULT NULL COMMENT '风速×10',
    gust_kph_tenths INT(11) DEFAULT NULL COMMENT '阵风×10',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) DEFAULT NULL,
    archived_at DATETIME(6) DEFAULT NULL COMMENT '归档时间',
    PRIMARY KEY (location_id, forecast_time_utc, run_time_utc),
    INDEX idx_ft (forecast_time_utc),
    INDEX idx_archived (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
ROW_FORMAT=COMPRESSED
KEY_BLOCK_SIZE=8
COMMENT='预报数据归档表(冷数据，存储30天前数据)';

-- 4. 数据库归档日志表
-- 用途: 记录数据库热表迁移到冷表的操作日志
CREATE TABLE IF NOT EXISTS wds_db_archive_log (
    log_id BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
    cutoff_date DATETIME NOT NULL COMMENT '截止日期',
    archived_rows INT(11) NOT NULL DEFAULT 0 COMMENT '归档行数',
    deleted_rows INT(11) NOT NULL DEFAULT 0 COMMENT '删除行数',
    execution_time_ms INT(11) DEFAULT NULL COMMENT '执行时间(毫秒)',
    created_at DATETIME(6) NOT NULL COMMENT '执行时间',
    INDEX idx_cutoff (cutoff_date),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='数据库归档操作日志表';

-- 5. 联合视图（透明查询冷热数据）
-- 用途: 提供统一查询接口，自动合并热表和冷表数据
DROP VIEW IF EXISTS vw_weather_forecast_all;
CREATE VIEW vw_weather_forecast_all AS
SELECT
    location_id,
    run_time_utc,
    forecast_time_utc,
    temp_c,
    wmo_code,
    precip_mm_tenths,
    precip_prob_pct,
    wind_kph_tenths,
    gust_kph_tenths,
    created_at,
    updated_at,
    'hot' as data_source,
    NULL as archived_at
FROM wds_weather_hourly_forecast
UNION ALL
SELECT
    location_id,
    run_time_utc,
    forecast_time_utc,
    temp_c,
    wmo_code,
    precip_mm_tenths,
    precip_prob_pct,
    wind_kph_tenths,
    gust_kph_tenths,
    created_at,
    updated_at,
    'archive' as data_source,
    archived_at
FROM wds_weather_hourly_forecast_archive;

-- ============================================================
-- 执行完毕
-- 使用方法: mysql -u用户名 -p < wds_optimization_tables.sql
-- ============================================================
