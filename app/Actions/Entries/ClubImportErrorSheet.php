<?php

namespace App\Actions\Entries;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The rows of a club's file that could not be imported, in the template's columns plus the reason, so the
 * club can fix them and send the file back.
 */
class ClubImportErrorSheet
{
    public const ERROR_HEADER = 'Greška';

    /**
     * Write the rows with problems to a temporary file and return its path.
     */
    public function build(ClubImportPreview $preview): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('greske_', true).'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName(EntryTemplate::SHEET);
        $sheet->setColumnWidth(16, 1, 2);
        $sheet->setColumnWidth(8, 3);
        $sheet->setColumnWidth(16, 4);
        $sheet->setColumnWidth(10, 5);
        $sheet->setColumnWidth(8, 6, 7);
        $sheet->setColumnWidth(60, 8);
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));

        $writer->addRow(Row::fromValues([...EntryTemplate::HEADER, self::ERROR_HEADER], (new Style)->setFontBold()->setBackgroundColor(Color::rgb(221, 235, 247))));

        foreach ($preview->errorRows() as $row) {
            $writer->addRow(Row::fromValues([
                $row->raw['first_name'] ?? '',
                $row->raw['last_name'] ?? '',
                $row->raw['gender'] ?? '',
                $row->raw['birth_date'] ?? '',
                $row->raw['weight'] ?? '',
                $row->raw['kata'] ?? '',
                $row->raw['kumite'] ?? '',
                implode(' ', $row->messages),
            ]));
        }

        $writer->close();

        return $path;
    }
}
