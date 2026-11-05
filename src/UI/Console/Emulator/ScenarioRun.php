<?php

declare(strict_types=1);

namespace App\UI\Console\Emulator;

use App\Application\Port\Thresholds;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

/**
 * Один прогон генератора: изменяемое состояние симуляции живёт только здесь.
 *
 * Время событий кратно минуте, heartbeat — раз в 60 с (SPEC §4), поэтому активное время задания
 * равно числу его heartbeat: сессия из k heartbeat подряд длится k минут (SPEC §6.1, п. 4).
 *
 * @internal
 */
final class ScenarioRun
{
    public const WEEKLY_GOAL_MINUTES = 600;
    private const TASKS_PER_STAGE = 3;
    private const SESSION_HOUR = 18;
    private const SESSION_MINUTES = 120;
    private const FAILED_RUN_EVERY_MINUTES = 30;
    private const INTERVIEW_MINUTES = 30;
    private const COMPLETION_FACTOR = [0.8, 1.2];
    /** Запас над порогом: условие сигнала выполняется не «впритык» */
    private const MARGIN_MINUTES = 15;
    private const NEVER = \PHP_INT_MAX;

    private Randomizer $random;
    private string $studentId;
    private string $mentorId;
    private string $pathId;
    private \DateTimeImmutable $startedAt;
    private ?\DateTimeImmutable $lastActivity = null;
    private float $lowFactor;
    private float $highFactor;

    /** @var list<array{id: string, stage: string, kind: string, estimate: int}> */
    private array $tasks = [];
    /** @var array<string, int> задание → активных минут, при которых оно закрывается */
    private array $targets = [];
    /** @var array<string, int> */
    private array $active = [];
    /** @var array<string, true> */
    private array $started = [];
    /** @var array<string, true> */
    private array $completed = [];
    /** @var array<string, int> */
    private array $failedRuns = [];
    /** @var list<EmulatedEvent> */
    private array $events = [];

    public function __construct(
        private readonly Scenario $scenario,
        private readonly \DateTimeImmutable $now,
        private readonly \DateTimeZone $zone,
        private readonly Thresholds $thresholds,
    ) {
        $this->random = new Randomizer(new Mt19937($scenario->seed));
        // закрытие задания не позже 0,9 × stuck_time_ratio: steady не должен застревать (design, решение 4)
        $this->highFactor = min(self::COMPLETION_FACTOR[1], 0.9 * $thresholds->stuckTimeRatio());
        $this->lowFactor = min(self::COMPLETION_FACTOR[0], $this->highFactor);
    }

    public function run(): EmulatedStream
    {
        $profile = $this->scenario->profile;
        $pause = $this->thresholds->inactiveStudentDays() + 1;
        if (Profile::Inactive === $profile && $this->scenario->days <= $pause) {
            throw new \InvalidArgumentException(\sprintf('Profile inactive needs --days of at least %d (inactive_student_days + 2)', $pause + 1));
        }

        $this->studentId = $this->scenario->studentId ?? $this->uuid();
        $this->mentorId = $this->scenario->mentorId ?? $this->uuid();
        $this->pathId = $this->uuid();
        $today = $this->now->setTimezone($this->zone)->setTime(0, 0);
        $this->startedAt = $today->modify(\sprintf('-%d days', $this->scenario->days))->setTime(9, 0);
        $lastStudyDay = Profile::Inactive === $profile ? $today->modify(\sprintf('-%d days', $pause)) : $today;

        $this->assignPath();
        $sessions = [];
        for ($day = $this->startedAt->setTime(0, 0); $day <= $lastStudyDay; $day = $day->modify('+1 day')) {
            if ($day->setTime(self::SESSION_HOUR, 0) <= $this->now) {
                $sessions[] = $day->setTime(self::SESSION_HOUR, 0);
            }
        }
        $last = array_key_last($sessions);
        foreach ($sessions as $i => $start) {
            match (true) {
                Profile::Stuck === $profile && $i === $last => $this->stuckSession($start),
                Profile::Behind === $profile => $this->session($start, $this->behindMinutes()),
                default => $this->session($start, self::SESSION_MINUTES),
            };
        }
        if (Profile::Steady === $profile) {
            $this->keepWeeklyGoal();
        }

        $events = $this->events;
        // стабильная сортировка: при равном времени task.started остаётся раньше heartbeat
        usort($events, static fn (EmulatedEvent $a, EmulatedEvent $b): int => $a->occurredAt <=> $b->occurredAt);

        return new EmulatedStream($this->studentId, $this->mentorId, $this->pathId, $events);
    }

