<?php

namespace Tests\Feature\Pdf;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PdfTranslationsTest extends TestCase
{
    public function test_every_translatable_string_of_the_pdf_documents_and_their_buttons_has_a_croatian_translation(): void
    {
        $translations = json_decode((string) file_get_contents(lang_path('hr.json')), true, flags: JSON_THROW_ON_ERROR);

        $files = [
            ...File::allFiles(resource_path('views/pdf')),
            ...File::allFiles(resource_path('views/pages/admin/competitions')),
            ...File::allFiles(app_path('Support')),
        ];

        $missing = [];

        foreach ($files as $file) {
            preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $key) {
                $key = str_replace("\\'", "'", $key);

                if (! array_key_exists($key, $translations)) {
                    $missing[$key] = $file->getFilename();
                }
            }
        }

        $this->assertSame([], $missing, 'Missing Croatian translations');
    }
}
