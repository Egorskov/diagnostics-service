<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\SignalStateRepository;
use App\Domain\Signal\Audience;
use App\Domain\Signal\SignalKey;
use App\Domain\Signal\SignalState;
use App\Domain\Signal\SignalType;

final class InMemorySignalStateRepository implements SignalStateRepository
{
    /** @var array<string, SignalState> */
    public array $states = [];

    public function find(SignalKey $key): ?SignalState
    {
        return $this->states[$key->toString()] ?? null;
    }

    public function add(SignalState $state): void
    {
        $this->states[$state->id()] = $state;
    }

    public function activeEmittedBefore(SignalType $type, Audience $audience, \DateTimeImmutable $before): array
    {
        return array_values(array_filter($this->states, static fn (SignalState $state) => $state->key()->type === $type
            && $state->key()->audience === $audience
            && $state->isActive()
            && $state->lastEmittedAt() <= $before));
    }
}
