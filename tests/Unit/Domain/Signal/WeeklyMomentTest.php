<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Signal;

use App\Domain\Signal\WeeklyMoment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeeklyMomentTest extends TestCase
{
    private static function thursdayEvening(): WeeklyMoment
    {
        return new WeeklyMoment(4, 18, 0, new \DateTimeZone('Europe/Belgrade'));
    }

    #[DataProvider('moments')]
    public function testWeekAndOccurrence(string $now, string $weekStart, string $occurrence, bool $passed): void
    {
        $moment = self::thursdayEvening();
        $at = new \DateTimeImmutable($now);

        self::assertSame($weekStart, $moment->weekStart($at)->format(\DATE_ATOM));
        self::assertSame($occurrence, $moment->occurrence($at)->format(\DATE_ATOM));
        self::assertSame($passed, $moment->hasPassed($at));
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function moments(): iterable
    {
        yield 'четверг 17:59' => ['2026-11-05T16:59:00Z', '2026-11-02T00:00:00+01:00', '2026-11-05T18:00:00+01:00', false];
        yield 'четверг 18:00' => ['2026-11-05T17:00:00Z', '2026-11-02T00:00:00+01:00', '2026-11-05T18:00:00+01:00', true];
        yield 'воскресенье 23:59' => ['2026-11-08T22:59:00Z', '2026-11-02T00:00:00+01:00', '2026-11-05T18:00:00+01:00', true];
        yield 'понедельник 00:00 — новая неделя' => ['2026-11-08T23:00:00Z', '2026-11-09T00:00:00+01:00', '2026-11-12T18:00:00+01:00', false];
        // переход на зимнее время 25.10: неделя началась в CEST, четверг — уже в CET
        yield 'неделя после перехода, 17:59' => ['2026-10-29T16:59:59Z', '2026-10-26T00:00:00+01:00', '2026-10-29T18:00:00+01:00', false];
        yield 'неделя перехода: понедельник в CEST' => ['2026-10-25T12:00:00Z', '2026-10-19T00:00:00+02:00', '2026-10-22T18:00:00+02:00', true];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidMoment(int $day, int $hour, int $minute): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WeeklyMoment($day, $hour, $minute, new \DateTimeZone('UTC'));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function invalid(): iterable
    {
        yield 'день 0' => [0, 18, 0];
        yield 'день 8' => [8, 18, 0];
        yield 'час 24' => [4, 24, 0];
        yield 'минута 60' => [4, 18, 60];
    }
}
