<?php

namespace Tests\Unit\Actions\Pdf;

use App\Actions\Pdf\BracketGrid;
use App\Enums\BoutBracket;
use App\Models\Bout;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BracketGridTest extends TestCase
{
    /**
     * @return Collection<int, Bout>
     */
    private function bracket(int $size): Collection
    {
        $bouts = new Collection;

        for ($round = 1; $round <= log($size, 2); $round++) {
            for ($position = 1; $position <= $size / (2 ** $round); $position++) {
                $bouts->push(new Bout(['bracket' => BoutBracket::Main, 'round' => $round, 'position' => $position]));
            }
        }

        return $bouts;
    }

    /**
     * @return array<string, array{int}>
     */
    public static function sizes(): array
    {
        return ['2' => [2], '4' => [4], '8' => [8], '16' => [16], '32' => [32], '64' => [64]];
    }

    #[DataProvider('sizes')]
    public function test_the_table_has_one_row_per_first_round_bout_and_one_cell_per_bout(int $size): void
    {
        $grid = (new BracketGrid)->build($this->bracket($size));
        $rounds = (int) log($size, 2);

        $this->assertSame($rounds, $grid['rounds']);
        $this->assertSame($size / 2, $grid['rows']);
        $this->assertCount($size / 2, $grid['cells']);

        $cells = collect($grid['cells'])->flatten(1)->filter();
        $this->assertCount($size - 1, $cells);

        foreach (range(1, $rounds) as $round) {
            $spans = collect($grid['cells'])->pluck($round)->filter()->sum('rowspan');
            $this->assertSame($size / 2, $spans, "round $round does not fill the column");
        }

        $this->assertNotNull($grid['cells'][0][$rounds]);
        $this->assertSame($size / 2, $grid['cells'][0][$rounds]['rowspan']);
    }

    public function test_the_layout_of_a_bracket_of_eight_is_exact(): void
    {
        $grid = (new BracketGrid)->build($this->bracket(8));

        $position = fn (?array $cell) => $cell === null ? null : [$cell['bout']->position, $cell['rowspan']];

        $actual = array_map(fn (array $row) => array_map($position, $row), $grid['cells']);

        $this->assertSame([
            [1 => [1, 1], 2 => [1, 2], 3 => [1, 4]],
            [1 => [2, 1], 2 => null, 3 => null],
            [1 => [3, 1], 2 => [2, 2], 3 => null],
            [1 => [4, 1], 2 => null, 3 => null],
        ], $actual);
    }

    public function test_a_lone_final_is_a_single_row(): void
    {
        $grid = (new BracketGrid)->build($this->bracket(2));

        $this->assertSame(1, $grid['rows']);
        $this->assertSame(1, $grid['rounds']);
        $this->assertSame(1, $grid['cells'][0][1]['bout']->position);
    }

    public function test_an_empty_set_gives_an_empty_grid(): void
    {
        $this->assertSame(['rounds' => 0, 'rows' => 0, 'cells' => []], (new BracketGrid)->build(new Collection));
    }

    public function test_an_incomplete_set_does_not_throw_and_leaves_a_cell_without_a_bout(): void
    {
        $bouts = $this->bracket(8)->reject(fn (Bout $bout) => $bout->round === 1 && $bout->position === 3)->values();

        $grid = (new BracketGrid)->build($bouts);

        $this->assertNull($grid['cells'][2][1]['bout']);
        $this->assertSame(1, $grid['cells'][2][1]['rowspan']);
        $this->assertNotNull($grid['cells'][2][2]['bout']);
    }
}
