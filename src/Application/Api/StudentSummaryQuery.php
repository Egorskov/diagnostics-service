<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Application\Port\ProgressReadModel;
use App\Application\Port\Thresholds;
use App\Domain\Metrics\PathMetrics;
use App\Domain\Metrics\Regularity;
use App\Domain\Metrics\TimeBalance;
use App\Domain\Signal\WeeklyMoment;
use Psr\Clock\ClockInterface;

/**
 * GET /students/{id}/summary (SPEC §8): метрики пути, неделя, серия, баланс и активные сигналы.
 */
final readonly class StudentSummaryQuery
{
    private \DateTimeZone $zone;

    public function __construct(
        private ProgressReadModel $readModel,
        private Thresholds $thresholds,
        private ClockInterface $clock,
        string $timezone,
    ) {
        $this->zone = new \DateTimeZone($timezone);
    }

    /**
     * null — у ученика нет пути.
     */
    public function find(string $studentId): ?StudentSummary
    {
        $path = $this->readModel->currentPath($studentId);
        if (null === $path) {
            return null;
        }

        $now = $this->clock->now();
        // неделя сводки — с понедельника 00:00 в зоне расчёта (design, решение 5)
        $weekStart = (new WeeklyMoment(1, 0, 0, $this->zone))->weekStart($now);
        $logged = $this->readModel->loggedByCategory($studentId, $weekStart, $weekStart->modify('+6 days'));
        // предмет недельного сигнала — ровно так, как его пишет WeeklyGoalCheck (design, решение 8)
        $weekSubject = $this->thresholds->weeklyGoalCheck()->weekStart($now)->format('Y-m-d');

        return new StudentSummary(
            $studentId,
            $path->pathId,
            PathMetrics::calculate($this->readModel->pathPlan($path), $now, $this->zone),
            $path->weeklyGoalMinutes,
            array_sum($logged),
            Regularity::calculate($this->readModel->sessions($studentId), $now, $this->zone),
            TimeBalance::of($logged),
            $this->readModel->activeSignalTypes($studentId, $weekSubject),
        );
    }
}
