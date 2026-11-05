<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Console\Emulator;

use App\Tests\Support\FixedThresholds;
use App\UI\Console\Emulator\EmulatedEvent;
use App\UI\Console\Emulator\EmulatedStream;
use App\UI\Console\Emulator\Profile;
use App\UI\Console\Emulator\Scenario;
use App\UI\Console\Emulator\ScenarioGenerator;
use App\UI\Console\Emulator\ScenarioRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Каждый профиль доводит до условия своего сигнала при любых порогах (design, решение 4).
 */
final class ScenarioProfilesTest extends TestCase
{
    /** Среда 18:12 по Белграду — недельная проверка ещё впереди */
    private const WEDNESDAY = '2026-11-04T17:12:00Z';
    /** Четверг 19:00 по Белграду — недельная проверка уже прошла */
    private const THURSDAY = '2026-11-05T18:00:00Z';
    /** Пятница 07:00 по Белграду: путь с --days=1 начался в четверг, после проверки */
    private const FRIDAY_MORNING = '2026-11-06T06:00:00Z';

    private static function custom(): FixedThresholds
    {
        return new FixedThresholds(inactiveStudentDays: 5, stuckTimeRatio: 1.1, stuckFailedRuns: 4, weeklyGoalMinRatio: 0.8);
    }

    private static function generate(Profile $profile, int $days, string $now, FixedThresholds $thresholds): EmulatedStream
    {
        return (new ScenarioGenerator($thresholds, 'Europe/Belgrade'))->generate(new Scenario($profile, $days, 42), new \DateTimeImmutable($now));
    }

    /**
     * @return array<string, int> задание → активных минут (= число heartbeat, SPEC §6.1)
     */
    private static function activeMinutes(EmulatedStream $stream): array
    {
        $active = [];
        foreach ($stream->events as $event) {
            if ('activity.heartbeat' === $event->type) {
                $active[$event->payload['task_id']] = ($active[$event->payload['task_id']] ?? 0) + 1;
            }
        }

        return $active;
    }

    /**
     * @return array<string, int>
     */
    private static function estimates(EmulatedStream $stream): array
    {
        return array_column($stream->events[0]->payload['tasks'], 'estimate_minutes', 'task_id');
    }

    private static function loggedInWeek(EmulatedStream $stream, string $now): int
    {
        $local = (new \DateTimeImmutable($now))->setTimezone(new \DateTimeZone('Europe/Belgrade'));
        $monday = $local->modify('monday this week')->format('Y-m-d');
        $sunday = $local->modify('sunday this week')->format('Y-m-d');

        return array_sum(array_map(
            static fn (EmulatedEvent $event): int => 'time.logged' === $event->type && $event->payload['logged_for_date'] >= $monday && $event->payload['logged_for_date'] <= $sunday ? $event->payload['minutes'] : 0,
            $stream->events,
        ));
    }

    /**
     * @return iterable<string, array{int, string, FixedThresholds}>
     */
    public static function runs(): iterable
    {
        foreach (['дефолт SPEC' => new FixedThresholds(), 'свои пороги' => self::custom()] as $name => $thresholds) {
            yield $name.', 14 дней, среда' => [14, self::WEDNESDAY, $thresholds];
            yield $name.', 14 дней, четверг' => [14, self::THURSDAY, $thresholds];
            yield $name.', 1 день, пятница утром' => [1, self::FRIDAY_MORNING, $thresholds];
            yield $name.', 56 дней' => [56, self::THURSDAY, $thresholds];
        }
    }

    #[DataProvider('runs')]
    public function testSteadyMeetsNoSignalCondition(int $days, string $now, FixedThresholds $thresholds): void
    {
        $stream = self::generate(Profile::Steady, $days, $now, $thresholds);

        $estimates = self::estimates($stream);
        foreach (self::activeMinutes($stream) as $task => $minutes) {
            self::assertLessThanOrEqual($thresholds->stuckTimeRatio() * $estimates[$task], $minutes);
        }
        $failed = [];
        foreach ($stream->events as $event) {
            if ('sandbox.run' === $event->type && 'failed' === $event->payload['result']) {
                $failed[$event->payload['task_id']] = ($failed[$event->payload['task_id']] ?? 0) + 1;
            }
        }
        self::assertLessThan($thresholds->stuckFailedRuns(), max([0, ...$failed]));
        $activity = array_values(array_filter($stream->events, static fn (EmulatedEvent $event): bool => 'activity.heartbeat' === $event->type));
        self::assertGreaterThan((new \DateTimeImmutable($now))->modify(\sprintf('-%d days', $thresholds->inactiveStudentDays())), ($activity[array_key_last($activity) ?? 0] ?? self::fail('no activity'))->occurredAt);
        if ($thresholds->weeklyGoalCheck()->hasPassed(new \DateTimeImmutable($now))) {
            self::assertGreaterThanOrEqual($thresholds->weeklyGoalMinRatio() * ScenarioRun::WEEKLY_GOAL_MINUTES, self::loggedInWeek($stream, $now));
        }
    }

    #[DataProvider('runs')]
    public function testStuckTaskExceedsTimeRatio(int $days, string $now, FixedThresholds $thresholds): void
    {
        $stream = self::generate(Profile::Stuck, $days, $now, $thresholds);

        $completed = [];
        $lastStarted = null;
        foreach ($stream->events as $event) {
            match ($event->type) {
                'task.started' => $lastStarted = $event->payload['task_id'],
                'task.completed' => $completed[$event->payload['task_id']] = true,
                default => null,
            };
        }
        self::assertIsString($lastStarted);
        self::assertArrayNotHasKey($lastStarted, $completed);
        self::assertGreaterThan($thresholds->stuckTimeRatio() * self::estimates($stream)[$lastStarted], self::activeMinutes($stream)[$lastStarted]);
    }

    #[DataProvider('runs')]
    public function testInactiveStopsBeforeThreshold(int $days, string $now, FixedThresholds $thresholds): void
    {
        $days = max($days, $thresholds->inactiveStudentDays() + 2);
        $stream = self::generate(Profile::Inactive, $days, $now, $thresholds);

        $activity = array_values(array_filter($stream->events, static fn (EmulatedEvent $event): bool => 'activity.heartbeat' === $event->type));
        self::assertNotEmpty($activity);
        self::assertLessThanOrEqual((new \DateTimeImmutable($now))->modify(\sprintf('-%d days', $thresholds->inactiveStudentDays())), end($activity)->occurredAt);
    }

    #[DataProvider('runs')]
    public function testBehindLogsLessThanWeeklyRatio(int $days, string $now, FixedThresholds $thresholds): void
    {
        $stream = self::generate(Profile::Behind, $days, $now, $thresholds);

        self::assertNotEmpty(self::activeMinutes($stream));
        self::assertLessThan($thresholds->weeklyGoalMinRatio() * ScenarioRun::WEEKLY_GOAL_MINUTES, self::loggedInWeek($stream, $now));
    }

    public function testInactiveNeedsHistoryLongerThanPause(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 5');

        self::generate(Profile::Inactive, 4, self::WEDNESDAY, new FixedThresholds());
    }

    public function testStuckRejectsUnreachableRatio(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::generate(Profile::Stuck, 1, self::FRIDAY_MORNING, new FixedThresholds(stuckTimeRatio: 20.0));
    }
}
