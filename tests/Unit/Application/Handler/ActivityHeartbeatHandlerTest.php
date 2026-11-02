<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Event\EventMetadata;
use App\Application\Event\Incoming\ActivityHeartbeat;
use App\Application\Handler\ActivityHeartbeatHandler;
use App\Domain\Progress\ActivitySource;
use App\Tests\Support\RecordingActivityTracker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActivityHeartbeatHandlerTest extends TestCase
{
    #[DataProvider('tasks')]
    public function testPassesActivityPointOn(?string $taskId): void
    {
        $tracker = new RecordingActivityTracker();
        $occurredAt = new \DateTimeImmutable('2026-10-30T09:00:00Z');
        $metadata = new EventMetadata('event', 'activity.heartbeat', 1, $occurredAt, 'courses');

        (new ActivityHeartbeatHandler($tracker))(new ActivityHeartbeat($metadata, 'student', $taskId, ActivitySource::Theory));

        self::assertCount(1, $tracker->points);
        self::assertSame('student', $tracker->points[0]->studentId);
        self::assertSame($taskId, $tracker->points[0]->taskId);
        self::assertSame(ActivitySource::Theory, $tracker->points[0]->source);
        self::assertSame($occurredAt, $tracker->points[0]->occurredAt);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function tasks(): iterable
    {
        yield 'с заданием' => ['task'];
        yield 'без задания' => [null];
    }
}
