<?php

namespace App\Duplicate;

enum DuplicateFlag: string
{
    /** Same person registered twice to the same session: counted once (the earliest registration is kept). */
    case SameSession = 'same_session_duplicate';

    /** Same email, first names close (typo) or same birth date: maybe the same person, a human checks. */
    case Probable = 'probable_duplicate';

    /** Same person registered to several sessions: allowed or not, the operations team decides. */
    case MultipleSessions = 'multiple_sessions';
}
