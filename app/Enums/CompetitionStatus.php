<?php

namespace App\Enums;

enum CompetitionStatus: string
{
    case Draft = 'draft';
    case Registration = 'registration';
    case Drawn = 'drawn';
    case InProgress = 'in_progress';
    case Finished = 'finished';
}
