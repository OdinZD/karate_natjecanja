@props(['progress'])

@php
    use App\Enums\DrawProgress;

    [$state, $label] = match ($progress) {
        DrawProgress::NoDraw => ['none', __('No draw')],
        DrawProgress::Pending => ['drawn', __('Draw done')],
        DrawProgress::Running => ['live', __('In progress')],
        DrawProgress::Finished => ['done', __('Finished')],
    };
@endphp

<x-arena.status :state="$state" :label="$label" {{ $attributes }} />
