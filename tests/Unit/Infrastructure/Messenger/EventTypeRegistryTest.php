<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Messenger;

use App\Application\Event\Incoming\ActivityHeartbeat;
use App\Application\Event\Incoming\PathAssigned;
use App\Application\Event\Incoming\SandboxRun;
use App\Application\Event\Incoming\TaskCompleted;
use App\Application\Event\Incoming\TaskStarted;
use App\Application\Event\Incoming\TimeLogged;
use App\Infrastructure\Messenger\DecodeFailure;
use App\Infrastructure\Messenger\EventTypeRegistry;
use App\Infrastructure\Messenger\UnsupportedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventTypeRegistryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function mvpEvents(): iterable
    {
        yield 'path.assigned' => ['path.assigned', PathAssigned::class];
        yield 'task.started' => ['task.started', TaskStarted::class];
        yield 'task.completed' => ['task.completed', TaskCompleted::class];
        yield 'time.logged' => ['time.logged', TimeLogged::class];
        yield 'activity.heartbeat' => ['activity.heartbeat', ActivityHeartbeat::class];
        yield 'sandbox.run' => ['sandbox.run', SandboxRun::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('mvpEvents')]
    public function testResolvesMvpEventVersion1(string $eventType, string $class): void
    {
        self::assertSame($class, (new EventTypeRegistry())->resolve($eventType, 1));
    }

    /**
     * @return iterable<string, array{string, int, DecodeFailure}>
     */
    public static function unsupported(): iterable
    {
        yield 'backlog event' => ['quiz.answered', 1, DecodeFailure::UnknownEventType];
        yield 'typo' => ['task.complete', 1, DecodeFailure::UnknownEventType];
        yield 'version 2' => ['task.completed', 2, DecodeFailure::UnsupportedEventVersion];
    }

    #[DataProvider('unsupported')]
    public function testRejectsUnsupported(string $eventType, int $version, DecodeFailure $reason): void
    {
        try {
            (new EventTypeRegistry())->resolve($eventType, $version);
            self::fail('UnsupportedEvent expected');
        } catch (UnsupportedEvent $e) {
            self::assertSame($reason, $e->reason);
            self::assertStringContainsString($eventType, $e->getMessage());
        }
    }
}
