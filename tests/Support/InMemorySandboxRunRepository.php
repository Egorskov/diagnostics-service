<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\SandboxRunRepository;
use App\Domain\Progress\SandboxRun;

final class InMemorySandboxRunRepository implements SandboxRunRepository
{
    /** @var array<string, SandboxRun> */
    public array $runs = [];

    public function find(string $runId): ?SandboxRun
    {
        return $this->runs[$runId] ?? null;
    }

    public function add(SandboxRun $run): void
    {
        $this->runs[$run->runId()] = $run;
    }
}
