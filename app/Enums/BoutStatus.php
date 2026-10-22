<?php

namespace App\Enums;

enum BoutStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Finished = 'finished';
}
