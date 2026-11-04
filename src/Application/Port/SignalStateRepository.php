<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Signal\Audience;
use App\Domain\Signal\SignalKey;
use App\Domain\Signal\SignalState;
use App\Domain\Signal\SignalType;

interface SignalStateRepository
{
    public function find(SignalKey $key): ?SignalState;

    public function add(SignalState $state): void;

    /**
     * Активные состояния, опубликованные не позже $before, — для эскалаций.
     *
     * @return list<SignalState>
     */
    public function activeEmittedBefore(SignalType $type, Audience $audience, \DateTimeImmutable $before): array;
}
