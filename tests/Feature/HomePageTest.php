<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_the_placeholder_home_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Hijaz')
            ->assertSee('Rihla Travels');
    }
}
