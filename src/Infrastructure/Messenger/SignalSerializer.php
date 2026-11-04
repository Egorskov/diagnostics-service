<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Application\Signal\OutgoingSignal;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Тело сообщения в diagnostics.events — JSON конверта asyncapi.yaml → Envelope, без служебных заголовков Messenger.
 */
final readonly class SignalSerializer implements SerializerInterface
{
    public function decode(array $encodedEnvelope): Envelope
    {
        throw new \LogicException('Diagnostics does not consume its own signals');
    }

    public function encode(Envelope $envelope): array
    {
        $signal = $envelope->getMessage();
        if (!$signal instanceof OutgoingSignal) {
            throw new \LogicException(\sprintf('%s encodes only %s, got %s', self::class, OutgoingSignal::class, $signal::class));
        }

        return [
            'body' => json_encode($signal->envelope(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            'headers' => ['Content-Type' => 'application/json'],
        ];
    }
}
