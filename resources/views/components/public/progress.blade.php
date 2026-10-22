@props(['progress'])

@php
    use App\Enums\DrawProgress;

    [$label, $tone] = match ($progress) {
        DrawProgress::NoDraw => [__('No draw'), 'neutral'],
        DrawProgress::Pending => [__('Draw done'), 'soon'],
        DrawProgress::Running => [__('In progress'), 'live'],
        DrawProgress::Finished => [__('Finished'), 'done'],
    };
@endphp

<x-public.status :tone="$tone" {{ $attributes }}>{{ $label }}</x-public.status>
