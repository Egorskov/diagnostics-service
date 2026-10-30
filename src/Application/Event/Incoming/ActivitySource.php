<?php

declare(strict_types=1);

namespace App\Application\Event\Incoming;

/**
 * Источник точки активности (asyncapi.yaml → ActivityHeartbeat.source).
 */
enum ActivitySource: string
{
    case Theory = 'theory';
    case Sandbox = 'sandbox';
    case Quiz = 'quiz';
    case Other = 'other';
}
