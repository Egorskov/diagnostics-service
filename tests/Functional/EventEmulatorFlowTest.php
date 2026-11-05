<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Signal\CheckStudentInactivity;
use App\Application\Signal\CheckWeeklyGoals;
use App\Infrastructure\Messenger\RawBodyStamp;
use App\Tests\Support\ApiToken;
use App\Tests\Support\EventFlowTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * app:emulate-events → транспорты → EnvelopeSerializer → воркер → состояние, сигналы и Read-API.
 * In-memory транспорт хранит отправленный конверт как есть, поэтому тело из RawBodyStamp
 * подаётся обратно через decode(), как его получил бы AMQP-receiver (design, решение 2).
 */
final class EventEmulatorFlowTest extends EventFlowTestCase
{
    use ClockSensitiveTrait;

    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const MENTOR = '44444444-4444-4444-8444-444444444444';
    /** Четверг 19:00 по Белграду: недельная проверка этой недели уже прошла */
    private const NOW = '2026-11-05T18:00:00Z';

    /**
     * @param array<string, string> $options
     *
     * @return list<array{string, string}> транспорт и тело каждого опубликованного события
     */
    private function emulate(array $options): array
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException('Kernel is not booted')))->find('app:emulate-events'));
        $tester->execute($options + ['--student' => self::STUDENT, '--mentor' => self::MENTOR, '--seed' => '42']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $bodies = [];
        foreach (['courses', 'time', 'heartbeat'] as $name) {
            foreach (self::transport($name)->getSent() as $envelope) {
                $bodies[] = [$name, ($envelope->last(RawBodyStamp::class) ?? self::fail('No raw body'))->body];
            }
            self::transport($name)->reset();
        }

        return $bodies;
    }

    /**
     * @param list<array{string, string}> $bodies
     */
    private static function process(array $bodies): void
    {
        foreach ($bodies as [$transport, $body]) {
            self::deliver($transport, $body);
        }
        self::consume(\count($bodies), ['courses', 'time', 'heartbeat'], timeLimit: 120);
        self::assertSame([], self::transport('failed')->getSent());
    }

    /**
     * @return list<string>
     */
    private function activeSignals(): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT DISTINCT signal_type FROM signal_state WHERE active AND student_id = ? ORDER BY 1', [self::STUDENT]);
    }

    private static function runChecks(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new CheckStudentInactivity());
        $bus->dispatch(new CheckWeeklyGoals());
    }

    public function testSteadyStudentIsVisibleInApiWithoutSignals(): void
    {
        self::process($this->emulate(['--days' => '4']));
        self::runChecks();

        $summary = self::get('/api/v1/students/'.self::STUDENT.'/summary', ApiToken::student(self::STUDENT));
        self::assertSame(200, $summary->getStatusCode());
        $json = self::json($summary);
        self::assertSame(self::STUDENT, $json['student_id']);
        self::assertSame($this->connection->fetchOne('SELECT path_id FROM student_path WHERE student_id = ?', [self::STUDENT]), $json['path_id']);
        self::assertGreaterThan(0, $json['ev_minutes']);

        $students = self::json(self::get('/api/v1/mentor/students', ApiToken::mentor(self::MENTOR)))['students'];
        self::assertIsArray($students);
        self::assertSame([self::STUDENT], array_column($students, 'student_id'));
        self::assertSame([], $this->activeSignals());
    }

    public function testStuckProfileRaisesTaskStuck(): void
    {
        self::process($this->emulate(['profile' => 'stuck', '--days' => '2']));

        self::assertSame(['diagnostics.task.stuck'], $this->activeSignals());
    }

    public function testInactiveProfileRaisesStudentInactive(): void
    {
        self::process($this->emulate(['profile' => 'inactive', '--days' => '6']));
        self::runChecks();

        self::assertContains('diagnostics.student.inactive', $this->activeSignals());
    }

    public function testBehindProfileRaisesWeeklyGoalBehind(): void
    {
        self::process($this->emulate(['profile' => 'behind', '--days' => '4']));
        self::runChecks();

        self::assertSame(['diagnostics.weekly_goal.behind'], $this->activeSignals());
    }

    public function testSameSeedTwiceChangesNothing(): void
    {
        $bodies = $this->emulate(['--days' => '2']);
        self::process($bodies);
        $snapshot = fn (): array => $this->connection->fetchAllAssociative(
            'SELECT (SELECT count(*) FROM inbox_event) inbox, (SELECT count(*) FROM activity_session) sessions,
                    (SELECT sum(active_minutes) FROM task_progress) active, (SELECT sum(minutes) FROM time_log) logged',
        );
        $before = $snapshot();

        self::process($this->emulate(['--days' => '2']));

        self::assertSame($before, $snapshot());
        self::assertSame(\count($bodies), (int) $before[0]['inbox']);
    }
}
