<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Signal;

use App\Domain\Signal\Inactivity;
use App\Domain\Signal\StuckReason;
use App\Domain\Signal\TaskStuck;
use App\Domain\Signal\WeeklyGoal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignalRulesTest extends TestCase
{
    #[DataProvider('inactivity')]
    public function testInactivity(string $lastActivity, int $days, bool $student, bool $mentor): void
    {
        $inactivity = new Inactivity(new \DateTimeImmutable($lastActivity), new \DateTimeImmutable('2026-11-10T12:00:00Z'));

        self::assertSame($days, $inactivity->days);
        self::assertSame($student, $inactivity->reaches(3));
        self::assertSame($mentor, $inactivity->reaches(7));
    }

    /**
     * @return iterable<string, array{string, int, bool, bool}>
     */
    public static function inactivity(): iterable
    {
        yield '2 суток 23 часа' => ['2026-11-07T13:00:00Z', 2, false, false];
        yield 'ровно 3 суток' => ['2026-11-07T12:00:00Z', 3, true, false];
        yield '6 суток 23 часа 59 минут' => ['2026-11-03T12:01:00Z', 6, true, false];
        yield '7 суток' => ['2026-11-03T12:00:00Z', 7, true, true];
        yield 'активность позже сейчас' => ['2026-11-10T13:00:00Z', 0, false, false];
    }

    #[DataProvider('stuck')]
    public function testTaskStuck(bool $completed, int $active, ?int $estimate, int $failedRuns, ?StuckReason $expected): void
    {
        self::assertSame($expected, TaskStuck::reason($completed, $active, $estimate, $failedRuns, 1.5, 10));
    }

    /**
     * @return iterable<string, array{bool, int, ?int, int, ?StuckReason}>
     */
    public static function stuck(): iterable
    {
        yield 'ровно полторы оценки' => [false, 90, 60, 0, null];
        yield 'больше полутора оценок' => [false, 91, 60, 0, StuckReason::TimeExceeded];
        yield '9 неудачных запусков' => [false, 0, 60, 9, null];
        yield '10 неудачных запусков' => [false, 0, 60, 10, StuckReason::FailedRuns];
        yield 'оба условия — время' => [false, 91, 60, 10, StuckReason::TimeExceeded];
        yield 'задание закрыто' => [true, 500, 60, 20, null];
        yield 'нет оценки, много времени' => [false, 5000, null, 0, null];
        yield 'нет оценки, 10 запусков' => [false, 5000, null, 10, StuckReason::FailedRuns];
    }

    #[DataProvider('weeklyGoals')]
    public function testWeeklyGoal(int $goal, int $logged, bool $behind): void
    {
        self::assertSame($behind, WeeklyGoal::isBehind($goal, $logged, 0.5));
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function weeklyGoals(): iterable
    {
        yield '540 из 1500' => [1500, 540, true];
        yield '749 из 1500' => [1500, 749, true];
        yield '750 из 1500' => [1500, 750, false];
        yield 'нулевая цель' => [0, 0, false];
        yield 'отрицательная цель' => [-10, 0, false];
    }
}
