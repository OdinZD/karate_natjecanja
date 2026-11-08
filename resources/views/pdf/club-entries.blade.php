@extends('pdf.layout', ['title' => __('Entry confirmation').' - '.$club->name.' - '.$competition->name])

@section('content')
    <h1>{{ __('Entry confirmation') }}</h1>
    <div><strong>{{ $competition->name }}</strong></div>
    <div class="muted small">{{ $competition->date->format('d.m.Y.') }}, {{ $competition->venue }}</div>

    <h2>{{ $club->name }}@if ($club->city), {{ $club->city }}@endif</h2>
    <div class="muted small">{{ __('As of :date', ['date' => now()->format('d.m.Y. H:i')]) }}</div>

    <p class="small" style="margin: 3mm 0;">
        @if ($competition->registration_deadline)
            {{ __('Check the details and report any corrections to the organiser by :date.', ['date' => $competition->registration_deadline->format('d.m.Y. H:i')]) }}
        @else
            {{ __('Check the details and report any corrections to the organiser.') }}
        @endif
    </p>

    <table class="plain">
        <thead>
            <tr>
                <th style="width: 8mm;">#</th>
                <th>{{ __('Competitor') }}</th>
                <th style="width: 24mm;">{{ __('Year of birth') }}</th>
                <th>{{ __('Category') }}</th>
                <th style="width: 20mm;">{{ __('Weight (kg)') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($competitors as $competitor)
                @foreach ($competitor->entries as $entry)
                    <tr>
                        {{-- No rowspan: dompdf breaks a spanning cell that crosses a page, so the later rows of a competitor keep empty cells. --}}
                        @if ($loop->first)
                            <td>{{ $loop->parent->iteration }}</td>
                            <td><strong>{{ $competitor->last_name }} {{ $competitor->first_name }}</strong></td>
                            <td>{{ $competitor->birth_date->format('Y') }}</td>
                        @else
                            <td></td>
                            <td></td>
                            <td></td>
                        @endif
                        <td>{{ $entry->category->name }}</td>
                        <td>{{ $entry->weightLabel() ?? '–' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <p style="margin-top: 4mm;"><strong>{{ __('Total: :competitors competitors, :entries entries', ['competitors' => $competitors->count(), 'entries' => $competitors->sum(fn ($competitor) => $competitor->entries->count())]) }}</strong></p>
@endsection
