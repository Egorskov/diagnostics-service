<?php

declare(strict_types=1);

namespace App\UI\Console\Emulator;

use App\Infrastructure\Messenger\RawBodyStamp;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Прямо в sender входящего транспорта, мимо шины (design, решение 2): exchange берётся из конфига
 * транспорта, тело — из RawBodyStamp через EnvelopeSerializer::encode(), routing key = event_type.
 */
final readonly class MessengerEventPublisher
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.courses')]
        private SenderInterface $courses,
        #[Autowire(service: 'messenger.transport.time')]
        private SenderInterface $time,
        #[Autowire(service: 'messenger.transport.heartbeat')]
        private SenderInterface $heartbeat,
    ) {
    }

    public function publish(EmulatedEvent $event): void
    {
        $sender = match ($event->transport) {
            'courses' => $this->courses,
            'time' => $this->time,
            'heartbeat' => $this->heartbeat,
            default => throw new \LogicException(\sprintf('Unknown transport %s', $event->transport)),
        };
        $sender->send(new Envelope($event, [new RawBodyStamp($event->json()), new AmqpStamp($event->type)]));
    }
}
