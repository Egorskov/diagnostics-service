<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Signal;

use App\Domain\Signal\Audience;
use App\Domain\Signal\SignalKey;
use App\Domain\Signal\SignalState;
use App\Domain\Signal\SignalType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignalStateTest extends TestCase
{
    /**
     * @param list<bool> $conditions условие на проверках с 1-го по n-й час
     * @param list<bool> $published  что вернула каждая проверка
     */
    #[DataProvider('sequences')]
    public function testPublishesOnlyOnTransition(array $conditions, array $published, bool $active, ?int $firstHour, ?int $lastHour): void
    {
        $state = new SignalState(new SignalKey(SignalType::StudentInactive, Audience::Student, 'student'));

        $results = [];
        foreach ($conditions as $hour => $condition) {
            $results[] = $state->observe($condition, self::hour($hour + 1));
        }

        self::assertSame($published, $results);
        self::assertSame($active, $state->isActive());
        self::assertEquals(null === $firstHour ? null : self::hour($firstHour), $state->firstEmittedAt());
        self::assertEquals(null === $lastHour ? null : self::hour($lastHour), $state->lastEmittedAt());
    }

    /**
     * @return iterable<string, array{list<bool>, list<bool>, bool, ?int, ?int}>
     */
    public static function sequences(): iterable
    {
        yield 'условие ложно' => [[false, false], [false, false], false, null, null];
        yield 'переход в истину' => [[false, true], [false, true], true, 2, 2];
        yield 'повторная истина' => [[true, true, true], [true, false, false], true, 1, 1];
        yield 'сброс' => [[true, false], [true, false], false, 1, 1];
        yield 'сброс и новый переход' => [[true, false, true], [true, false, true], true, 1, 3];
    }

    #[DataProvider('keys')]
    public function testKey(SignalKey $key, string $expected): void
    {
        $state = new SignalState($key);

        self::assertSame($expected, $key->toString());
        self::assertSame($expected, $state->id());
        self::assertEquals($key, $state->key());
    }

    /**
     * @return iterable<string, array{SignalKey, string}>
     */
    public static function keys(): iterable
    {
        yield 'без предмета' => [new SignalKey(SignalType::StudentInactive, Audience::Mentor, 's1'), 'diagnostics.student.inactive|mentor|s1|'];
        yield 'задание' => [new SignalKey(SignalType::TaskStuck, Audience::Student, 's1', 't1'), 'diagnostics.task.stuck|student|s1|t1'];
        yield 'неделя' => [new SignalKey(SignalType::WeeklyGoalBehind, Audience::Student, 's1', '2026-11-02'), 'diagnostics.weekly_goal.behind|student|s1|2026-11-02'];
    }

    private static function hour(int $hour): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('2026-11-03T%02d:00:00Z', $hour));
    }
}
