<?php

namespace App\Report;

/**
 * Where an active registration stands in a session.
 */
enum Place: string
{
    case Confirmed = 'confirmed';
    case WaitingList = 'waiting_list';
    /** Second registration of the same person to the same session: takes no seat. */
    case Duplicate = 'duplicate';
}
