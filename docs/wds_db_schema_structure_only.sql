-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主机： localhost
-- 生成日期： 2025-12-16 23:06:50
-- 服务器版本： 10.5.8-MariaDB-log
-- PHP 版本： 8.2.27

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `mhdlmskp2kpxguj`
--
CREATE DATABASE IF NOT EXISTS `mhdlmskp2kpxguj` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `mhdlmskp2kpxguj`;

-- --------------------------------------------------------

--
-- 表的结构 `wds_business_hours`
--

DROP TABLE IF EXISTS `wds_business_hours`;
CREATE TABLE `wds_business_hours` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `open_hour_local` tinyint(4) NOT NULL DEFAULT 12,
  `close_hour_local` tinyint(4) NOT NULL DEFAULT 22,
  `created_at` datetime(6) NOT NULL DEFAULT utc_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT utc_timestamp(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `wds_holidays`
--

DROP TABLE IF EXISTS `wds_holidays`;
CREATE TABLE `wds_holidays` (
  `scope_key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `local_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country_code` char(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ES',
  `fixed` tinyint(1) NOT NULL DEFAULT 0,
  `global` tinyint(1) NOT NULL DEFAULT 1,
  `type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT utc_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT utc_timestamp(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `wds_locations`
--

DROP TABLE IF EXISTS `wds_locations`;
CREATE TABLE `wds_locations` (
  `location_id` bigint(20) NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lat` decimal(8,5) NOT NULL,
  `lon` decimal(8,5) NOT NULL,
  `country_code` char(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ES',
  `region_code` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT 'ES-M',
  `city` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT 'Madrid',
  `district` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT 'Usera',
  `primary_station` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime(6) NOT NULL DEFAULT utc_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT utc_timestamp(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `wds_weather_hourly_forecast`
--

DROP TABLE IF EXISTS `wds_weather_hourly_forecast`;
CREATE TABLE `wds_weather_hourly_forecast` (
  `location_id` bigint(20) NOT NULL,
  `run_time_utc` datetime(6) NOT NULL,
  `forecast_time_utc` datetime(6) NOT NULL,
  `temp_c` int(11) DEFAULT NULL,
  `wmo_code` int(11) DEFAULT NULL,
  `precip_mm_tenths` int(11) DEFAULT NULL,
  `precip_prob_pct` int(11) DEFAULT NULL,
  `wind_kph_tenths` int(11) DEFAULT NULL,
  `gust_kph_tenths` int(11) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT utc_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT utc_timestamp(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `wds_weather_hourly_observed`
--

DROP TABLE IF EXISTS `wds_weather_hourly_observed`;
CREATE TABLE `wds_weather_hourly_observed` (
  `location_id` bigint(20) NOT NULL,
  `obs_time_utc` datetime(6) NOT NULL,
  `temp_c` int(11) DEFAULT NULL,
  `wmo_code` int(11) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT utc_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT utc_timestamp(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转储表的索引
--

--
-- 表的索引 `wds_business_hours`
--
ALTER TABLE `wds_business_hours`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `wds_holidays`
--
ALTER TABLE `wds_holidays`
  ADD PRIMARY KEY (`scope_key`),
  ADD KEY `idx_wds_holiday_date` (`date`);

--
-- 表的索引 `wds_locations`
--
ALTER TABLE `wds_locations`
  ADD PRIMARY KEY (`location_id`);

--
-- 表的索引 `wds_weather_hourly_forecast`
--
ALTER TABLE `wds_weather_hourly_forecast`
  ADD PRIMARY KEY (`location_id`,`forecast_time_utc`,`run_time_utc`),
  ADD KEY `idx_wds_fc_run` (`run_time_utc`),
  ADD KEY `idx_wds_fc_ft` (`forecast_time_utc`);

--
-- 表的索引 `wds_weather_hourly_observed`
--
ALTER TABLE `wds_weather_hourly_observed`
  ADD PRIMARY KEY (`location_id`,`obs_time_utc`),
  ADD KEY `idx_wds_ob_t` (`obs_time_utc`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `wds_locations`
--
ALTER TABLE `wds_locations`
  MODIFY `location_id` bigint(20) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
