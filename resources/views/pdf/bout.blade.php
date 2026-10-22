@php
    $compact ??= false;
    $showClub ??= true;
    $nameLimit ??= 22;
    $clubLimit ??= 26;
    $isBye = $bout->decision === \App\Enums\BoutDecision::Bye;

    $sides = [
        ['AKA', 'aka', $bout->aka, $bout->aka_entry_id, $bout->aka_score],
        ['AO', 'ao', $bout->ao, $bout->ao_entry_id, $bout->ao_score],
    ];
@endphp

<table class="bout">
    @foreach ($sides as [$tag, $side, $entry, $entryId, $score])
        <tr class="{{ $side }}{{ $entryId !== null && $bout->winner_entry_id === $entryId ? ' winner' : '' }}">
            <td class="tag">{{ $tag }}</td>
            <td>
                @if ($entry)
                    @if ($compact)
                        {{ \Illuminate\Support\Str::limit($entry->competitor->last_name.' '.$entry->competitor->first_name, $nameLimit, '…') }}
                        @if ($showClub)
                            <span class="club">{{ \Illuminate\Support\Str::limit($entry->competitor->club->name, $clubLimit, '…') }}</span>
                        @endif
                    @else
                        {{ \Illuminate\Support\Str::limit($entry->competitor->last_name.' '.$entry->competitor->first_name, 22, '…') }}<br>
                        <span class="club">{{ \Illuminate\Support\Str::limit($entry->competitor->club->name, 26, '…') }}</span>
                    @endif
                @elseif ($isBye)
                    <span class="muted">{{ __('Bye') }}</span>
                @else
                    <span class="muted">—</span>
                @endif
            </td>
            <td class="score">{{ $score }}</td>
        </tr>
    @endforeach
</table>
