<?php

declare(strict_types=1);

namespace App\Application\Signal;

use App\Application\Port\SignalPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Relay outbox → брокер (ADR 0005, п. 3): ошибка публикации уходит в ретраи транспорта outbox.
 */
#[AsMessageHandler]
final readonly class RelaySignalHandler
{
    public function __construct(private SignalPublisher $publisher)
    {
    }

    public function __invoke(OutgoingSignal $signal): void
    {
        $this->publisher->publish($signal);
    }
}
