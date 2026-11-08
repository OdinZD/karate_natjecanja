<?php

namespace Tests\Feature;

use App\Actions\Categories\ImportOfficialCategories;
use App\Actions\Entries\ImportClubEntries;
use App\Enums\CategorySet;
use App\Enums\CompetitionStatus;
use App\Models\Club;
use App\Models\Competition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    private ?string $file = null;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if ($this->file !== null) {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    public function test_the_application_runs_in_croatian_time(): void
    {
        $this->assertSame('Europe/Zagreb', config('app.timezone'));
        $this->assertSame('Europe/Zagreb', now()->timezoneName);
        $this->assertSame('Europe/Zagreb', date_default_timezone_get());
    }

    /**
     * @return array<int, string>
     */
    private function warningsAt(string $now): array
    {
        $competition = Competition::factory()->create([
            'date' => '2026-05-10',
            'status' => CompetitionStatus::Registration,
            'registration_deadline' => '2026-04-01 12:00:00',
        ]);
        app(ImportOfficialCategories::class)->handle($competition, CategorySet::Tournament);

        $this->file = sys_get_temp_dir().'/'.uniqid('tz_', true).'.xlsx';
        $writer = new Writer;
        $writer->openToFile($this->file);
        $writer->addRow(Row::fromValues(['Ime', 'Prezime', 'Spol', 'Datum rođenja', 'Težina', 'Kata', 'Kumite']));
        $writer->close();

        Carbon::setTestNow(Carbon::parse($now, 'Europe/Zagreb'));

        return app(ImportClubEntries::class)->analyze($competition->refresh(), Club::factory()->create(), $this->file, 'xlsx')->warnings;
    }

    public function test_the_deadline_is_compared_with_croatian_wall_clock_time(): void
    {
        // 12:30 in Zagreb is 10:30 UTC: under UTC the 12:00 deadline would not have passed yet.
        $this->assertSame(['The registration deadline passed on 01.04.2026. 12:00.'], $this->warningsAt('2026-04-01 12:30'));
    }

    public function test_a_deadline_that_has_not_passed_in_croatian_time_gives_no_warning(): void
    {
        $this->assertSame([], $this->warningsAt('2026-04-01 11:30'));
    }
}
