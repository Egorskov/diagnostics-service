<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * Входящее событие не соответствует контракту asyncapi.yaml.
 */
final class InvalidPayload extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $field,
        public readonly string $reason,
    ) {
        parent::__construct(\sprintf('%s: %s', $field, $reason));
    }
}
