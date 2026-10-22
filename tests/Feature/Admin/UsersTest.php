<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_admin_can_create_a_verified_operator(): void
    {
        Livewire::test('pages::admin.users.index')
            ->set('name', 'Ivo Operater')
            ->set('email', 'ivo@example.com')
            ->set('role', 'operator')
            ->set('password', 'secret-pass-1')
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('email', 'ivo@example.com')->firstOrFail();

        $this->assertSame(UserRole::Operator, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret-pass-1', $user->password));
    }

    public function test_password_is_required_when_creating(): void
    {
        Livewire::test('pages::admin.users.index')
            ->set('name', 'No Password')
            ->set('email', 'nopass@example.com')
            ->call('save')
            ->assertHasErrors(['password' => 'required']);
    }

    public function test_email_must_be_unique(): void
    {
        $existing = User::factory()->create();

        Livewire::test('pages::admin.users.index')
            ->set('name', 'Duplicate')
            ->set('email', $existing->email)
            ->set('password', 'secret-pass-1')
            ->call('save')
            ->assertHasErrors(['email' => 'unique']);
    }

    public function test_admin_can_edit_a_user_without_changing_the_password(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);
        $oldHash = $user->password;

        Livewire::test('pages::admin.users.index')
            ->call('edit', $user->id)
            ->assertSet('name', 'Old Name')
            ->set('name', 'New Name')
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('New Name', $user->name);
        $this->assertSame($oldHash, $user->password);
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        Livewire::test('pages::admin.users.index')
            ->call('edit', $this->admin->id)
            ->set('role', 'operator')
            ->call('save')
            ->assertHasErrors('role');

        $this->assertTrue($this->admin->refresh()->isAdmin());
    }

    public function test_admin_can_delete_another_user(): void
    {
        $user = User::factory()->create();

        Livewire::test('pages::admin.users.index')
            ->call('confirmDelete', $user->id)
            ->call('delete');

        $this->assertModelMissing($user);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        Livewire::test('pages::admin.users.index')
            ->call('confirmDelete', $this->admin->id)
            ->call('delete');

        $this->assertModelExists($this->admin);
    }
}
