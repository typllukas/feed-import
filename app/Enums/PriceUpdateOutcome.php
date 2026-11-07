<?php

declare(strict_types=1);

namespace App\Enums;

enum PriceUpdateOutcome: string
{
    case Recorded = 'recorded';
    case Unchanged = 'unchanged';
    case Stale = 'stale';
}
