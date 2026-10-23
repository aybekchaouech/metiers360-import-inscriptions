<?php

namespace App\Import;

/**
 * Problems found while cleaning a submission. The submission is imported anyway;
 * these codes tell the operations team what to check.
 */
enum Anomaly: string
{
    case FirstNameMissing = 'first_name_missing';
    case LastNameMissing = 'last_name_missing';
    case EmailMissing = 'email_missing';
    case EmailInvalid = 'email_invalid';
    case PhoneInvalid = 'phone_invalid';
    case BirthDateMissing = 'birth_date_missing';
    case BirthDateUnreadable = 'birth_date_unreadable';
    case BirthDateImplausible = 'birth_date_implausible';
    case StructureTypeMissing = 'structure_type_missing';
    case StructureTypeUnknown = 'structure_type_unknown';
    case StatusUnknown = 'status_unknown';
}
