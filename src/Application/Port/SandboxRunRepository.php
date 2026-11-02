<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Progress\SandboxRun;

interface SandboxRunRepository
{
    public function find(string $runId): ?SandboxRun;

    public function add(SandboxRun $run): void;
}
