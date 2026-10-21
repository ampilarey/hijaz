<?php

namespace Tests\Feature\Portal;

use App\Models\Island;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupTest extends TestCase
{
    // Not RefreshDatabase: that wraps each test in a transaction, and a
    // backup taken inside one is refused on purpose.
    use DatabaseMigrations;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/backups-test-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_backup_is_written_verified_and_pruned(): void
    {
        Island::factory()->count(3)->create();

        $this->artisan('hijaz:backup', ['--path' => $this->dir, '--keep' => 2])->assertSuccessful();
        $this->artisan('hijaz:backup:verify', ['--path' => $this->dir])->assertSuccessful();

        File::put($this->dir.'/hijaz-2020-01-01_000000.sqlite', str_repeat('x', 2048));
        touch($this->dir.'/hijaz-2020-01-01_000000.sqlite', 1_577_836_800);
        File::put($this->dir.'/hijaz-2021-01-01_000000.sqlite', str_repeat('x', 2048));
        touch($this->dir.'/hijaz-2021-01-01_000000.sqlite', 1_609_459_200);

        $this->artisan('hijaz:backup', ['--path' => $this->dir, '--keep' => 2])->assertSuccessful();

        $this->assertCount(2, File::files($this->dir));
        $this->assertFileDoesNotExist($this->dir.'/hijaz-2020-01-01_000000.sqlite');
    }

    public function test_verify_fails_when_there_is_nothing_to_verify(): void
    {
        $this->artisan('hijaz:backup:verify', ['--path' => $this->dir])->assertFailed();
    }

    public function test_preflight_passes_on_a_healthy_install(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('hijaz:preflight')->assertSuccessful();
    }

    public function test_preflight_fails_when_roles_are_not_seeded(): void
    {
        $this->artisan('hijaz:preflight')->assertFailed();
    }
}
