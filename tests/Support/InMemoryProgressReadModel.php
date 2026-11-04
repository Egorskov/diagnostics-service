<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\CurrentPath;
use App\Application\Port\ProgressReadModel;
use App\Domain\Metrics\ActivityInterval;
use App\Domain\Metrics\PathPlan;
use App\Domain\Signal\SignalType;

final class InMemoryProgressReadModel implements ProgressReadModel
{
    /** @var list<CurrentPath> все пути, не только текущие */
    public array $paths = [];
    /** @var array<string, PathPlan> path_id => план */
    public array $plans = [];
    /** @var array<string, list<ActivityInterval>> */
    public array $sessions = [];
    /** @var list<array{string, string, 'theory'|'practice'|'interview', int}> ученик, дата, категория, минуты */
    public array $logs = [];
    /** @var array<string, list<SignalType>> ученик => активные типы */
    public array $signals = [];
    /** @var list<string> с какой неделей запрашивались сигналы */
    public array $weekSubjects = [];

    public function currentPath(string $studentId): ?CurrentPath
    {
        $current = null;
        foreach ($this->paths as $path) {
            if ($path->studentId === $studentId && (null === $current || [$path->startedAt, $path->pathId] > [$current->startedAt, $current->pathId])) {
                $current = $path;
            }
        }

        return $current;
    }

    public function currentPathsOfMentor(string $mentorId): array
    {
        $paths = [];
        foreach (array_unique(array_map(static fn (CurrentPath $path): string => $path->studentId, $this->paths)) as $studentId) {
            $path = $this->currentPath($studentId);
            if (null !== $path && $path->mentorId === $mentorId) {
                $paths[$studentId] = $path;
            }
        }
        ksort($paths);

        return array_values($paths);
    }

    public function pathPlan(CurrentPath $path): PathPlan
    {
        return $this->plans[$path->pathId] ?? new PathPlan($path->startedAt, $path->weeklyGoalMinutes, []);
    }

    public function sessions(string $studentId): array
    {
        return $this->sessions[$studentId] ?? [];
    }

    public function loggedByCategory(string $studentId, \DateTimeImmutable $fromDate, \DateTimeImmutable $toDate): array
    {
        $logged = [];
        foreach ($this->logs as [$student, $date, $category, $minutes]) {
            if ($student === $studentId && $date >= $fromDate->format('Y-m-d') && $date <= $toDate->format('Y-m-d')) {
                $logged[$category] = ($logged[$category] ?? 0) + $minutes;
            }
        }

        return $logged;
    }

    public function activeSignalTypes(string $studentId, string $weekSubject): array
    {
        $this->weekSubjects[] = $weekSubject;

        return $this->signals[$studentId] ?? [];
    }
}
