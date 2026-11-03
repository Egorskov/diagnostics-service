<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Event\Incoming\TaskCompleted;
use App\Application\Port\ActivityPoint;
use App\Application\Port\ActivityTracker;
use App\Application\Port\TaskProgressRepository;
use App\Application\Port\TaskStuckCheck;
use App\Domain\Progress\ActivitySource;
use App\Domain\Progress\TaskProgress;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Событие самодостаточно: path_id есть в нём самом, path.assigned и task.started не требуются (ADR 0003).
 */
#[AsMessageHandler]
final readonly class TaskCompletedHandler
{
    public function __construct(
        private TaskProgressRepository $progress,
        private ActivityTracker $activity,
        private TaskStuckCheck $stuck,
    ) {
    }

    public function __invoke(TaskCompleted $event): void
    {
        $occurredAt = $event->metadata()->occurredAt;

        $progress = $this->progress->findForUpdate($event->studentId, $event->taskId);
        if (null === $progress) {
            $progress = new TaskProgress($event->studentId, $event->taskId, $event->pathId);
            $this->progress->add($progress);
        }
        $progress->complete($occurredAt);

        $this->activity->record(new ActivityPoint($event->studentId, $event->taskId, ActivitySource::Other, $occurredAt));
        // закрытое задание сбрасывает сигналы застревания
        $this->stuck->check($event->studentId, $event->taskId, $event->metadata());
    }
}
