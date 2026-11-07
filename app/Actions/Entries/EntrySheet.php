<?php

namespace App\Actions\Entries;

use App\Enums\Gender;
use App\Models\Club;
use App\Models\Competitor;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reading and parsing of the spreadsheet files shared by the entry imports.
 */
class EntrySheet
{
    private const DATE_FORMATS = ['Y-m-d', 'd.m.Y.', 'd.m.Y', 'j.n.Y.', 'j.n.Y'];

    /**
     * The rows of the first sheet of an XLSX or CSV file, keyed by row number.
     *
     * @return Generator<int, array<int, mixed>>
     */
    public function rows(string $path, string $extension): Generator
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
     * @param  array<int, mixed>  $cells
     * @return array<int, string>
     */
    public function values(array $cells): array
    {
        return array_map($this->stringValue(...), $cells);
    }

    /**
     * Index of the first column matching each field's accepted header names.
     *
     * @param  array<int, string>  $headerRow
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, int>
     */
    public function mapColumns(array $headerRow, array $headers): array
    {
        $columns = [];

        foreach ($headerRow as $index => $header) {
            $normalised = Str::lower(Str::ascii(trim($header)));

            foreach ($headers as $field => $aliases) {
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

    public function parseGender(string $value): ?Gender
    {
        return match (Str::lower(Str::ascii($value))) {
            'm', 'muski', 'male' => Gender::Male,
            'z', 'zenski', 'female' => Gender::Female,
            default => null,
        };
    }

    public function parseDate(string $value): ?CarbonImmutable
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

    /**
     * A weight with a decimal point, or null when the value is not a plain number with at most two decimals.
     */
    public function parseWeight(string $value): ?string
    {
        $weight = str_replace(',', '.', $value);

        return preg_match('/^\d{1,3}(\.\d{1,2})?$/', $weight) === 1 ? $weight : null;
    }

    public function findOrBuildCompetitor(Club $club, string $firstName, string $lastName, Gender $gender, CarbonImmutable $birthDate): Competitor
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
