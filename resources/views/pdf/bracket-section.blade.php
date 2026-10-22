@php
    use App\Actions\Pdf\BracketGrid;
    use App\Enums\BoutBracket;
    use App\Support\RoundLabel;
    use Illuminate\Support\Str;

    $bouts = $category->bouts->sortBy([['round', 'asc'], ['position', 'asc']])->values();
    $grid = app(BracketGrid::class)->build($bouts->where('bracket', BoutBracket::Main)->values());
    $repechage = $bouts->where('bracket', BoutBracket::Repechage)->groupBy('position')->sortKeys();
    $size = $grid['rows'] * 2;
    $compact = $size >= 16;
    $sizeClass = match (true) {
        $size >= 64 => 'size-64',
        $size >= 32 => 'size-32',
        $size >= 16 => 'size-16',
        default => 'size-small',
    };
    // Name and club limits are chosen so that bold CAPITAL letters (surnames are often written that way)
    // fit the column at the font size of the bracket: [name in round 1, club in round 1, name in later rounds].
    [$nameFirst, $clubFirst, $nameLater] = match (true) {
        $size >= 64 => [20, 10, 11],
        $size >= 32 => [22, 14, 14],
        default => [20, 12, 16],
    };
    $firstColumn = $compact ? 34 : null;
    $otherColumn = $compact && $grid['rounds'] > 1 ? round((100 - $firstColumn) / ($grid['rounds'] - 1), 2) : null;
    $placeName = fn (int $place) => match ($place) {
        1 => __('1st place'),
        2 => __('2nd place'),
        default => __('3rd place'),
    };
    $meta = implode(', ', array_filter([
        __(Str::headline($category->discipline->value)),
        $category->gender ? __(Str::headline($category->gender->value)) : __('Mixed'),
        __('Born :from to :to', ['from' => $category->birth_year_from, 'to' => $category->birth_year_to]),
        $category->weightLabel(),
    ]));
@endphp

@if ($compact)
    <div style="font-size: 9pt; margin-bottom: 1.5mm;">
        <strong>{{ $competition->name }}</strong>
        <span class="muted">{{ $competition->date->format('d.m.Y.') }}, {{ $competition->venue }}</span>
    </div>
    <div style="font-size: 11pt; margin-bottom: 1.5mm;">
        <strong>{{ $category->name }}</strong>
        <span class="muted small">{{ $meta }}</span>
    </div>
@else
    <h1>{{ $competition->name }}</h1>
    <div class="muted small">{{ $competition->date->format('d.m.Y.') }}, {{ $competition->venue }}</div>

    <h2>{{ $category->name }}</h2>
    <div class="muted small" style="margin-bottom: 3mm;">{{ $meta }}</div>
@endif

<table class="bracket {{ $sizeClass }}">
    <tr>
        @foreach (range(1, $grid['rounds']) as $round)
            <th @if ($compact) style="width: {{ $round === 1 ? $firstColumn : ($otherColumn ?? 'auto') }}%;" @endif>{{ RoundLabel::for($round, $grid['rounds']) }}</th>
        @endforeach
    </tr>

    @foreach ($grid['cells'] as $row)
        <tr>
            @foreach ($row as $cell)
                @continue($cell === null)

                <td class="slot {{ $loop->index === 0 ? 'round-1' : '' }}" rowspan="{{ $cell['rowspan'] }}">
                    @if ($cell['bout'])
                        @include('pdf.bout', ['bout' => $cell['bout'], 'compact' => $compact, 'showClub' => ! $compact || $loop->index === 0, 'nameLimit' => $loop->index === 0 ? $nameFirst : $nameLater, 'clubLimit' => $clubFirst])
                    @endif
                </td>
            @endforeach
        </tr>
    @endforeach
</table>

@if ($repechage->isNotEmpty() || $category->placements->isNotEmpty())
    {{-- Repechage and results start on a fresh page, so a bracket is never split from the heading of its follow-up. --}}
    <div style="page-break-before: always;">
        <h2>{{ $category->name }}</h2>

        @if ($repechage->isNotEmpty())
            <h3>{{ __('Repechage') }}</h3>

            <table style="width: 100%;" class="{{ $sizeClass === 'size-small' ? 'size-small' : 'size-16' }}">
                @foreach ($repechage as $half => $steps)
                    <tr>
                        <td style="width: 22mm; vertical-align: middle;" class="small muted">{{ __('Half :number', ['number' => $half]) }}</td>
                        @foreach ($steps->sortBy('round') as $bout)
                            <td style="width: {{ round(78 / max(1, $steps->count()), 2) }}%; padding: 1.5mm; vertical-align: middle;">
                                @include('pdf.bout', ['bout' => $bout, 'compact' => false])
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        @endif

        @if ($category->placements->isNotEmpty())
            <h3>{{ __('Results') }}</h3>

            <table class="plain" style="width: 60%;">
                @foreach ($category->placements->sortBy('place') as $placement)
                    <tr>
                        <td style="width: 28mm;">{{ $placeName($placement->place) }}</td>
                        <td>
                            <strong>{{ $placement->entry->competitor->last_name }} {{ $placement->entry->competitor->first_name }}</strong>
                            <span class="muted">{{ $placement->entry->competitor->club->name }}</span>
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endif
