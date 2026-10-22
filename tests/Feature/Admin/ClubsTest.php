<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Competitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClubsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_admin_can_create_a_club(): void
    {
        Livewire::test('pages::admin.clubs.index')
            ->set('name', 'KK Ivanec')
            ->set('city', 'Ivanec')
            ->set('country', 'hr')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('clubs', ['name' => 'KK Ivanec', 'city' => 'Ivanec', 'country' => 'HR']);
    }

    public function test_country_must_be_two_letters(): void
    {
        Livewire::test('pages::admin.clubs.index')
            ->set('name', 'KK Test')
            ->set('country', '1!')
            ->call('save')
            ->assertHasErrors(['country' => 'alpha']);
    }

    public function test_the_same_name_in_the_same_city_is_rejected(): void
    {
        Club::factory()->create(['name' => 'KK Ivanec', 'city' => 'Ivanec']);

        Livewire::test('pages::admin.clubs.index')
            ->set('name', 'KK Ivanec')
            ->set('city', 'Ivanec')
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);
    }

    public function test_admin_can_edit_a_club(): void
    {
        $club = Club::factory()->create(['name' => 'Old']);

        Livewire::test('pages::admin.clubs.index')
            ->call('edit', $club->id)
            ->assertSet('name', 'Old')
            ->set('name', 'New')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New', $club->refresh()->name);
    }

    public function test_search_filters_the_list(): void
    {
        Club::factory()->create(['name' => 'KK Sisak']);
        Club::factory()->create(['name' => 'KK Rijeka']);

        Livewire::test('pages::admin.clubs.index')
            ->set('search', 'Sisak')
            ->assertSee('KK Sisak')
            ->assertDontSee('KK Rijeka');
    }

    public function test_a_club_with_competitors_is_not_deleted(): void
    {
        $competitor = Competitor::factory()->create();

        Livewire::test('pages::admin.clubs.index')
            ->call('confirmDelete', $competitor->club_id)
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertModelExists($competitor->club);
    }

    public function test_an_empty_club_is_deleted(): void
    {
        $club = Club::factory()->create();

        Livewire::test('pages::admin.clubs.index')
            ->call('confirmDelete', $club->id)
            ->call('delete');

        $this->assertModelMissing($club);
    }
}
