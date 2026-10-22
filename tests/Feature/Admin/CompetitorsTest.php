<?php

namespace Tests\Feature\Admin;

use App\Enums\Gender;
use App\Models\Club;
use App\Models\Competitor;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompetitorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_admin_can_create_a_competitor(): void
    {
        $club = Club::factory()->create();

        Livewire::test('pages::admin.competitors.index')
            ->set('first_name', 'Ana')
            ->set('last_name', 'Horvat')
            ->set('gender', 'female')
            ->set('birth_date', '2012-03-14')
            ->set('club_id', (string) $club->id)
            ->call('save')
            ->assertHasNoErrors();

        $competitor = Competitor::firstOrFail();

        $this->assertSame(Gender::Female, $competitor->gender);
        $this->assertSame($club->id, $competitor->club_id);
        $this->assertSame('2012-03-14', $competitor->birth_date->format('Y-m-d'));
    }

    public function test_validation_rejects_bad_input(): void
    {
        Livewire::test('pages::admin.competitors.index')
            ->set('gender', 'other')
            ->set('birth_date', now()->addDay()->format('Y-m-d'))
            ->set('club_id', '9999')
            ->call('save')
            ->assertHasErrors([
                'first_name' => 'required',
                'last_name' => 'required',
                'gender',
                'birth_date' => 'before',
                'club_id' => 'exists',
            ]);

        $this->assertSame(0, Competitor::count());
    }

    public function test_admin_can_edit_a_competitor(): void
    {
        $competitor = Competitor::factory()->create(['first_name' => 'Old']);

        Livewire::test('pages::admin.competitors.index')
            ->call('edit', $competitor->id)
            ->assertSet('first_name', 'Old')
            ->set('first_name', 'New')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New', $competitor->refresh()->first_name);
    }

    public function test_search_and_club_filter_narrow_the_list(): void
    {
        $clubA = Club::factory()->create(['name' => 'KK Alfa']);
        $clubB = Club::factory()->create(['name' => 'KK Beta']);
        Competitor::factory()->for($clubA)->create(['last_name' => 'Kovač']);
        Competitor::factory()->for($clubB)->create(['last_name' => 'Babić']);

        Livewire::test('pages::admin.competitors.index')
            ->set('search', 'Kovač')
            ->assertSee('Kovač')
            ->assertDontSee('Babić')
            ->set('search', '')
            ->set('clubFilter', (string) $clubB->id)
            ->assertSee('Babić')
            ->assertDontSee('Kovač');
    }

    public function test_a_competitor_with_entries_is_not_deleted(): void
    {
        $entry = Entry::factory()->create();

        Livewire::test('pages::admin.competitors.index')
            ->call('confirmDelete', $entry->competitor_id)
            ->call('delete');

        $this->assertModelExists($entry->competitor);
    }

    public function test_a_competitor_without_entries_is_deleted(): void
    {
        $competitor = Competitor::factory()->create();

        Livewire::test('pages::admin.competitors.index')
            ->call('confirmDelete', $competitor->id)
            ->call('delete');

        $this->assertModelMissing($competitor);
    }
}
