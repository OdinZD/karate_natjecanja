@extends('pdf.layout', ['title' => __('Time table').' - '.$competition->name])

@section('content')
    @foreach ($blocks as $block)
        <div @class(['page-break' => ! $loop->last])>
            <h1>{{ $competition->name }}</h1>
            <div class="muted small">{{ $competition->date->format('d.m.Y.') }}, {{ $competition->venue }}</div>

            <h2>
                {{ __('Tatami :number', ['number' => $block->tatami->number]) }}@if ($block->tatami->name), {{ $block->tatami->name }}@endif
            </h2>

            <table class="plain">
                <tr>
                    <th style="width: 32mm;">{{ __('Time') }}</th>
                    <th>{{ __('Category') }}</th>
                    <th style="width: 24mm;">{{ __('Discipline') }}</th>
                    <th style="width: 20mm;">{{ __('Entries') }}</th>
                    <th style="width: 20mm;">{{ __('Bouts') }}</th>
                </tr>
                @foreach ($block->rows as $row)
                    <tr>
                        <td>{{ $row->start->format('H:i') }}–{{ $row->end->format('H:i') }}</td>
                        <td><strong>{{ $row->category->name }}</strong></td>
                        <td>{{ __(\Illuminate\Support\Str::headline($row->category->discipline->value)) }}</td>
                        <td>{{ $row->entries }}</td>
                        <td>{{ $row->bouts }}</td>
                    </tr>
                @endforeach
            </table>

            <p class="muted small" style="margin-top: 4mm;">
                {{ __('Times are estimates based on the number of entries and the bout duration. They do not shift when the programme runs late.') }}
            </p>
        </div>
    @endforeach
@endsection