    /**
     * Последняя сессия stuck: текущее задание не закрывается, активное время доводится выше
     * stuck_time_ratio × оценки; сессия сдвигается раньше, чтобы закончиться не позже $now.
     */
    private function stuckSession(\DateTimeImmutable $planned): void
    {
        $task = $this->currentTask() ?? throw new \LogicException('Path has no open task');
        $id = $task['id'];
        $this->targets[$id] = self::NEVER;
        $need = (int) floor($this->thresholds->stuckTimeRatio() * $task['estimate']) + 1 + self::MARGIN_MINUTES - $this->active[$id];
        $minutes = max(self::SESSION_MINUTES, $need);

        $latest = $this->currentMinute()->modify(\sprintf('-%d minutes', $minutes - 1));
        $start = min($planned, $latest);
        $earliest = null === $this->lastActivity ? $this->startedAt : $this->lastActivity->modify('+1 minute');
        if ($start < $earliest) {
            throw new \InvalidArgumentException(\sprintf('Profile stuck needs %d minutes of work before now: increase --days', $minutes));
        }
        $this->session($start, $minutes);
    }

    /**
     * Минут в день у behind: за 7 дней меньше weekly_goal_min_ratio × недельной цели.
     */
    private function behindMinutes(): int
    {
        $limit = (int) ceil($this->thresholds->weeklyGoalMinRatio() * self::WEEKLY_GOAL_MINUTES) - 1;

        return max(1, min(30, intdiv($limit, 7)));
    }

    /**
     * WeeklyGoalCheck смотрит списанное за неделю каждый час после своего момента: steady после него
     * не должен оказаться ниже порога, даже если путь начался на этой неделе (design, решение 4).
     */
    private function keepWeeklyGoal(): void
    {
        $moment = $this->thresholds->weeklyGoalCheck();
        if (!$moment->hasPassed($this->now)) {
            return;
        }
        $from = $moment->weekStart($this->now)->format('Y-m-d');
        $to = $moment->weekStart($this->now)->modify('+6 days')->format('Y-m-d');
        $logged = 0;
        foreach ($this->events as $event) {
            $date = $event->payload['logged_for_date'] ?? null;
            if ('time.logged' === $event->type && \is_string($date) && $date >= $from && $date <= $to && \is_int($event->payload['minutes'])) {
                $logged += $event->payload['minutes'];
            }
        }
        $need = (int) ceil($this->thresholds->weeklyGoalMinRatio() * self::WEEKLY_GOAL_MINUTES);
        if ($logged < $need) {
            $at = $this->currentMinute();
            $this->logTime($at, $at->format('Y-m-d'), $need - $logged + self::MARGIN_MINUTES * 2, 'practice', null);
        }
    }

    private function assignPath(): void
    {
        // заданий больше, чем ученик успеет закрыть: иначе после конца пути он «пропадёт» и станет неактивным
        $minTaskMinutes = (int) ceil(120 * $this->lowFactor);
        $count = max(6, (int) ceil(($this->scenario->days + 1) * self::SESSION_MINUTES / $minTaskMinutes) + 1);

        $stage = '';
        $payloadTasks = [];
        for ($position = 1; $position <= $count; ++$position) {
            if (1 === $position % self::TASKS_PER_STAGE) {
                $stage = $this->uuid();
            }
            $task = [
                'id' => $this->uuid(),
                'stage' => $stage,
                'kind' => 1 === $position % 2 ? 'theory' : 'sandbox',
                'estimate' => $this->random->getInt(4, 16) * 30,
            ];
            $this->tasks[] = $task;
            $this->targets[$task['id']] = (int) ceil($task['estimate'] * $this->factor($this->lowFactor, $this->highFactor));
            $this->active[$task['id']] = 0;
            $payloadTasks[] = [
                'task_id' => $task['id'],
                'stage_id' => $task['stage'],
                'estimate_minutes' => $task['estimate'],
                'position' => $position,
                'kind' => $task['kind'],
            ];
        }

        $this->emit('path.assigned', $this->startedAt, [
            'student_id' => $this->studentId,
            'path_id' => $this->pathId,
            'mentor_id' => $this->mentorId,
            'started_at' => $this->utc($this->startedAt),
            'weekly_goal_minutes' => self::WEEKLY_GOAL_MINUTES,
            'tasks' => $payloadTasks,
        ]);
    }

