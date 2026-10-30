<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Application\Event\Incoming\ActivityHeartbeat;
use App\Application\Event\Incoming\PathAssigned;
use App\Application\Event\Incoming\SandboxRun;
use App\Application\Event\Incoming\TaskCompleted;
use App\Application\Event\Incoming\TaskStarted;
use App\Application\Event\Incoming\TimeLogged;
use App\Application\Event\IncomingEvent;

/**
 * Какие входящие события и версии сервис умеет разбирать (MVP, SPEC §10; ADR 0002).
 */
final class EventTypeRegistry
{
    /** @var array<string, array<int, class-string<IncomingEvent>>> */
    private const EVENTS = [
        'path.assigned' => [1 => PathAssigned::class],
        'task.started' => [1 => TaskStarted::class],
        'task.completed' => [1 => TaskCompleted::class],
        'time.logged' => [1 => TimeLogged::class],
        'activity.heartbeat' => [1 => ActivityHeartbeat::class],
        'sandbox.run' => [1 => SandboxRun::class],
    ];

    /**
     * @return class-string<IncomingEvent>
     *
     * @throws UnsupportedEvent
     */
    public function resolve(string $eventType, int $eventVersion): string
    {
        $versions = self::EVENTS[$eventType]
            ?? throw new UnsupportedEvent(DecodeFailure::UnknownEventType, \sprintf('Unknown event type "%s"', $eventType));

        if (!isset($versions[$eventVersion])) {
            $message = \sprintf('Event "%s" version %d is not supported (supported: %s)', $eventType, $eventVersion, implode(', ', array_keys($versions)));

            throw new UnsupportedEvent(DecodeFailure::UnsupportedEventVersion, $message);
        }

        return $versions[$eventVersion];
    }
}
