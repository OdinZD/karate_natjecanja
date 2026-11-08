<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Entries\EntryTemplate;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Competition;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EntryTemplateController extends Controller
{
    /**
     * The Excel template for clubs to fill in their entries, prefilled for one club when ?club= is given.
     */
    public function __invoke(Request $request, Competition $competition, EntryTemplate $template): BinaryFileResponse
    {
        $club = null;

        if ($request->query->has('club')) {
            $id = $request->query('club');

            abort_unless(is_string($id) && ctype_digit($id), 404);

            $club = Club::findOrFail((int) $id);
        }

        return response()
            ->download(
                $template->build($competition, $club),
                'prijave-'.$competition->slug.($club === null ? '' : '-'.Str::slug($club->name)).'.xlsx',
            )
            ->deleteFileAfterSend();
    }
}
