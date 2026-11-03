<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Activity;

use App\Application\Activity\SessionActivityTracker;
use App\Application\Port\ActivityPoint;
use App\Application\Port\InvalidThreshold;
use App\Domain\Progress\ActivitySession;
use App\Domain\Progress\ActivitySource;
use App\Domain\Progress\TaskProgress;
use App\Domain\Progress\TaskStatus;
use App\Tests\Support\FixedThresholds;
use App\Tests\Support\InMemoryActivitySessionRepository;
use App\Tests\Support\InMemoryTaskProgressRepository;
use PHPUnit\Framework\TestCase;

final class SessionActivityTrackerTest extends TestCase
{
    private InMemoryActivitySessionRepository $sessions;
    private InMemoryTaskProgressRepository $progress;

    protected function setUp(): void
    {
        $this->sessions = new InMemoryActivitySessionRepository();
        $this->progress = new InMemoryTaskProgressRepository();
    }

    private function record(string $time, ?string $taskId = 'task', string $studentId = 'student', ActivitySource $source = ActivitySource::Theory, ?int $idle = 15): void
    {
        $tracker = new SessionActivityTracker($this->sessions, $this->progress, new FixedThresholds($idle));
        $tracker->record(new ActivityPoint($studentId, $taskId, $source, new \DateTimeImmutable('2026-10-30T'.$time.'Z')));
    }

    private function progress(): TaskProgress
    {
        $progress = new TaskProgress('student', 'task', 'path');
        $this->progress->add($progress);

        return $progress;
    }

    /**
     * @return list<array{string, string}> границы всех сессий
     */
    private function bounds(): array
    {
        return array_map(
            static fn (ActivitySession $session) => [$session->startedAt()->format('H:i:s'), $session->lastSeenAt()->format('H:i:s')],
            $this->sessions->sessions,
        );
    }

    public function testFirstPointOpensSession(): void
    {
        $this->record('10:00:00');

        self::assertSame([['10:00:00', '10:00:00']], $this->bounds());
    }

    public function testPointWithinTimeoutExtendsSession(): void
    {
        $this->record('10:00:00');
        $this->record('10:15:00');

        self::assertSame([['10:00:00', '10:15:00']], $this->bounds());
    }

    public function testPointAfterTimeoutOpensNewSession(): void
    {
        $this->record('10:00:00');
        $this->record('10:15:01');

        self::assertSame([['10:00:00', '10:00:00'], ['10:15:01', '10:15:01']], $this->bounds());
    }

    public function testOtherTaskOpensOwnSession(): void
    {
        $this->record('10:00:00', 'task');
        $this->record('10:01:00', 'other-task');

        self::assertSame([['10:00:00', '10:00:00'], ['10:01:00', '10:01:00']], $this->bounds());
    }

    public function testPointWithoutTaskExtendsOnlySessionWithoutTask(): void
    {
        $this->record('10:00:00', 'task');
        $this->record('10:01:00', null);
        $this->record('10:02:00', null);

        self::assertSame([['10:00:00', '10:00:00'], ['10:01:00', '10:02:00']], $this->bounds());
    }

    public function testOtherStudentOpensOwnSession(): void
    {
        $this->record('10:00:00');
        $this->record('10:01:00', studentId: 'other-student');

        self::assertCount(2, $this->sessions->sessions);
    }

    public function testOtherSourceExtendsSessionAndKeepsFirstSource(): void
    {
        $this->record('10:00:00', source: ActivitySource::Other);
        $this->record('10:01:00', source: ActivitySource::Theory);

        self::assertCount(1, $this->sessions->sessions);
        self::assertSame(ActivitySource::Other, $this->sessions->sessions[0]->source());
    }

    public function testLatePointInsideSessionChangesNothing(): void
    {
        $this->record('10:00:00');
        $this->record('10:10:00');
        $this->record('10:05:00');

        self::assertSame([['10:00:00', '10:10:00']], $this->bounds());
    }

    public function testLatePointWithinTimeoutMovesStart(): void
    {
        $this->record('10:00:00');
        $this->record('09:50:00');

        self::assertSame([['09:50:00', '10:00:00']], $this->bounds());
    }

    public function testLatePointBeyondTimeoutOpensSeparateSession(): void
    {
        $this->record('10:00:00');
        $this->record('09:44:00');

        self::assertSame([['10:00:00', '10:00:00'], ['09:44:00', '09:44:00']], $this->bounds());
    }

    public function testPointBetweenSessionsExtendsLatest(): void
    {
        $this->record('10:00:00');
        $this->record('10:20:00');
        $this->record('10:10:00');

        self::assertSame([['10:00:00', '10:00:00'], ['10:10:00', '10:20:00']], $this->bounds());
    }

    public function testActiveMinutesAreSumOfTaskSessions(): void
    {
        $progress = $this->progress();

        $this->record('10:00:00');
        $this->record('10:02:00');
        self::assertSame(3, $progress->activeMinutes());

        $this->record('11:00:00');
        $this->record('11:04:00');
        self::assertSame(8, $progress->activeMinutes());
    }

    public function testFirstPointCountsNewSession(): void
    {
        $progress = $this->progress();

        $this->record('10:00:00');

        self::assertSame(1, $progress->activeMinutes());
    }

    public function testPointDoesNotCreateProgress(): void
    {
        $this->record('10:00:00');

        self::assertCount(1, $this->sessions->sessions);
        self::assertSame([], $this->progress->progress);
    }

    public function testProgressAppearedLaterIncludesEarlierSessions(): void
    {
        $this->record('10:00:00');
        $this->record('10:02:00');
        $progress = $this->progress();

        $this->record('11:00:00');

        self::assertSame(4, $progress->activeMinutes());
    }

    public function testPointAfterCompletionUpdatesMinutesAndKeepsStatus(): void
    {
        $progress = $this->progress();
        $progress->complete(new \DateTimeImmutable('2026-10-30T09:00:00Z'));

        $this->record('10:00:00');

        self::assertSame(1, $progress->activeMinutes());
        self::assertSame(TaskStatus::Completed, $progress->status());
    }

    public function testPointWithoutTaskChangesNoProgress(): void
    {
        $progress = $this->progress();

        $this->record('10:00:00', null);

        self::assertSame(0, $progress->activeMinutes());
    }

    public function testTimeoutComesFromThresholds(): void
    {
        $this->record('10:00:00', idle: 30);
        $this->record('10:20:00', idle: 30);

        self::assertSame([['10:00:00', '10:20:00']], $this->bounds());
    }

    public function testMissingThresholdChangesNothing(): void
    {
        $this->record('10:00:00');

        try {
            $this->record('10:01:00', idle: null);
            self::fail('Ожидалось исключение InvalidThreshold');
        } catch (InvalidThreshold) {
        }

        self::assertSame([['10:00:00', '10:00:00']], $this->bounds());
    }
}
