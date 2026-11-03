<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\ActivitySessionRepository;
use App\Domain\Progress\ActivitySession;

final class InMemoryActivitySessionRepository implements ActivitySessionRepository
{
    /** @var list<ActivitySession> */
    public array $sessions = [];

    public function forTask(string $studentId, string $taskId): array
    {
        return array_values(array_filter(
            $this->sessions,
            static fn (ActivitySession $session) => $session->studentId() === $studentId && $session->taskId() === $taskId,
        ));
    }

    public function withoutTaskNear(string $studentId, \DateTimeImmutable $at, int $idleMinutes): array
    {
        return array_values(array_filter(
            $this->sessions,
            static fn (ActivitySession $session) => $session->studentId() === $studentId && null === $session->taskId() && $session->covers($at, $idleMinutes),
        ));
    }

    public function add(ActivitySession $session): void
    {
        $this->sessions[] = $session;
    }
}
