<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Event\Incoming\TaskStarted;
use App\Application\Port\ActivityPoint;
use App\Application\Port\ActivityTracker;
use App\Application\Port\TaskProgressRepository;
use App\Domain\Progress\ActivitySource;
use App\Domain\Progress\TaskProgress;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Событие самодостаточно: path_id есть в нём самом, path.assigned не требуется (ADR 0003).
 */
#[AsMessageHandler]
final readonly class TaskStartedHandler
{
    public function __construct(
        private TaskProgressRepository $progress,
        private ActivityTracker $activity,
    ) {
    }

    public function __invoke(TaskStarted $event): void
    {
        $occurredAt = $event->metadata()->occurredAt;

        $progress = $this->progress->findForUpdate($event->studentId, $event->taskId);
        if (null === $progress) {
            $progress = new TaskProgress($event->studentId, $event->taskId, $event->pathId);
            $this->progress->add($progress);
        }
        $progress->start($occurredAt);

        $this->activity->record(new ActivityPoint($event->studentId, $event->taskId, ActivitySource::Other, $occurredAt));
    }
}
