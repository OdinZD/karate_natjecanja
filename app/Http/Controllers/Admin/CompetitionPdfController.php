<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Timetable\TimetableBuilder;
use App\Actions\Timetable\TimetableTatami;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Competition;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CompetitionPdfController extends Controller
{
    private const MEMORY_LIMIT_BYTES = 512 * 1024 * 1024;

    /**
     * The draw of one category.
     */
    public function bracket(Competition $competition, Category $category): Response
    {
        $this->allowMoreMemory();

        abort_unless($category->competition_id === $competition->id, 404);

        $category->load($this->drawRelations());

        abort_if($category->bouts->isEmpty(), 404);

        return Pdf::loadView('pdf.bracket', ['competition' => $competition, 'category' => $category])
            ->setPaper('a4', 'landscape')
            ->stream('zrijeb-'.Str::slug($competition->name).'-'.Str::slug($category->name).'.pdf');
    }

    /**
     * The draws of all categories that have one, a page each.
     */
    public function draw(Competition $competition): Response
    {
        $this->allowMoreMemory();

        $categories = $competition->categories()
            ->whereHas('bouts')
            ->with($this->drawRelations())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        abort_if($categories->isEmpty(), 404);

        return Pdf::loadView('pdf.draw', ['competition' => $competition, 'categories' => $categories])
            ->setPaper('a4', 'landscape')
            ->stream('zrijebovi-'.Str::slug($competition->name).'.pdf');
    }

    /**
     * The time table, a page per tatami (or only the tatami given as ?tatami=ID).
     */
    public function timetable(Request $request, Competition $competition): Response
    {
        $this->allowMoreMemory();

        $blocks = collect(app(TimetableBuilder::class)->build($competition))
            ->filter(fn (TimetableTatami $block) => $block->rows !== []);

        $suffix = '';

        if ($request->filled('tatami')) {
            $tatami = $competition->tatamis()->findOrFail((int) $request->query('tatami'));

            $blocks = $blocks->filter(fn (TimetableTatami $block) => $block->tatami->is($tatami));
            $suffix = '-tatami-'.$tatami->number;
        }

        abort_if($blocks->isEmpty(), 404);

        return Pdf::loadView('pdf.timetable', ['competition' => $competition, 'blocks' => $blocks->values()])
            ->setPaper('a4', 'portrait')
            ->stream('time-table-'.Str::slug($competition->name).$suffix.'.pdf');
    }

    /**
     * @return array<int|string, mixed>
     */
    private function drawRelations(): array
    {
        return [
            'bouts.aka.competitor.club',
            'bouts.ao.competitor.club',
            'placements.entry.competitor.club',
        ];
    }

    /**
     * dompdf keeps the whole document in memory, and a draw of dozens of categories can pass the usual 128M.
     */
    private function allowMoreMemory(): void
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return;
        }

        if (ini_parse_quantity($limit) < self::MEMORY_LIMIT_BYTES) {
            ini_set('memory_limit', (string) self::MEMORY_LIMIT_BYTES);
        }
    }
}
