<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\Mrz;
use Tests\TestCase;

class MrzTest extends TestCase
{
    /** The ICAO specimen passport: every check digit known good. */
    private const LINE1 = 'P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<';

    private const LINE2 = 'L898902C36UTO7408122F1204159ZE184226B<<<<<10';

    public function test_a_good_mrz_is_parsed_and_passes_its_check_digits(): void
    {
        $mrz = Mrz::parse("Some header text\n".self::LINE1."\n".self::LINE2."\n");

        $this->assertNotNull($mrz);
        $this->assertTrue($mrz['valid'], json_encode($mrz['checks']));
        $this->assertSame('L898902C3', $mrz['passport_number']);
        $this->assertSame('ERIKSSON', $mrz['surname']);
        $this->assertSame('ANNA MARIA', $mrz['given_names']);
        $this->assertSame('1974-08-12', $mrz['date_of_birth']);
        $this->assertSame('female', $mrz['gender']);
        $this->assertSame('2012-04-15', $mrz['expires_on']);
        $this->assertSame('UTO', $mrz['nationality']);
    }

    public function test_an_ocr_mistake_in_a_digit_field_is_repaired_and_a_real_error_is_caught(): void
    {
        // O for 0 and I for 1 inside the date of birth: repaired before checking.
        $smudged = str_replace('7408122', '74O8I22', self::LINE2);
        $this->assertTrue(Mrz::parse(self::LINE1."\n".$smudged)['valid']);

        // A wrong digit that is not a known confusion fails its own check.
        $wrong = substr_replace(self::LINE2, '7408132', 13, 7);
        $mrz = Mrz::parse(self::LINE1."\n".$wrong);
        $this->assertFalse($mrz['valid']);
        $this->assertFalse($mrz['checks']['date_of_birth']);
    }

    public function test_spaces_and_short_lines_are_tolerated_and_no_mrz_is_null(): void
    {
        $spaced = 'P<UTO ERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<'."\n".'L898902C36UTO 7408122F1204159ZE184226B<<<<<10';
        $this->assertTrue(Mrz::parse($spaced)['valid']);
        $this->assertNull(Mrz::parse("REPUBLIC OF MALDIVES\nPASSPORT\nName AHMED"));
    }

    public function test_the_check_digit_matches_the_icao_examples(): void
    {
        $this->assertSame(3, Mrz::checkDigit('L898902C3'[0] === 'L' ? 'L898902C' : ''));
        $this->assertSame(2, Mrz::checkDigit('740812'));
        $this->assertSame(9, Mrz::checkDigit('120415'));
    }
}
