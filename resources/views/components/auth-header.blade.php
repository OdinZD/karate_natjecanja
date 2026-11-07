@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-1 text-center">
    <h1 class="font-display text-4xl font-black uppercase leading-none tracking-[.02em] text-arena-ink">{{ $title }}</h1>
    <p class="text-sm text-arena-muted">{{ $description }}</p>
</div>
