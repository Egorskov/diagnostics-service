<?php

declare(strict_types=1);

namespace App\Application\Event\Incoming;

/**
 * Категория списанного времени (asyncapi.yaml → TimeLogged.category).
 */
enum TimeCategory: string
{
    case Theory = 'theory';
    case Practice = 'practice';
    case Interview = 'interview';
}
