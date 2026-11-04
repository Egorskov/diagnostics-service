<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Application\Port\ProgressReadModel;
use App\Application\Port\Thresholds;
use App\Domain\Metrics\ActivityInterval;
use App\Domain\Metrics\PathMetrics;
use Psr\Clock\ClockInterface;

/**
 * GET /mentor/students (SPEC §8): ученики текущих путей ментора, самые рискованные первыми.
 * Запросы — на каждого ученика; батчинг — когда понадобится (design, риски).
 */
final readonly class MentorStudentsQuery
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
     * @return list<MentorStudent>
     */
    public function list(string $mentorId): array
    {
        $now = $this->clock->now();
        $weekSubject = $this->thresholds->weeklyGoalCheck()->weekStart($now)->format('Y-m-d');

        $students = [];
        foreach ($this->readModel->currentPathsOfMentor($mentorId) as $path) {
            $sessions = $this->readModel->sessions($path->studentId);
            $students[] = new MentorStudent(
                $path->studentId,
                $path->pathId,
                PathMetrics::calculate($this->readModel->pathPlan($path), $now, $this->zone),
                // последняя активность — как у student.inactive: без сессий — начало пути
                [] === $sessions ? $path->startedAt : max(array_map(static fn (ActivityInterval $session): \DateTimeImmutable => $session->lastSeenAt, $sessions)),
                $this->readModel->activeSignalTypes($path->studentId, $weekSubject),
            );
        }
        usort($students, self::byRisk(...));

        return $students;
    }

    /**
     * spi по возрастанию, затем delay_days по убыванию, затем student_id; null — в конец (design, решение 7).
     */
    private static function byRisk(MentorStudent $a, MentorStudent $b): int
    {
        return self::compare($a->metrics->spi, $b->metrics->spi, descending: false)
            ?: self::compare($a->metrics->delayDays, $b->metrics->delayDays, descending: true)
            ?: $a->studentId <=> $b->studentId;
    }

    /**
     * null идёт после чисел при любом направлении: не посчитано — признаков отставания нет.
     */
    private static function compare(int|float|null $a, int|float|null $b, bool $descending): int
    {
        if (null === $a || null === $b) {
            return (null === $a) <=> (null === $b);
        }

        return $descending ? $b <=> $a : $a <=> $b;
    }
}
