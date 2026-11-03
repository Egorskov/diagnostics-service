<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Metrics;

use App\Domain\Metrics\PathMetrics;
use App\Domain\Metrics\PathPlan;
use App\Domain\Metrics\PlannedTask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathMetricsTest extends TestCase
{
    private const STARTED = '2026-10-01T00:00:00Z';

    /**
     * @param list<PlannedTask> $tasks
     */
    private static function calculate(array $tasks, string $now, int $weeklyGoal = 1500, string $started = self::STARTED): PathMetrics
    {
        return PathMetrics::calculate(
            new PathPlan(new \DateTimeImmutable($started), $weeklyGoal, $tasks),
            new \DateTimeImmutable($now),
            new \DateTimeZone('Europe/Belgrade'),
        );
    }

    private static function done(int $estimate, int $logged = 0, string $at = '2026-10-10T12:00:00Z'): PlannedTask
    {
        return new PlannedTask($estimate, new \DateTimeImmutable($at), $logged);
    }

    private static function open(int $estimate, int $logged = 0): PlannedTask
    {
        return new PlannedTask($estimate, null, $logged);
    }

    /**
     * @param list<PlannedTask> $tasks
     */
    #[DataProvider('volumes')]
    public function testEarnedPlannedActual(array $tasks, string $now, int $weeklyGoal, int $earned, int $planned, int $actual): void
    {
        $metrics = self::calculate($tasks, $now, $weeklyGoal);

        self::assertSame($earned, $metrics->earnedMinutes);
        self::assertSame($planned, $metrics->plannedMinutes);
        self::assertSame($actual, $metrics->actualMinutes);
    }

    /**
     * @return iterable<string, array{list<PlannedTask>, string, int, int, int, int}>
     */
    public static function volumes(): iterable
    {
        // 16,8 суток = 2,4 недели
        yield 'пример SPEC §8' => [[self::done(2700, 2000), self::open(3300, 1300)], '2026-10-17T19:12:00Z', 1500, 2700, 3600, 3300];
        yield 'план ограничен объёмом пути' => [[self::open(3000)], '2026-10-22T00:00:00Z', 1500, 0, 3000, 0];
        yield 'неполная минута плана' => [[self::open(6000)], '2026-10-02T00:00:00Z', 1000, 0, 142, 0];
        yield 'сейчас ровно начало пути' => [[self::open(6000)], self::STARTED, 1500, 0, 0, 0];
        yield 'путь ещё не начался' => [[self::open(6000)], '2026-09-30T00:00:00Z', 1500, 0, 0, 0];
        yield 'начатое задание не освоено' => [[self::open(60, 30), self::done(40)], '2026-10-02T00:00:00Z', 1500, 40, 100, 30];
        yield 'нулевая цель' => [[self::done(60)], '2026-10-22T00:00:00Z', 0, 60, 0, 0];
        yield 'отрицательная цель' => [[self::done(60)], '2026-10-22T00:00:00Z', -100, 60, 0, 0];
        yield 'пустой путь' => [[], '2026-10-22T00:00:00Z', 1500, 0, 0, 0];
    }

    /**
     * @param list<PlannedTask> $tasks
     */
    #[DataProvider('indexes')]
    public function testPerformanceIndexes(array $tasks, string $now, ?float $spi, ?float $cpi): void
    {
        $metrics = self::calculate($tasks, $now);

        if (null === $spi) {
            self::assertNull($metrics->spi);
        } else {
            self::assertEqualsWithDelta($spi, $metrics->spi, 1e-9);
        }
        if (null === $cpi) {
            self::assertNull($metrics->cpi);
        } else {
            self::assertEqualsWithDelta($cpi, $metrics->cpi, 1e-9);
        }
    }

    /**
     * @return iterable<string, array{list<PlannedTask>, string, ?float, ?float}>
     */
    public static function indexes(): iterable
    {
        yield 'пример SPEC §8' => [[self::done(2700, 2000), self::open(3300, 1300)], '2026-10-17T19:12:00Z', 0.75, 2700 / 3300];
        yield 'опережение и экономия' => [[self::done(3000, 1500)], '2026-10-08T00:00:00Z', 2.0, 2.0];
        yield 'нулевой план' => [[self::done(60, 30)], self::STARTED, null, 2.0];
        yield 'ничего не списано' => [[self::done(60)], '2026-10-08T00:00:00Z', 1.0, null];
        yield 'ничего не освоено' => [[self::open(60, 30)], '2026-10-08T00:00:00Z', 0.0, 0.0];
    }

    public function testCostIndexRoundsAsInSpecExample(): void
    {
        $metrics = self::calculate([self::done(2700, 2000), self::open(3300, 1300)], '2026-10-17T19:12:00Z');

        self::assertNotNull($metrics->cpi);
        self::assertSame(0.82, round($metrics->cpi, 2));
    }
}
