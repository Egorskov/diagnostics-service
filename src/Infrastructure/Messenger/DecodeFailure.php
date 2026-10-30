<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

/**
 * Почему входящее сообщение не удалось превратить в событие.
 */
enum DecodeFailure: string
{
    case InvalidJson = 'invalid_json';
    case InvalidEnvelope = 'invalid_envelope';
    case UnknownEventType = 'unknown_event_type';
    case UnsupportedEventVersion = 'unsupported_event_version';
    case InvalidPayload = 'invalid_payload';
}
