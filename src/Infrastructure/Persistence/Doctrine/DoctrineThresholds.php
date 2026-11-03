<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\InvalidThreshold;
use App\Application\Port\Thresholds;
use App\Domain\Signal\WeeklyMoment;
use App\Domain\Threshold\Threshold;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineThresholds implements Thresholds
{
    /** Дни недели в формате значения §7.1 «четверг 18:00 Europe/Belgrade» */
    private const DAYS = [
        'понедельник' => 1,
        'вторник' => 2,
        'среда' => 3,
        'четверг' => 4,
        'пятница' => 5,
        'суббота' => 6,
        'воскресенье' => 7,
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function idleTimeoutMinutes(): int
    {
        return $this->positiveInt('idle_timeout_min');
    }

    public function inactiveStudentDays(): int
    {
        return $this->positiveInt('inactive_student_days');
    }

    public function inactiveMentorDays(): int
    {
        return $this->positiveInt('inactive_mentor_days');
    }

    public function stuckTimeRatio(): float
    {
        return $this->positiveFloat('stuck_time_ratio');
    }

    public function stuckFailedRuns(): int
    {
        return $this->positiveInt('stuck_failed_runs');
    }

    public function stuckEscalationHours(): int
    {
        return $this->positiveInt('stuck_escalation_hours');
    }

    public function weeklyGoalMinRatio(): float
    {
        return $this->positiveFloat('weekly_goal_min_ratio');
    }

    public function weeklyGoalCheck(): WeeklyMoment
    {
        $key = 'weekly_goal_check';
        $value = $this->value($key);
        if (1 !== preg_match('/^(\p{L}+) (\d{2}):(\d{2}) (\S+)$/u', $value, $match) || !isset(self::DAYS[mb_strtolower($match[1])])) {
            throw self::invalid($key, 'a moment like "четверг 18:00 Europe/Belgrade"', $value);
        }

        try {
            return new WeeklyMoment(self::DAYS[mb_strtolower($match[1])], (int) $match[2], (int) $match[3], new \DateTimeZone($match[4]));
        } catch (\Exception $e) {
            throw self::invalid($key, 'a moment like "четверг 18:00 Europe/Belgrade"', $value, $e);
        }
    }

    private function positiveInt(string $key): int
    {
        $raw = $this->value($key);
        $value = filter_var($raw, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $value) {
            throw self::invalid($key, 'a positive integer', $raw);
        }

        return $value;
    }

    private function positiveFloat(string $key): float
    {
        $raw = $this->value($key);
        $value = filter_var($raw, \FILTER_VALIDATE_FLOAT);
        if (false === $value || $value <= 0) {
            throw self::invalid($key, 'a positive number', $raw);
        }

        return $value;
    }

    private function value(string $key): string
    {
        $threshold = $this->entityManager->find(Threshold::class, $key)
            ?? throw new InvalidThreshold(\sprintf('Threshold "%s" is not set', $key));

        return $threshold->value();
    }

    private static function invalid(string $key, string $expected, string $value, ?\Throwable $previous = null): InvalidThreshold
    {
        return new InvalidThreshold(\sprintf('Threshold "%s" must be %s, got "%s"', $key, $expected, $value), 0, $previous);
    }
}
