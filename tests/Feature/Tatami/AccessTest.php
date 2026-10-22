<?php

namespace Tests\Feature\Tatami;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Draw\InteractsWithDraws;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use InteractsWithDraws;
    use RefreshDatabase;

    private Tatami $tatami;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->tatami = Tatami::factory()->create(['number' => 1]);
        $this->operator = User::factory()->create();
        $this->tatami->operators()->attach($this->operator);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('tatami.index'))->assertRedirect(route('login'));
        $this->get(route('tatami.console', $this->tatami))->assertRedirect(route('login'));
    }

    public function test_the_assigned_operator_and_administrators_may_open_the_console(): void
    {
        $this->actingAs($this->operator)->get(route('tatami.console', $this->tatami))->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get(route('tatami.console', $this->tatami))->assertOk();
    }

    public function test_other_operators_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('tatami.console', $this->tatami))
            ->assertForbidden();
    }

    public function test_other_operators_cannot_call_console_actions(): void
    {
        $category = $this->drawnCategory(2);
        $category->update(['tatami_id' => $this->tatami->id, 'competition_id' => $this->tatami->competition_id]);

        $html = $this->actingAs($this->operator)->get(route('tatami.console', $this->tatami))->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES);

        $payload = [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => ['winner' => 'aka', 'decision' => 'hantei'],
                'calls' => [['path' => '', 'method' => 'record', 'params' => [$category->bouts()->firstOrFail()->id]]],
            ]],
        ];

        $this->actingAs(User::factory()->create())
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app('livewire')->getUpdateUri(), $payload)
            ->assertForbidden();

        $this->assertSame(0, $category->bouts()->where('status', 'finished')->where('decision', '!=', 'bye')->count());

        $this->actingAs($this->operator)
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app('livewire')->getUpdateUri(), $payload)
            ->assertOk();

        $this->assertSame(1, $category->bouts()->where('status', 'finished')->count());
    }

    public function test_the_index_lists_only_the_operators_own_tatamis(): void
    {
        $other = Tatami::factory()->create(['number' => 7]);

        Livewire::actingAs($this->operator)
            ->test('pages::tatami.index')
            ->assertSee($this->tatami->competition->name)
            ->assertDontSee($other->competition->name);
    }

    public function test_administrators_see_every_tatami(): void
    {
        $other = Tatami::factory()->create();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::tatami.index')
            ->assertSee($this->tatami->competition->name)
            ->assertSee($other->competition->name);
    }

    public function test_tatamis_of_finished_competitions_are_hidden(): void
    {
        $finished = Competition::factory()->create(['status' => CompetitionStatus::Finished, 'name' => 'Old Cup']);
        $old = Tatami::factory()->for($finished)->create();
        $old->operators()->attach($this->operator);

        Livewire::actingAs($this->operator)
            ->test('pages::tatami.index')
            ->assertSee($this->tatami->competition->name)
            ->assertDontSee('Old Cup');
    }

    public function test_an_operator_without_tatamis_gets_a_hint(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::tatami.index')
            ->assertSee('no tatamis assigned');
    }

    public function test_the_sidebar_link_is_shown_to_operators_and_administrators_only(): void
    {
        $this->actingAs($this->operator)->get(route('dashboard'))->assertSee(route('tatami.index'));
        $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))->assertSee(route('tatami.index'));
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertDontSee(route('tatami.index'));
    }
}
