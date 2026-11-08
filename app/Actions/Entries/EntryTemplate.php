<?php

namespace App\Actions\Entries;

use App\Enums\Gender;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Competitor;
use App\Models\Entry;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The Excel file the admin sends to a club: one sheet to fill in, one with instructions and the
 * categories of the competition. It is read back by ImportClubEntries.
 *
 * Built for a club, the sheet already lists the children the club is known to have and a hidden
 * sheet records who the file was prepared for.
 */
class EntryTemplate
{
    public const SHEET = 'Prijave';

    public const META_SHEET = 'Klub';

    public const HEADER = ['Ime', 'Prezime', 'Spol', 'Datum rođenja', 'Težina', 'Kata', 'Kumite'];

    /** Reminder column of the club template, ignored by the import. */
    public const LAST_WEIGHT_HEADER = 'Prošla težina (info)';

    /**
     * Write the template to a temporary file and return its path.
     */
    public function build(Competition $competition, ?Club $club = null): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('prijave_', true).'.xlsx';
        $bold = (new Style)->setFontBold();

        $writer = new Writer;
        $writer->openToFile($path);

        $entries = $writer->getCurrentSheet();
        $entries->setName(self::SHEET);
        $entries->setColumnWidth(16, 1, 2);
        $entries->setColumnWidth(8, 3);
        $entries->setColumnWidth(16, 4);
        $entries->setColumnWidth(10, 5);
        $entries->setColumnWidth(8, 6, 7);
        $header = $club === null ? self::HEADER : [...self::HEADER, self::LAST_WEIGHT_HEADER];
        $writer->addRow(Row::fromValues($header, (new Style)->setFontBold()->setBackgroundColor(Color::rgb(221, 235, 247))));

        if ($club !== null) {
            $entries->setColumnWidth(20, 8);
            $entries->setSheetView((new SheetView)->setFreezeRow(2));

            foreach ($this->knownChildren($club) as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $instructions = $writer->addNewSheetAndMakeItCurrent();
        $instructions->setName('Upute');
        $instructions->setColumnWidth(46, 1);
        $instructions->setColumnWidth(14, 2);
        $instructions->setColumnWidth(14, 3);

        $writer->addRow(Row::fromValues(['Upute za ispunjavanje'], $bold));
        $writer->addRow(Row::fromValues(['Natjecanje: '.$competition->name]));
        $writer->addRow(Row::fromValues(['Datum: '.$competition->date->format('d.m.Y.')]));
        $writer->addRow(Row::fromValues(['']));

        foreach ([
            'Ispunite samo list "'.self::SHEET.'". Jedan red je jedno dijete.',
            'Ime, Prezime: upišite kako piše u dokumentima.',
            'Spol: M ili Ž.',
            'Datum rođenja: npr. 14.03.2015.',
            'Težina: u kilogramima, npr. 33,5. Obavezna je ako je Kumite = DA.',
            'Kata, Kumite: upišite DA za disciplinu u kojoj dijete nastupa, inače ostavite prazno. Dijete može nastupiti u obje.',
            'Kategorija se određuje automatski prema spolu, godištu i težini.',
            ...($club === null ? [] : [
                'Klub: '.$club->name,
                'Djeca koja su već bila prijavljena upisana su unaprijed. Upišite težinu i DA za disciplinu u kojoj dijete nastupa.',
                'Dijete koje ne nastupa ostavite bez DA, bit će preskočeno. Novo dijete dodajte u novi red.',
                'Stupac "'.self::LAST_WEIGHT_HEADER.'" je samo podsjetnik i ne uvozi se.',
            ]),
        ] as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }

        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['Kategorije ovog natjecanja'], $bold));
        $writer->addRow(Row::fromValues(['Kategorija', 'Godište', 'Težina'], $bold));

        foreach ($competition->categories()->orderBy('sort_order')->orderBy('name')->get() as $category) {
            $writer->addRow(Row::fromValues([
                $category->name,
                $category->birth_year_from.'–'.$category->birth_year_to,
                $category->weightLabel() ?? '',
            ]));
        }

        if ($club !== null) {
            $meta = $writer->addNewSheetAndMakeItCurrent();
            $meta->setName(self::META_SHEET);
            $meta->setIsVisible(false);

            $writer->addRow(Row::fromValues(['club_id', $club->id]));
            $writer->addRow(Row::fromValues(['competition_id', $competition->id]));
            $writer->addRow(Row::fromValues(['Klub', $club->name]));
        }

        $writer->close();

        return $path;
    }

    /**
     * The club's known competitors as template rows, with the weight of their latest weighed entry.
     *
     * @return array<int, array<int, string|float|null>>
     */
    private function knownChildren(Club $club): array
    {
        $competitors = $club->competitors()->orderByName('last_name')->orderByName('first_name')->get();

        $lastWeights = Entry::query()
            ->whereIn('competitor_id', $competitors->pluck('id'))
            ->whereNotNull('weight')
            ->orderBy('id')
            ->get(['competitor_id', 'weight'])
            ->mapWithKeys(fn (Entry $entry) => [$entry->competitor_id => (float) $entry->weight]);

        return $competitors
            ->map(fn (Competitor $competitor) => [
                $competitor->first_name,
                $competitor->last_name,
                $competitor->gender === Gender::Male ? 'M' : 'Ž',
                $competitor->birth_date->format('d.m.Y.'),
                '',
                '',
                '',
                $lastWeights[$competitor->id] ?? '',
            ])
            ->all();
    }
}
