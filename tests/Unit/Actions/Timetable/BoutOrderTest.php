<?php

namespace Tests\Unit\Actions\Timetable;

use App\Actions\Timetable\BoutOrder;
use App\Actions\Timetable\TimetableBuilder;
use App\Enums\BoutBracket;
use App\Enums\Discipline;
use App\Models\Bout;
use App\Models\Category;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BoutOrderTest extends TestCase
{
    private function bout(BoutBracket $bracket, int $round, int $position): Bout
    {
        return new Bout(['bracket' => $bracket, 'round' => $round, 'position' => $position]);
    }

    public function test_bouts_are_played_by_round_then_repechage_then_final(): void
    {
        $main = BoutBracket::Main;
        $repechage = BoutBracket::Repechage;

        $bouts = new Collection([
            $this->bout($main, 3, 1),
            $this->bout($repechage, 2, 2),
            $this->bout($main, 2, 2),
            $this->bout($repechage, 1, 1),
            $this->bout($main, 1, 4),
            $this->bout($main, 1, 1),
            $this->bout($repechage, 2, 1),
            $this->bout($main, 2, 1),
            $this->bout($repechage, 1, 2),
        ]);

        $order = (new BoutOrder)->sort($bouts, 3)
            ->map(fn (Bout $bout) => $bout->bracket->value[0].$bout->round.$bout->position)
            ->all();

        $this->assertSame(['m11', 'm14', 'm21', 'm22', 'r11', 'r12', 'r21', 'r22', 'm31'], $order);
    }

    /**
     * @return array<string, array{int, Discipline, int}>
     */
    public static function estimates(): array
    {
        return [
            'no entries' => [0, Discipline::Kumite, 0],
            'one entry' => [1, Discipline::Kumite, 0],
            'kumite 2' => [2, Discipline::Kumite, 1],
            'kumite 3' => [3, Discipline::Kumite, 2],
            'kumite 4' => [4, Discipline::Kumite, 3],
            'kumite 5' => [5, Discipline::Kumite, 6],
            'kumite 8' => [8, Discipline::Kumite, 9],
            'kumite 16' => [16, Discipline::Kumite, 19],
            'kata 8' => [8, Discipline::Kata, 7],
            'kata 16' => [16, Discipline::Kata, 15],
        ];
    }

    #[DataProvider('estimates')]
    public function test_the_number_of_bouts_is_estimated_from_the_entries(int $entries, Discipline $discipline, int $expected): void
    {
        $category = new Category(['discipline' => $discipline]);
        $category->setAttribute('entries_count', $entries);

        $this->assertSame($expected, (new TimetableBuilder)->estimateBouts($category));
    }
}
