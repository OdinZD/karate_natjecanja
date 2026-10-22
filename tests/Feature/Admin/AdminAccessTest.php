<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function adminUrls(): array
    {
        $competition = Competition::factory()->create();
        $category = Category::factory()->for($competition)->create();

        return [
            'competitions' => route('admin.competitions.index'),
            'competition' => route('admin.competitions.show', $competition),
            'categories' => route('admin.competitions.categories', $competition),
            'entries' => route('admin.competitions.entries', $competition),
            'draw' => route('admin.competitions.draw', $competition),
            'timetable' => route('admin.competitions.timetable', $competition),
            'bracket' => route('admin.competitions.bracket', [$competition, $category]),
            'clubs' => route('admin.clubs.index'),
            'competitors' => route('admin.competitors.index'),
            'users' => route('admin.users.index'),
        ];
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach ($this->adminUrls() as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_operators_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ($this->adminUrls() as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_admins_can_open_every_admin_page(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach ($this->adminUrls() as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_operators_cannot_call_admin_livewire_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create();
        $victim = User::factory()->create();

        $html = $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES);

        $payload = [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => (object) [],
                'calls' => [
                    ['path' => '', 'method' => 'confirmDelete', 'params' => [$victim->id]],
                    ['path' => '', 'method' => 'delete', 'params' => []],
                ],
            ]],
        ];

        $this->actingAs($operator)
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app('livewire')->getUpdateUri(), $payload)
            ->assertForbidden();

        $this->assertModelExists($victim);
    }

    public function test_only_admins_see_the_administration_menu(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertDontSee(route('admin.users.index'));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertSee(route('admin.users.index'));
    }
}
