<?php

namespace App\Actions\Entries;

use App\Models\Bout;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Exception\OpenSpoutException;

class ImportEntries
{
    /**
     * Normalised header names accepted for every column.
     *
     * @var array<string, array<int, string>>
     */
    private const HEADERS = [
        'club' => ['klub', 'club'],
        'city' => ['grad', 'city'],
        'first_name' => ['ime', 'first name'],
        'last_name' => ['prezime', 'last name'],
        'gender' => ['spol', 'gender'],
        'birth_date' => ['datum rodenja', 'date of birth'],
        'category' => ['kategorija', 'category'],
        'weight' => ['tezina', 'weight'],
    ];

    /**
     * Column names shown in error messages.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'club' => 'Klub',
        'city' => 'Grad',
        'first_name' => 'Ime',
        'last_name' => 'Prezime',
        'gender' => 'Spol',
        'birth_date' => 'Datum rođenja',
        'category' => 'Kategorija',
        'weight' => 'Težina',
    ];

    private const REQUIRED = ['club', 'first_name', 'last_name', 'gender', 'birth_date', 'category'];

    public function __construct(
        private readonly EntryEligibility $eligibility,
        private readonly EntrySheet $sheet,
    ) {}

    /**
     * Import entries from an XLSX or CSV file. Nothing is saved unless every row is valid.
     */
    public function handle(Competition $competition, string $path, string $extension): ImportEntriesResult
    {
        try {
            return $this->import($competition, $path, $extension);
        } catch (OpenSpoutException $exception) {
            report($exception);

            return new ImportEntriesResult(errors: [__('The file could not be read. Is it a valid XLSX or CSV file?')]);
        }
    }

    private function import(Competition $competition, string $path, string $extension): ImportEntriesResult
    {
        $categories = $competition->categories()->get()->keyBy(fn (Category $category) => trim($category->name));

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
        /** @var array<string, Club> $clubs */
        $clubs = [];
        /** @var array<string, Competitor> $competitors */
        $competitors = [];
        /** @var array<string, array{club: Club, competitor: Competitor, category: Category, weight: ?string}> $planned */
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

            foreach (['club', 'first_name', 'last_name'] as $field) {
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

            $category = $categories->get($row['category']);
            if ($category === null) {
                $problems[] = __('Unknown category ":value".', ['value' => $row['category']]);
            } elseif (isset($drawn[$category->id])) {
                $problems[] = __('Category ":value" already has a draw.', ['value' => $row['category']]);
            }

            $weight = null;
            if ($row['weight'] !== '') {
                $weight = $this->sheet->parseWeight($row['weight']);

                if ($weight === null) {
                    $problems[] = __('Invalid weight ":value".', ['value' => $row['weight']]);
                }
            }

            if ($problems !== []) {
                $errors[] = __('Row :row: :problems', ['row' => $number, 'problems' => implode(' ', $problems)]);

                continue;
            }

            $club = $clubs[$clubKey = $row['club'].'|'.$row['city']] ??= $this->findOrBuildClub($row['club'], $row['city']);

            $competitorKey = implode('|', [$clubKey, $this->sheet->normalizeName($row['first_name']), $this->sheet->normalizeName($row['last_name']), $birthDate->format('Y-m-d')]);
            $competitor = $competitors[$competitorKey] ??= $this->sheet->findOrBuildCompetitor($club, $row['first_name'], $row['last_name'], $gender, $birthDate);

            if ($competitor->gender !== $gender) {
                $errors[] = __('Row :row: :problems', [
                    'row' => $number,
                    'problems' => __('Gender ":value" differs from the gender already recorded for this competitor.', ['value' => $row['gender']]),
                ]);

                continue;
            }

            $violations = $this->eligibility->violations($category, $competitor, $weight);

            if ($violations !== []) {
                $errors[] = __('Row :row: :problems', ['row' => $number, 'problems' => implode(' ', $violations)]);

                continue;
            }

            $entryKey = $category->id.'|'.$competitorKey;

            if (isset($planned[$entryKey]) || ($competitor->exists && isset($existingEntries[$category->id.'|'.$competitor->id]))) {
                $skipped++;

                continue;
            }

            $planned[$entryKey] = ['club' => $club, 'competitor' => $competitor, 'category' => $category, 'weight' => $weight];
        }

        if ($columns === null) {
            return new ImportEntriesResult(errors: [__('The file is empty.')]);
        }

        if ($errors !== []) {
            return new ImportEntriesResult(entriesSkipped: $skipped, errors: $errors);
        }

        return DB::transaction(function () use ($planned, $skipped): ImportEntriesResult {
            $clubsCreated = 0;
            $competitorsCreated = 0;

            foreach ($planned as $item) {
                if (! $item['club']->exists) {
                    $item['club']->save();
                    $clubsCreated++;
                }

                if (! $item['competitor']->exists) {
                    $item['competitor']->club_id = $item['club']->id;
                    $item['competitor']->save();
                    $competitorsCreated++;
                }

                Entry::create([
                    'category_id' => $item['category']->id,
                    'competitor_id' => $item['competitor']->id,
                    'weight' => $item['weight'],
                ]);
            }

            return new ImportEntriesResult(count($planned), $skipped, $competitorsCreated, $clubsCreated);
        });
    }

    private function findOrBuildClub(string $name, string $city): Club
    {
        return Club::where('name', $name)->where('city', $city !== '' ? $city : null)->first()
            ?? new Club(['name' => $name, 'city' => $city !== '' ? $city : null, 'country' => 'HR']);
    }
}
