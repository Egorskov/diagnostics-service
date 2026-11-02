<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Progress\StudentPath;

interface StudentPathRepository
{
    public function find(string $studentId, string $pathId): ?StudentPath;

    public function add(StudentPath $path): void;
}
