<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

final class UnsupportedEvent extends \RuntimeException
{
    public function __construct(
        public readonly DecodeFailure $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
