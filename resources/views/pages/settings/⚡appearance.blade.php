<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <div x-data role="radiogroup" aria-label="{{ __('Appearance') }}" class="grid gap-3 sm:grid-cols-3">
            @foreach (['light' => [__('Light'), 'sun'], 'dark' => [__('Dark'), 'moon'], 'system' => [__('System'), 'computer-desktop']] as $value => [$label, $icon])
                <label class="group relative flex cursor-pointer flex-col gap-3 border border-arena-line bg-arena-surface p-3 has-checked:border-arena-ink has-checked:shadow-[inset_0_0_0_2px_var(--arena-focus)] has-focus-visible:outline-[3px] has-focus-visible:outline-offset-2 has-focus-visible:outline-(--arena-focus)">
                    <input type="radio" name="appearance" value="{{ $value }}" x-model="$flux.appearance" class="sr-only">

                    <span aria-hidden="true" @class([
                        'flex h-16 flex-col justify-end gap-1.5 p-2',
                        'bg-white' => $value === 'light',
                        'bg-black' => $value === 'dark',
                        'bg-linear-135 from-white from-50% to-black to-50%' => $value === 'system',
                    ])>
                        <span @class(['h-2 w-2/3', 'bg-black' => $value !== 'dark', 'bg-white' => $value === 'dark'])></span>
                        <span class="h-2 w-1/3 bg-arena-volt"></span>
                    </span>

                    <span class="flex items-center gap-2 text-sm font-semibold">
                        <flux:icon :icon="$icon" class="size-4" />{{ $label }}
                        <flux:icon.check class="ms-auto hidden size-4 group-has-checked:block" />
                    </span>
                </label>
            @endforeach
        </div>
    </x-pages::settings.layout>
</section>
