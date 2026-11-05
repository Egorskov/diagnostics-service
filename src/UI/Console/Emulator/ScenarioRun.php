<?php

declare(strict_types=1);

namespace App\UI\Console\Emulator;

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

    private Randomizer $random;
    private string $studentId;
    private string $mentorId;
    private string $pathId;
    private \DateTimeImmutable $startedAt;

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
    /** @var list<EmulatedEvent> */
    private array $events = [];

    public function __construct(
        private readonly Scenario $scenario,
        private readonly \DateTimeImmutable $now,
        private readonly \DateTimeZone $zone,
    ) {
        $this->random = new Randomizer(new Mt19937($scenario->seed));
    }

    public function run(): EmulatedStream
    {
        $this->studentId = $this->scenario->studentId ?? $this->uuid();
        $this->mentorId = $this->scenario->mentorId ?? $this->uuid();
        $this->pathId = $this->uuid();
        $today = $this->now->setTimezone($this->zone)->setTime(0, 0);
        $this->startedAt = $today->modify(\sprintf('-%d days', $this->scenario->days))->setTime(9, 0);

        $this->assignPath();
        for ($day = $this->startedAt->setTime(0, 0); $day <= $today; $day = $day->modify('+1 day')) {
            $this->session($day->setTime(self::SESSION_HOUR, 0), self::SESSION_MINUTES);
        }

        $events = $this->events;
        // стабильная сортировка: при равном времени task.started остаётся раньше heartbeat
        usort($events, static fn (EmulatedEvent $a, EmulatedEvent $b): int => $a->occurredAt <=> $b->occurredAt);

        return new EmulatedStream($this->studentId, $this->mentorId, $this->pathId, $events);
    }

    private function assignPath(): void
    {
        // заданий больше, чем ученик успеет закрыть: иначе после конца пути он «пропадёт» и станет неактивным
        [$lowFactor] = self::COMPLETION_FACTOR;
        $minTaskMinutes = (int) ceil(120 * $lowFactor);
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
            $this->targets[$task['id']] = (int) ceil($task['estimate'] * $this->factor(...self::COMPLETION_FACTOR));
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

        $date = $start->format('Y-m-d');
        foreach ($logged as $taskId => $taskMinutes) {
            $this->logTime($last, $date, $taskMinutes, 'theory' === $this->kind($taskId) ? 'theory' : 'practice', $taskId);
        }
        if (5 === (int) $start->format('N')) {
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
        } elseif ($sandbox && 0 === $active % self::FAILED_RUN_EVERY_MINUTES) {
            $this->sandboxRun($id, $at, 'failed');
        }
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

    private function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
