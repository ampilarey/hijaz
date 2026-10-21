<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_anonymous_callers_get_ok_without_the_commit(): void
    {
        config(['deploy.webhook_secret' => str_repeat('s', 32)]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonMissing(['commit']);
    }

    public function test_the_deploy_pipeline_is_told_the_running_commit(): void
    {
        $secret = str_repeat('s', 32);
        config(['deploy.webhook_secret' => $secret]);

        $this->withHeader('Authorization', 'Bearer '.$secret)
            ->getJson('/api/health')
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonStructure(['ok', 'app', 'env', 'commit']);
    }

    public function test_a_wrong_secret_is_treated_as_anonymous(): void
    {
        config(['deploy.webhook_secret' => str_repeat('s', 32)]);

        $this->withHeader('Authorization', 'Bearer wrong')
            ->getJson('/api/health')
            ->assertOk()
            ->assertJsonMissing(['commit']);
    }
}
