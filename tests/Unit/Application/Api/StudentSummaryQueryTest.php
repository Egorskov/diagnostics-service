<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Api;

use App\Application\Api\StudentSummaryQuery;
use App\Application\Port\CurrentPath;
use App\Domain\Metrics\ActivityInterval;
use App\Domain\Metrics\PathPlan;
use App\Domain\Metrics\PlannedTask;
use App\Domain\Signal\SignalType;
use App\Domain\Signal\WeeklyMoment;
use App\Tests\Support\FixedThresholds;
use App\Tests\Support\InMemoryProgressReadModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class StudentSummaryQueryTest extends TestCase
{
    private const STUDENT = 'student';
    /** Среда 18:12 по Белграду; неделя — 2026-11-02 … 2026-11-08 */
    private const NOW = '2026-11-04T17:12:00Z';

    private InMemoryProgressReadModel $readModel;

    protected function setUp(): void
    {
        $this->readModel = new InMemoryProgressReadModel();
    }

    private function query(?WeeklyMoment $weeklyCheck = null): StudentSummaryQuery
    {
        return new StudentSummaryQuery($this->readModel, new FixedThresholds(weeklyGoalCheck: $weeklyCheck), new MockClock(self::NOW), 'Europe/Belgrade');
    }

    private static function session(string $startedAt, string $lastSeenAt): ActivityInterval
    {
        return new ActivityInterval(new \DateTimeImmutable($startedAt), new \DateTimeImmutable($lastSeenAt));
    }

    public function testWholeSpecExample(): void
    {
        $started = new \DateTimeImmutable('2026-10-18T22:00:00Z');
        $this->readModel->paths = [
            new CurrentPath(self::STUDENT, 'old-path', 'old-mentor', new \DateTimeImmutable('2026-09-01T00:00:00Z'), 600),
            new CurrentPath(self::STUDENT, 'path', 'mentor', $started, 1500),
        ];
        $this->readModel->plans['path'] = new PathPlan($started, 1500, [
            new PlannedTask(2700, new \DateTimeImmutable('2026-10-30T10:00:00Z'), 2000),
            new PlannedTask(12300, null, 1300),
        ]);
        $this->readModel->sessions[self::STUDENT] = [
            self::session('2026-11-01T10:00:00Z', '2026-11-01T10:09:00Z'), // воскресенье — прошлая неделя
            self::session('2026-11-02T10:00:00Z', '2026-11-02T13:19:00Z'), // 200 минут
            self::session('2026-11-03T10:00:00Z', '2026-11-03T13:19:00Z'), // 200
            self::session('2026-11-04T10:00:00Z', '2026-11-04T13:29:00Z'), // 210
        ];
        $this->readModel->logs = [
            [self::STUDENT, '2026-11-02', 'practice', 300],
            [self::STUDENT, '2026-11-04', 'practice', 150],
            [self::STUDENT, '2026-11-08', 'interview', 90],
            [self::STUDENT, '2026-11-01', 'theory', 500], // прошлая неделя
            ['other', '2026-11-03', 'theory', 60],
        ];
        $this->readModel->signals[self::STUDENT] = [SignalType::TaskStuck];

        $summary = $this->query()->find(self::STUDENT);

        self::assertNotNull($summary);
        self::assertSame(['student', 'path'], [$summary->studentId, $summary->pathId]);
        self::assertSame([2700, 3600, 3300], [$summary->metrics->earnedMinutes, $summary->metrics->plannedMinutes, $summary->metrics->actualMinutes]);
        self::assertSame(['2026-12-28', '2027-01-20', 23], [$summary->metrics->plannedFinish?->format('Y-m-d'), $summary->metrics->forecastFinish?->format('Y-m-d'), $summary->metrics->delayDays]);
        self::assertSame([1500, 540], [$summary->weekGoalMinutes, $summary->weekLoggedMinutes]);
        self::assertSame([610, 3, 4], [$summary->regularity->weekActiveMinutes, $summary->regularity->activeDaysInWeek, $summary->regularity->streakDays]);
        self::assertEqualsWithDelta([0.0, 450 / 540, 90 / 540], [$summary->balance->theory, $summary->balance->practice, $summary->balance->interview], 1e-9);
        self::assertSame([SignalType::TaskStuck], $summary->activeSignals);
        self::assertSame(['2026-11-02'], $this->readModel->weekSubjects);
    }

    public function testNoPath(): void
    {
        self::assertNull($this->query()->find(self::STUDENT));
    }

    public function testWeekSubjectFollowsWeeklyCheckZone(): void
    {
        $this->readModel->paths = [new CurrentPath(self::STUDENT, 'path', 'mentor', new \DateTimeImmutable('2026-10-01T00:00:00Z'), 1500)];
        $auckland = new WeeklyMoment(4, 18, 0, new \DateTimeZone('Pacific/Auckland'));

        // воскресенье 23:30 по Белграду — уже понедельник новой недели в Окленде (UTC+13)
        (new StudentSummaryQuery($this->readModel, new FixedThresholds(weeklyGoalCheck: $auckland), new MockClock('2026-11-08T22:30:00Z'), 'Europe/Belgrade'))
            ->find(self::STUDENT);

        self::assertSame(['2026-11-09'], $this->readModel->weekSubjects);
    }
}
