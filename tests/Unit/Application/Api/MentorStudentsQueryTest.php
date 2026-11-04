<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Api;

use App\Application\Api\MentorStudent;
use App\Application\Api\MentorStudentsQuery;
use App\Application\Port\CurrentPath;
use App\Domain\Metrics\ActivityInterval;
use App\Domain\Metrics\PathPlan;
use App\Domain\Metrics\PlannedTask;
use App\Domain\Signal\SignalType;
use App\Tests\Support\FixedThresholds;
use App\Tests\Support\InMemoryProgressReadModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class MentorStudentsQueryTest extends TestCase
{
    private const MENTOR = 'mentor';
    /** С начала путей 2026-10-01 прошло 4,96 недели: при цели 1000 план — 4959 минут */
    private const NOW = '2026-11-04T17:12:00Z';
    private const STARTED = '2026-10-01T00:00:00Z';

    private InMemoryProgressReadModel $readModel;

    protected function setUp(): void
    {
        $this->readModel = new InMemoryProgressReadModel();
    }

    /**
     * @param list<PlannedTask> $tasks
     */
    private function student(string $student, array $tasks, string $started = self::STARTED, string $mentor = self::MENTOR): void
    {
        $startedAt = new \DateTimeImmutable($started);
        $this->readModel->paths[] = new CurrentPath($student, $student.'-path', $mentor, $startedAt, 1000);
        $this->readModel->plans[$student.'-path'] = new PathPlan($startedAt, 1000, $tasks);
    }

    private static function done(int $estimate, string $at = '2026-11-01T10:00:00Z'): PlannedTask
    {
        return new PlannedTask($estimate, new \DateTimeImmutable($at), 0);
    }

    private static function open(int $estimate): PlannedTask
    {
        return new PlannedTask($estimate, null, 0);
    }

    /**
     * @return list<MentorStudent>
     */
    private function list(): array
    {
        return (new MentorStudentsQuery($this->readModel, new FixedThresholds(), new MockClock(self::NOW), 'Europe/Belgrade'))->list(self::MENTOR);
    }

    public function testLaggingStudentFirstAndUncountedLast(): void
    {
        $this->student('a-ahead', [self::done(5500), self::open(1000)]); // spi ≈ 1,11
        $this->student('b-new', [self::open(6000)], '2026-11-10T00:00:00Z'); // путь не начался: spi не посчитан
        $this->student('c-behind', [self::done(3000), self::open(3000)]); // spi ≈ 0,6

        $students = $this->list();

        self::assertSame(['c-behind', 'a-ahead', 'b-new'], array_map(static fn (MentorStudent $s): string => $s->studentId, $students));
        self::assertEqualsWithDelta(0.6, $students[0]->metrics->spi, 0.01);
        self::assertEqualsWithDelta(1.11, $students[1]->metrics->spi, 0.01);
        self::assertNull($students[2]->metrics->spi);
    }

    public function testEqualPaceSortsByDelay(): void
    {
        // одинаковый EV 3000; у «a» темп окна 750/нед — 28 дней, у «b» 375/нед — 56 дней
        $this->student('a-fast', [self::done(3000), self::open(3000)]);
        $this->student('b-slow', [self::done(1500), self::done(1500, '2026-10-02T10:00:00Z'), self::open(3000)]);
        // темп за окно не посчитан — прогноза и отставания нет
        $this->student('c-stalled', [self::done(3000, '2026-10-02T10:00:00Z'), self::open(3000)]);

        $students = $this->list();

        self::assertSame(['b-slow', 'a-fast', 'c-stalled'], array_map(static fn (MentorStudent $s): string => $s->studentId, $students));
        self::assertSame($students[0]->metrics->spi, $students[1]->metrics->spi);
        self::assertGreaterThan($students[1]->metrics->delayDays, $students[0]->metrics->delayDays);
        self::assertNull($students[2]->metrics->delayDays);
    }

    public function testLastActivityAndSignals(): void
    {
        $this->student('active', [self::open(600)]);
        $this->student('idle', [self::open(600)]);
        $this->readModel->sessions['active'] = [
            new ActivityInterval(new \DateTimeImmutable('2026-11-03T10:00:00Z'), new \DateTimeImmutable('2026-11-03T11:00:00Z')),
            new ActivityInterval(new \DateTimeImmutable('2026-11-01T10:00:00Z'), new \DateTimeImmutable('2026-11-01T10:30:00Z')),
        ];
        $this->readModel->signals['idle'] = [SignalType::StudentInactive];

        $students = $this->list();

        self::assertSame(['active', 'idle'], array_map(static fn (MentorStudent $s): string => $s->studentId, $students));
        self::assertEquals(new \DateTimeImmutable('2026-11-03T11:00:00Z'), $students[0]->lastActivityAt);
        // ни разу не занимался — начало пути
        self::assertEquals(new \DateTimeImmutable(self::STARTED), $students[1]->lastActivityAt);
        self::assertSame([[], [SignalType::StudentInactive]], [$students[0]->activeSignals, $students[1]->activeSignals]);
        self::assertSame(['2026-11-02', '2026-11-02'], $this->readModel->weekSubjects);
    }

    public function testFormerAndOtherMentorsStudentsExcluded(): void
    {
        $this->student('moved', [self::open(600)]);
        $this->student('moved', [self::open(600)], '2026-10-15T00:00:00Z', 'other-mentor');
        $this->student('foreign', [self::open(600)], self::STARTED, 'other-mentor');

        self::assertSame([], $this->list());
    }
}
