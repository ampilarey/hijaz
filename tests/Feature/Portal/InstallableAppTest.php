<?php

namespace Tests\Feature\Portal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Both portals install as apps: a manifest each, icons Android accepts, and a worker that keeps only the offline page. */
class InstallableAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_portal_links_its_own_manifest(): void
    {
        $this->get('/portal/login')->assertOk()->assertSee('<link rel="manifest" href="/portal.webmanifest">', false);
        $this->get('/my/locked')->assertOk()->assertSee('<link rel="manifest" href="/my.webmanifest">', false);
    }

    public function test_the_manifests_name_the_app_and_its_icons_exist(): void
    {
        foreach (['portal.webmanifest' => '/portal', 'my.webmanifest' => '/my'] as $file => $start) {
            $manifest = json_decode(File::get(public_path($file)), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame($start, $manifest['start_url']);
            $this->assertSame('standalone', $manifest['display']);
            $this->assertStringNotContainsStringIgnoringCase('rihla', File::get(public_path($file)));

            $sizes = [];
            foreach ($manifest['icons'] as $icon) {
                $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
                $sizes[] = $icon['sizes'].' '.$icon['purpose'];
            }

            $this->assertContains('192x192 any', $sizes);
            $this->assertContains('512x512 any', $sizes);
            $this->assertContains('512x512 maskable', $sizes);
        }
    }

    public function test_the_worker_caches_nothing_but_the_offline_page(): void
    {
        $worker = File::get(public_path('app-sw.js'));

        $this->assertStringContainsString("event.request.mode !== 'navigate'", $worker);
        $this->assertStringContainsString('cache.add(OFFLINE)', $worker);
        $this->assertStringNotContainsString('cache.put', $worker);
        $this->assertFileExists(public_path('offline.html'));
    }
}
