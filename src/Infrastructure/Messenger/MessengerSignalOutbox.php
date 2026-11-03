<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Application\Port\SignalOutbox;
use App\Application\Signal\OutgoingSignal;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * OutgoingSignal маршрутизирован в Doctrine-транспорт outbox: INSERT в messenger_messages идёт через то же
 * соединение и попадает в текущую транзакцию (ADR 0005, п. 3).
 */
final readonly class MessengerSignalOutbox implements SignalOutbox
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function put(OutgoingSignal $signal): void
    {
        $this->bus->dispatch($signal);
    }
}