    /**
     * Сессия с $start длиной до $minutes минут, обрезанная текущим моментом; в конце — time.logged.
     */
    private function session(\DateTimeImmutable $start, int $minutes): void
    {
        /** @var array<string, int> $logged задание → минут в этой сессии */
        $logged = [];
        $last = null;
        for ($minute = 0; $minute < $minutes; ++$minute) {
            $at = $start->modify(\sprintf('+%d minutes', $minute));
            $task = $this->currentTask();
            if ($at > $this->now || null === $task) {
                break;
            }
            $this->work($task, $at);
            $logged[$task['id']] = ($logged[$task['id']] ?? 0) + 1;
            $last = $at;
        }
        if (null === $last) {
            return;
        }
        $this->lastActivity = $last;

        $date = $start->format('Y-m-d');
        foreach ($logged as $taskId => $taskMinutes) {
            $this->logTime($last, $date, $taskMinutes, 'theory' === $this->kind($taskId) ? 'theory' : 'practice', $taskId);
        }
        if (5 === (int) $start->format('N') && Profile::Behind !== $this->scenario->profile) {
            $this->logTime($last, $date, self::INTERVIEW_MINUTES, 'interview', null);
        }
    }

    /**
     * Минута работы над заданием: heartbeat, при необходимости старт, запуски песочницы и закрытие.
     *
     * @param array{id: string, stage: string, kind: string, estimate: int} $task
     */
    private function work(array $task, \DateTimeImmutable $at): void
    {
        $id = $task['id'];
        $sandbox = 'sandbox' === $task['kind'];
        if (!isset($this->started[$id])) {
            $this->started[$id] = true;
            $this->emit('task.started', $at, ['student_id' => $this->studentId, 'path_id' => $this->pathId, 'task_id' => $id]);
        }
        $this->emit('activity.heartbeat', $at, ['student_id' => $this->studentId, 'task_id' => $id, 'source' => $sandbox ? 'sandbox' : 'theory']);
        $active = ++$this->active[$id];

        if ($active >= $this->targets[$id]) {
            if ($sandbox) {
                $this->sandboxRun($id, $at, 'passed');
            }
            $this->completed[$id] = true;
            $this->emit('task.completed', $at, ['student_id' => $this->studentId, 'path_id' => $this->pathId, 'task_id' => $id]);
        } elseif ($sandbox && 0 === $active % self::FAILED_RUN_EVERY_MINUTES && $this->mayFail($id)) {
            $this->failedRuns[$id] = ($this->failedRuns[$id] ?? 0) + 1;
            $this->sandboxRun($id, $at, 'failed');
        }
    }

    /**
     * Неудачных запусков меньше stuck_failed_runs: застревает только задание профиля stuck.
     */
    private function mayFail(string $taskId): bool
    {
        return self::NEVER === $this->targets[$taskId] || ($this->failedRuns[$taskId] ?? 0) < $this->thresholds->stuckFailedRuns() - 1;
    }

    private function sandboxRun(string $taskId, \DateTimeImmutable $at, string $result): void
    {
        $passed = 'passed' === $result;
        $payload = [
            'run_id' => $this->uuid(),
            'student_id' => $this->studentId,
            'task_id' => $taskId,
            'result' => $result,
            'tests_passed' => $passed ? 10 : $this->random->getInt(0, 9),
            'tests_total' => 10,
        ];
        if (!$passed) {
            $payload['error_type'] = 'AssertionError';
        }
        $this->emit('sandbox.run', $at, $payload);
    }

    private function logTime(\DateTimeImmutable $at, string $date, int $minutes, string $category, ?string $taskId): void
    {
        $payload = ['student_id' => $this->studentId];
        if (null !== $taskId) {
            $payload['task_id'] = $taskId;
        }
        $this->emit('time.logged', $at, $payload + ['minutes' => $minutes, 'category' => $category, 'logged_for_date' => $date]);
    }

    /**
     * @return array{id: string, stage: string, kind: string, estimate: int}|null первое незакрытое по position
     */
    private function currentTask(): ?array
    {
        foreach ($this->tasks as $task) {
            if (!isset($this->completed[$task['id']])) {
                return $task;
            }
        }

        return null;
    }

    private function kind(string $taskId): string
    {
        foreach ($this->tasks as $task) {
            if ($task['id'] === $taskId) {
                return $task['kind'];
            }
        }

        throw new \LogicException(\sprintf('Unknown task %s', $taskId));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emit(string $type, \DateTimeImmutable $at, array $payload): void
    {
        $this->events[] = new EmulatedEvent($this->uuid(), $type, $at, $payload);
    }

    private function factor(float $low, float $high): float
    {
        return $low + ($high - $low) * $this->random->nextFloat();
    }

    /**
     * UUID v4 из байт seed-генератора: тот же seed — те же id (design, решение 5).
     */
    private function uuid(): string
    {
        $bytes = $this->random->getBytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);

        return Uuid::fromBinary($bytes)->toRfc4122();
    }

    private function currentMinute(): \DateTimeImmutable
    {
        $now = $this->now->setTimezone($this->zone);

        return $now->setTime((int) $now->format('H'), (int) $now->format('i'));
    }

    private function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
