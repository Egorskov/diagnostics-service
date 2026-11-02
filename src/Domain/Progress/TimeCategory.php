<?php

declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Категория списанного времени (asyncapi.yaml → TimeLogged.category).
 */
enum TimeCategory: string
{
    case Theory = 'theory';
    case Practice = 'practice';
    case Interview = 'interview';
}
