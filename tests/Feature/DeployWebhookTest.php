<?php

namespace Tests\Feature;

use App\Services\Deploy\DeployTrigger;
use Tests\TestCase;

class DeployWebhookTest extends TestCase
{
    private const SECRET = 'a-secret-long-enough-to-count-as-one';

    private const SHA = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'deploy.webhook_secret' => self::SECRET,
            'deploy.allowed_hosts' => ['hijaz.rihla.mv'],
            'app.url' => 'https://hijaz.rihla.mv',
        ]);
    }

    public function test_it_is_a_404_when_no_secret_is_configured(): void
    {
        config(['deploy.webhook_secret' => null]);

        $this->postJson('https://hijaz.rihla.mv/api/deploy/pull', ['sha' => self::SHA])
            ->assertNotFound();
    }

    public function test_a_short_secret_counts_as_none(): void
    {
        config(['deploy.webhook_secret' => 'short']);

        $this->withHeader('Authorization', 'Bearer short')
            ->postJson('https://hijaz.rihla.mv/api/deploy/pull', ['sha' => self::SHA])
            ->assertNotFound();
    }

    public function test_it_is_a_404_on_a_host_that_is_not_allowed(): void
    {
        config(['app.url' => 'https://rihla.mv']);

        $this->withHeader('Authorization', 'Bearer '.self::SECRET)
            ->postJson('https://rihla.mv/api/deploy/pull', ['sha' => self::SHA])
            ->assertNotFound();
    }

    public function test_a_wrong_secret_is_unauthorized(): void
    {
        $this->withHeader('Authorization', 'Bearer nope')
            ->postJson('https://hijaz.rihla.mv/api/deploy/pull', ['sha' => self::SHA])
            ->assertUnauthorized();
    }

    public function test_a_missing_secret_is_unauthorized(): void
    {
        $this->postJson('https://hijaz.rihla.mv/api/deploy/pull', ['sha' => self::SHA])
            ->assertUnauthorized();
    }

    public function test_the_pipeline_starts_a_deploy_for_the_verified_commit(): void
    {
        $trigger = $this->fakeTrigger(['ok' => true, 'message' => 'Deploy started']);

        $this->withHeader('Authorization', 'Bearer '.self::SECRET)
            ->postJson('https://hijaz.rihla.mv/api/deploy/pull', ['sha' => strtoupper(self::SHA)])
            ->assertStatus(202)
            ->assertJson(['message' => 'Deploy started', 'sha' => self::SHA]);

        $this->assertSame([self::SHA], $trigger->calls);
    }

    public function test_a_malformed_sha_is_ignored_rather_than_passed_to_the_shell(): void
    {
        $trigger = $this->fakeTrigger(['ok' => true, 'message' => 'Deploy started']);

        $this->withHeader('Authorization', 'Bearer '.self::SECRET)
            ->postJson('https://hijaz.rihla.mv/api/deploy/pull', ['sha' => 'main; rm -rf /'])
            ->assertStatus(202)
            ->assertJson(['sha' => null]);

        $this->assertSame([null], $trigger->calls);
    }

    public function test_a_trigger_that_cannot_spawn_is_a_503(): void
    {
        $this->fakeTrigger(['ok' => false, 'message' => 'Unable to spawn deploy process (proc_open blocked?)']);

        $this->withHeader('Authorization', 'Bearer '.self::SECRET)
            ->postJson('https://hijaz.rihla.mv/api/deploy/pull', ['sha' => self::SHA])
            ->assertStatus(503);
    }

    /**
     * @param  array{ok: bool, message: string}  $result
     */
    private function fakeTrigger(array $result): DeployTrigger
    {
        $fake = new class($result) extends DeployTrigger
        {
            /** @var list<?string> */
            public array $calls = [];

            /** @param array{ok: bool, message: string} $result */
            public function __construct(private array $result) {}

            public function triggerAsync(?string $expectedSha = null): array
            {
                $this->calls[] = $expectedSha;

                return $this->result;
            }
        };

        $this->app->instance(DeployTrigger::class, $fake);

        return $fake;
    }
}
