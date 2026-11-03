<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Progress\ActivitySession;

interface ActivitySessionRepository
{
    /**
     * Все сессии ученика по заданию: из них считается активное время.
     *
     * @return list<ActivitySession>
     */
    public function forTask(string $studentId, string $taskId): array;

    /**
     * Сессии без задания, в окно которых попадает момент: всех таких сессий у ученика со временем много.
     *
     * @return list<ActivitySession>
     */
    public function withoutTaskNear(string $studentId, \DateTimeImmutable $at, int $idleMinutes): array;

    public function add(ActivitySession $session): void;
}
