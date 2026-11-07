<?php

namespace App\Actions\Entries;

use App\Enums\Discipline;
use App\Enums\Gender;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Exception\OpenSpoutException;

/**
 * Imports the sheet a club filled in from the entry template: the club is chosen by the user and the
 * categories are found from each competitor's gender, birth year and weight.
 */
class ImportClubEntries
{
    /**
     * Normalised header names accepted for every column.
     *
     * @var array<string, array<int, string>>
     */
    private const HEADERS = [
        'first_name' => ['ime', 'first name'],
        'last_name' => ['prezime', 'last name'],
        'gender' => ['spol', 'gender'],
        'birth_date' => ['datum rodenja', 'date of birth'],
        'weight' => ['tezina', 'weight'],
        'kata' => ['kata'],
        'kumite' => ['kumite'],
    ];

    /**
     * Column names shown in error messages.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'first_name' => 'Ime',
        'last_name' => 'Prezime',
        'gender' => 'Spol',
        'birth_date' => 'Datum rođenja',
        'weight' => 'Težina',
        'kata' => 'Kata',
        'kumite' => 'Kumite',
    ];

    private const REQUIRED = ['first_name', 'last_name', 'gender', 'birth_date', 'kata', 'kumite'];

    public function __construct(
        private readonly EntrySheet $sheet,
        private readonly CategoryMatcher $matcher,
    ) {}

    /**
     * Import a club's entries from an XLSX or CSV file. Nothing is saved unless every row is valid.
     */
    public function handle(Competition $competition, Club $club, string $path, string $extension): ImportEntriesResult
    {
        try {
            return $this->import($competition, $club, $path, $extension);
        } catch (OpenSpoutException $exception) {
            report($exception);

            return new ImportEntriesResult(errors: [__('The file could not be read. Is it a valid XLSX or CSV file?')]);
        }
    }

