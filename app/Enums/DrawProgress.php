<?php

namespace App\Enums;

enum DrawProgress: string
{
    case NoDraw = 'no_draw';
    case Pending = 'pending';
    case Running = 'running';
    case Finished = 'finished';
}
