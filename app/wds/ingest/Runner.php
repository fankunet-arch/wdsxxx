<?php
declare(strict_types=1);

namespace WDS\ingest;

use PDO;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * 极简 Runner
 * - 目标：把“采集/核验”的受理记录写入 wds_exec_log，供控制台与历史页统计。
 * - 后续可在此接入真实的抓取器（AEMET/Open-Meteo 等）。
 */
class Runner
{
    private PDO $pdo;
    private array $cfg;

    public function __construct(PDO $pdo, array $cfg)
    {
        $this->pdo = $pdo;
        $this->cfg = $cfg;
    }

    /** 记录一条执行日志（有则累加） */
    public function logExec(string $execDate, string $hm, string $action, int $total, int $done): void
    {
        $sql = "INSERT INTO wds_exec_log(exec_date, hm, action, total, done, created_at)
                VALUES(:d,:hm,:act,:t,:dn, NOW())
                ON DUPLICATE KEY UPDATE total = total + VALUES(total), done = done + VALUES(done), updated_at = NOW()";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':d'   => $execDate,
            ':hm'  => $hm,
            ':act' => $action,
            ':t'   => $total,
            ':dn'  => $done,
        ]);
    }

    /** 提交“预报任务”（当前为受理即记 1/1） */
    public function forecast(string $date, string $slot): array
    {
        $slot = $slot ?: 'auto';
        if ($slot === 'auto') {
            $hm = $this->nearestSlotHm(new DateTimeImmutable('now', $this->tz()), $this->slots());
        } else {
            $hm = $slot;
        }
        $this->logExec($date, $hm, 'forecast', 1, 1);
        return ['action' => 'accepted', 'date' => $date, 'slot' => $hm];
    }

    /** 自动采集：在窗口内且未采集过则记一笔 */
    public function collectIfDue(DateTimeImmutable $now, int $windowMin, array $slots): array
    {
        $hm         = $this->nearestSlotHm($now, $slots);
        $diffMin    = $this->diffToSlotMin($now, $hm);
        $inWindow   = ($diffMin <= $windowMin);
        // [修复] 检查 'collect' action，而不是 'forecast'
        $already    = $this->alreadyCollected($now->format('Y-m-d'), $hm, 'collect');

        if (!$inWindow) return ['action'=>'noop',    'reason'=>'outside_window',    'slot'=>$hm, 'diff_min'=>$diffMin];
        if ($already)   return ['action'=>'noop',    'reason'=>'already_collected', 'slot'=>$hm, 'diff_min'=>$diffMin];

        // TODO: 接入真实采集流程
        // [修复] 记录 'collect' action
        $this->logExec($now->format('Y-m-d'), $hm, 'collect', 1, 1);
        return ['action'=>'collect', 'reason'=>'accepted', 'slot'=>$hm, 'diff_min'=>$diffMin];
    }

    /** 是否已经记过账 */
    private function alreadyCollected(string $date, string $hm, string $action): bool
    {
        try {
            $q = $this->pdo->prepare("SELECT done FROM wds_exec_log WHERE exec_date=:d AND hm=:hm AND action=:a");
            $q->execute([':d'=>$date, ':hm'=>$hm, ':a'=>$action]);
            return ((int)($q->fetchColumn() ?: 0)) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function tz(): DateTimeZone
    {
        $tz = $this->cfg['timezone_local'] ?? 'Europe/Madrid';
        return new DateTimeZone($tz);
    }

    /** 计算最近的时段（'HH:MM'） */
    private function nearestSlotHm(DateTimeImmutable $now, array $slots): string
    {
        $best = $slots[0] ?? '07:15'; $bestDiff = PHP_INT_MAX;
        foreach ($slots as $hm) {
            $diff = abs($this->diffToSlotMin($now, $hm));
            if ($diff < $bestDiff) { $best = $hm; $bestDiff = $diff; }
        }
        return $best;
    }

    /** now 到当天 $hm 的分钟差 */
    private function diffToSlotMin(DateTimeImmutable $now, string $hm): int
    {
        $t = DateTimeImmutable::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $hm, $this->tz());
        return (int) round(($now->getTimestamp() - $t->getTimestamp()) / 60);
    }

    /** slots from cfg or default */
    private function slots(): array
    {
        return $this->cfg['auto_collect']['slots'] ?? ['01:15','07:15','11:15','13:15','19:15'];
    }
}