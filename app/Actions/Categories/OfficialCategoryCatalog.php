<?php

namespace App\Actions\Categories;

use App\Enums\CategorySet;
use App\Enums\Discipline;
use App\Enums\Gender;

/**
 * Official individual kata and kumite categories of the Croatian Karate Federation (HKS), 2026.
 * Source: "Službene kategorije za natjecanja u 2026. godini" (Natjecateljska komisija HKS).
 *
 * Ages are completed years on the day of the competition and are turned into birth years from the
 * competition year. A weight of "-52" means up to 52 kg, "+70" means over 70 kg.
 *
 * @phpstan-type KataRow array{0: string, 1: string|null, 2: int, 3: int|null}
 * @phpstan-type KumiteRow array{0: string, 1: string, 2: int, 3: int|null, 4: int, 5: string, 6: string}
 * @phpstan-type Tables array{kata: list<KataRow>, kataB: list<KataRow>, kumite: list<KumiteRow>}
 */
class OfficialCategoryCatalog
{
    private const KATA_SECONDS = 120;

    private const CADETS_MALE = '-52 -57 -63 -70 +70';

    private const CADETS_FEMALE = '-47 -54 -61 +61';

    private const JUNIORS_MALE = '-55 -61 -68 -76 +76';

    private const JUNIORS_FEMALE = '-48 -53 -59 -66 +66';

    private const SENIORS_MALE = '-60 -67 -75 -84 +84';

    private const SENIORS_FEMALE = '-50 -55 -61 -68 +68';

    private const VETERANS_MALE = '-75 -85 +85';

    private const VETERANS_FEMALE = '-65 -75 +75';

    /**
     * @return list<OfficialCategory>
     */
    public function categories(CategorySet $set, int $year): array
    {
        $tables = match ($set) {
            CategorySet::Tournament => $this->tournament(),
            CategorySet::NationalChampionship => $this->nationalChampionship(),
            CategorySet::NationalCup => $this->nationalCup($year),
        };

        $categories = [];

        foreach ($tables['kata'] as $row) {
            array_push($categories, ...$this->kata($row, 'Kata', $year));
        }

        foreach ($tables['kataB'] as $row) {
            array_push($categories, ...$this->kata($row, 'Kata B', $year));
        }

        foreach ($tables['kumite'] as $row) {
            array_push($categories, ...$this->kumite($row, $year));
        }

        return $categories;
    }

    /**
     * @param  KataRow  $row
     * @return list<OfficialCategory>
     */
    private function kata(array $row, string $label, int $year): array
    {
        [$male, $female, $minAge, $maxAge] = $row;

        $categories = [];

        foreach ([[Gender::Male, $male], [Gender::Female, $female]] as [$gender, $group]) {
            if ($group === null) {
                continue;
            }

            $categories[] = new OfficialCategory(
                name: $group.' - '.$label,
                discipline: Discipline::Kata,
                gender: $gender,
                birthYearFrom: $this->birthYearFrom($year, $maxAge),
                birthYearTo: $year - $minAge,
                weightMin: null,
                weightMax: null,
                boutDurationSeconds: self::KATA_SECONDS,
            );
        }

        return $categories;
    }

    /**
     * @param  KumiteRow  $row
     * @return list<OfficialCategory>
     */
    private function kumite(array $row, int $year): array
    {
        [$male, $female, $minAge, $maxAge, $seconds, $maleWeights, $femaleWeights] = $row;

        $categories = [];

        foreach ([[Gender::Male, $male, $maleWeights], [Gender::Female, $female, $femaleWeights]] as [$gender, $group, $weights]) {
            foreach (explode(' ', $weights) as $weight) {
                $limit = (int) substr($weight, 1);
                $upTo = $weight[0] === '-';

                $categories[] = new OfficialCategory(
                    name: $group.' - Kumite '.$weight.' kg',
                    discipline: Discipline::Kumite,
                    gender: $gender,
                    birthYearFrom: $this->birthYearFrom($year, $maxAge),
                    birthYearTo: $year - $minAge,
                    weightMin: $upTo ? null : $limit,
                    weightMax: $upTo ? $limit : null,
                    boutDurationSeconds: $seconds,
                );
            }
        }

        return $categories;
    }

    /**
     * Open-ended groups (16+, 35+) start at 1900, the earliest birth year a category accepts.
     */
    private function birthYearFrom(int $year, ?int $maxAge): int
    {
        return $maxAge === null ? 1900 : $year - $maxAge;
    }

