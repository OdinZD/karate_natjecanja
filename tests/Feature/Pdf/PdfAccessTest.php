<?php

namespace Tests\Feature\Pdf;

use App\Enums\CompetitionStatus;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class PdfAccessTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Competition $competition;

    private Category $category;

    private Tatami $tatami;

    protected function setUp(): void
    {
        parent::setUp();

        $this->competition = Competition::factory()->create(['name' => 'Ivanec Open', 'status' => CompetitionStatus::InProgress]);
        $this->category = $this->drawnCategory(4, competition: $this->competition);
        $this->category->update(['name' => 'Cadets Boys']);
        $this->tatami = Tatami::factory()->for($this->competition)->create(['number' => 1]);
        $this->category->update(['tatami_id' => $this->tatami->id, 'sort_order' => 1]);
    }

    /**
     * @return array<string, string>
     */
    private function urls(): array
    {
        return [
            'bracket' => route('admin.competitions.bracket.pdf', [$this->competition, $this->category]),
            'draw' => route('admin.competitions.draw.pdf', $this->competition),
            'timetable' => route('admin.competitions.timetable.pdf', $this->competition),
        ];
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        foreach ($this->urls() as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_operators_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ($this->urls() as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_administrators_get_inline_pdf_documents_with_ascii_file_names(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $expected = [
            'bracket' => 'zrijeb-ivanec-open-cadets-boys.pdf',
            'draw' => 'zrijebovi-ivanec-open.pdf',
            'timetable' => 'time-table-ivanec-open.pdf',
        ];

        foreach ($this->urls() as $name => $url) {
            $response = $this->get($url)->assertOk();

            $this->assertSame('application/pdf', $response->headers->get('Content-Type'), $name);
            $this->assertStringStartsWith('%PDF-', $response->getContent(), $name);
            $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'), $name);
            $this->assertStringContainsString($expected[$name], $response->headers->get('Content-Disposition'), $name);
        }
    }

    public function test_the_time_table_can_be_limited_to_one_tatami(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get(route('admin.competitions.timetable.pdf', [$this->competition, 'tatami' => $this->tatami->id]))->assertOk();

        $this->assertStringContainsString('time-table-ivanec-open-tatami-1.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_a_tatami_of_another_competition_is_not_found(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $foreign = Tatami::factory()->create();

        $this->get(route('admin.competitions.timetable.pdf', [$this->competition, 'tatami' => $foreign->id]))->assertNotFound();
    }

    public function test_a_category_of_another_competition_is_not_found(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $foreign = $this->drawnCategory(4);

        $this->get(route('admin.competitions.bracket.pdf', [$this->competition, $foreign]))->assertNotFound();
    }

    public function test_nothing_to_print_gives_a_not_found(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $empty = Competition::factory()->create();
        $undrawn = Category::factory()->for($empty)->create();

        $this->get(route('admin.competitions.bracket.pdf', [$empty, $undrawn]))->assertNotFound();
        $this->get(route('admin.competitions.draw.pdf', $empty))->assertNotFound();
        $this->get(route('admin.competitions.timetable.pdf', $empty))->assertNotFound();

        $undrawn->update(['tatami_id' => Tatami::factory()->for($empty)->create()->id]);
        $this->get(route('admin.competitions.timetable.pdf', $empty))->assertOk();
    }

    public function test_drafts_can_be_printed(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->competition->update(['status' => CompetitionStatus::Draft]);

        foreach ($this->urls() as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_the_pdf_routes_carry_the_admin_authorization(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_ends_with((string) $route->getName(), '.pdf'));

        $this->assertCount(3, $routes);

        foreach ($routes as $route) {
            $this->assertStringContainsString('can:admin', implode(',', $route->gatherMiddleware()), (string) $route->getName());
        }
    }
}
