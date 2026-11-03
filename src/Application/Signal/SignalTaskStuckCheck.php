<?php

declare(strict_types=1);

namespace App\Application\Signal;

use App\Application\Event\EventMetadata;
use App\Application\Port\PathTaskRepository;
use App\Application\Port\StudentPathRepository;
use App\Application\Port\TaskProgressRepository;
use App\Application\Port\TaskStuckCheck;
use App\Application\Port\Thresholds;
use App\Domain\Progress\PathTask;
use App\Domain\Progress\TaskProgress;
use App\Domain\Progress\TaskStatus;
use App\Domain\Signal\Audience;
use App\Domain\Signal\SignalKey;
use App\Domain\Signal\SignalType;
use App\Domain\Signal\StuckReason;
use App\Domain\Signal\TaskStuck;
use Psr\Clock\ClockInterface;

/**
 * task.stuck: сигнал ученику на событиях и эскалация ментору по расписанию (SPEC §7.2; design, решения 4–5).
 */
final readonly class SignalTaskStuckCheck implements TaskStuckCheck
{
    public function __construct(
        private TaskProgressRepository $progress,
        private PathTaskRepository $pathTasks,
        private StudentPathRepository $paths,
        private Thresholds $thresholds,
        private SignalEmitter $emitter,
        private ClockInterface $clock,
    ) {
    }

    public function check(string $studentId, string $taskId, EventMetadata $cause): void
    {
        // прогресс уже заблокирован хендлером в этой транзакции и берётся из identity map
        $progress = $this->progress->findForUpdate($studentId, $taskId);
        if (null === $progress) {
            return;
        }

        $now = $this->clock->now();
        $reason = $this->reason($progress);
        $this->emitter->observe(
            self::key(Audience::Student, $progress),
            null !== $reason,
            $now,
            fn (): array => $this->payload(Audience::Student, $progress, $reason),
            $cause,
        );
        if (null === $reason) {
            // эскалация ментору — только из расписания, здесь её можно лишь сбросить
            $this->emitter->observe(self::key(Audience::Mentor, $progress), false, $now, static fn (): array => []);
        }
    }

    /**
     * Эскалация ментору по активному сигналу ученику; если застревания уже нет, оба состояния сбрасываются.
     */
    public function escalate(string $studentId, string $taskId): void
    {
        // блокировка не даёт разминуться с параллельным task.completed
        $progress = $this->progress->findForUpdate($studentId, $taskId);
        $reason = null === $progress ? null : $this->reason($progress);
        $now = $this->clock->now();

        if (null === $progress || null === $reason) {
            foreach ([Audience::Student, Audience::Mentor] as $audience) {
                $this->emitter->observe(new SignalKey(SignalType::TaskStuck, $audience, $studentId, $taskId), false, $now, static fn (): array => []);
            }

            return;
        }

        $this->emitter->observe(
            self::key(Audience::Mentor, $progress),
            true,
            $now,
            fn (): array => $this->payload(Audience::Mentor, $progress, $reason),
        );
    }

    private function reason(TaskProgress $progress): ?StuckReason
    {
        // закрытое задание не застряло, пороги для этого не нужны
        if (TaskStatus::Completed === $progress->status()) {
            return null;
        }

        return TaskStuck::reason(
            false,
            $progress->activeMinutes(),
            $this->pathTask($progress)?->estimateMinutes(),
            $progress->failedRuns(),
            $this->thresholds->stuckTimeRatio(),
            $this->thresholds->stuckFailedRuns(),
        );
    }

    private function pathTask(TaskProgress $progress): ?PathTask
    {
        foreach ($this->pathTasks->forPath($progress->pathId()) as $task) {
            if ($task->taskId() === $progress->taskId()) {
                return $task;
            }
        }

        return null;
    }

    private static function key(Audience $audience, TaskProgress $progress): SignalKey
    {
        return new SignalKey(SignalType::TaskStuck, $audience, $progress->studentId(), $progress->taskId());
    }

    /**
     * asyncapi.yaml → TaskStuck.
     *
     * @return array<string, mixed>
     */
    private function payload(Audience $audience, TaskProgress $progress, ?StuckReason $reason): array
    {
        $payload = ['audience' => $audience->value, 'student_id' => $progress->studentId()];
        $mentorId = $this->paths->find($progress->studentId(), $progress->pathId())?->mentorId();
        if (null !== $mentorId) {
            $payload['mentor_id'] = $mentorId;
        }
        $payload += [
            'task_id' => $progress->taskId(),
            'reason' => $reason?->value,
            'active_minutes' => $progress->activeMinutes(),
        ];
        $estimate = $this->pathTask($progress)?->estimateMinutes();
        if (null !== $estimate) {
            $payload['estimate_minutes'] = $estimate;
        }
        $payload['failed_runs'] = $progress->failedRuns();

        return $payload;
    }
}
