<?php

use App\Domain\Auth\Role;
use App\Domain\Organization\Organization;
use App\Domain\Webhook\WebhookEndpoint;

it('lets the owner register a webhook endpoint, showing the secret exactly once', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $response = $this->postJson('/api/v1/webhook-endpoints', [
        'organization_id' => $organization->public_id,
        'url' => 'https://example.com/webhooks/booking',
        'events' => ['booking.created', 'booking.confirmed'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.url', 'https://example.com/webhooks/booking')
        ->assertJsonPath('data.events', ['booking.created', 'booking.confirmed'])
        ->assertJsonPath('data.status', 'active');

    $secret = $response->json('data.secret');
    expect($secret)->not->toBeNull()->and(strlen($secret))->toBe(40);

    $endpoint = WebhookEndpoint::firstOrFail();
    expect($endpoint->secret)->toBe($secret);
});

it('rejects a non-https webhook URL', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->postJson('/api/v1/webhook-endpoints', [
        'organization_id' => $organization->public_id,
        'url' => 'http://example.com/webhooks/booking',
        'events' => ['booking.created'],
    ])->assertStatus(422);
});

it('forbids a manager from registering a webhook endpoint', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);

    $this->postJson('/api/v1/webhook-endpoints', [
        'organization_id' => $organization->public_id,
        'url' => 'https://example.com/webhooks/booking',
        'events' => ['booking.created'],
    ])->assertStatus(403);
});

it('never returns the secret when listing webhook endpoints', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    WebhookEndpoint::factory()->for($organization)->create();

    $response = $this->getJson("/api/v1/webhook-endpoints?organization_id={$organization->public_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0'))->not->toHaveKey('secret');
});

it('lets the owner update a webhook endpoint\'s subscribed events and status', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $endpoint = WebhookEndpoint::factory()->for($organization)->create(['events' => ['booking.created']]);

    $this->patchJson("/api/v1/webhook-endpoints/{$endpoint->public_id}", [
        'events' => ['booking.cancelled'],
        'status' => 'disabled',
    ])
        ->assertOk()
        ->assertJsonPath('data.events', ['booking.cancelled'])
        ->assertJsonPath('data.status', 'disabled');
});

it('lets the owner delete a webhook endpoint', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $endpoint = WebhookEndpoint::factory()->for($organization)->create();

    $this->deleteJson("/api/v1/webhook-endpoints/{$endpoint->public_id}")->assertNoContent();

    expect(WebhookEndpoint::find($endpoint->id))->toBeNull();
});
