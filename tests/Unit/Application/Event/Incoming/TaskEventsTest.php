<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Event\Incoming;

use App\Application\Event\Incoming\TaskCompleted;
use App\Application\Event\Incoming\TaskStarted;
use App\Application\Event\InvalidPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskEventsTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<TaskStarted|TaskCompleted>}>
     */
    public static function eventClasses(): iterable
    {
        yield 'task.started' => [TaskStarted::class];
        yield 'task.completed' => [TaskCompleted::class];
    }

    /**
     * @param class-string<TaskStarted|TaskCompleted> $class
     */
    #[DataProvider('eventClasses')]
    public function testReadsPayload(string $class): void
    {
        $metadata = EventFixture::metadata('task.started');

        $event = $class::fromPayload($metadata, EventFixture::payload([
            'student_id' => EventFixture::STUDENT,
            'path_id' => EventFixture::PATH,
            'task_id' => EventFixture::TASK,
        ]));

        self::assertSame($metadata, $event->metadata());
        self::assertSame(EventFixture::STUDENT, $event->studentId);
        self::assertSame(EventFixture::PATH, $event->pathId);
        self::assertSame(EventFixture::TASK, $event->taskId);
    }

    /**
     * @return iterable<string, array{class-string<TaskStarted|TaskCompleted>, string}>
     */
    public static function missingFields(): iterable
    {
        foreach (self::eventClasses() as $name => [$class]) {
            foreach (['student_id', 'path_id', 'task_id'] as $field) {
                yield "{$name} without {$field}" => [$class, $field];
            }
        }
    }

    /**
     * @param class-string<TaskStarted|TaskCompleted> $class
     */
    #[DataProvider('missingFields')]
    public function testRequiresEveryField(string $class, string $field): void
    {
        $payload = ['student_id' => EventFixture::STUDENT, 'path_id' => EventFixture::PATH, 'task_id' => EventFixture::TASK];
        unset($payload[$field]);

        $this->expectException(InvalidPayload::class);
        $this->expectExceptionMessage("payload.{$field}: is required");

        $class::fromPayload(EventFixture::metadata('task.started'), EventFixture::payload($payload));
    }
}
