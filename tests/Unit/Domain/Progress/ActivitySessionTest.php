<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Progress;

use App\Domain\Progress\ActivitySession;
use App\Domain\Progress\ActivitySource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActivitySessionTest extends TestCase
{
    private const START = '2026-10-30T10:00:00Z';
    private const LAST = '2026-10-30T10:30:00Z';

    private static function session(string $start = self::START, string $last = self::LAST): ActivitySession
    {
        $session = new ActivitySession('student', 'task', ActivitySource::Other, new \DateTimeImmutable($start));
        $session->touch(new \DateTimeImmutable($last));

        return $session;
    }

    public function testNewSessionStartsAndEndsAtPoint(): void
    {
        $at = new \DateTimeImmutable(self::START);

        $session = new ActivitySession('student', null, ActivitySource::Theory, $at);

        self::assertSame('student', $session->studentId());
        self::assertNull($session->taskId());
        self::assertSame(ActivitySource::Theory, $session->source());
        self::assertEquals($at, $session->startedAt());
        self::assertEquals($at, $session->lastSeenAt());
    }

    #[DataProvider('windows')]
    public function testCovers(string $at, bool $covered): void
    {
        self::assertSame($covered, self::session()->covers(new \DateTimeImmutable($at), 15));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function windows(): iterable
    {
        yield 'внутри сессии' => ['2026-10-30T10:15:00Z', true];
        yield 'ровно через таймаут' => ['2026-10-30T10:45:00Z', true];
        yield 'через таймаут и секунду' => ['2026-10-30T10:45:01Z', false];
        yield 'раньше начала ровно на таймаут' => ['2026-10-30T09:45:00Z', true];
        yield 'раньше начала на таймаут и секунду' => ['2026-10-30T09:44:59Z', false];
    }

    #[DataProvider('touches')]
    public function testTouchOnlyExpandsBounds(string $at, string $startedAt, string $lastSeenAt): void
    {
        $session = self::session();

        $session->touch(new \DateTimeImmutable($at));

        self::assertEquals(new \DateTimeImmutable($startedAt), $session->startedAt());
        self::assertEquals(new \DateTimeImmutable($lastSeenAt), $session->lastSeenAt());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function touches(): iterable
    {
        yield 'позже конца' => ['2026-10-30T10:40:00Z', self::START, '2026-10-30T10:40:00Z'];
        yield 'внутри' => ['2026-10-30T10:15:00Z', self::START, self::LAST];
        yield 'раньше начала' => ['2026-10-30T09:50:00Z', '2026-10-30T09:50:00Z', self::LAST];
    }

    #[DataProvider('durations')]
    public function testMinutes(string $last, int $minutes): void
    {
        self::assertSame($minutes, self::session(self::START, $last)->minutes());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function durations(): iterable
    {
        yield 'одна точка' => [self::START, 1];
        yield '59 секунд' => ['2026-10-30T10:00:59Z', 1];
        yield '90 секунд' => ['2026-10-30T10:01:30Z', 2];
        yield 'две минуты' => ['2026-10-30T10:02:00Z', 3];
    }
}
