<?php

namespace App\Enums;

enum BoutDecision: string
{
    case Points = 'points';
    case Hantei = 'hantei';
    case Flags = 'flags';
    case Kiken = 'kiken';
    case Shikkaku = 'shikkaku';
    case Bye = 'bye';
}
