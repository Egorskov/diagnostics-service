<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * Входящее событие другого сервиса, разобранное по контракту asyncapi.yaml.
 */
interface IncomingEvent
{
    /**
     * @throws InvalidPayload
     */
    public static function fromPayload(EventMetadata $metadata, PayloadReader $payload): static;

    public function metadata(): EventMetadata;
}
