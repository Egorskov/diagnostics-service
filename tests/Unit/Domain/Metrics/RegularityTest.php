<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Metrics;

use App\Domain\Metrics\ActivityInterval;
use App\Domain\Metrics\Regularity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegularityTest extends TestCase
{
    /** Среда, 12:00 по Белграду; неделя — 2026-11-02 … 2026-11-08 */
    private const NOW = '2026-11-04T11:00:00Z';

    /**
     * @param list<ActivityInterval> $sessions
     */
    #[DataProvider('cases')]
    public function testCalculate(array $sessions, string $now, int $activeDays, int $streak, int $weekMinutes): void
    {
        $regularity = Regularity::calculate($sessions, new \DateTimeImmutable($now), new \DateTimeZone('Europe/Belgrade'));

        self::assertSame(
            [$activeDays, $streak, $weekMinutes],
            [$regularity->activeDaysInWeek, $regularity->streakDays, $regularity->weekActiveMinutes],
        );
    }

    /**
     * @return iterable<string, array{list<ActivityInterval>, string, int, int, int}>
     */
    public static function cases(): iterable
    {
        yield 'нет сессий' => [[], self::NOW, 0, 0, 0];
        yield 'сегодня уже занимался' => [
            [self::half('2026-11-01'), self::half('2026-11-02'), self::half('2026-11-03'), self::half('2026-11-04')],
            self::NOW, 3, 4, 93,
        ];
        yield 'сегодня ещё не занимался' => [
            [self::half('2026-11-02'), self::half('2026-11-03')],
            '2026-11-04T07:00:00Z', 2, 2, 62,
        ];
        yield 'пропуск' => [[self::half('2026-11-02')], self::NOW, 1, 0, 31];
        // воскресенье 23:50 → понедельник 00:20 по Белграду
        yield 'сессия через полночь понедельника' => [
            [self::session('2026-11-01T22:50:00Z', '2026-11-01T23:20:00Z')],
            self::NOW, 1, 0, 0,
        ];
        yield 'воскресенье 23:59' => [
            [self::session('2026-11-08T22:59:00Z', '2026-11-08T22:59:00Z')],
            '2026-11-08T22:59:30Z', 1, 1, 1,
        ];
        yield 'понедельник 00:00 — новая неделя' => [
            [self::session('2026-11-08T22:59:00Z', '2026-11-08T22:59:00Z')],
            '2026-11-08T23:00:00Z', 0, 1, 0,
        ];
        // 2026-10-25 часы переводятся с +02:00 на +01:00; неделя — 2026-10-19 … 2026-10-25
        yield 'переход на зимнее время' => [
            [
                self::session('2026-10-18T21:30:00Z', '2026-10-18T21:35:00Z'), // воскресенье 23:30 — прошлая неделя
                self::session('2026-10-18T22:30:00Z', '2026-10-18T22:40:00Z'), // понедельник 00:30
                self::session('2026-10-25T10:00:00Z', '2026-10-25T10:09:00Z'),
            ],
            '2026-10-25T20:00:00Z', 2, 1, 21,
        ];
    }

    public function testSessionCannotEndBeforeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::session('2026-11-04T10:00:00Z', '2026-11-04T09:59:00Z');
    }

    /**
     * 31 минута с 10:00 UTC дня.
     */
    private static function half(string $date): ActivityInterval
    {
        return self::session($date.'T10:00:00Z', $date.'T10:30:00Z');
    }

    private static function session(string $startedAt, string $lastSeenAt): ActivityInterval
    {
        return new ActivityInterval(new \DateTimeImmutable($startedAt), new \DateTimeImmutable($lastSeenAt));
    }
}
