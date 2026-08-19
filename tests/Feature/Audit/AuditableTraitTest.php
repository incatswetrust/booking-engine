<?php

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\Role;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceBlock;
use App\Models\User;

it('records an audit log entry when an auditable model is created', function () {
    $organization = Organization::factory()->create();
    $user = actingAsMember($this, $organization, Role::OrganizationOwner);

    $location = Location::factory()->for($organization)->create();

    $log = AuditLog::where('entity_type', 'Location')->where('entity_id', $location->public_id)->firstOrFail();

    expect($log->action)->toBe('location.created')
        ->and($log->actor_id)->toBe($user->id)
        ->and($log->organization_id)->toBe($organization->id)
        ->and($log->before)->toBeNull()
        ->and($log->after['name'])->toBe($location->name);
});

it('records the before/after diff when an auditable model is updated', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $location = Location::factory()->for($organization)->create(['name' => 'Old Name']);

    $location->update(['name' => 'New Name']);

    $log = AuditLog::where('entity_type', 'Location')
        ->where('entity_id', $location->public_id)
        ->where('action', 'location.updated')
        ->firstOrFail();

    expect($log->before)->toBe(['name' => 'Old Name'])
        ->and($log->after)->toBe(['name' => 'New Name']);
});

it('does not record an update log when nothing actually changed', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $location = Location::factory()->for($organization)->create(['name' => 'Same Name']);

    AuditLog::query()->delete();

    $location->name = 'Same Name';
    $location->save();

    expect(AuditLog::where('action', 'location.updated')->count())->toBe(0);
});

it('records an audit log entry when an auditable model is deleted', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $location = Location::factory()->for($organization)->create();
    $locationPublicId = $location->public_id;

    $location->delete();

    $log = AuditLog::where('entity_type', 'Location')
        ->where('entity_id', $locationPublicId)
        ->where('action', 'location.deleted')
        ->firstOrFail();

    expect($log->before['name'])->toBe($location->name)
        ->and($log->after)->toBeNull();
});

it('logs organization.settings_changed instead of organization.updated when settings change', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $organization->update(['settings' => array_merge($organization->settings, ['booking_max_days_ahead' => 30])]);

    expect(AuditLog::where('entity_type', 'Organization')->where('action', 'organization.settings_changed')->exists())->toBeTrue();
    expect(AuditLog::where('entity_type', 'Organization')->where('action', 'organization.updated')->exists())->toBeFalse();
});

it('logs organization.updated for non-settings changes', function () {
    $organization = Organization::factory()->create(['name' => 'Old Name']);
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $organization->update(['name' => 'New Name']);

    expect(AuditLog::where('entity_type', 'Organization')->where('action', 'organization.updated')->exists())->toBeTrue();
});

it('resolves organization_id for a resource block via its resource relation', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();

    $block = ResourceBlock::factory()->for($resource)->create();

    $log = AuditLog::where('entity_type', 'ResourceBlock')
        ->where('entity_id', $block->public_id)
        ->firstOrFail();

    expect($log->organization_id)->toBe($organization->id);
});

it('captures actor, ip, and user agent from the real HTTP request', function () {
    $organization = Organization::factory()->create(['name' => 'Old Name']);
    $user = actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->patchJson("/api/v1/organizations/{$organization->public_id}", ['name' => 'New Name'])
        ->assertOk();

    $log = AuditLog::where('entity_type', 'Organization')->where('action', 'organization.updated')->firstOrFail();

    expect($log->actor_id)->toBe($user->id)
        ->and($log->user_agent)->not->toBeNull();
});

it('does not audit-log models that do not use the Auditable trait', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    User::factory()->create();

    expect(AuditLog::where('entity_type', 'User')->exists())->toBeFalse();
});
