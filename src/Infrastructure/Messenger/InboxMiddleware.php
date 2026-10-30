<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Application\Event\IncomingEvent;
use App\Infrastructure\Persistence\Doctrine\DbalInbox;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Идемпотентность входящих событий по event_id (SPEC §5, §9; ADR 0002, п. 6).
 *
 * Стоит в шине после doctrine_transaction: отметка в inbox_event и изменения хендлера
 * коммитятся вместе, а при ошибке хендлера откатываются вместе — ретрай обработает событие заново.
 */
final readonly class InboxMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DbalInbox $inbox,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $event = $envelope->getMessage();
        // только события из брокера: собственные dispatch и UndecodableMessage идут мимо
        if (!$event instanceof IncomingEvent || null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $metadata = $event->metadata();
        if (!$this->inbox->markProcessed($metadata)) {
            $this->logger->info('Duplicate event skipped', [
                'event_id' => $metadata->eventId,
                'event_type' => $metadata->eventType,
                'correlation_id' => $metadata->correlationId,
            ]);

            return $envelope;
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
