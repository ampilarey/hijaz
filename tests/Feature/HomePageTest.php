<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_the_placeholder_home_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Hijaz Hajj &amp; Umrah', false)
            ->assertSee('/images/brand/hijaz-logo.svg');
    }

    public function test_the_home_page_does_not_mention_rihla(): void
    {
        // Hijaz is its own company; it is only hosted on a rihla.mv subdomain.
        $this->get('/')->assertDontSee('Rihla', false)->assertDontSee('rihla.mv', false);
    }

    public function test_the_logo_files_exist(): void
    {
        foreach (['hijaz-logo.svg', 'hijaz-logo.png', 'hijaz-logo-on-blue.svg', 'hijaz-logo-on-blue.png', 'hijaz-mark.svg', 'hijaz-mark.png', 'favicon.svg'] as $file) {
            $this->assertFileExists(public_path("images/brand/{$file}"));
        }
    }

    public function test_the_business_contact_details_are_recorded(): void
    {
        $this->assertSame('hijazumra@gmail.com', config('hijaz.email'));
        $this->assertSame(['+9609775123', '+9609906869'], array_column(config('hijaz.phones'), 'tel'));
    }
}
