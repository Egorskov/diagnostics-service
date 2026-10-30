<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Исходное тело сообщения из брокера: при ретрае отправляется обратно байт-в-байт (design §4).
 */
final readonly class RawBodyStamp implements StampInterface
{
    public function __construct(
        public string $body,
    ) {
    }
}
