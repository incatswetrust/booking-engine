<?php

it('reports liveness without checking dependencies', function () {
    $this->getJson('/health/live')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('reports readiness with database and redis checks', function () {
    $this->getJson('/health/ready')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'checks' => [
                'database' => true,
                'redis' => true,
            ],
        ]);
});

it('exposes the same checks under the aggregate health endpoint', function () {
    $this->getJson('/health')
        ->assertOk()
        ->assertJsonStructure(['status', 'checks' => ['database', 'redis']]);
});
