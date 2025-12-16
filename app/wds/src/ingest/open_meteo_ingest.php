<?php
namespace WDS\ingest;

use PDO;
use DateTimeImmutable;
use DateTimeZone;

class OpenMeteoIngest {
    private PDO $pdo;
    private array $cfg;
    private string $rawDir;

    public function __construct(PDO $pdo, array $cfg) {
        $this->pdo = $pdo;
        $this->cfg = $cfg;
        $this->rawDir = APP_WDS . '/storage/raw';
        ensure_dir($this->rawDir);
        ensure_dir($this->rawDir . '/open_meteo');
        ensure_dir($this->rawDir . '/open_meteo_archive');
    }

    private function http_get_json(string $url) : array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'WDS/0.3.3 (+open-meteo)',
        ]);
        $body = curl_exec($ch);
        if ($body === false) { $err = curl_error($ch); curl_close($ch); throw new \RuntimeException("HTTP error: $err"); }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) throw new \RuntimeException("HTTP status $code for $url");
        $j = json_decode($body, true);
        if (!is_array($j)) throw new \RuntimeException("Invalid JSON from $url");
        return $j;
    }

    private function save_snapshot(array $j, string $subdir, string $prefix, int $locId) : string {
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $ts    = $nowUtc->format('Ymd_His');
        $month = $nowUtc->format('Y-m');
        $dir   = $this->rawDir . '/' . $subdir . '/' . $month;
        ensure_dir($dir);
        $name = sprintf('%s_%d_%s.json', $prefix, $locId, $ts);
        $abs  = $dir . '/' . $name;
        file_put_contents($abs, json_encode($j, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $abs;
    }

    private function active_locations() : array {
        $stmt = $this->pdo->query("SELECT location_id, name, lat, lon FROM wds_locations WHERE is_active=1 ORDER BY location_id");
        $rows = $stmt->fetchAll();
        $stmt->closeCursor(); // 关闭游标
        return $rows ?: [];
    }

    private function business_hours() : array {
        $stmt = $this->pdo->query("SELECT open_hour_local, close_hour_local FROM wds_business_hours LIMIT 1");
        $row = $stmt->fetch();
        $stmt->closeCursor(); // 关闭游标
        $open = isset($row['open_hour_local']) ? (int)$row['open_hour_local'] : 12;
        $close = isset($row['close_hour_local']) ? (int)$row['close_hour_local'] : 22;
        return [$open, $close];
    }

    public function fetchForecast(int $days=16) : array {
        [$openH, $closeH] = $this->business_hours();
        $tzLocal = $this->cfg['timezone_local'] ?? 'Europe/Madrid';

        $out = [];
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $runUtcStr = $nowUtc->format('Y-m-d H:i:s.u');

        $this->pdo->beginTransaction();
        try {
            $ins = $this->pdo->prepare("
                INSERT INTO wds_weather_hourly_forecast
                (location_id, run_time_utc, forecast_time_utc, temp_c, wmo_code, precip_mm_tenths, precip_prob_pct, wind_kph_tenths, gust_kph_tenths, created_at, updated_at)
                VALUES (:loc, :run, :ft, :t, :wmo, :prc, :pop, :wnd, :gst, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE
                  temp_c=VALUES(temp_c),
                  wmo_code=VALUES(wmo_code),
                  precip_mm_tenths=VALUES(precip_mm_tenths),
                  precip_prob_pct=VALUES(precip_prob_pct),
                  wind_kph_tenths=VALUES(wind_kph_tenths),
                  gust_kph_tenths=VALUES(gust_kph_tenths),
                  updated_at=UTC_TIMESTAMP(6)
            ");
            foreach ($this->active_locations() as $loc) {
                $lat = $loc['lat']; $lon = $loc['lon']; $locId = (int)$loc['location_id'];
                $url = sprintf(
                    'https://api.open-meteo.com/v1/forecast?latitude=%.5f&longitude=%.5f&hourly=temperature_2m,precipitation,weathercode,wind_speed_10m,wind_gusts_10m,precipitation_probability&forecast_days=%d&timezone=UTC&windspeed_unit=kmh',
                    $lat, $lon, $days
                );
                $j = $this->http_get_json($url);
                $snap = $this->save_snapshot($j, 'open_meteo', 'forecast', $locId);

                $hourly = $j['hourly'] ?? null;
                if (!$hourly || !isset($hourly['time'])) continue;
                $times = $hourly['time'];
                $temp   = $hourly['temperature_2m'] ?? [];
                $wcode  = $hourly['weathercode'] ?? [];
                $prec   = $hourly['precipitation'] ?? [];
                $pop    = $hourly['precipitation_probability'] ?? [];
                $wind   = $hourly['wind_speed_10m'] ?? [];
                $gust   = $hourly['wind_gusts_10m'] ?? [];

                for ($i=0,$n=count($times); $i<$n; $i++) {
                    $dtUtc = new DateTimeImmutable($times[$i], new DateTimeZone('UTC'));
                    $dtLocal = $dtUtc->setTimezone(new DateTimeZone($tzLocal));
                    $h = (int)$dtLocal->format('H');
                    if ($h < $openH || $h > $closeH) continue;

                    $ins->execute([
                        ':loc'=>$locId,
                        ':run'=>$runUtcStr,
                        ':ft'=>$dtUtc->format('Y-m-d H:00:00.000000'),
                        ':t'=> isset($temp[$i]) ? (int)round(((float)$temp[$i])*10) : null,
                        ':wmo'=> isset($wcode[$i]) ? (int)$wcode[$i] : null,
                        ':prc'=> isset($prec[$i]) ? (int)round(((float)$prec[$i])*10) : null,
                        ':pop'=> isset($pop[$i])  ? (int)round(((float)$pop[$i]))   : null,
                        ':wnd'=> isset($wind[$i]) ? (int)round(((float)$wind[$i])*10) : null,
                        ':gst'=> isset($gust[$i]) ? (int)round(((float)$gust[$i])*10) : null,
                    ]);
                }
                $out[] = ['location_id'=>$locId, 'snapshot'=>$snap];
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack(); throw $e;
        }
        return $out;
    }

    public function fetchArchive(string $startYmd, string $endYmd) : array {
        [$openH, $closeH] = $this->business_hours();
        $tzLocal = $this->cfg['timezone_local'] ?? 'Europe/Madrid';

        $out = [];
        $this->pdo->beginTransaction();
        try {
            $ins = $this->pdo->prepare("
                INSERT INTO wds_weather_hourly_observed
                (location_id, obs_time_utc, temp_c, wmo_code, created_at, updated_at)
                VALUES (:loc, :ot, :t, :wmo, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE
                  temp_c=VALUES(temp_c),
                  wmo_code=VALUES(wmo_code),
                  updated_at=UTC_TIMESTAMP(6)
            ");

            foreach ($this->active_locations() as $loc) {
                $lat = $loc['lat']; $lon = $loc['lon']; $locId = (int)$loc['location_id'];
                $url = sprintf(
                    'https://archive-api.open-meteo.com/v1/archive?latitude=%.5f&longitude=%.5f&start_date=%s&end_date=%s&hourly=temperature_2m,weathercode&timezone=UTC',
                    $lat, $lon, $startYmd, $endYmd
                );
                $j = $this->http_get_json($url);
                $snap = $this->save_snapshot($j, 'open_meteo_archive', 'archive', $locId);

                $hourly = $j['hourly'] ?? null;
                if (!$hourly || !isset($hourly['time'])) continue;
                $times = $hourly['time'];
                $temp  = $hourly['temperature_2m'] ?? [];
                $wcode = $hourly['weathercode'] ?? [];

                for ($i=0,$n=count($times); $i<$n; $i++) {
                    $dtUtc = new DateTimeImmutable($times[$i], new DateTimeZone('UTC'));
                    $dtLocal = $dtUtc->setTimezone(new DateTimeZone($tzLocal));
                    $h = (int)$dtLocal->format('H');
                    if ($h < $openH || $h > $closeH) continue;

                    $ins->execute([
                        ':loc'=>$locId,
                        ':ot'=>$dtUtc->format('Y-m-d H:00:00.000000'),
                        ':t'=> isset($temp[$i]) ? (int)round(((float)$temp[$i])*10) : null,
                        ':wmo'=> isset($wcode[$i]) ? (int)$wcode[$i] : null,
                    ]);
                }
                $out[] = ['location_id'=>$locId, 'snapshot'=>$snap];
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack(); throw $e;
        }
        return $out;
    }

    /**
     * 智能回填历史数据 - 只回填缺失的数据
     * @param string $startYmd 开始日期 YYYY-MM-DD
     * @param string $endYmd 结束日期 YYYY-MM-DD
     * @param bool $skipIfExists 是否跳过已存在的数据
     * @return array
     */
    public function fetchArchiveSmart(string $startYmd, string $endYmd, bool $skipIfExists = true) : array {
        [$openH, $closeH] = $this->business_hours();
        $tzLocal = $this->cfg['timezone_local'] ?? 'Europe/Madrid';

        $out = [];
        $skipped = [];

        // 遍历每个地点
        foreach ($this->active_locations() as $loc) {
            $locId = (int)$loc['location_id'];

            // 遍历日期范围，逐日检查
            $currentDate = new DateTimeImmutable($startYmd);
            $endDate = new DateTimeImmutable($endYmd);

            while ($currentDate <= $endDate) {
                $dateStr = $currentDate->format('Y-m-d');

                // 检查数据是否完整
                if ($skipIfExists) {
                    $isComplete = $this->isArchiveComplete($locId, $dateStr);
                    $hasSnapshot = $this->hasArchiveSnapshot($locId, $dateStr);

                    // 如果数据完整且快照已存在，跳过
                    if ($isComplete && $hasSnapshot) {
                        $skipped[] = [
                            'location_id' => $locId,
                            'date' => $dateStr,
                            'reason' => 'complete'
                        ];
                        $currentDate = $currentDate->modify('+1 day');
                        continue;
                    }
                }

                // 需要回填：拉取单日数据
                try {
                    $result = $this->fetchArchiveSingleDay($locId, $dateStr, $loc);
                    if ($result) {
                        $out[] = $result;
                    }
                } catch (\Throwable $e) {
                    error_log("Archive fetch failed for location {$locId} date {$dateStr}: " . $e->getMessage());
                }

                $currentDate = $currentDate->modify('+1 day');
            }
        }

        return [
            'fetched' => $out,
            'skipped' => $skipped
        ];
    }

    /**
     * 检查某地点某日期的历史数据是否完整
     * @param int $locationId
     * @param string $date YYYY-MM-DD
     * @return bool
     */
    private function isArchiveComplete(int $locationId, string $date) : bool {
        $sql = "SELECT COUNT(*) FROM wds_weather_hourly_observed
                WHERE location_id = :lid
                AND DATE(obs_time_utc) = :d";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':lid' => $locationId, ':d' => $date]);
        $count = (int)$stmt->fetchColumn();
        $stmt->closeCursor(); // 关闭游标

        // 营业时段11小时，如果>=9则认为完整（允许1-2小时容错）
        return $count >= 9;
    }

    /**
     * 检查某地点某日期的JSON快照是否已存在
     * @param int $locationId
     * @param string $date YYYY-MM-DD
     * @return bool
     */
    private function hasArchiveSnapshot(int $locationId, string $date) : bool {
        $month = substr($date, 0, 7); // YYYY-MM
        $filename = sprintf('archive_%d_%s.json', $locationId, str_replace('-', '', $date));
        $filepath = $this->rawDir . '/open_meteo_archive/' . $month . '/' . $filename;

        return file_exists($filepath);
    }

    /**
     * 回填单个地点单日数据
     * @param int $locId
     * @param string $date YYYY-MM-DD
     * @param array $loc 地点信息
     * @return array|null
     */
    private function fetchArchiveSingleDay(int $locId, string $date, array $loc) : ?array {
        [$openH, $closeH] = $this->business_hours();
        $tzLocal = $this->cfg['timezone_local'] ?? 'Europe/Madrid';

        $lat = $loc['lat'];
        $lon = $loc['lon'];

        $url = sprintf(
            'https://archive-api.open-meteo.com/v1/archive?latitude=%.5f&longitude=%.5f&start_date=%s&end_date=%s&hourly=temperature_2m,weathercode&timezone=UTC',
            $lat, $lon, $date, $date
        );

        $j = $this->http_get_json($url);

        // 使用日期命名的快照（避免重复）
        $snap = $this->save_snapshot_by_date($j, 'open_meteo_archive', 'archive', $locId, $date);

        // 插入数据库
        $this->pdo->beginTransaction();
        try {
            $ins = $this->pdo->prepare("
                INSERT INTO wds_weather_hourly_observed
                (location_id, obs_time_utc, temp_c, wmo_code, created_at, updated_at)
                VALUES (:loc, :ot, :t, :wmo, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE
                  temp_c=VALUES(temp_c),
                  wmo_code=VALUES(wmo_code),
                  updated_at=UTC_TIMESTAMP(6)
            ");

            $hourly = $j['hourly'] ?? null;
            if ($hourly && isset($hourly['time'])) {
                $times = $hourly['time'];
                $temp  = $hourly['temperature_2m'] ?? [];
                $wcode = $hourly['weathercode'] ?? [];

                for ($i=0,$n=count($times); $i<$n; $i++) {
                    $dtUtc = new DateTimeImmutable($times[$i], new DateTimeZone('UTC'));
                    $dtLocal = $dtUtc->setTimezone(new DateTimeZone($tzLocal));
                    $h = (int)$dtLocal->format('H');
                    if ($h < $openH || $h > $closeH) continue;

                    $ins->execute([
                        ':loc'=>$locId,
                        ':ot'=>$dtUtc->format('Y-m-d H:00:00.000000'),
                        ':t'=> isset($temp[$i]) ? (int)round(((float)$temp[$i])*10) : null,
                        ':wmo'=> isset($wcode[$i]) ? (int)$wcode[$i] : null,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'location_id' => $locId,
            'date' => $date,
            'snapshot' => $snap
        ];
    }

    /**
     * 按日期保存快照（避免重复文件）
     * @param array $j
     * @param string $subdir
     * @param string $prefix
     * @param int $locId
     * @param string $date YYYY-MM-DD
     * @return string
     */
    private function save_snapshot_by_date(array $j, string $subdir, string $prefix, int $locId, string $date) : string {
        $month = substr($date, 0, 7); // YYYY-MM
        $dir   = $this->rawDir . '/' . $subdir . '/' . $month;
        ensure_dir($dir);

        // 文件名格式：archive_12345_20251216.json（无时间戳）
        $name = sprintf('%s_%d_%s.json', $prefix, $locId, str_replace('-', '', $date));
        $abs  = $dir . '/' . $name;

        // 如果文件已存在，不重复保存（直接返回路径）
        if (file_exists($abs)) {
            return $abs;
        }

        file_put_contents($abs, json_encode($j, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $abs;
    }
}
