<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\StudentPathRepository;
use App\Domain\Progress\StudentPath;

final class InMemoryStudentPathRepository implements StudentPathRepository
{
    /** @var array<string, StudentPath> */
    public array $paths = [];

    public function find(string $studentId, string $pathId): ?StudentPath
    {
        return $this->paths[$studentId.'|'.$pathId] ?? null;
    }

    public function add(StudentPath $path): void
    {
        $this->paths[$path->studentId().'|'.$path->pathId()] = $path;
    }
}
