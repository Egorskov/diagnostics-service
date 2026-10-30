<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Application\Event\IncomingEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Алерт в лог о сообщении, которое уходит в failure transport (SPEC §9 «Ретраи»).
 *
 * Слушает после штатного SendFailedMessageForRetryListener (priority 100), когда уже
 * известно, будет ли ретрай. Промежуточные неудачи не логируются: их видно по счётчику попыток.
 */
#[AsEventListener]
final readonly class FailedMessageLogger
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        $context = match (true) {
            $message instanceof IncomingEvent => [
                'event_id' => $message->metadata()->eventId,
                'event_type' => $message->metadata()->eventType,
                'correlation_id' => $message->metadata()->correlationId,
            ],
            $message instanceof UndecodableMessage => [
                'event_id' => $message->eventId,
                'event_type' => $message->eventType,
                'correlation_id' => $message->correlationId,
                'decode_failure' => $message->reason->value,
            ],
            default => ['message_class' => $message::class],
        };

        $this->logger->error('Message moved to failure transport', $context + [
            'transport' => $event->getReceiverName(),
            'error' => self::errorOf($event->getThrowable()),
        ]);
    }

    /**
     * HandlerFailedException — обёртка Messenger; в лог нужны исключения хендлеров.
     */
    private static function errorOf(\Throwable $throwable): string
    {
        if (!$throwable instanceof HandlerFailedException) {
            return $throwable->getMessage();
        }

        return implode('; ', array_map(
            static fn (\Throwable $e): string => $e->getMessage(),
            $throwable->getWrappedExceptions(),
        ));
    }
}
