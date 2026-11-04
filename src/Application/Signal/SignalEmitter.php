<?php

declare(strict_types=1);

namespace App\Application\Signal;

use App\Application\Event\EventMetadata;
use App\Application\Port\SignalOutbox;
use App\Application\Port\SignalStateRepository;
use App\Domain\Signal\SignalKey;
use App\Domain\Signal\SignalState;

/**
 * Состояние сигнала и запись в outbox — в транзакции вызывающего хендлера (SPEC §7.3, §9; ADR 0005).
 */
final readonly class SignalEmitter
{
    public function __construct(
        private SignalStateRepository $states,
        private SignalOutbox $outbox,
    ) {
    }

    /**
     * @param callable(): array<string, mixed> $payload строится только при публикации
     *
     * @return bool опубликован ли сигнал
     */
    public function observe(SignalKey $key, bool $condition, \DateTimeImmutable $now, callable $payload, ?EventMetadata $cause = null): bool
    {
        $state = $this->states->find($key);
        if (null === $state) {
            // строка состояния появляется только с первым сигналом
            if (!$condition) {
                return false;
            }
            $state = new SignalState($key);
            $this->states->add($state);
        }

        if (!$state->observe($condition, $now)) {
            return false;
        }

        $this->outbox->put(OutgoingSignal::create($key->type, $payload(), $now, $cause));

        return true;
    }
}
