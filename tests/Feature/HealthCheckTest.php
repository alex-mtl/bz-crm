<?php

use Illuminate\Support\Facades\Redis;

it('reports healthy when all dependencies respond', function () {
    $this->getJson('/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => [
                'database' => 'ok',
                'redis' => 'ok',
                'cache' => 'ok',
                'storage' => 'ok',
            ],
        ]);
});

it('reports degraded with 503 when a dependency fails, without leaking error details', function () {
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('secret-host:6379 refused'));

    $response = $this->getJson('/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.redis', 'fail')
        ->assertJsonPath('checks.database', 'ok');

    expect($response->getContent())->not->toContain('secret-host');
});

it('keeps the framework liveness route', function () {
    $this->get('/up')->assertOk();
});
