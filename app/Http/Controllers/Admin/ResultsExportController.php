<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Results\ResultsExport;
use App\Http\Controllers\Controller;
use App\Models\Competition;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ResultsExportController extends Controller
{
    /**
     * The results of the competition as an Excel file.
     */
    public function __invoke(Competition $competition, ResultsExport $export): BinaryFileResponse
    {
        abort_unless($competition->categories()->whereHas('placements')->exists(), 404);

        return response()
            ->download($export->build($competition), 'rezultati-'.$competition->slug.'.xlsx')
            ->deleteFileAfterSend();
    }
}
