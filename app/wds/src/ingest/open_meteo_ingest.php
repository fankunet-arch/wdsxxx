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
        $rows = $this->pdo->query("SELECT location_id, name, lat, lon FROM wds_locations WHERE is_active=1 ORDER BY location_id")->fetchAll();
        return $rows ?: [];
    }

    private function business_hours() : array {
        $row = $this->pdo->query("SELECT open_hour_local, close_hour_local FROM wds_business_hours LIMIT 1")->fetch();
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
}
