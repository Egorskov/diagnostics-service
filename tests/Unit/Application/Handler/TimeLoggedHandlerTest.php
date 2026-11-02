<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Event\EventMetadata;
use App\Application\Event\Incoming\TimeLogged;
use App\Application\Handler\TimeLoggedHandler;
use App\Domain\Progress\TimeCategory;
use App\Tests\Support\InMemoryTimeLogRepository;
use PHPUnit\Framework\TestCase;

final class TimeLoggedHandlerTest extends TestCase
{
    private InMemoryTimeLogRepository $logs;

    protected function setUp(): void
    {
        $this->logs = new InMemoryTimeLogRepository();
    }

    private function logged(string $eventId, ?string $taskId, int $minutes = 30): void
    {
        $metadata = new EventMetadata($eventId, 'time.logged', 1, new \DateTimeImmutable('2026-10-30T20:00:00Z'), 'time');
        $event = new TimeLogged($metadata, 'student', $taskId, $minutes, TimeCategory::Practice, new \DateTimeImmutable('2026-10-29T00:00:00Z'));

        (new TimeLoggedHandler($this->logs))($event);
    }

    public function testLogWithTask(): void
    {
        $this->logged('event', 'task', 45);

        self::assertCount(1, $this->logs->logs);
        $log = $this->logs->logs[0];
        self::assertSame('student', $log->studentId());
        self::assertSame('task', $log->taskId());
        self::assertSame(TimeCategory::Practice, $log->category());
        self::assertSame(45, $log->minutes());
        self::assertSame('2026-10-29', $log->loggedForDate()->format('Y-m-d'));
        self::assertSame('event', $log->sourceEventId());
    }

    public function testLogWithoutTask(): void
    {
        $this->logged('event', null);

        self::assertNull($this->logs->logs[0]->taskId());
    }

    public function testTwoLogsForOneDayAreSeparateRecords(): void
    {
        $this->logged('event-1', 'task');
        $this->logged('event-2', 'task');

        self::assertCount(2, $this->logs->logs);
    }
}
