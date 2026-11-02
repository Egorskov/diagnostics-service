<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\EventFlowTestCase;

/**
 * Сессионизация (activity-sessions) через настоящий воркер и БД.
 */
final class ActivitySessionFlowTest extends EventFlowTestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const PATH = '22222222-2222-4222-8222-222222222222';
    private const TASK = '33333333-3333-4333-8333-333333333333';

    protected function tearDown(): void
    {
        $this->connection->executeStatement("INSERT INTO threshold (key, value) VALUES ('idle_timeout_min', '15') ON CONFLICT (key) DO NOTHING");
        parent::tearDown();
    }

    private static function eventId(int $number): string
    {
        return \sprintf('0b6f6a7e-3c1d-4b8a-9e2f-%012d', $number);
    }

    private static function deliverHeartbeat(int $number, string $occurredAt): void
    {
        self::deliver('heartbeat', self::event(self::eventId($number), 'activity.heartbeat', [
            'student_id' => self::STUDENT,
            'task_id' => self::TASK,
            'source' => 'theory',
        ], $occurredAt));
    }

    private static function deliverTaskStarted(int $number, string $occurredAt): void
    {
        self::deliver('courses', self::event(self::eventId($number), 'task.started', [
            'student_id' => self::STUDENT,
            'path_id' => self::PATH,
            'task_id' => self::TASK,
        ], $occurredAt));
    }

    public function testTaskStartedAsFirstEventCountsItsOwnSession(): void
    {
        self::deliverTaskStarted(1, '2026-10-30T12:00:00Z');

        self::consume(1);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT active_minutes FROM task_progress'));
        self::assertSame('other', $this->connection->fetchOne('SELECT source FROM activity_session'));
    }

    public function testHeartbeatsFormSessionsAndSum(): void
    {
        self::deliverTaskStarted(1, '2026-10-30T12:00:00Z');
        self::deliverHeartbeat(2, '2026-10-30T12:01:00Z');
        self::deliverHeartbeat(3, '2026-10-30T12:17:01Z');

        self::consume(3, ['courses', 'heartbeat']);

        self::assertSame([
            ['started_at' => '2026-10-30 12:00:00', 'last_seen_at' => '2026-10-30 12:01:00'],
            ['started_at' => '2026-10-30 12:17:01', 'last_seen_at' => '2026-10-30 12:17:01'],
        ], $this->connection->fetchAllAssociative('SELECT started_at, last_seen_at FROM activity_session ORDER BY started_at'));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT active_minutes FROM task_progress'));
        self::assertSame([], self::transport('failed')->getSent());
    }

    public function testHeartbeatWithoutProgressKeepsSessionOnly(): void
    {
        self::deliverHeartbeat(1, '2026-10-30T12:00:00Z');

        self::consume(1, ['heartbeat']);

        self::assertSame(1, $this->rowCount('activity_session'));
        self::assertSame(0, $this->rowCount('task_progress'));
        self::assertSame([], self::transport('failed')->getSent());
    }

    public function testMissingThresholdFailsProcessingAndEventWaitsForRetry(): void
    {
        $this->connection->executeStatement("DELETE FROM threshold WHERE key = 'idle_timeout_min'");
        self::deliverHeartbeat(1, '2026-10-30T12:00:00Z');

        self::consume(1, ['heartbeat']);

        self::assertSame(0, $this->rowCount('activity_session'));
        self::assertSame(0, $this->rowCount('inbox_event'));
        self::assertCount(2, self::transport('heartbeat')->getSent());
        self::assertSame([], self::transport('failed')->getSent());
    }
}
