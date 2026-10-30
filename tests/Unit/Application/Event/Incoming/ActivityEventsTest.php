<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Event\Incoming;

use App\Application\Event\Incoming\ActivityHeartbeat;
use App\Application\Event\Incoming\ActivitySource;
use App\Application\Event\Incoming\SandboxResult;
use App\Application\Event\Incoming\SandboxRun;
use App\Application\Event\Incoming\TimeCategory;
use App\Application\Event\Incoming\TimeLogged;
use App\Application\Event\IncomingEvent;
use App\Application\Event\InvalidPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * time.logged, activity.heartbeat, sandbox.run — события с необязательными полями и enum.
 */
final class ActivityEventsTest extends TestCase
{
    public function testTimeLoggedWithAllFields(): void
    {
        $event = TimeLogged::fromPayload(EventFixture::metadata('time.logged'), EventFixture::payload([
            'student_id' => EventFixture::STUDENT,
            'task_id' => EventFixture::TASK,
            'minutes' => 45,
            'category' => 'practice',
            'logged_for_date' => '2026-10-29',
        ]));

        self::assertSame(EventFixture::TASK, $event->taskId);
        self::assertSame(45, $event->minutes);
        self::assertSame(TimeCategory::Practice, $event->category);
        self::assertSame('2026-10-29T00:00:00+00:00', $event->loggedForDate->format(\DATE_ATOM));
    }

    public function testTimeLoggedWithoutTask(): void
    {
        $event = TimeLogged::fromPayload(EventFixture::metadata('time.logged'), EventFixture::payload([
            'student_id' => EventFixture::STUDENT,
            'minutes' => 30,
            'category' => 'interview',
            'logged_for_date' => '2026-10-29',
        ]));

        self::assertNull($event->taskId);
    }

    public function testHeartbeatWithoutTask(): void
    {
        $event = ActivityHeartbeat::fromPayload(EventFixture::metadata('activity.heartbeat'), EventFixture::payload([
            'student_id' => EventFixture::STUDENT,
            'source' => 'theory',
        ]));

        self::assertNull($event->taskId);
        self::assertSame(ActivitySource::Theory, $event->source);
    }

    public function testSandboxRunWithoutOptionalFields(): void
    {
        $event = SandboxRun::fromPayload(EventFixture::metadata('sandbox.run'), EventFixture::payload([
            'run_id' => EventFixture::RUN,
            'student_id' => EventFixture::STUDENT,
            'task_id' => EventFixture::TASK,
            'result' => 'failed',
        ]));

        self::assertSame(EventFixture::RUN, $event->runId);
        self::assertSame(SandboxResult::Failed, $event->result);
        self::assertNull($event->testsPassed);
        self::assertNull($event->testsTotal);
        self::assertNull($event->errorType);
    }

    public function testSandboxRunWithOptionalFields(): void
    {
        $event = SandboxRun::fromPayload(EventFixture::metadata('sandbox.run'), EventFixture::payload([
            'run_id' => EventFixture::RUN,
            'student_id' => EventFixture::STUDENT,
            'task_id' => EventFixture::TASK,
            'result' => 'error',
            'tests_passed' => 3,
            'tests_total' => 10,
            'error_type' => 'TypeError',
        ]));

        self::assertSame(3, $event->testsPassed);
        self::assertSame(10, $event->testsTotal);
        self::assertSame('TypeError', $event->errorType);
    }

    public function testSandboxRunAcceptsEmptyErrorType(): void
    {
        $event = SandboxRun::fromPayload(EventFixture::metadata('sandbox.run'), EventFixture::payload([
            'run_id' => EventFixture::RUN,
            'student_id' => EventFixture::STUDENT,
            'task_id' => EventFixture::TASK,
            'result' => 'failed',
            'error_type' => '',
        ]));

        self::assertSame('', $event->errorType);
    }

    /**
     * @return iterable<string, array{class-string<IncomingEvent>, array<string, mixed>, string}>
     */
    public static function invalidPayloads(): iterable
    {
        $time = ['student_id' => EventFixture::STUDENT, 'minutes' => 30, 'category' => 'theory', 'logged_for_date' => '2026-10-29'];
        yield 'time category out of enum' => [TimeLogged::class, ['category' => 'meeting'] + $time, 'payload.category: must be one of: theory, practice, interview'];
        yield 'time zero minutes' => [TimeLogged::class, ['minutes' => 0] + $time, 'payload.minutes: must be >= 1'];
        yield 'time date with time' => [TimeLogged::class, ['logged_for_date' => '2026-10-29T10:00:00Z'] + $time, 'payload.logged_for_date: must be a date'];

        $heartbeat = ['student_id' => EventFixture::STUDENT, 'source' => 'quiz'];
        yield 'heartbeat source out of enum' => [ActivityHeartbeat::class, ['source' => 'video'] + $heartbeat, 'payload.source: must be one of: theory, sandbox, quiz, other'];
        yield 'heartbeat without source' => [ActivityHeartbeat::class, array_diff_key($heartbeat, ['source' => true]), 'payload.source: is required'];

        $run = ['run_id' => EventFixture::RUN, 'student_id' => EventFixture::STUDENT, 'task_id' => EventFixture::TASK, 'result' => 'passed'];
        yield 'run result out of enum' => [SandboxRun::class, ['result' => 'timeout'] + $run, 'payload.result: must be one of: passed, failed, error'];
        yield 'run without task' => [SandboxRun::class, array_diff_key($run, ['task_id' => true]), 'payload.task_id: is required'];
        yield 'run tests_total not int' => [SandboxRun::class, ['tests_total' => '10'] + $run, 'payload.tests_total: must be an integer'];
    }

    /**
     * @param class-string<IncomingEvent> $class
     * @param array<string, mixed>        $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(string $class, array $payload, string $message): void
    {
        $this->expectException(InvalidPayload::class);
        $this->expectExceptionMessage($message);

        $class::fromPayload(EventFixture::metadata('any'), EventFixture::payload($payload));
    }
}
