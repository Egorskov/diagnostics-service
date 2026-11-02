<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Event\Incoming\PathAssigned;
use App\Application\Port\PathTaskRepository;
use App\Application\Port\StudentPathRepository;
use App\Application\Port\TaskProgressRepository;
use App\Domain\Progress\PathTask;
use App\Domain\Progress\StudentPath;
use App\Domain\Progress\TaskProgress;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Снапшот полный и заменяет прежний состав пути. Путь — план, прогресс — факт:
 * существующие прогрессы не меняются и не удаляются (design change add-event-consumers, п. 5).
 */
#[AsMessageHandler]
final readonly class PathAssignedHandler
{
    public function __construct(
        private StudentPathRepository $paths,
        private PathTaskRepository $tasks,
        private TaskProgressRepository $progress,
    ) {
    }

    public function __invoke(PathAssigned $event): void
    {
        $this->savePath($event);
        $this->replaceTasks($event);

        foreach ($event->tasks as $task) {
            if (null === $this->progress->findForUpdate($event->studentId, $task->taskId)) {
                $this->progress->add(new TaskProgress($event->studentId, $task->taskId, $event->pathId));
            }
        }
    }

    private function savePath(PathAssigned $event): void
    {
        $path = $this->paths->find($event->studentId, $event->pathId);
        if (null === $path) {
            $this->paths->add(new StudentPath($event->studentId, $event->pathId, $event->mentorId, $event->startedAt, $event->weeklyGoalMinutes));

            return;
        }

        $path->updateFromSnapshot($event->mentorId, $event->startedAt, $event->weeklyGoalMinutes);
    }

    private function replaceTasks(PathAssigned $event): void
    {
        $stale = [];
        foreach ($this->tasks->forPath($event->pathId) as $task) {
            $stale[$task->taskId()] = $task;
        }

        foreach ($event->tasks as $task) {
            $existing = $stale[$task->taskId] ?? null;
            if (null === $existing) {
                $this->tasks->add(new PathTask($event->pathId, $task->taskId, $task->stageId, $task->estimateMinutes, $task->position));
                continue;
            }

            $existing->updateFromSnapshot($task->stageId, $task->estimateMinutes, $task->position);
            unset($stale[$task->taskId]);
        }

        foreach ($stale as $task) {
            $this->tasks->remove($task);
        }
    }
}
