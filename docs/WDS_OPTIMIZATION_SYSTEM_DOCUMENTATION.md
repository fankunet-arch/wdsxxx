# WDS 天气数据系统 - 数据优化方案完整文档

**版本**: 1.0
**创建日期**: 2025-12-16
**作者**: Claude (System Architect)
**目的**: 详细说明WDS系统的数据优化方案，帮助快速理解和维护系统

---

## 📑 目录

1. [系统概述](#1-系统概述)
2. [优化方案背景](#2-优化方案背景)
3. [系统架构](#3-系统架构)
4. [核心模块详解](#4-核心模块详解)
5. [数据流程](#5-数据流程)
6. [数据库设计](#6-数据库设计)
7. [配置说明](#7-配置说明)
8. [实施步骤](#8-实施步骤)
9. [使用指南](#9-使用指南)
10. [监控和维护](#10-监控和维护)
11. [故障排查](#11-故障排查)
12. [性能优化建议](#12-性能优化建议)
13. [未来扩展计划](#13-未来扩展计划)

---

## 1. 系统概述

### 1.1 系统简介

WDS (Weather Data System) 是一个天气数据采集和分析系统，主要功能包括：

- **预报数据采集**: 从 Open-Meteo API 采集16天天气预报数据
- **历史数据回填**: 回填历史观测数据用于预报准确度验证
- **数据存储**: 双存储策略（数据库 + JSON文件）
- **准确度评估**: 对预报数据源进行MAE（平均绝对误差）评估
- **数据优化**: 自动归档、压缩、冷热分离

### 1.2 技术栈

```
后端语言: PHP 8.2+
数据库: MariaDB 10.5+ / MySQL
数据源: Open-Meteo Forecast API + Archive API
架构模式: MVC + RESTful API
时区: Europe/Madrid (本地) + UTC (存储)
自动化: API触发式维护任务
```

### 1.3 业务特点

- **营业时段数据**: 仅采集营业时间段（12:00-22:00）的天气数据
- **多次预报记录**: 每天5次采集同一时间点的预报（用于准确度考核）
- **长期数据保留**: 所有数据零删除，用于长期趋势分析和业务决策
- **NAS友好存储**: 控制文件数量，适配NAS存储

---

## 2. 优化方案背景

### 2.1 优化前面临的问题

#### 问题1: JSON文件冗余
```
现象: 每天重复回填历史数据，导致相同数据生成多个JSON文件
示例:
  2025-12-16 01:15: 回填 12-14, 12-15, 12-16 → 生成15个JSON
  2025-12-17 01:15: 回填 12-15, 12-16, 12-17 → 又生成15个JSON
                    ├─ 12-15重复了
                    └─ 12-16重复了

影响: 月增长 900个文件，年增长 10,800个文件
```

#### 问题2: 数据库持续增长
```
现象: 预报数据每天5次采集×16天预报 = 大量重叠数据
计算:
  每次采集: 5地点 × 11小时 × 16天 = 880行
  每日新增: 880行 × 5次 = 4,400行/天
  每月新增: 132,000行 ≈ 30 MB/月
  年增长: 360 MB

问题: 2年后接近数据库阈值（800MB）
```

#### 问题3: 无清理机制
```
现状:
  - 30天前数据无自动清理
  - JSON文件永久累积
  - 数据库阈值配置未启用
```

### 2.2 优化目标

✅ **零删除**: 所有有价值数据永久保留
✅ **控制数量**: JSON文件数量减少90%+
✅ **节省空间**: 存储空间节省30-40%
✅ **提升性能**: 热表查询速度提升60%+
✅ **自动化**: 无需人工干预的自动维护

---

## 3. 系统架构

### 3.1 整体架构图

```
┌─────────────────────────────────────────────────────────────────┐
│                     外部数据源                                   │
│  ┌────────────────────┐       ┌────────────────────┐            │
│  │ Open-Meteo API     │       │ Archive API        │            │
│  │ (16天预报)         │       │ (历史观测)         │            │
│  └────────────────────┘       └────────────────────┘            │
└─────────────────┬────────────────────────┬─────────────────────┘
                  │                        │
                  ▼                        ▼
┌─────────────────────────────────────────────────────────────────┐
│                API 触发器 (auto_collect.php)                     │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ 时间槽: 01:15, 11:15, 13:15, 16:15, 19:15                │   │
│  │ 窗口: ±10分钟                                            │   │
│  │ 认证: Bearer Token                                       │   │
│  └──────────────────────────────────────────────────────────┘   │
└────────┬────────────────────────────────────────────────────────┘
         │
         ├─────────► [数据采集] OpenMeteoIngest
         │           ├─ fetchForecast()         预报数据
         │           └─ fetchArchiveSmart()     智能回填
         │
         ├─────────► [JSON存储] save_snapshot_by_date()
         │           ├─ /storage/raw/open_meteo/{YYYY-MM}/
         │           └─ /storage/raw/open_meteo_archive/{YYYY-MM}/
         │
         ├─────────► [数据库写入] wds_weather_hourly_*
         │           ├─ forecast (热表)
         │           └─ observed
         │
         └─────────► [维护任务] (仅01:15槽)
                     ├─ 月度归档 (每月1日)
                     │   └─ MonthlyArchiver::executeMonthlyArchive()
                     └─ 数据库归档 (每天)
                         └─ DatabaseArchiver::archiveOldForecasts()

┌─────────────────────────────────────────────────────────────────┐
│                    存储层                                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │ 数据库热表   │  │ 数据库冷表   │  │ JSON归档     │          │
│  │ (最近30天)   │  │ (30天前)     │  │ (月度压缩)   │          │
│  │ 快速查询     │  │ 压缩存储     │  │ tar.gz       │          │
│  └──────────────┘  └──────────────┘  └──────────────┘          │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2 目录结构

```
wdsxxx/
├── app/wds/
│   ├── bootstrap/
│   │   └── app.php                      # 应用引导文件
│   ├── config_wds/
│   │   ├── env_wds.php                  # 配置文件（包含归档配置）
│   │   └── env_wds.sample.php
│   ├── src/
│   │   ├── ingest/
│   │   │   └── open_meteo_ingest.php    # 数据采集类（含智能回填）
│   │   └── maintenance/                 # 🆕 维护模块
│   │       ├── monthly_archiver.php     # 🆕 月度JSON归档类
│   │       └── db_archiver.php          # 🆕 数据库归档类
│   └── storage/
│       └── raw/
│           ├── open_meteo/              # 预报JSON（按月分组）
│           ├── open_meteo_archive/      # 历史JSON（按月分组）
│           └── archives/                # 🆕 归档压缩文件
│
├── dc_html/wds/
│   ├── api/
│   │   └── auto_collect.php             # 自动采集API（集成维护任务）
│   ├── console/
│   │   ├── test_optimization.php        # 🆕 测试脚本
│   │   ├── housekeeping.php             # 数据清理
│   │   ├── db_size.php                  # 数据库监控
│   │   └── ...
│   └── index.php
│
└── docs/
    ├── wds_db_schema_structure_only.sql
    ├── wds_optimization_tables.sql       # 🆕 优化方案数据库表
    └── WDS_OPTIMIZATION_SYSTEM_DOCUMENTATION.md  # 🆕 本文档
```

---

## 4. 核心模块详解

### 4.1 智能回填模块 (OpenMeteoIngest)

**文件**: `/app/wds/src/ingest/open_meteo_ingest.php`

#### 4.1.1 核心方法

##### `fetchArchiveSmart()`

**功能**: 智能回填历史数据，只回填缺失的数据

**逻辑流程**:
```php
foreach (地点) {
    foreach (日期范围) {
        // 1. 检查数据库是否完整
        if (已有 >= 9小时数据) {
            跳过
        }

        // 2. 检查JSON快照是否存在
        if (文件存在: archive_{location_id}_{YYYYMMDD}.json) {
            跳过
        }

        // 3. 回填单日数据
        fetchArchiveSingleDay(location_id, date)
    }
}
```

**关键优化**:
- ✅ 按日期命名JSON文件：`archive_123_20251216.json`（无时间戳）
- ✅ 文件存在则跳过，避免重复生成
- ✅ 数据库完整性检查：>=9小时即认为完整（允许1-2小时容错）

**返回结果**:
```json
{
  "fetched": [
    {"location_id": 1, "date": "2025-12-14", "snapshot": "/path/to/file.json"}
  ],
  "skipped": [
    {"location_id": 1, "date": "2025-12-15", "reason": "complete"}
  ]
}
```

---

### 4.2 月度归档模块 (MonthlyArchiver)

**文件**: `/app/wds/src/maintenance/monthly_archiver.php`

#### 4.2.1 核心方法

##### `executeMonthlyArchive($month)`

**功能**: 执行完整的月度归档流程

**执行步骤**:
```
Step 1: 压缩预报JSON
  - 输入: /storage/raw/open_meteo/YYYY-MM/*.json
  - 输出: /storage/raw/archives/forecast_YYYY-MM.tar.gz
  - 索引: forecast_YYYY-MM_index.json

Step 2: 压缩历史JSON
  - 输入: /storage/raw/open_meteo_archive/YYYY-MM/*.json
  - 输出: /storage/raw/archives/archive_YYYY-MM.tar.gz
  - 索引: archive_YYYY-MM_index.json

Step 3: 备份归档文件（可选）
  - 目标: $cfg['backup_path']（如NAS挂载点）
  - 条件: $cfg['backup_enabled'] = true

Step 4: 删除旧原始文件
  - 删除: 2个月前的原始JSON
  - 规则: 3月归档2月数据，删除1月原始文件
  - 保护: 只有归档文件存在才删除原始文件
```

**安全机制**:
```php
// 删除前验证
if (file_exists($archivePath)) {
    // 归档存在，可以安全删除原始文件
    unlink($originalFile);
}
```

**压缩效果**:
```
典型压缩率: 70-80%
示例:
  原始: 900个文件, 25 MB
  压缩后: 1个文件, 7.5 MB
  节省: 70%
```

---

### 4.3 数据库归档模块 (DatabaseArchiver)

**文件**: `/app/wds/src/maintenance/db_archiver.php`

#### 4.3.1 核心方法

##### `archiveOldForecasts($daysOld = 30)`

**功能**: 将热表中30天前的数据迁移到压缩冷表

**SQL流程**:
```sql
-- Step 1: 复制到归档表
INSERT INTO wds_weather_hourly_forecast_archive
SELECT *, UTC_TIMESTAMP(6) as archived_at
FROM wds_weather_hourly_forecast
WHERE forecast_time_utc < DATE_SUB(NOW(), INTERVAL 30 DAY)
ON DUPLICATE KEY UPDATE ...

-- Step 2: 从热表删除
DELETE FROM wds_weather_hourly_forecast
WHERE forecast_time_utc < DATE_SUB(NOW(), INTERVAL 30 DAY)

-- Step 3: 优化表（回收空间）
OPTIMIZE TABLE wds_weather_hourly_forecast
```

**冷表优化**:
```sql
-- 使用压缩行格式
ROW_FORMAT=COMPRESSED
KEY_BLOCK_SIZE=8

-- 减少索引（仅保留必要索引）
INDEX idx_ft (forecast_time_utc)
INDEX idx_archived (archived_at)
```

**触发条件**:
```php
public function shouldArchive() : bool {
    $count = 热表行数;
    return $count > 100000;  // 超过10万行才归档
}
```

---

### 4.4 API集成模块 (auto_collect.php)

**文件**: `/dc_html/wds/api/auto_collect.php`

#### 4.4.1 执行流程

```php
// ========== 常规采集（所有时间槽） ==========
1. 验证Token
2. 检查时间窗口
3. 检查是否已采集
4. 执行预报数据采集: fetchForecast(16天)

// ========== 01:15槽特殊任务 ==========
if (时间槽 === '01:15') {
    // 任务1: 智能回填
    fetchArchiveSmart('t-2', 't')  // 最近2天

    // 任务2: 月度归档（每月1日）
    if (日期 === 1) {
        MonthlyArchiver::executeMonthlyArchive(上月)
    }

    // 任务3: 数据库归档（每天）
    if (热表行数 > 10万) {
        DatabaseArchiver::archiveOldForecasts(30天)
    }
}
```

#### 4.4.2 响应格式

```json
{
  "ok": true,
  "now_local": "2025-12-16 01:16:30",
  "timezone": "Europe/Madrid",
  "in_window": true,
  "slot": {"hm": "01:15", "window_local": ["01:05", "01:25"]},
  "locations_total": 5,
  "locations_done": 5,
  "action": "collected",
  "days": 16,
  "saved": [
    {"location_id": 1, "snapshot": "/open_meteo/2025-12/forecast_1_...json"}
  ],
  "archive": {
    "start": "2025-12-14",
    "end": "2025-12-16",
    "fetched": 2,
    "skipped": 1,
    "details": {...}
  },
  "maintenance": {
    "monthly_archive": {
      "success": true,
      "month": "2025-11",
      "steps": {...}
    },
    "db_archive": {
      "success": true,
      "archived_rows": 45000,
      "deleted_rows": 45000
    }
  }
}
```

---

## 5. 数据流程

### 5.1 正常采集流程（每天5次）

```
┌───────────────────────────────────────────────────────────┐
│ 定时任务调用API                                            │
│ curl -H "Authorization: Bearer TOKEN"                     │
│      https://domain.com/wds/api/auto_collect.php          │
└───────────────────┬───────────────────────────────────────┘
                    │
                    ▼
┌───────────────────────────────────────────────────────────┐
│ 01:15, 11:15, 13:15, 16:15, 19:15 (±10分钟窗口)          │
└───────────────────┬───────────────────────────────────────┘
                    │
                    ▼
         ┌──────────┴──────────┐
         │                     │
         ▼                     ▼
┌─────────────────┐   ┌─────────────────┐
│ 拉取16天预报     │   │ 保存JSON快照    │
│ 5地点×11小时×16天│   │ 按月目录组织    │
│ = 880行/次      │   │ 5个文件/次      │
└────────┬────────┘   └────────┬────────┘
         │                     │
         └──────────┬──────────┘
                    │
                    ▼
         ┌──────────────────────┐
         │ 写入数据库热表        │
         │ ON DUPLICATE KEY UPDATE │
         └──────────────────────┘
```

### 5.2 智能回填流程（仅01:15）

```
┌───────────────────────────────────────────────────────────┐
│ 01:15槽触发回填                                            │
│ 回填范围: 今天 + 昨天 + 前天                               │
└───────────────────┬───────────────────────────────────────┘
                    │
                    ▼
         ┌──────────────────────┐
         │ 逐日检查数据完整性    │
         │ 1. 数据库是否>=9小时  │
         │ 2. JSON快照是否存在   │
         └────────┬──────────────┘
                  │
        ┌─────────┴─────────┐
        │                   │
        ▼                   ▼
┌──────────────┐    ┌──────────────┐
│ 已完整       │    │ 缺失         │
│ 跳过回填     │    │ 拉取单日数据 │
└──────────────┘    └──────┬───────┘
                           │
                           ▼
                  ┌────────────────┐
                  │ 保存JSON        │
                  │ 按日期命名      │
                  │ 已存在则跳过    │
                  └────────┬───────┘
                           │
                           ▼
                  ┌────────────────┐
                  │ 写入数据库      │
                  │ observed表      │
                  └────────────────┘
```

### 5.3 月度归档流程（每月1日01:15）

```
┌───────────────────────────────────────────────────────────┐
│ 每月1日01:15触发                                           │
│ 归档上个月数据                                             │
└───────────────────┬───────────────────────────────────────┘
                    │
          ┌─────────┴─────────┐
          │                   │
          ▼                   ▼
┌──────────────────┐  ┌──────────────────┐
│ 压缩预报JSON      │  │ 压缩历史JSON      │
│ forecast_YYYY-MM  │  │ archive_YYYY-MM   │
│ .tar.gz          │  │ .tar.gz          │
└─────────┬────────┘  └─────────┬────────┘
          │                     │
          └──────────┬──────────┘
                     │
                     ▼
          ┌──────────────────────┐
          │ 生成索引文件          │
          │ *_index.json         │
          └──────────┬───────────┘
                     │
                     ▼
          ┌──────────────────────┐
          │ 记录归档元数据        │
          │ wds_monthly_archives │
          └──────────┬───────────┘
                     │
                     ▼
          ┌──────────────────────┐
          │ 备份到NAS（可选）     │
          └──────────┬───────────┘
                     │
                     ▼
          ┌──────────────────────┐
          │ 删除2个月前原始文件   │
          │ （归档存在才删除）    │
          └──────────────────────┘
```

### 5.4 数据库归档流程（每天01:15）

```
┌───────────────────────────────────────────────────────────┐
│ 检查热表行数                                               │
│ if (行数 > 100,000) then 执行归档                         │
└───────────────────┬───────────────────────────────────────┘
                    │
                    ▼
         ┌──────────────────────┐
         │ 复制30天前数据到冷表  │
         │ INSERT INTO archive   │
         │ SELECT FROM hot       │
         │ WHERE forecast_time   │
         │   < NOW() - 30 DAYS   │
         └────────┬──────────────┘
                  │
                  ▼
         ┌──────────────────────┐
         │ 从热表删除旧数据      │
         │ DELETE FROM hot       │
         │ WHERE forecast_time   │
         │   < NOW() - 30 DAYS   │
         └────────┬──────────────┘
                  │
                  ▼
         ┌──────────────────────┐
         │ 优化表（回收空间）    │
         │ OPTIMIZE TABLE hot    │
         └────────┬──────────────┘
                  │
                  ▼
         ┌──────────────────────┐
         │ 记录日志              │
         │ wds_db_archive_log    │
         └──────────────────────┘
```

---

## 6. 数据库设计

### 6.1 核心业务表（优化前已存在）

#### `wds_weather_hourly_forecast` (预报数据热表)

```sql
主键: (location_id, forecast_time_utc, run_time_utc)

字段说明:
- location_id: 地点ID
- run_time_utc: 预报运行时间（何时拉取的预报）
- forecast_time_utc: 预报目标时间（预报哪个时间点）
- temp_c: 温度×10（避免浮点精度问题）
- wmo_code: WMO天气代码
- precip_mm_tenths: 降水量×10
- precip_prob_pct: 降水概率%
- wind_kph_tenths: 风速×10
- gust_kph_tenths: 阵风×10

索引:
- idx_wds_fc_run: (run_time_utc)
- idx_wds_fc_ft: (forecast_time_utc)

特点:
- 同一forecast_time可以有多个run_time（多次预报）
- 用于评估预报准确度
```

#### `wds_weather_hourly_observed` (历史观测数据)

```sql
主键: (location_id, obs_time_utc)

字段说明:
- obs_time_utc: 观测时间
- temp_c: 实际温度×10
- wmo_code: 实际天气代码

用途: MAE验证、业务分析
```

### 6.2 优化方案新增表

#### `wds_weather_hourly_forecast_archive` (预报数据冷表)

```sql
-- 冷表：存储30天前的预报数据
CREATE TABLE wds_weather_hourly_forecast_archive (
    location_id BIGINT(20) NOT NULL,
    run_time_utc DATETIME(6) NOT NULL,
    forecast_time_utc DATETIME(6) NOT NULL,
    temp_c INT(11) DEFAULT NULL,
    wmo_code INT(11) DEFAULT NULL,
    precip_mm_tenths INT(11) DEFAULT NULL,
    precip_prob_pct INT(11) DEFAULT NULL,
    wind_kph_tenths INT(11) DEFAULT NULL,
    gust_kph_tenths INT(11) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) DEFAULT NULL,
    archived_at DATETIME(6) DEFAULT NULL,  -- 归档时间戳
    PRIMARY KEY (location_id, forecast_time_utc, run_time_utc),
    INDEX idx_ft (forecast_time_utc),
    INDEX idx_archived (archived_at)
) ENGINE=InnoDB
ROW_FORMAT=COMPRESSED       -- 压缩行格式
KEY_BLOCK_SIZE=8;           -- 压缩块大小

压缩效果: 节省40-50%空间
查询性能: 略慢于热表，但数据访问频率低
```

#### `wds_monthly_archives` (月度归档追踪表)

```sql
CREATE TABLE wds_monthly_archives (
    archive_id BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
    month VARCHAR(7) NOT NULL,              -- YYYY-MM
    archive_type ENUM('forecast', 'archive') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_count INT(11) NOT NULL DEFAULT 0,
    original_size_bytes BIGINT(20) NOT NULL DEFAULT 0,
    compressed_size_bytes BIGINT(20) NOT NULL DEFAULT 0,
    compression_ratio DECIMAL(5,2) GENERATED ALWAYS AS (
        ROUND((1 - compressed_size_bytes / NULLIF(original_size_bytes, 0)) * 100, 2)
    ) STORED,                               -- 自动计算压缩率
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uk_month_type (month, archive_type),
    INDEX idx_month (month)
);

示例数据:
month       | archive_type | file_count | original_mb | compressed_mb | ratio
------------|--------------|------------|-------------|---------------|-------
2025-11     | forecast     | 750        | 24.5        | 7.2           | 70.6%
2025-11     | archive      | 150        | 3.8         | 1.1           | 71.1%
```

#### `wds_archive_history` (归档操作历史)

```sql
CREATE TABLE wds_archive_history (
    history_id BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
    month VARCHAR(7) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    steps_json TEXT,                        -- JSON格式的详细步骤
    error_message TEXT,
    created_at DATETIME(6) NOT NULL,
    INDEX idx_month (month),
    INDEX idx_success (success)
);

用途: 审计、故障排查、性能分析
```

#### `wds_db_archive_log` (数据库归档日志)

```sql
CREATE TABLE wds_db_archive_log (
    log_id BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
    cutoff_date DATETIME NOT NULL,
    archived_rows INT(11) NOT NULL DEFAULT 0,
    deleted_rows INT(11) NOT NULL DEFAULT 0,
    execution_time_ms INT(11) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    INDEX idx_cutoff (cutoff_date)
);

用途: 监控归档性能、验证数据一致性
```

### 6.3 联合视图

#### `vw_weather_forecast_all` (透明查询视图)

```sql
CREATE VIEW vw_weather_forecast_all AS
SELECT
    location_id, run_time_utc, forecast_time_utc,
    temp_c, wmo_code, precip_mm_tenths, precip_prob_pct,
    wind_kph_tenths, gust_kph_tenths, created_at, updated_at,
    'hot' as data_source, NULL as archived_at
FROM wds_weather_hourly_forecast
UNION ALL
SELECT
    location_id, run_time_utc, forecast_time_utc,
    temp_c, wmo_code, precip_mm_tenths, precip_prob_pct,
    wind_kph_tenths, gust_kph_tenths, created_at, updated_at,
    'archive' as data_source, archived_at
FROM wds_weather_hourly_forecast_archive;

使用方法:
-- 查询所有数据（自动包含冷热表）
SELECT * FROM vw_weather_forecast_all
WHERE location_id = 1
  AND forecast_time_utc BETWEEN '2025-01-01' AND '2025-12-31'
ORDER BY forecast_time_utc, run_time_utc;
```

---

## 7. 配置说明

### 7.1 配置文件 (`env_wds.php`)

```php
<?php
return [
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'mhdlmskp2kpxguj',
    'user' => 'mhdlmskp2kpxguj',
    'pass' => 'BWNrmksqMEqgbX37r3QNDJLGRrUka',
    'charset' => 'utf8mb4',
  ],

  'timezone_local' => 'Europe/Madrid',

  'api_token' => '3UsMvup5VdFWmFw7UcyfXs5FRJNumtzdqabS5Eepdzb77pWtUBbjGgc',

  // ========== 数据保留和归档配置 ==========
  'retention' => [
    'db_soft_gb' => 0.80,        // 软阈值：800MB（建议清理）
    'db_hard_gb' => 0.95,        // 硬阈值：950MB（强制清理）
    'db_archive_days' => 30,     // 30天前数据迁移到冷表
    'json_keep_months' => 2,     // 保留最近2个月原始JSON
  ],

  // ========== 备份配置（可选） ==========
  'backup_enabled' => false,           // 是否启用备份
  'backup_path' => '/mnt/nas/wds_backups',  // NAS挂载点
];
```

### 7.2 配置参数详解

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `db_soft_gb` | float | 0.80 | 数据库软阈值（GB），达到后触发归档建议 |
| `db_hard_gb` | float | 0.95 | 数据库硬阈值（GB），达到后强制清理 |
| `db_archive_days` | int | 30 | 热表保留天数，超过则迁移到冷表 |
| `json_keep_months` | int | 2 | 原始JSON保留月数（当月+上月） |
| `backup_enabled` | bool | false | 是否启用备份功能 |
| `backup_path` | string | - | 备份目标路径（NAS挂载点） |

---

## 8. 实施步骤

### 8.1 准备工作

#### Step 1: 备份现有数据（重要！）

```bash
# 备份数据库
mysqldump -u mhdlmskp2kpxguj -p mhdlmskp2kpxguj > backup_$(date +%Y%m%d).sql

# 备份JSON文件
cd /home/user/wdsxxx/app/wds/storage
tar -czf backup_raw_$(date +%Y%m%d).tar.gz raw/

# 验证备份
ls -lh backup_*.sql backup_*.tar.gz
```

#### Step 2: 创建优化表

```bash
# 执行SQL文件
mysql -u mhdlmskp2kpxguj -p < /home/user/wdsxxx/docs/wds_optimization_tables.sql

# 验证表创建
mysql -u mhdlmskp2kpxguj -p -e "SHOW TABLES LIKE 'wds_%archive%'" mhdlmskp2kpxguj
```

### 8.2 代码部署

所有代码文件已部署完成：

- ✅ `open_meteo_ingest.php` - 智能回填
- ✅ `monthly_archiver.php` - 月度归档
- ✅ `db_archiver.php` - 数据库归档
- ✅ `auto_collect.php` - API集成
- ✅ `env_wds.php` - 配置更新

### 8.3 测试验证

#### Step 1: 测试智能回填

```bash
# 访问测试页面
http://yourdomain.com/wds/console/test_optimization.php?action=status

# 或使用curl测试
curl -H "Authorization: Bearer YOUR_TOKEN" \
     "http://yourdomain.com/wds/api/auto_collect.php"
```

#### Step 2: 测试月度归档

```bash
# 访问测试页面
http://yourdomain.com/wds/console/test_optimization.php?action=test_monthly&month=2024-11

# 检查归档文件
ls -lh /home/user/wdsxxx/app/wds/storage/raw/archives/
```

#### Step 3: 测试数据库归档

```bash
# 访问测试页面
http://yourdomain.com/wds/console/test_optimization.php?action=test_db

# 查看归档表
mysql -u USER -p -e "SELECT COUNT(*) FROM wds_weather_hourly_forecast_archive" DB_NAME
```

### 8.4 上线运行

确保定时任务正常调用API：

```bash
# 检查定时任务
crontab -l

# 示例定时任务（每5分钟检查一次）
*/5 * * * * curl -H "Authorization: Bearer YOUR_TOKEN" \
            "https://yourdomain.com/wds/api/auto_collect.php" \
            >> /var/log/wds/auto_collect.log 2>&1
```

---

## 9. 使用指南

### 9.1 查看系统状态

访问: `http://yourdomain.com/wds/console/test_optimization.php?action=status`

显示信息：
- 数据库总大小
- 热表/冷表行数
- JSON文件数量
- 归档文件数量

### 9.2 手动触发月度归档

```bash
# 通过测试页面
http://yourdomain.com/wds/console/test_optimization.php?action=test_monthly&month=2024-11

# 或通过PHP脚本
<?php
require_once('/path/to/bootstrap/app.php');
use WDS\maintenance\MonthlyArchiver;

$archiver = new MonthlyArchiver(db(), cfg());
$result = $archiver->executeMonthlyArchive('2024-11');
print_r($result);
```

### 9.3 手动触发数据库归档

```bash
# 通过测试页面
http://yourdomain.com/wds/console/test_optimization.php?action=test_db

# 或通过PHP脚本
<?php
require_once('/path/to/bootstrap/app.php');
use WDS\maintenance\DatabaseArchiver;

$archiver = new DatabaseArchiver(db());
if ($archiver->shouldArchive()) {
    $result = $archiver->archiveOldForecasts(30);
    print_r($result);
}
```

### 9.4 查询历史数据

```sql
-- 查询所有数据（使用视图）
SELECT * FROM vw_weather_forecast_all
WHERE location_id = 1
  AND forecast_time_utc = '2024-06-15 14:00:00'
ORDER BY run_time_utc;

-- 查询冷表数据
SELECT * FROM wds_weather_hourly_forecast_archive
WHERE forecast_time_utc < '2024-11-01';

-- 查看归档统计
SELECT * FROM wds_monthly_archives ORDER BY month DESC;
```

### 9.5 解压归档文件

```bash
# 查看归档内容（不解压）
tar -tzf /path/to/forecast_2024-11.tar.gz | head -20

# 解压到临时目录
mkdir -p /tmp/archive_extract
tar -xzf /path/to/forecast_2024-11.tar.gz -C /tmp/archive_extract

# 查看索引文件
cat /path/to/forecast_2024-11_index.json | jq .
```

---

## 10. 监控和维护

### 10.1 监控指标

#### 数据库监控

```sql
-- 数据库总大小
SELECT
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS total_mb
FROM information_schema.TABLES
WHERE table_schema = 'mhdlmskp2kpxguj';

-- 热表大小
SELECT
    table_name,
    ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb,
    table_rows
FROM information_schema.TABLES
WHERE table_schema = 'mhdlmskp2kpxguj'
  AND table_name = 'wds_weather_hourly_forecast';

-- 冷表大小
SELECT
    table_name,
    ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb,
    table_rows
FROM information_schema.TABLES
WHERE table_schema = 'mhdlmskp2kpxguj'
  AND table_name = 'wds_weather_hourly_forecast_archive';
```

#### 文件系统监控

```bash
# JSON文件统计
find /path/to/storage/raw/open_meteo -name "*.json" | wc -l
find /path/to/storage/raw/open_meteo_archive -name "*.json" | wc -l

# 归档文件统计
ls -lh /path/to/storage/raw/archives/

# 磁盘使用
du -sh /path/to/storage/raw/*
```

### 10.2 日志监控

#### 归档日志

```sql
-- 最近10次归档历史
SELECT * FROM wds_archive_history
ORDER BY created_at DESC
LIMIT 10;

-- 失败的归档
SELECT * FROM wds_archive_history
WHERE success = 0
ORDER BY created_at DESC;
```

#### 数据库归档日志

```sql
-- 最近10次数据库归档
SELECT * FROM wds_db_archive_log
ORDER BY created_at DESC
LIMIT 10;

-- 性能分析
SELECT
    DATE(created_at) as date,
    AVG(archived_rows) as avg_archived,
    AVG(execution_time_ms) as avg_time_ms
FROM wds_db_archive_log
GROUP BY DATE(created_at)
ORDER BY date DESC;
```

### 10.3 定期维护任务

| 任务 | 频率 | 执行方式 |
|------|------|----------|
| 数据库归档 | 每天 | 自动（01:15槽） |
| 月度归档 | 每月1日 | 自动（01:15槽） |
| 检查归档完整性 | 每周 | 手动/脚本 |
| 清理错误日志 | 每月 | 手动 |
| 数据库优化 | 每季度 | 手动 |

---

## 11. 故障排查

### 11.1 常见问题

#### 问题1: 月度归档失败

**症状**: `wds_archive_history` 显示 `success=0`

**排查步骤**:
```bash
# 1. 查看错误日志
SELECT error_message, steps_json FROM wds_archive_history
WHERE success = 0
ORDER BY created_at DESC
LIMIT 1;

# 2. 检查目录权限
ls -ld /home/user/wdsxxx/app/wds/storage/raw/archives
# 应该是 drwxr-xr-x

# 3. 检查tar命令
which tar
tar --version

# 4. 手动测试压缩
tar -czf /tmp/test.tar.gz -C /home/user/wdsxxx/app/wds/storage/raw/open_meteo/2024-11 .
```

**解决方案**:
- 确保web服务器用户有写权限
- 安装tar工具：`apt-get install tar`
- 检查磁盘空间：`df -h`

#### 问题2: 数据库归档慢

**症状**: `execution_time_ms` 超过60秒

**排查步骤**:
```sql
-- 检查热表大小
SELECT COUNT(*) FROM wds_weather_hourly_forecast;

-- 检查索引
SHOW INDEX FROM wds_weather_hourly_forecast;

-- 分析查询
EXPLAIN SELECT * FROM wds_weather_hourly_forecast
WHERE forecast_time_utc < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

**解决方案**:
- 优化索引
- 分批归档（每次1万行）
- 在低峰时段执行

#### 问题3: JSON文件没有被删除

**症状**: 2个月前的原始JSON仍然存在

**排查步骤**:
```bash
# 1. 检查归档文件是否存在
ls -lh /path/to/archives/forecast_2024-09.tar.gz

# 2. 检查归档记录
SELECT * FROM wds_monthly_archives WHERE month = '2024-09';

# 3. 查看清理日志
SELECT steps_json FROM wds_archive_history
WHERE month = '2024-09' \G
```

**解决方案**:
- 确保归档成功后才执行清理
- 手动清理：
```bash
# 确认归档存在后
rm -rf /path/to/open_meteo/2024-09/*.json
```

### 11.2 数据恢复

#### 从归档恢复JSON文件

```bash
# 解压到原位置
tar -xzf /path/to/archives/forecast_2024-11.tar.gz \
    -C /path/to/open_meteo/2024-11/

# 或解压特定文件
tar -xzf /path/to/archives/forecast_2024-11.tar.gz \
    -C /tmp/ \
    --wildcards "forecast_123_*.json"
```

#### 从冷表恢复到热表

```sql
-- 恢复特定时间段数据到热表
INSERT INTO wds_weather_hourly_forecast
SELECT
    location_id, run_time_utc, forecast_time_utc,
    temp_c, wmo_code, precip_mm_tenths, precip_prob_pct,
    wind_kph_tenths, gust_kph_tenths, created_at, updated_at
FROM wds_weather_hourly_forecast_archive
WHERE forecast_time_utc BETWEEN '2024-06-01' AND '2024-06-30'
ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at);
```

---

## 12. 性能优化建议

### 12.1 数据库优化

```sql
-- 定期分析表
ANALYZE TABLE wds_weather_hourly_forecast;
ANALYZE TABLE wds_weather_hourly_forecast_archive;

-- 定期优化表
OPTIMIZE TABLE wds_weather_hourly_forecast;

-- 检查碎片率
SELECT
    table_name,
    ROUND(data_length / 1024 / 1024, 2) AS data_mb,
    ROUND(data_free / 1024 / 1024, 2) AS free_mb,
    ROUND(data_free / (data_length + data_free) * 100, 2) AS fragmentation_pct
FROM information_schema.TABLES
WHERE table_schema = 'mhdlmskp2kpxguj'
  AND table_name LIKE 'wds_weather%';
```

### 12.2 查询优化

```sql
-- 使用覆盖索引
CREATE INDEX idx_forecast_cover ON wds_weather_hourly_forecast
(location_id, forecast_time_utc, run_time_utc, temp_c);

-- 分区表（如果数据量非常大）
ALTER TABLE wds_weather_hourly_forecast_archive
PARTITION BY RANGE (YEAR(forecast_time_utc)) (
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION p2026 VALUES LESS THAN (2027)
);
```

### 12.3 文件系统优化

```bash
# 使用更高压缩率（牺牲一点速度）
tar -czf forecast.tar.gz --best -C /path .

# 使用xz压缩（更高压缩率）
tar -cJf forecast.tar.xz -C /path .

# 定期清理临时文件
find /path/to/storage -name "*.tmp" -mtime +7 -delete
```

---

## 13. 未来扩展计划

### 13.1 短期优化（1-3个月）

- [ ] **监控仪表盘**: 可视化展示归档状态、数据库增长趋势
- [ ] **告警系统**: 达到阈值时发送邮件/短信通知
- [ ] **自动备份**: 集成NAS自动备份，定期同步归档文件
- [ ] **数据验证**: 归档后验证数据完整性

### 13.2 中期优化（3-6个月）

- [ ] **增量归档**: 只归档变化的数据，减少重复处理
- [ ] **智能压缩**: 根据数据类型选择最佳压缩算法
- [ ] **多地点扩展**: 支持更多采集地点的扩展性优化
- [ ] **API限流**: 防止超出Open-Meteo API调用限制

### 13.3 长期优化（6-12个月）

- [ ] **数据湖架构**: 引入对象存储（S3/MinIO）存储历史数据
- [ ] **机器学习**: 基于历史数据训练预报准确度预测模型
- [ ] **实时流处理**: 引入消息队列（Kafka/RabbitMQ）异步处理
- [ ] **分布式存储**: 支持多节点部署和负载均衡

---

## 附录

### A. 文件清单

| 文件路径 | 类型 | 说明 |
|----------|------|------|
| `/docs/wds_optimization_tables.sql` | SQL | 优化方案数据库表 |
| `/app/wds/src/ingest/open_meteo_ingest.php` | PHP | 数据采集类（含智能回填） |
| `/app/wds/src/maintenance/monthly_archiver.php` | PHP | 月度归档类 |
| `/app/wds/src/maintenance/db_archiver.php` | PHP | 数据库归档类 |
| `/dc_html/wds/api/auto_collect.php` | PHP | API入口（集成维护） |
| `/app/wds/config_wds/env_wds.php` | PHP | 配置文件 |
| `/dc_html/wds/console/test_optimization.php` | PHP | 测试页面 |
| `/docs/WDS_OPTIMIZATION_SYSTEM_DOCUMENTATION.md` | Markdown | 本文档 |

### B. 关键命令速查

```bash
# 查看数据库大小
mysql -u USER -p -e "SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) AS mb FROM information_schema.TABLES WHERE table_schema=DATABASE()" DB_NAME

# 统计JSON文件
find /path/to/storage/raw -name "*.json" | wc -l

# 查看最新归档
ls -lht /path/to/storage/raw/archives/ | head -10

# 测试API
curl -H "Authorization: Bearer TOKEN" "https://domain.com/wds/api/auto_collect.php"

# 查看归档日志
mysql -u USER -p -e "SELECT * FROM wds_archive_history ORDER BY created_at DESC LIMIT 5" DB_NAME
```

### C. 联系和支持

- **系统维护**: 参考本文档
- **问题反馈**: 创建GitHub Issue
- **功能建议**: 提交Feature Request

---

**文档版本**: 1.0
**最后更新**: 2025-12-16
**维护者**: System Administrator

---

**祝使用顺利！** 🚀
