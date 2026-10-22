<?php

namespace App\Actions\Entries;

use App\Enums\Gender;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

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

    private const DATE_FORMATS = ['Y-m-d', 'd.m.Y.', 'd.m.Y', 'j.n.Y.', 'j.n.Y'];

    public function __construct(private readonly EntryEligibility $eligibility) {}

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

        foreach ($this->readRows($path, $extension) as $number => $cells) {
            $values = array_map($this->stringValue(...), $cells);

            if (implode('', $values) === '') {
                continue;
            }

            if ($columns === null) {
                $columns = $this->mapColumns($values);
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

            $gender = $this->parseGender($row['gender']);
            if ($gender === null) {
                $problems[] = __('Unknown gender ":value".', ['value' => $row['gender']]);
            }

            $birthDate = $this->parseDate($row['birth_date']);
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
                $weight = str_replace(',', '.', $row['weight']);

                if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $weight) !== 1) {
                    $problems[] = __('Invalid weight ":value".', ['value' => $row['weight']]);
                    $weight = null;
                }
            }

            if ($problems !== []) {
                $errors[] = __('Row :row: :problems', ['row' => $number, 'problems' => implode(' ', $problems)]);

                continue;
            }

            $club = $clubs[$clubKey = $row['club'].'|'.$row['city']] ??= $this->findOrBuildClub($row['club'], $row['city']);

            $competitorKey = implode('|', [$clubKey, $row['first_name'], $row['last_name'], $birthDate->format('Y-m-d')]);
            $competitor = $competitors[$competitorKey] ??= $this->findOrBuildCompetitor($club, $row['first_name'], $row['last_name'], $gender, $birthDate);

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

    /**
     * @return Generator<int, array<int, mixed>>
     */
    private function readRows(string $path, string $extension): Generator
    {
        if (strtolower($extension) === 'xlsx') {
            $options = new XlsxOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new XlsxReader($options);
        } else {
            $reader = new CsvReader($this->csvOptions($path));
        }

        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $index => $row) {
                    yield $index => $row->toArray();
                }

                break;
            }
        } finally {
            $reader->close();
        }
    }

    private function csvOptions(string $path): CsvOptions
    {
        $contents = (string) file_get_contents($path);
        $firstLine = strtok($contents, "\n") ?: '';

        $options = new CsvOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->FIELD_DELIMITER = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $options->ENCODING = 'WINDOWS-1250';
        }

        return $options;
    }

    /**
     * @param  array<int, string>  $headerRow
     * @return array<string, int>
     */
    private function mapColumns(array $headerRow): array
    {
        $columns = [];

        foreach ($headerRow as $index => $header) {
            $normalised = Str::lower(Str::ascii(trim($header)));

            foreach (self::HEADERS as $field => $aliases) {
                if (! isset($columns[$field]) && in_array($normalised, $aliases, true)) {
                    $columns[$field] = $index;
                }
            }
        }

        return $columns;
    }

    private function stringValue(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && floor($value) === $value => (string) (int) $value,
            default => trim((string) $value),
        };
    }

    private function parseGender(string $value): ?Gender
    {
        return match (Str::lower(Str::ascii($value))) {
            'm', 'muski', 'male' => Gender::Male,
            'z', 'zenski', 'female' => Gender::Female,
            default => null,
        };
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        // Date cells that are not formatted as dates in Excel arrive as serial day numbers.
        if (ctype_digit($value) && (int) $value >= 10000 && (int) $value <= 80000) {
            $date = CarbonImmutable::create(1899, 12, 30)->addDays((int) $value);

            return $date->isPast() ? $date : null;
        }

        foreach (self::DATE_FORMATS as $format) {
            if (! CarbonImmutable::canBeCreatedFromFormat($value, '!'.$format)) {
                continue;
            }

            $date = CarbonImmutable::createFromFormat('!'.$format, $value);

            if ($date->format($format) === $value && $date->isPast()) {
                return $date;
            }
        }

        return null;
    }

    private function findOrBuildClub(string $name, string $city): Club
    {
        return Club::where('name', $name)->where('city', $city !== '' ? $city : null)->first()
            ?? new Club(['name' => $name, 'city' => $city !== '' ? $city : null, 'country' => 'HR']);
    }

    private function findOrBuildCompetitor(Club $club, string $firstName, string $lastName, Gender $gender, CarbonImmutable $birthDate): Competitor
    {
        if ($club->exists) {
            $existing = Competitor::where('club_id', $club->id)
                ->where('first_name', $firstName)
                ->where('last_name', $lastName)
                ->whereDate('birth_date', $birthDate)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return new Competitor([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => $gender,
            'birth_date' => $birthDate,
        ]);
    }
}