    /**
     * Turnir: kata and kumite categories for tournaments, pages 2 and 3 of the HKS document.
     *
     * @return Tables
     */
    private function tournament(): array
    {
        return [
            'kata' => [
                ['U8 - Cicibani', 'U8 - Cicibanke', 5, 7],
                ['U10 - Mlađi učenici', 'U10 - Mlađe učenice', 8, 9],
                ['U12 - Učenici', 'U12 - Učenice', 10, 11],
                ['U14 - Mlađi kadeti', 'U14 - Mlađe kadetkinje', 12, 13],
                ['Kadeti', 'Kadetkinje', 14, 15],
                ['Juniori', 'Juniorke', 16, 17],
                ['Mlađi seniori', 'Mlađe seniorke', 18, 20],
                ['Seniori', 'Seniorke', 16, null],
                ['Veterani 35+', 'Veteranke 35+', 35, null],
                ['Veterani 50+', null, 50, null],
            ],
            'kataB' => [
                ['U8 - Cicibani', 'U8 - Cicibanke', 5, 7],
                ['U10 - Mlađi učenici', 'U10 - Mlađe učenice', 8, 9],
                ['U12 - Učenici', 'U12 - Učenice', 10, 11],
                ['U14 - Mlađi kadeti', 'U14 - Mlađe kadetkinje', 12, 13],
            ],
            'kumite' => [
                ['U8 - Cicibani', 'U8 - Cicibanke', 6, 7, 60, '-24 -28 -32 -36 +36', '-24 -28 -32 -36 +36'],
                ['U10 - Mlađi učenici', 'U10 - Mlađe učenice', 8, 9, 60, '-27 -31 -35 -39 -43 +43', '-27 -31 -35 -39 -43 +43'],
                ['U12 - Učenici', 'U12 - Učenice', 10, 11, 90, '-34 -39 -44 -49 -54 +54', '-34 -39 -44 -49 -54 +54'],
                ['U14 - Mlađi kadeti', 'U14 - Mlađe kadetkinje', 12, 13, 90, '-40 -45 -50 -55 -60 -65 +65', '-37 -42 -47 -52 -57 +57'],
                ['Kadeti', 'Kadetkinje', 14, 15, 120, self::CADETS_MALE, self::CADETS_FEMALE],
                ['Juniori', 'Juniorke', 16, 17, 120, self::JUNIORS_MALE, self::JUNIORS_FEMALE],
                ['Mlađi seniori', 'Mlađe seniorke', 18, 20, 180, self::SENIORS_MALE, self::SENIORS_FEMALE],
                ['Seniori', 'Seniorke', 18, null, 180, self::SENIORS_MALE, self::SENIORS_FEMALE],
                ['Veterani 35+', 'Veteranke 35+', 35, null, 120, self::VETERANS_MALE, self::VETERANS_FEMALE],
                ['Veterani 50+', 'Veteranke 50+', 50, null, 120, self::VETERANS_MALE, self::VETERANS_FEMALE],
            ],
        ];
    }

