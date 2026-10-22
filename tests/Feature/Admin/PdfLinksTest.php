<?php

namespace Tests\Feature\Admin;

use App\Enums\CompetitionStatus;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class PdfLinksTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->actingAs(User::factory()->admin()->create());
        $this->competition = Competition::factory()->create(['status' => CompetitionStatus::InProgress]);
    }

    /**
     * The opening tag of the element carrying the given data-test hook.
     */
    private function tag(string $html, string $hook): ?string
    {
        preg_match('/<a\b[^>]*data-test="'.preg_quote($hook, '/').'"[^>]*>/s', $html, $matches);

        return $matches[0] ?? null;
    }

    private function assertPrintLink(string $html, string $hook, string $url): void
    {
        $tag = $this->tag($html, $hook);

        $this->assertNotNull($tag, "{$hook} is missing");
        $this->assertStringContainsString('href="'.$url.'"', $tag);
        $this->assertStringContainsString('target="_blank"', $tag);
        $this->assertStringNotContainsString('wire:navigate', $tag);
    }

    public function test_the_draw_page_offers_pdfs_only_for_drawn_categories(): void
    {
        $undrawn = Category::factory()->for($this->competition)->create();

        $html = $this->get(route('admin.competitions.draw', $this->competition))->assertOk()->getContent();

        $this->assertNull($this->tag($html, 'draw-pdf-link'));
        $this->assertNull($this->tag($html, 'bracket-pdf-link-'.$undrawn->id));

        $drawn = $this->drawnCategory(4, competition: $this->competition);

        $html = $this->get(route('admin.competitions.draw', $this->competition))->assertOk()->getContent();

        $this->assertPrintLink($html, 'draw-pdf-link', route('admin.competitions.draw.pdf', $this->competition));
        $this->assertPrintLink($html, 'bracket-pdf-link-'.$drawn->id, route('admin.competitions.bracket.pdf', [$this->competition, $drawn]));
        $this->assertNull($this->tag($html, 'bracket-pdf-link-'.$undrawn->id));
    }

    public function test_the_bracket_page_has_a_print_button_only_with_a_draw(): void
    {
        $undrawn = Category::factory()->for($this->competition)->create();
        $drawn = $this->drawnCategory(4, competition: $this->competition);

        $without = $this->get(route('admin.competitions.bracket', [$this->competition, $undrawn]))->assertOk()->getContent();
        $with = $this->get(route('admin.competitions.bracket', [$this->competition, $drawn]))->assertOk()->getContent();

        $this->assertNull($this->tag($without, 'bracket-pdf-link'));
        $this->assertPrintLink($with, 'bracket-pdf-link', route('admin.competitions.bracket.pdf', [$this->competition, $drawn]));
    }

    public function test_the_time_table_page_offers_pdfs_only_for_scheduled_tatamis(): void
    {
        $busy = Tatami::factory()->for($this->competition)->create(['number' => 1]);
        $idle = Tatami::factory()->for($this->competition)->create(['number' => 2]);

        $html = $this->get(route('admin.competitions.timetable', $this->competition))->assertOk()->getContent();
        $this->assertNull($this->tag($html, 'timetable-pdf-link'));

        $this->drawnCategory(4, competition: $this->competition)->update(['tatami_id' => $busy->id, 'sort_order' => 1]);

        $html = $this->get(route('admin.competitions.timetable', $this->competition))->assertOk()->getContent();

        $this->assertPrintLink($html, 'timetable-pdf-link', route('admin.competitions.timetable.pdf', $this->competition));
        $this->assertPrintLink($html, 'tatami-pdf-link-'.$busy->id, route('admin.competitions.timetable.pdf', [$this->competition, 'tatami' => $busy->id]));
        $this->assertNull($this->tag($html, 'tatami-pdf-link-'.$idle->id));
    }
}
