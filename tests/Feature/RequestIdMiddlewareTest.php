<?php

use App\Http\Middleware\AssignRequestId;

it('assigns a request id header when none is provided', function () {
    $response = $this->getJson('/health/live');

    $response->assertHeader(AssignRequestId::HEADER);
    expect($response->headers->get(AssignRequestId::HEADER))->not->toBeEmpty();
});

it('echoes back an inbound request id instead of generating a new one', function () {
    $response = $this->withHeaders([AssignRequestId::HEADER => 'req_test_123'])
        ->getJson('/health/live');

    $response->assertHeader(AssignRequestId::HEADER, 'req_test_123');
});