    private function import(Competition $competition, Club $club, string $path, string $extension): ImportEntriesResult
    {
        $categories = $competition->categories()->get();

        $drawn = Bout::query()
            ->whereIn('category_id', $categories->pluck('id'))
            ->distinct()
            ->pluck('category_id')
            ->flip();

        $existingEntries = Entry::query()
            ->whereIn('category_id', $categories->pluck('id'))
            ->get(['category_id', 'competitor_id'])
            ->mapWithKeys(fn (Entry $entry) => [$entry->category_id.'|'.$entry->competitor_id => true]);

        /** @var array<string, int>|null $columns */
        $columns = null;
        /** @var array<int, string> $errors */
        $errors = [];
        /** @var array<string, Competitor> $competitors */
        $competitors = [];
        /** @var array<string, array{competitor: Competitor, category: Category, weight: ?string}> $planned */
        $planned = [];
        $skipped = 0;

        foreach ($this->sheet->rows($path, $extension) as $number => $cells) {
            $values = $this->sheet->values($cells);

            if (implode('', $values) === '') {
                continue;
            }

            if ($columns === null) {
                $columns = $this->sheet->mapColumns($values, self::HEADERS);
                $missing = array_diff(self::REQUIRED, array_keys($columns));

                if ($missing !== []) {
                    return new ImportEntriesResult(errors: [
                        __('Missing required column(s): :columns.', ['columns' => implode(', ', array_map(fn (string $field) => self::LABELS[$field], $missing))]),
                    ]);
                }

                continue;
            }

            $row = [];
            foreach (self::HEADERS as $field => $aliases) {
                $row[$field] = isset($columns[$field]) ? ($values[$columns[$field]] ?? '') : '';
            }

            $problems = [];

            foreach (['first_name', 'last_name'] as $field) {
                if ($row[$field] === '') {
                    $problems[] = __('The :field is empty.', ['field' => self::LABELS[$field]]);
                }
            }

            $gender = $this->sheet->parseGender($row['gender']);
            if ($gender === null) {
                $problems[] = __('Unknown gender ":value".', ['value' => $row['gender']]);
            }

            $birthDate = $this->sheet->parseDate($row['birth_date']);
            if ($birthDate === null) {
                $problems[] = __('Invalid date of birth ":value".', ['value' => $row['birth_date']]);
            }

            $weight = null;
            if ($row['weight'] !== '') {
                $weight = $this->sheet->parseWeight($row['weight']);

                if ($weight === null) {
                    $problems[] = __('Invalid weight ":value".', ['value' => $row['weight']]);
                }
            }

            $disciplines = [];
            foreach ([Discipline::Kata, Discipline::Kumite] as $discipline) {
                $marked = $this->parseFlag($row[$discipline->value]);

                if ($marked === null) {
                    $problems[] = __('Invalid value ":value" in column :column. Use DA or leave it empty.', [
                        'value' => $row[$discipline->value],
                        'column' => self::LABELS[$discipline->value],
                    ]);
                } elseif ($marked) {
                    $disciplines[] = $discipline;
                }
            }

            if ($disciplines === [] && ! $this->hasFlagProblem($row)) {
                $problems[] = __('Neither Kata nor Kumite is marked.');
            }

            if (in_array(Discipline::Kumite, $disciplines, true) && $row['weight'] === '') {
                $problems[] = __('Enter the weight for Kumite.');
            }

            if ($problems !== []) {
                $errors[] = $this->rowError($number, implode(' ', $problems));

                continue;
            }

            $competitorKey = implode('|', [$row['first_name'], $row['last_name'], $birthDate->format('Y-m-d')]);
            $competitor = $competitors[$competitorKey] ??= $this->sheet->findOrBuildCompetitor($club, $row['first_name'], $row['last_name'], $gender, $birthDate);

            if ($competitor->gender !== $gender) {
                $errors[] = $this->rowError($number, __('Gender ":value" differs from the gender already recorded for this competitor.', ['value' => $row['gender']]));

                continue;
            }

            foreach ($disciplines as $discipline) {
                $entryWeight = $discipline === Discipline::Kumite ? $weight : null;
                $category = $this->matcher->match($categories, $discipline, $gender, $birthDate->year, $entryWeight !== null ? (float) $entryWeight : null);

                if ($category === null) {
                    $errors[] = $this->rowError($number, $this->noCategory($discipline, $gender, $birthDate->year, $entryWeight));

                    continue;
                }

                if (isset($drawn[$category->id])) {
                    $errors[] = $this->rowError($number, __('Category ":value" already has a draw.', ['value' => $category->name]));

                    continue;
                }

                $entryKey = $category->id.'|'.$competitorKey;

                if (isset($planned[$entryKey]) || ($competitor->exists && isset($existingEntries[$category->id.'|'.$competitor->id]))) {
                    $skipped++;

                    continue;
                }

                $planned[$entryKey] = ['competitor' => $competitor, 'category' => $category, 'weight' => $entryWeight];
            }
        }

        if ($columns === null) {
            return new ImportEntriesResult(errors: [__('The file is empty.')]);
        }

        if ($errors !== []) {
            return new ImportEntriesResult(entriesSkipped: $skipped, errors: $errors);
        }

        return DB::transaction(function () use ($club, $planned, $skipped): ImportEntriesResult {
            $competitorsCreated = 0;

            foreach ($planned as $item) {
                if (! $item['competitor']->exists) {
                    $item['competitor']->club_id = $club->id;
                    $item['competitor']->save();
                    $competitorsCreated++;
                }

                Entry::create([
                    'category_id' => $item['category']->id,
                    'competitor_id' => $item['competitor']->id,
                    'weight' => $item['weight'],
                ]);
            }

            return new ImportEntriesResult(count($planned), $skipped, $competitorsCreated, 0);
        });
    }

    /**
     * Whether the Kata or Kumite cell holds something other than DA/NE, which is reported on its own.
     *
     * @param  array<string, string>  $row
     */
    private function hasFlagProblem(array $row): bool
    {
        return $this->parseFlag($row['kata']) === null || $this->parseFlag($row['kumite']) === null;
    }

    /**
     * True for DA, false for an empty cell or NE, null for anything else.
     */
    private function parseFlag(string $value): ?bool
    {
        return match (Str::lower(Str::ascii($value))) {
            'da', 'd', 'x', 'yes', '1' => true,
            '', 'ne', 'n', 'no', '0' => false,
            default => null,
        };
    }

    private function noCategory(Discipline $discipline, Gender $gender, int $birthYear, ?string $weight): string
    {
        $replace = [
            'discipline' => __(Str::headline($discipline->value)),
            'gender' => __(Str::headline($gender->value)),
            'year' => $birthYear,
        ];

        return $weight === null
            ? __('No :discipline category fits: :gender, born :year.', $replace)
            : __('No :discipline category fits: :gender, born :year, :weight kg.', [...$replace, 'weight' => $weight]);
    }

    private function rowError(int $number, string $problems): string
    {
        return __('Row :row: :problems', ['row' => $number, 'problems' => $problems]);
    }
}
