<?php

namespace Tests\Feature\Public;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PublicTranslationsTest extends TestCase
{
    public function test_every_translatable_string_of_the_public_site_has_a_croatian_translation(): void
    {
        $translations = json_decode((string) file_get_contents(lang_path('hr.json')), true, flags: JSON_THROW_ON_ERROR);

        $files = [
            ...File::allFiles(resource_path('views/pages/public')),
            ...File::allFiles(resource_path('views/components/public')),
            ...File::allFiles(app_path('Actions/Results')),
            ...File::allFiles(app_path('Actions/Timetable')),
            new \SplFileInfo(resource_path('views/layouts/public.blade.php')),
            new \SplFileInfo(resource_path('views/components/bracket.blade.php')),
            new \SplFileInfo(resource_path('views/components/bracket-bout.blade.php')),
        ];

        $missing = [];

        foreach ($files as $file) {
            preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file->getPathname()), $matches);

            preg_match_all("/#\\[Title\\('((?:[^'\\\\]|\\\\.)*)'\\)/", (string) file_get_contents($file->getPathname()), $titles);

            foreach ([...$matches[1], ...$titles[1]] as $key) {
                $key = str_replace("\\'", "'", $key);

                if (! array_key_exists($key, $translations)) {
                    $missing[$key] = $file->getFilename();
                }
            }
        }

        $this->assertSame([], $missing, 'Missing Croatian translations');
    }
}
