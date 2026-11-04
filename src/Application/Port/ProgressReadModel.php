<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Metrics\ActivityInterval;
use App\Domain\Metrics\PathPlan;
use App\Domain\Progress\TimeCategory;
use App\Domain\Signal\SignalType;

/**
 * Данные Read-API (design, решение 4). Идентификатор не в формате UUID — такого ученика или ментора нет.
 */
interface ProgressReadModel
{
    public function currentPath(string $studentId): ?CurrentPath;

    /**
     * Текущие пути, которые ведёт ментор; бывшие ученики сюда не попадают.
     *
     * @return list<CurrentPath> по student_id
     */
    public function currentPathsOfMentor(string $mentorId): array;

    /**
     * Текущий состав пути с закрытием и списанным временем по каждому заданию.
     */
    public function pathPlan(CurrentPath $path): PathPlan;

    /**
     * Все сессии ученика, с заданием и без.
     *
     * @return list<ActivityInterval>
     */
    public function sessions(string $studentId): array;

    /**
     * Списания ученика за даты включительно, с заданием и без.
     *
     * @return array<value-of<TimeCategory>, int> категории без списаний отсутствуют
     */
    public function loggedByCategory(string $studentId, \DateTimeImmutable $fromDate, \DateTimeImmutable $toDate): array;

    /**
     * Типы с активной записью signal_state ученика для любой аудитории; weekly_goal.behind — только за неделю $weekSubject.
     *
     * @return list<SignalType> без повторов, по значению типа
     */
    public function activeSignalTypes(string $studentId, string $weekSubject): array;
}