    /**
     * Prvenstvo Hrvatske: pages 4 and 5 of the HKS document.
     *
     * @return Tables
     */
    private function nationalChampionship(): array
    {
        return [
            'kata' => [
                ['U7 - Dječaci', 'U7 - Djevojčice', 6, 6],
                ['U8 - Dječaci', 'U8 - Djevojčice', 7, 7],
                ['U9 - Dječaci', 'U9 - Djevojčice', 8, 8],
                ['U10 - Dječaci', 'U10 - Djevojčice', 9, 9],
                ['U11 - Dječaci', 'U11 - Djevojčice', 10, 10],
                ['U12 - Dječaci', 'U12 - Djevojčice', 11, 11],
                ['U13 - Dječaci', 'U13 - Djevojčice', 12, 12],
                ['U14 - Dječaci', 'U14 - Djevojčice', 13, 13],
                ['Kadeti', 'Kadetkinje', 14, 15],
                ['Juniori', 'Juniorke', 16, 17],
                ['Mlađi seniori', 'Mlađe seniorke', 18, 20],
                ['Seniori', 'Seniorke', 16, null],
                ['Veterani 35+', 'Veteranke 35+', 35, null],
                ['Veterani 50+', null, 50, null],
            ],
            'kataB' => [],
            'kumite' => [
                ['U8 - Dječaci', 'U8 - Djevojčice', 6, 7, 60, '-24 -27 -30 -33 +33', '-24 -27 -30 -33 +33'],
                ['U9 - Dječaci', 'U9 - Djevojčice', 8, 8, 60, '-26 -29 -32 -35 -38 +38', '-28 -31 -34 -37 +37'],
                ['U10 - Dječaci', 'U10 - Djevojčice', 9, 9, 60, '-32 -35 -38 -41 -44 +44', '-30 -33 -36 -39 -42 +42'],
                ['U11 - Dječaci', 'U11 - Djevojčice', 10, 10, 90, '-32 -36 -40 -44 -48 -52 +52', '-35 -39 -43 -47 -51 +51'],
                ['U12 - Dječaci', 'U12 - Djevojčice', 11, 11, 90, '-37 -41 -45 -49 -53 -57 +57', '-38 -42 -46 -50 -54 +54'],
                ['U13 - Dječaci', 'U13 - Djevojčice', 12, 12, 90, '-40 -45 -50 -55 -60 +60', '-39 -44 -49 -54 +54'],
                ['U14 - Dječaci', 'U14 - Djevojčice', 13, 13, 90, '-40 -45 -50 -55 +55', '-42 -47 -52 +52'],
                ['Kadeti', 'Kadetkinje', 14, 15, 120, self::CADETS_MALE, self::CADETS_FEMALE],
                ['Juniori', 'Juniorke', 16, 17, 120, self::JUNIORS_MALE, self::JUNIORS_FEMALE],
                ['Mlađi seniori', 'Mlađe seniorke', 18, 20, 180, self::SENIORS_MALE, self::SENIORS_FEMALE],
                ['Seniori', 'Seniorke', 18, null, 180, self::SENIORS_MALE, self::SENIORS_FEMALE],
                ['Veterani 35+', 'Veteranke 35+', 35, null, 120, self::VETERANS_MALE, self::VETERANS_FEMALE],
                ['Veterani 50+', 'Veteranke 50+', 50, null, 120, self::VETERANS_MALE, self::VETERANS_FEMALE],
            ],
        ];
    }

    /**
     * Kup Hrvatske: page 6 of the HKS document. The children's groups are listed by birth year
     * (2018–2013 in 2026), so they are named from the competition year. The document gives no ages for
     * seniors, so the 16+ (kata) and 18+ (kumite) of the other two sets are used.
     *
     * @return Tables
     */
    private function nationalCup(int $year): array
    {
        $kata = [];

        foreach (range(8, 13) as $age) {
            $kata[] = [($year - $age).'. Dječaci', ($year - $age).'. Djevojčice', $age, $age];
        }

        array_push(
            $kata,
            ['Kadeti', 'Kadetkinje', 14, 15],
            ['Juniori', 'Juniorke', 16, 17],
            ['Mlađi seniori', 'Mlađe seniorke', 18, 20],
            ['Seniori', 'Seniorke', 16, null],
            ['Veterani 35+', 'Veteranke 35+', 35, null],
            ['Veterani 50+', 'Veteranke 50+', 50, null],
        );

        return [
            'kata' => $kata,
            'kataB' => [],
            'kumite' => [
                [($year - 10).'. Dječaci', ($year - 10).'. Djevojčice', 10, 10, 60, '-30 -35 -40 -45 +45', '-30 -34 -37 -40 +40'],
                [($year - 11).'. Dječaci', ($year - 11).'. Djevojčice', 11, 11, 60, '-35 -40 -45 -50 +50', '-32 -36 -40 -44 +44'],
                [($year - 12).'. Dječaci', ($year - 12).'. Djevojčice', 12, 12, 90, '-37 -42 -47 -52 +52', '-35 -40 -45 -50 +50'],
                [($year - 13).'. Dječaci', ($year - 13).'. Djevojčice', 13, 13, 90, '-40 -45 -50 -55 +55', '-39 -44 -49 -54 +54'],
                ['Kadeti', 'Kadetkinje', 14, 15, 120, self::CADETS_MALE, self::CADETS_FEMALE],
                ['Juniori', 'Juniorke', 16, 17, 120, self::JUNIORS_MALE, self::JUNIORS_FEMALE],
                ['Mlađi seniori', 'Mlađe seniorke', 18, 20, 180, self::SENIORS_MALE, self::SENIORS_FEMALE],
                ['Seniori', 'Seniorke', 18, null, 180, self::SENIORS_MALE, self::SENIORS_FEMALE],
                ['Veterani 35+', 'Veteranke 35+', 35, null, 120, self::VETERANS_MALE, self::VETERANS_FEMALE],
                ['Veterani 50+', 'Veteranke 50+', 50, null, 120, self::VETERANS_MALE, self::VETERANS_FEMALE],
            ],
        ];
    }
}
