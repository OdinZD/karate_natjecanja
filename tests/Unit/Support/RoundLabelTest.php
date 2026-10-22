<?php

namespace Tests\Unit\Support;

use App\Support\RoundLabel;
use Tests\TestCase;

class RoundLabelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
    }

    public function test_the_last_rounds_have_names_and_earlier_ones_are_numbered(): void
    {
        $this->assertSame('Final', RoundLabel::for(1, 1));

        $this->assertSame('Semifinals', RoundLabel::for(1, 2));
        $this->assertSame('Final', RoundLabel::for(2, 2));

        $this->assertSame('Quarterfinals', RoundLabel::for(1, 3));
        $this->assertSame('Semifinals', RoundLabel::for(2, 3));
        $this->assertSame('Final', RoundLabel::for(3, 3));

        $this->assertSame('Round 1', RoundLabel::for(1, 4));
        $this->assertSame('Quarterfinals', RoundLabel::for(2, 4));
        $this->assertSame('Semifinals', RoundLabel::for(3, 4));
        $this->assertSame('Final', RoundLabel::for(4, 4));
        $this->assertSame('Round 1', RoundLabel::for(1, 5));
        $this->assertSame('Round 2', RoundLabel::for(2, 5));
        $this->assertSame('Quarterfinals', RoundLabel::for(3, 5));
        $this->assertSame('Semifinals', RoundLabel::for(4, 5));
        $this->assertSame('Final', RoundLabel::for(5, 5));
    }

    public function test_the_names_are_translated(): void
    {
        app()->setLocale('hr');

        $this->assertSame('Finale', RoundLabel::for(3, 3));
        $this->assertSame('Polufinale', RoundLabel::for(2, 3));
        $this->assertSame('Četvrtfinale', RoundLabel::for(1, 3));
        $this->assertSame('Kolo 1', RoundLabel::for(1, 4));
    }
}
