<?php

namespace Tests\Feature\Admin;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Tatami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompetitionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    /**
     * @return array<string, string>
     */
    private function validForm(string $name = 'Zagreb Open'): array
    {
        return [
            'name' => $name,
            'date' => '2026-12-05',
            'start_time' => '10:00',
            'venue' => 'Dvorana Sutinska',
            'organizer' => 'KK Zagreb',
            'registration_deadline' => '2026-12-01T23:59',
        ];
    }

    public function test_admin_can_create_a_competition_and_is_redirected_to_it(): void
    {
        $component = Livewire::test('pages::admin.competitions.index');

        foreach ($this->validForm() as $field => $value) {
            $component->set($field, $value);
        }

        $component->call('save')->assertHasNoErrors();

        $competition = Competition::firstOrFail();

        $component->assertRedirectToRoute('admin.competitions.show', $competition);
        $this->assertSame('zagreb-open', $competition->slug);
        $this->assertSame(CompetitionStatus::Draft, $competition->status);
    }

    public function test_duplicate_names_get_a_numeric_slug_suffix(): void
    {
        Competition::factory()->create(['slug' => 'zagreb-open']);

        $component = Livewire::test('pages::admin.competitions.index');

        foreach ($this->validForm() as $field => $value) {
            $component->set($field, $value);
        }

        $component->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('competitions', ['slug' => 'zagreb-open-2']);
    }

    public function test_creating_requires_name_date_and_venue(): void
    {
        Livewire::test('pages::admin.competitions.index')
            ->set('start_time', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'date' => 'required', 'venue' => 'required', 'start_time' => 'required']);
    }

    public function test_admin_can_update_details_and_status(): void
    {
        $competition = Competition::factory()->create();

        Livewire::test('pages::admin.competitions.show', ['competition' => $competition])
            ->set('name', 'Renamed Cup')
            ->set('status', 'registration')
            ->call('update')
            ->assertHasNoErrors();

        $competition->refresh();

        $this->assertSame('Renamed Cup', $competition->name);
        $this->assertSame(CompetitionStatus::Registration, $competition->status);
    }

    public function test_status_must_be_a_known_value(): void
    {
        $competition = Competition::factory()->create();

        Livewire::test('pages::admin.competitions.show', ['competition' => $competition])
            ->set('status', 'nonsense')
            ->call('update')
            ->assertHasErrors('status');
    }

    public function test_admin_can_add_tatamis_with_suggested_numbers(): void
    {
        $competition = Competition::factory()->create();

        Livewire::test('pages::admin.competitions.show', ['competition' => $competition])
            ->assertSet('tatamiNumber', 1)
            ->set('tatamiName', 'Glavni')
            ->call('addTatami')
            ->assertHasNoErrors()
            ->assertSet('tatamiNumber', 2)
            ->call('addTatami')
            ->assertHasNoErrors();

        $this->assertSame([1, 2], $competition->tatamis()->orderBy('number')->pluck('number')->all());
    }

    public function test_tatami_numbers_are_unique_within_a_competition(): void
    {
        $competition = Competition::factory()->create();
        Tatami::factory()->for($competition)->create(['number' => 3]);

        Livewire::test('pages::admin.competitions.show', ['competition' => $competition])
            ->set('tatamiNumber', 3)
            ->call('addTatami')
            ->assertHasErrors(['tatamiNumber' => 'unique']);
    }

    public function test_admin_can_delete_a_tatami_of_the_competition_only(): void
    {
        $competition = Competition::factory()->create();
        $tatami = Tatami::factory()->for($competition)->create();
        $foreign = Tatami::factory()->create();

        $component = Livewire::test('pages::admin.competitions.show', ['competition' => $competition]);

        $component->call('deleteTatami', $tatami->id);
        $this->assertModelMissing($tatami);

        $component->call('deleteTatami', $foreign->id)->assertStatus(404);
        $this->assertModelExists($foreign);
    }

    public function test_admin_can_assign_operators_to_a_tatami(): void
    {
        $competition = Competition::factory()->create();
        $tatami = Tatami::factory()->for($competition)->create();
        $operators = User::factory()->count(2)->create();

        Livewire::test('pages::admin.competitions.show', ['competition' => $competition])
            ->call('assignOperators', $tatami->id)
            ->set('operatorIds', $operators->pluck('id')->all())
            ->call('saveOperators')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(
            $operators->pluck('id')->all(),
            $tatami->operators()->pluck('users.id')->all(),
        );

        Livewire::test('pages::admin.competitions.show', ['competition' => $competition])
            ->call('assignOperators', $tatami->id)
            ->set('operatorIds', [$operators->first()->id])
            ->call('saveOperators');

        $this->assertSame(1, $tatami->operators()->count());
    }

    public function test_administrators_cannot_be_assigned_as_operators(): void
    {
        $competition = Competition::factory()->create();
        $tatami = Tatami::factory()->for($competition)->create();
        $admin = User::factory()->admin()->create();

        Livewire::test('pages::admin.competitions.show', ['competition' => $competition])
            ->call('assignOperators', $tatami->id)
            ->set('operatorIds', [$admin->id])
            ->call('saveOperators')
            ->assertHasErrors('operatorIds.0');

        $this->assertSame(0, $tatami->operators()->count());
    }

    public function test_admin_can_delete_a_competition(): void
    {
        $competition = Competition::factory()->create();
        Tatami::factory()->for($competition)->create();

        Livewire::test('pages::admin.competitions.show', ['competition' => $competition])
            ->call('deleteCompetition')
            ->assertRedirectToRoute('admin.competitions.index');

        $this->assertModelMissing($competition);
        $this->assertSame(0, Tatami::count());
    }
}
