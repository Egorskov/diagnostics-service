<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Application\Port\SignalPublisher;
use App\Application\Signal\OutgoingSignal;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Прямо в транспорт signals, мимо шины: иначе маршрутизация вернула бы сигнал в outbox.
 */
final readonly class AmqpSignalPublisher implements SignalPublisher
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.signals')]
        private SenderInterface $signals,
    ) {
    }

    public function publish(OutgoingSignal $signal): void
    {
        // routing key = event_type (ADR 0005, п. 4)
        $this->signals->send(new Envelope($signal, [new AmqpStamp($signal->type->value)]));
    }
}
