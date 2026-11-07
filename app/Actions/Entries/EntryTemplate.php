<?php

namespace App\Actions\Entries;

use App\Models\Competition;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The Excel file the admin sends to a club: one sheet to fill in, one with instructions and the
 * categories of the competition. It is read back by ImportClubEntries.
 */
class EntryTemplate
{
    public const SHEET = 'Prijave';

    public const HEADER = ['Ime', 'Prezime', 'Spol', 'Datum rođenja', 'Težina', 'Kata', 'Kumite'];

    /**
     * Write the template to a temporary file and return its path.
     */
    public function build(Competition $competition): string
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
        $writer->addRow(Row::fromValues(self::HEADER, (new Style)->setFontBold()->setBackgroundColor(Color::rgb(221, 235, 247))));

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

        $writer->close();

        return $path;
    }
}
