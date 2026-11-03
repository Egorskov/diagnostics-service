<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\InvalidThreshold;
use App\Application\Port\Thresholds;

final readonly class FixedThresholds implements Thresholds
{
    public function __construct(private ?int $idleTimeoutMinutes)
    {
    }

    public function idleTimeoutMinutes(): int
    {
        return $this->idleTimeoutMinutes ?? throw new InvalidThreshold('Threshold is not set');
    }
}
