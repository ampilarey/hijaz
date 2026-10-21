<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\Island;
use App\Services\Documents\FieldParser;
use App\Services\Documents\Readers\Extraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_id_card_text_yields_number_name_dates_gender_and_island(): void
    {
        Island::factory()->create(['name' => 'Thinadhoo', 'atoll_code' => 'GDh', 'atoll_name' => 'Gaafu Dhaalu']);

        $x = new Extraction('test', rawText: "REPUBLIC OF MALDIVES\nIDENTITY CARD\nNAME\nAHMED MOHAMED DIDI\nID NUMBER A123456\nSEX: M\nDATE OF BIRTH 12/08/1974\nADDRESS\nBLUE HOUSE, GDH. THINADHOO\nEXPIRY 01/01/2030\n");
        FieldParser::apply($x, Document::ID_CARD);

        $this->assertSame('A123456', $x->fields['id_number']);
        $this->assertSame('AHMED MOHAMED DIDI', $x->fields['name_en']);
        $this->assertSame('male', $x->fields['gender']);
        $this->assertSame('1974-08-12', $x->fields['date_of_birth']);
        $this->assertSame('2030-01-01', $x->fields['id_expires_on']);
        $this->assertSame((string) Island::first()->id, $x->fields['island_id']);
        $this->assertStringContainsString('THINADHOO', $x->fields['address']);
    }

    public function test_a_passport_text_with_an_mrz_is_trusted_over_the_printed_fields(): void
    {
        $x = new Extraction('test', rawText: "PASSPORT\nPassport No. P9999999\nDate of birth 01 JAN 1980\n".'P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<'."\n".'L898902C36UTO7408122F1204159ZE184226B<<<<<10');
        FieldParser::apply($x, Document::PASSPORT);

        $this->assertTrue($x->mrzValid);
        $this->assertSame('L898902C3', $x->fields['passport_number']);
        $this->assertSame('1974-08-12', $x->fields['date_of_birth']);
        $this->assertSame('2012-04-15', $x->fields['passport_expires_on']);
        $this->assertSame('ANNA MARIA ERIKSSON', $x->fields['name_en']);
        $this->assertGreaterThan(0.9, $x->confidence['passport_number']);
    }

    public function test_a_visa_text_yields_its_number_passport_and_validity(): void
    {
        $x = new Extraction('test', rawText: "KINGDOM OF SAUDI ARABIA\nUMRAH VISA\nVisa No: 1234567890\nFull Name: AHMED MOHAMED DIDI\nPassport No: P1234567\nDate of Issue: 15/06/2027\nDate of Expiry: 15/09/2027\n");
        FieldParser::apply($x, Document::VISA);

        $this->assertSame('1234567890', $x->fields['number']);
        $this->assertSame('P1234567', $x->fields['passport_number']);
        $this->assertSame('AHMED MOHAMED DIDI', $x->fields['name_en']);
        $this->assertSame('2027-06-15', $x->fields['issued_at']);
        $this->assertSame('2027-09-15', $x->fields['expires_at']);
    }

    public function test_a_bank_slip_text_yields_amount_currency_reference_and_accounts(): void
    {
        $x = new Extraction('test', rawText: "Bank of Maldives\nTransfer successful\nAmount MVR 28,500.00\nTo account 7730000123456\nFrom account 7710000999999\nReference No. BML2027061500123\nRemarks: HJZ-B-2027-0012\nDate 15/06/2027");
        FieldParser::apply($x, Document::BANK_SLIP);

        $this->assertSame('MVR', $x->fields['currency']);
        $this->assertSame('28500.00', $x->fields['amount']);
        $this->assertSame('BML2027061500123', $x->fields['reference']);
        $this->assertSame('7730000123456', $x->fields['payee_account']);
        $this->assertSame('7710000999999', $x->fields['payer_account']);
        $this->assertStringContainsString('HJZ-B-2027-0012', $x->fields['remarks']);
        $this->assertSame('2027-06-15', $x->fields['paid_on']);
    }
}
