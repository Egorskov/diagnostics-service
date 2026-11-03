<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Event\Incoming\ActivityHeartbeat;
use App\Application\Port\ActivityPoint;
use App\Application\Port\ActivityTracker;
use App\Application\Port\TaskStuckCheck;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ActivityHeartbeatHandler
{
    public function __construct(
        private ActivityTracker $activity,
        private TaskStuckCheck $stuck,
    ) {
    }

    public function __invoke(ActivityHeartbeat $event): void
    {
        $this->activity->record(new ActivityPoint($event->studentId, $event->taskId, $event->source, $event->metadata()->occurredAt));
        if (null !== $event->taskId) {
            $this->stuck->check($event->studentId, $event->taskId, $event->metadata());
        }
    }
}
