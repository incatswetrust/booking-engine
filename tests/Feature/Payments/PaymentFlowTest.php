<?php

use App\Application\Services\StripeGateway;
use App\Domain\Auth\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Models\User;
use Stripe\PaymentIntent;
use Stripe\Refund;

/**
 * The Stripe PHP SDK ships its own HTTP client, not Laravel's — so
 * Http::fake() can't intercept it. These tests mock StripeGateway
 * itself instead (the boundary PaymentService actually depends on),
 * using \Stripe\*::constructFrom() to build realistic SDK objects
 * without a network call. Live verification against real Stripe test
 * keys is a separate, later step once STRIPE_SECRET is configured.
 */
function fakePaymentIntent(string $id = 'pi_test123'): PaymentIntent
{
    return PaymentIntent::constructFrom(['id' => $id, 'client_secret' => "{$id}_secret_abc"]);
}

function fakeRefund(string $id = 're_test123'): Refund
{
    return Refund::constructFrom(['id' => $id, 'status' => 'succeeded']);
}

it('parks a booking in awaiting_payment for a full-payment service, without creating a Payment yet', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['payment_mode' => 'full']);
    $customer = User::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ]);

    $response->assertCreated()->assertJsonPath('data.status', 'awaiting_payment');
    expect(Payment::count())->toBe(0);
});

it('creates a Payment and Stripe PaymentIntent via POST /bookings/{id}/payment', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('createPaymentIntent')->once()->andReturn(fakePaymentIntent());
    });

    $organization = Organization::factory()->create();
    connectStripeAccount($organization);
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['payment_mode' => 'full']);
    $customer = User::factory()->create();

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::AwaitingPayment,
        'price' => 100.50,
    ]);

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/payment")
        ->assertCreated();

    $response->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount', 100.5)
        ->assertJsonPath('data.client_secret', 'pi_test123_secret_abc');

    $payment = Payment::firstOrFail();
    expect($payment->booking_id)->toBe($booking->id)
        ->and($payment->provider_payment_id)->toBe('pi_test123')
        ->and($payment->status)->toBe(PaymentStatus::Pending);
});

it('creates a Payment for the configured deposit amount when payment_mode is deposit', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('createPaymentIntent')->once()->andReturn(fakePaymentIntent());
    });

    $organization = Organization::factory()->create();
    connectStripeAccount($organization);
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['payment_mode' => 'deposit', 'deposit_amount' => 25.75]);
    $customer = User::factory()->create();

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::AwaitingPayment,
        'price' => 100,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/payment")
        ->assertCreated()
        ->assertJsonPath('data.amount', 25.75);
});

it('rejects initiating payment for a booking whose service does not require it', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/payment")
        ->assertStatus(422);
});

it('rejects initiating a second payment while one is already active', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['payment_mode' => 'full']);
    $customer = User::factory()->create();

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::AwaitingPayment,
    ]);

    Payment::factory()->for($booking)->create(['status' => PaymentStatus::Pending]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/payment")
        ->assertStatus(422);
});

it('allows a fresh payment attempt after the previous one failed', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('createPaymentIntent')->once()->andReturn(fakePaymentIntent('pi_retry'));
    });

    $organization = Organization::factory()->create();
    connectStripeAccount($organization);
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['payment_mode' => 'full']);
    $customer = User::factory()->create();

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::AwaitingPayment,
    ]);

    Payment::factory()->for($booking)->create(['status' => PaymentStatus::Failed]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/payment")
        ->assertCreated();

    expect(Payment::where('booking_id', $booking->id)->count())->toBe(2);
});

it('forbids a stranger from initiating payment for someone else\'s booking', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['payment_mode' => 'full']);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => User::factory()->create()->id,
        'status' => BookingStatus::AwaitingPayment,
    ]);

    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/payment")
        ->assertStatus(403);
});

it('fully refunds a paid payment', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('refund')->once()->andReturn(fakeRefund());
    });

    $organization = Organization::factory()->create();
    connectStripeAccount($organization);
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $payment = Payment::factory()->create([
        'amount' => 100.50,
        'amount_refunded' => 0,
        'status' => PaymentStatus::Paid,
    ]);
    $payment->booking->update(['organization_id' => $organization->id]);

    $this->postJson("/api/v1/payments/{$payment->public_id}/refund")
        ->assertOk()
        ->assertJsonPath('data.status', 'refunded')
        ->assertJsonPath('data.amount_refunded', 100.5);
});

it('partially refunds a paid payment when an amount is given', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('refund')->once()->andReturn(fakeRefund());
    });

    $organization = Organization::factory()->create();
    connectStripeAccount($organization);
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $payment = Payment::factory()->create([
        'amount' => 100.50,
        'amount_refunded' => 0,
        'status' => PaymentStatus::Paid,
    ]);
    $payment->booking->update(['organization_id' => $organization->id]);

    $this->postJson("/api/v1/payments/{$payment->public_id}/refund", ['amount' => 40.25])
        ->assertOk()
        ->assertJsonPath('data.status', 'partially_refunded')
        ->assertJsonPath('data.amount_refunded', 40.25);
});

it('rejects a refund larger than what is still refundable', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $payment = Payment::factory()->create([
        'amount' => 100,
        'amount_refunded' => 60,
        'status' => PaymentStatus::PartiallyRefunded,
    ]);
    $payment->booking->update(['organization_id' => $organization->id]);

    $this->postJson("/api/v1/payments/{$payment->public_id}/refund", ['amount' => 50])
        ->assertStatus(422);
});

it('rejects refunding a payment that was never paid', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $payment = Payment::factory()->create(['status' => PaymentStatus::Pending]);
    $payment->booking->update(['organization_id' => $organization->id]);

    $this->postJson("/api/v1/payments/{$payment->public_id}/refund")
        ->assertStatus(422);
});

it('forbids staff without payments.manage from issuing a refund', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::Staff);
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    $payment->booking->update(['organization_id' => $organization->id]);

    $this->postJson("/api/v1/payments/{$payment->public_id}/refund")
        ->assertStatus(403);
});

it('lets a customer view their own payment but not someone else\'s', function () {
    $customer = User::factory()->create();
    $payment = Payment::factory()->create();
    $payment->booking->update(['customer_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/v1/payments/{$payment->public_id}")
        ->assertOk();

    $stranger = User::factory()->create();
    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/v1/payments/{$payment->public_id}")
        ->assertStatus(403);
});
