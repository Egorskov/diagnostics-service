<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Event\Incoming\SandboxRun as SandboxRunEvent;
use App\Application\Port\ActivityPoint;
use App\Application\Port\ActivityTracker;
use App\Application\Port\SandboxRunRepository;
use App\Application\Port\TaskProgressRepository;
use App\Domain\Progress\ActivitySource;
use App\Domain\Progress\SandboxRun;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SandboxRunHandler
{
    public function __construct(
        private SandboxRunRepository $runs,
        private TaskProgressRepository $progress,
        private ActivityTracker $activity,
    ) {
    }

    /**
     * @throws UnknownTaskProgress в событии нет path_id, поэтому создать прогресс из него нельзя
     */
    public function __invoke(SandboxRunEvent $event): void
    {
        // inbox ловит повтор того же event_id, а это другое событие про тот же запуск
        if (null !== $this->runs->find($event->runId)) {
            return;
        }

        $progress = $this->progress->findForUpdate($event->studentId, $event->taskId)
            ?? throw new UnknownTaskProgress($event->studentId, $event->taskId);

        $occurredAt = $event->metadata()->occurredAt;
        $run = new SandboxRun($event->runId, $event->studentId, $event->taskId, $event->result, $event->errorType, $occurredAt);
        $this->runs->add($run);
        if ($run->isFailed()) {
            $progress->recordFailedRun();
        }

        $this->activity->record(new ActivityPoint($event->studentId, $event->taskId, ActivitySource::Sandbox, $occurredAt));
    }
}
