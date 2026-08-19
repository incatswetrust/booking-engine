<?php

use App\Domain\Auth\Role;
use App\Domain\Organization\Organization;
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookDeliveryStatus;
use App\Domain\Webhook\WebhookEndpoint;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('lets the owner retry a failed delivery, which re-dispatches and can succeed', function () {
    Http::fake(['https://example.com/*' => Http::response('ok', 200)]);

    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $endpoint = WebhookEndpoint::factory()->for($organization)->create(['url' => 'https://example.com/hook']);
    $delivery = WebhookDelivery::factory()->for($endpoint, 'webhookEndpoint')->create(['status' => WebhookDeliveryStatus::Failed]);

    $this->postJson("/api/v1/webhook-deliveries/{$delivery->public_id}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'delivered');

    expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Delivered);
});

it('rejects retrying a delivery that is not failed', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $endpoint = WebhookEndpoint::factory()->for($organization)->create();
    $delivery = WebhookDelivery::factory()->for($endpoint, 'webhookEndpoint')->create(['status' => WebhookDeliveryStatus::Delivered]);

    $this->postJson("/api/v1/webhook-deliveries/{$delivery->public_id}/retry")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('forbids a stranger from retrying someone else\'s webhook delivery', function () {
    $delivery = WebhookDelivery::factory()->create(['status' => WebhookDeliveryStatus::Failed]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/webhook-deliveries/{$delivery->public_id}/retry")
        ->assertStatus(403);
});

it('lists webhook deliveries scoped to organizations the user can manage integrations for', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $endpoint = WebhookEndpoint::factory()->for($organization)->create();
    WebhookDelivery::factory()->for($endpoint, 'webhookEndpoint')->count(2)->create();

    $otherEndpoint = WebhookEndpoint::factory()->create();
    WebhookDelivery::factory()->for($otherEndpoint, 'webhookEndpoint')->create();

    $this->getJson('/api/v1/webhook-deliveries')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
