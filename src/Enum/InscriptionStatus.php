<?php

namespace App\Enum;

enum InscriptionStatus: string
{
    case Submitted = 'submitted';
    case Cancelled = 'cancelled';
    // No longer returned by the form API after a complete import: kept for history, excluded from counts.
    case Removed = 'removed';
}
