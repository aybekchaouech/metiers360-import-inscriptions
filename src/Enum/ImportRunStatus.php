<?php

namespace App\Enum;

enum ImportRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
