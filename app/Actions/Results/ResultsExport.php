<?php

namespace App\Actions\Results;

use App\Models\Competition;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The results of a competition as an Excel file to share or archive: the medallists of every category that has any,
 * the club medal table and a sheet with what and when it is. Like the public pages it holds no birth dates, genders or
 * weights. The data is live, so categories that are not finished yet are marked.
 */
class ResultsExport
{
    public const RESULTS_SHEET = 'Rezultati';

    public const STANDINGS_SHEET = 'Poredak klubova';

    public const INFO_SHEET = 'Info';

    public const RESULTS_HEADER = ['Kategorija', 'Disciplina', 'Mjesto', 'Prezime', 'Ime', 'Klub', 'Stanje'];

    public const STANDINGS_HEADER = ['Mjesto', 'Klub', 'Grad', 'Zlato', 'Srebro', 'Bronca', 'Ukupno'];

    public function __construct(
        private readonly CategoryResults $results,
        private readonly ClubStandings $standings,
    ) {}

    /**
     * Write the export to a temporary file and return its path.
     */
    public function build(Competition $competition): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('rezultati_', true).'.xlsx';
        $header = (new Style)->setFontBold()->setBackgroundColor(Color::rgb(221, 235, 247));

        $writer = new Writer;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName(self::RESULTS_SHEET);
        $sheet->setColumnWidth(36, 1);
        $sheet->setColumnWidth(12, 2);
        $sheet->setColumnWidth(8, 3);
        $sheet->setColumnWidth(18, 4, 5);
        $sheet->setColumnWidth(28, 6);
        $sheet->setColumnWidth(12, 7);
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        $writer->addRow(Row::fromValues(self::RESULTS_HEADER, $header));

        foreach ($this->results->handle($competition) as $row) {
            $discipline = __(Str::headline($row->category->discipline->value));
            $state = $row->complete ? 'Završeno' : 'U tijeku';

            foreach ([1 => $row->first, 2 => $row->second, 3 => $row->third] as $place => $entries) {
                foreach ($entries as $entry) {
                    $writer->addRow(Row::fromValues([
                        $row->category->name,
                        $discipline,
                        $place,
                        $entry->competitor->last_name,
                        $entry->competitor->first_name,
                        $entry->competitor->club->name,
                        $state,
                    ]));
                }
            }
        }

        $standings = $writer->addNewSheetAndMakeItCurrent();
        $standings->setName(self::STANDINGS_SHEET);
        $standings->setColumnWidth(8, 1);
        $standings->setColumnWidth(28, 2);
        $standings->setColumnWidth(18, 3);
        $standings->setColumnWidth(10, 4, 5, 6, 7);
        $standings->setSheetView((new SheetView)->setFreezeRow(2));
        $writer->addRow(Row::fromValues(self::STANDINGS_HEADER, $header));

        foreach ($this->standings->handle($competition) as $row) {
            $writer->addRow(Row::fromValues([$row->rank, $row->club, $row->city ?? '', $row->gold, $row->silver, $row->bronze, $row->total]));
        }

        $info = $writer->addNewSheetAndMakeItCurrent();
        $info->setName(self::INFO_SHEET);
        $info->setColumnWidth(16, 1);
        $info->setColumnWidth(60, 2);
        $writer->addRow(Row::fromValues(['Natjecanje', $competition->name]));
        $writer->addRow(Row::fromValues(['Datum', $competition->date->format('d.m.Y.')]));
        $writer->addRow(Row::fromValues(['Mjesto', $competition->venue]));
        $writer->addRow(Row::fromValues(['Generirano', now()->format('d.m.Y. H:i')]));

        $writer->close();

        return $path;
    }
}
