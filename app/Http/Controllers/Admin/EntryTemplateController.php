<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Entries\EntryTemplate;
use App\Http\Controllers\Controller;
use App\Models\Competition;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EntryTemplateController extends Controller
{
    /**
     * The Excel template for clubs to fill in their entries.
     */
    public function __invoke(Competition $competition, EntryTemplate $template): BinaryFileResponse
    {
        return response()
            ->download($template->build($competition), 'prijave-'.$competition->slug.'.xlsx')
            ->deleteFileAfterSend();
    }
}
