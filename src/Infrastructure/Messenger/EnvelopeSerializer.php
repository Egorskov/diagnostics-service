<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Application\Event\EventMetadata;
use App\Application\Event\InvalidPayload;
use App\Application\Event\PayloadReader;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Сериализатор входящих AMQP-транспортов: JSON-конверт (asyncapi.yaml → Envelope) ⇄ DTO события.
 *
 * decode() никогда не бросает: неразбираемое сообщение превращается в UndecodableMessage,
 * иначе AMQP-receiver отклонил бы его мимо failure transport (ADR 0002, п. 3).
 */
final readonly class EnvelopeSerializer implements SerializerInterface
{
    public const RETRY_COUNT_HEADER = 'X-Retry-Count';
    public const REDELIVERED_AT_HEADER = 'X-Redelivered-At';

    public function __construct(
        private EventTypeRegistry $registry,
    ) {
    }

    /**
     * @param array{body?: mixed, headers?: mixed} $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        $body = \is_string($encodedEnvelope['body'] ?? null) ? $encodedEnvelope['body'] : '';
        /** @var array<mixed> $headers */
        $headers = \is_array($encodedEnvelope['headers'] ?? null) ? $encodedEnvelope['headers'] : [];

        return new Envelope($this->decodeMessage($body), [new RawBodyStamp($body), ...$this->redeliveryStamp($headers)]);
    }

    /**
     * @return array{body: string, headers: array<string, string>}
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        $body = match (true) {
            null !== $envelope->last(RawBodyStamp::class) => $envelope->last(RawBodyStamp::class)->body,
            $message instanceof UndecodableMessage => $message->rawBody,
            default => throw new \LogicException(\sprintf('%s encodes only messages received from the broker, got %s', self::class, $message::class)),
        };

        $headers = ['Content-Type' => 'application/json'];
        $redelivery = $envelope->last(RedeliveryStamp::class);
        if (null !== $redelivery) {
            $headers[self::RETRY_COUNT_HEADER] = (string) $redelivery->getRetryCount();
            $headers[self::REDELIVERED_AT_HEADER] = $redelivery->getRedeliveredAt()->format(\DATE_RFC3339_EXTENDED);
        }

        return ['body' => $body, 'headers' => $headers];
    }

    private function decodeMessage(string $body): object
    {
        try {
            $data = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new UndecodableMessage($body, DecodeFailure::InvalidJson, $e->getMessage());
        }
        if (!\is_array($data) || array_is_list($data)) {
            return new UndecodableMessage($body, DecodeFailure::InvalidJson, 'Body must be a JSON object');
        }

        $undecodable = fn (DecodeFailure $reason, string $detail): UndecodableMessage => new UndecodableMessage(
            $body,
            $reason,
            $detail,
            eventId: self::stringOrNull($data['event_id'] ?? null),
            eventType: self::stringOrNull($data['event_type'] ?? null),
            correlationId: self::stringOrNull($data['correlation_id'] ?? null),
        );

        try {
            $metadata = EventMetadata::fromEnvelope($data);
            $payload = (new PayloadReader($data))->object('payload');
        } catch (InvalidPayload $e) {
            return $undecodable(DecodeFailure::InvalidEnvelope, $e->getMessage());
        }

        try {
            $class = $this->registry->resolve($metadata->eventType, $metadata->eventVersion);
        } catch (UnsupportedEvent $e) {
            return $undecodable($e->reason, $e->getMessage());
        }

        try {
            return $class::fromPayload($metadata, $payload);
        } catch (InvalidPayload $e) {
            return $undecodable(DecodeFailure::InvalidPayload, $e->getMessage());
        }
    }

    /**
     * Заголовки приходят от чужих сервисов: читаем только число и дату, ничего не десериализуем.
     *
     * @param array<mixed> $headers
     *
     * @return list<RedeliveryStamp>
     */
    private function redeliveryStamp(array $headers): array
    {
        $count = $headers[self::RETRY_COUNT_HEADER] ?? null;
        if (!\is_string($count) || !ctype_digit($count)) {
            return [];
        }
        $redeliveredAt = \is_string($headers[self::REDELIVERED_AT_HEADER] ?? null)
            ? \DateTimeImmutable::createFromFormat(\DATE_RFC3339_EXTENDED, $headers[self::REDELIVERED_AT_HEADER])
            : false;

        return [new RedeliveryStamp((int) $count, false === $redeliveredAt ? null : $redeliveredAt)];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
