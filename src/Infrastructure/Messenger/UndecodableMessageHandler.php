<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Отправляет неразобранное сообщение в failure transport сразу, без ретраев:
 * повтор того же тела ничего не изменит (ADR 0002, п. 3).
 */
#[AsMessageHandler]
final class UndecodableMessageHandler
{
    public function __invoke(UndecodableMessage $message): never
    {
        throw new UnrecoverableMessageHandlingException(\sprintf('Undecodable message (%s): %s', $message->reason->value, $message->detail));
    }
}
