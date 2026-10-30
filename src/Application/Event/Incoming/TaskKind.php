<?php

declare(strict_types=1);

namespace App\Application\Event\Incoming;

/**
 * Тип задания пути (asyncapi.yaml → PathSnapshot.tasks[].kind).
 */
enum TaskKind: string
{
    case Theory = 'theory';
    case Quiz = 'quiz';
    case Sandbox = 'sandbox';
    case Video = 'video';
    case Reading = 'reading';
    case Questions = 'questions';
}
