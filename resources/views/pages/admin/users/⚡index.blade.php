<?php

use App\Enums\UserRole;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Users')] class extends Component {
    use WithPagination;

    public ?int $editingId = null;
    public ?int $deletingId = null;

    public string $name = '';
    public string $email = '';
    public string $role = 'operator';
    public string $password = '';

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::query()->orderBy('name')->paginate(15);
    }

    public function create(): void
    {
        $this->resetForm();

        Flux::modal('user-form')->show();
    }

    public function edit(int $id): void
    {
        $user = User::findOrFail($id);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role->value;

        Flux::modal('user-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($this->editingId)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', Password::default()],
        ]);

        if ($this->editingId === Auth::id() && $validated['role'] !== UserRole::Admin->value) {
            $this->addError('role', __('You cannot remove your own administrator role.'));

            return;
        }

        if ($this->editingId) {
            $user = User::findOrFail($this->editingId);
            $user->fill(collect($validated)->except('password')->all());

            if ($validated['password'] !== null && $validated['password'] !== '') {
                $user->password = $validated['password'];
            }

            $user->save();
        } else {
            $user = new User(collect($validated)->except('password')->all());
            $user->password = $validated['password'];
            $user->email_verified_at = now();
            $user->save();
        }

        Flux::modal('user-form')->close();
        Flux::toast(variant: 'success', text: __('User saved.'));
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;

        Flux::modal('user-delete')->show();
    }

    public function delete(): void
    {
        $user = User::findOrFail($this->deletingId);

        if ($user->is(Auth::user())) {
            Flux::modal('user-delete')->close();
            Flux::toast(variant: 'danger', text: __('You cannot delete your own account here.'));

            return;
        }

        $user->delete();

        $this->deletingId = null;
        Flux::modal('user-delete')->close();
        Flux::toast(variant: 'success', text: __('User deleted.'));
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'email', 'password']);
        $this->role = UserRole::Operator->value;
        $this->resetValidation();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-center justify-between">
        <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="create" data-test="create-user-button">
            {{ __('New user') }}
        </flux:button>
    </div>

    <flux:table :paginate="$this->users">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->users as $user)
                <flux:table.row :key="$user->id">
                    <flux:table.cell>{{ $user->name }}</flux:table.cell>
                    <flux:table.cell>{{ $user->email }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="$user->isAdmin() ? 'blue' : 'zinc'" size="sm">
                            {{ $user->isAdmin() ? __('Administrator') : __('Tatami operator') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="text-end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $user->id }})" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $user->id }})" />
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="user-form" class="md:w-96">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit user') : __('New user') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" type="text" required />
            <flux:input wire:model="email" :label="__('Email')" type="email" required />

            <flux:select wire:model="role" :label="__('Role')">
                <flux:select.option value="operator">{{ __('Tatami operator') }}</flux:select.option>
                <flux:select.option value="admin">{{ __('Administrator') }}</flux:select.option>
            </flux:select>

            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                :required="! $editingId"
                :description="$editingId ? __('Leave empty to keep the current password.') : null"
                viewable
            />

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="save-user-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="user-delete" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete user?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('This action cannot be undone.') }}</flux:text>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-user-button">
                    {{ __('Delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
