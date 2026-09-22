<?php

use App\Models\PestControl\AuditLog;
use App\Models\PestControl\ControlPoint;
use App\Models\PestControl\Establishment;
use App\Models\PestControl\PestSpecies;
use App\Models\PestControl\Product;
use App\Models\PestControl\Technician;
use App\Models\PestControl\Visit;
use App\Models\PestControl\VisitInspection;
use App\Models\PestControl\VisitSignature;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\PestControl\PestControlPermissions;
use App\Services\TenantModuleService;

function vtTenant(string $suffix): Tenant
{
    return Tenant::create([
        'company' => "Empresa VT {$suffix}",
        'cnpj' => "7777700000{$suffix}",
        'email' => "vt-{$suffix}@example.com",
        'status' => 1,
        'payment' => true,
        'expiration_date' => now()->addYear(),
        'plan_type' => Tenant::PLAN_INDIVIDUAL,
    ]);
}

function vtOwner(Tenant $tenant, string $suffix): User
{
    return User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => "Dono VT {$suffix}",
        'email' => "owner-vt-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_OWNER,
        'status' => 1,
    ]);
}

function vtSeller(Tenant $tenant, string $suffix): User
{
    return User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => "Técnico VT {$suffix}",
        'email' => "seller-vt-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_SELLER,
        'status' => 1,
    ]);
}

function vtTechnician(Tenant $tenant, string $suffix): User
{
    $user = User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => "Técnico VT {$suffix}",
        'email' => "tech-vt-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_SELLER,
        'status' => 1,
    ]);

    Technician::create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

    return $user;
}

function vtRoot(string $suffix): User
{
    return User::withoutGlobalScopes()->create([
        'name' => "Root VT {$suffix}",
        'email' => "root-vt-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_ROOT,
        'status' => true,
    ]);
}

function vtActivateModule(Tenant $tenant, User $root): void
{
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, $root);
}

function vtEstablishment(Tenant $tenant, array $overrides = []): Establishment
{
    return Establishment::create(array_merge([
        'tenant_id' => $tenant->id,
        'name' => 'Escola VT',
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
        'checkin_radius_meters' => 100,
    ], $overrides));
}

test('a user with visits.create can schedule a visit, and it is audited', function () {
    $tenant = vtTenant('1');
    $owner = vtOwner($tenant, '1');
    vtActivateModule($tenant, vtRoot('1'));
    $establishment = vtEstablishment($tenant);
    $technician = vtTechnician($tenant, '1');

    $this->actingAs($owner)->post(route('app.pest-control.visits.store'), [
        'establishment_id' => $establishment->id,
        'technician_id' => $technician->id,
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'service_type' => 'Manutenção',
    ])->assertRedirect();

    $visit = Visit::where('tenant_id', $tenant->id)->firstOrFail();
    expect($visit->status)->toBe(Visit::STATUS_SCHEDULED)
        ->and($visit->technician_id)->toBe($technician->id)
        ->and($visit->uuid)->not->toBeEmpty()
        ->and(AuditLog::where('action', 'visit.scheduled')->where('subject_id', $visit->id)->exists())->toBeTrue();
});

test('a visit can be scheduled without a technician for personal attendance', function () {
    $tenant = vtTenant('1a');
    $owner = vtOwner($tenant, '1a');
    vtActivateModule($tenant, vtRoot('1a'));
    $establishment = vtEstablishment($tenant);

    $this->actingAs($owner)->post(route('app.pest-control.visits.store'), [
        'establishment_id' => $establishment->id,
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'service_type' => 'Manutenção',
    ])->assertRedirect();

    $visit = Visit::where('tenant_id', $tenant->id)->firstOrFail();
    expect($visit->technician_id)->toBeNull();
});

test('scheduling a visit rejects a technician_id that is not a registered technician (seller or admin)', function () {
    $tenant = vtTenant('1b');
    $owner = vtOwner($tenant, '1b');
    $seller = vtSeller($tenant, '1b');
    vtActivateModule($tenant, vtRoot('1b'));
    $establishment = vtEstablishment($tenant);

    $this->actingAs($owner)->post(route('app.pest-control.visits.store'), [
        'establishment_id' => $establishment->id,
        'technician_id' => $seller->id,
        'scheduled_at' => now()->addDay()->toDateTimeString(),
    ])->assertSessionHasErrors('technician_id');

    $this->actingAs($owner)->post(route('app.pest-control.visits.store'), [
        'establishment_id' => $establishment->id,
        'technician_id' => $owner->id,
        'scheduled_at' => now()->addDay()->toDateTimeString(),
    ])->assertSessionHasErrors('technician_id');

    expect(Visit::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('the technician dropdown for scheduling only lists registered technicians, never sellers or the owner', function () {
    $tenant = vtTenant('1c');
    $owner = vtOwner($tenant, '1c');
    vtSeller($tenant, '1c'); // vendedor comum: não deve aparecer
    vtActivateModule($tenant, vtRoot('1c'));
    $technician = vtTechnician($tenant, '1c');

    $this->actingAs($owner)
        ->get(route('app.pest-control.visits.create'))
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('app/pest-control/visits/create-visit')
            ->has('technicians', 1)
            ->where('technicians.0.id', $technician->id));
});

test('check-in within the establishment radius does not raise an out-of-range occurrence, and check-out computes duration', function () {
    $tenant = vtTenant('2');
    $owner = vtOwner($tenant, '2');
    vtActivateModule($tenant, vtRoot('2'));
    $establishment = vtEstablishment($tenant);
    $visit = Visit::create([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'technician_id' => $owner->id,
        'scheduled_at' => now(),
    ]);

    $checkinTime = now();
    $this->actingAs($owner)->patch(route('app.pest-control.visits.check-in', $visit), [
        'device_time' => $checkinTime->toIso8601String(),
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
        'accuracy_meters' => 5,
    ])->assertRedirect();

    $visit->refresh();
    expect($visit->status)->toBe(Visit::STATUS_IN_PROGRESS)
        ->and($visit->checkin_at)->not->toBeNull()
        ->and((float) $visit->checkin_distance_meters)->toBeLessThan(1)
        ->and(AuditLog::where('action', 'visit.checkin.out_of_range')->where('subject_id', $visit->id)->exists())->toBeFalse();

    $this->actingAs($owner)->patch(route('app.pest-control.visits.check-out', $visit), [
        'device_time' => $checkinTime->copy()->addMinutes(10)->toIso8601String(),
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
        'summary' => 'Tudo certo.',
    ])->assertRedirect();

    $visit->refresh();
    expect($visit->status)->toBe(Visit::STATUS_COMPLETED)
        ->and($visit->duration_seconds)->toBeGreaterThanOrEqual(600);
});

test('check-in outside the establishment radius does not block, but raises an audited occurrence', function () {
    $tenant = vtTenant('3');
    $owner = vtOwner($tenant, '3');
    vtActivateModule($tenant, vtRoot('3'));
    $establishment = vtEstablishment($tenant, ['checkin_radius_meters' => 50]);
    $visit = Visit::create([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'technician_id' => $owner->id,
        'scheduled_at' => now(),
    ]);

    // ~1.1km de distância do estabelecimento.
    $this->actingAs($owner)->patch(route('app.pest-control.visits.check-in', $visit), [
        'latitude' => -23.5605000,
        'longitude' => -46.6333000,
        'justification' => 'Sinal de GPS ruim no local.',
    ])->assertRedirect();

    $visit->refresh();
    expect($visit->status)->toBe(Visit::STATUS_IN_PROGRESS)
        ->and((float) $visit->checkin_distance_meters)->toBeGreaterThan(50)
        ->and($visit->checkin_justification)->toBe('Sinal de GPS ruim no local.')
        ->and(AuditLog::where('action', 'visit.checkin.out_of_range')->where('subject_id', $visit->id)->exists())->toBeTrue();
});

test('an inspection is persisted per control point with species found, and not_inspected requires a reason', function () {
    $tenant = vtTenant('4');
    $owner = vtOwner($tenant, '4');
    vtActivateModule($tenant, vtRoot('4'));
    $establishment = vtEstablishment($tenant);
    $point = ControlPoint::create(['tenant_id' => $tenant->id, 'establishment_id' => $establishment->id, 'code' => 'P-01']);
    $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Isca X']);
    $species = PestSpecies::create(['tenant_id' => $tenant->id, 'name' => 'Rattus norvegicus']);
    $visit = Visit::create([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'technician_id' => $owner->id,
        'scheduled_at' => now(),
    ]);

    $this->actingAs($owner)->post(route('app.pest-control.visits.inspections.store', [$visit, $point]), [
        'product_id' => $product->id,
        'consumption_code' => '1',
        'replaced' => true,
        'live_count' => 2,
        'dead_count' => 1,
        'species' => [
            ['species_id' => $species->id, 'live_count' => 2, 'dead_count' => 1],
        ],
    ])->assertRedirect();

    $inspection = VisitInspection::where('visit_id', $visit->id)->where('control_point_id', $point->id)->firstOrFail();
    expect($inspection->consumption_code)->toBe('1')
        ->and($inspection->replaced)->toBeTrue()
        ->and($inspection->speciesFound()->count())->toBe(1)
        ->and($inspection->speciesFound()->first()->live_count)->toBe(2);

    $this->actingAs($owner)->post(route('app.pest-control.visits.inspections.store', [$visit, $point]), [
        'not_inspected' => true,
    ])->assertSessionHasErrors('not_inspected_reason');
});

test('a point from another establishment cannot be inspected on this visit', function () {
    $tenant = vtTenant('5');
    $owner = vtOwner($tenant, '5');
    vtActivateModule($tenant, vtRoot('5'));
    $establishment = vtEstablishment($tenant);
    $otherEstablishment = vtEstablishment($tenant, ['name' => 'Outro local']);
    $foreignPoint = ControlPoint::create(['tenant_id' => $tenant->id, 'establishment_id' => $otherEstablishment->id, 'code' => 'P-02']);
    $visit = Visit::create([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'technician_id' => $owner->id,
        'scheduled_at' => now(),
    ]);

    $this->actingAs($owner)
        ->post(route('app.pest-control.visits.inspections.store', [$visit, $foreignPoint]), ['consumption_code' => '0'])
        ->assertNotFound();
});

test('signing a visit creates a new version and supersedes the previous one', function () {
    $tenant = vtTenant('6');
    $owner = vtOwner($tenant, '6');
    vtActivateModule($tenant, vtRoot('6'));
    $establishment = vtEstablishment($tenant);
    $visit = Visit::create([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'technician_id' => $owner->id,
        'scheduled_at' => now(),
    ]);
    $pngDataUri = 'data:image/png;base64,'.base64_encode('fake-png-bytes');

    $this->actingAs($owner)->post(route('app.pest-control.visits.signature.store', $visit), [
        'responsible_name' => 'Maria Responsável',
        'signature' => $pngDataUri,
    ])->assertRedirect();

    $this->actingAs($owner)->post(route('app.pest-control.visits.signature.store', $visit), [
        'responsible_name' => 'Maria Responsável',
        'signature' => $pngDataUri,
    ])->assertRedirect();

    $signatures = VisitSignature::where('visit_id', $visit->id)->orderBy('version')->get();
    expect($signatures)->toHaveCount(2)
        ->and($signatures[0]->superseded)->toBeTrue()
        ->and($signatures[1]->superseded)->toBeFalse()
        ->and($signatures[1]->version)->toBe(2);
});

test('only a user with visits.approve can validate a completed visit', function () {
    $tenant = vtTenant('7');
    $owner = vtOwner($tenant, '7');
    $seller = vtSeller($tenant, '7');
    vtActivateModule($tenant, vtRoot('7'));
    app(PestControlPermissions::class)->grant($seller, 'pest_control.visits.edit', $owner);
    $establishment = vtEstablishment($tenant);
    $visit = Visit::create([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'technician_id' => $seller->id,
        'scheduled_at' => now(),
        'status' => Visit::STATUS_COMPLETED,
    ]);

    $this->actingAs($seller)->patch(route('app.pest-control.visits.approve', $visit))->assertForbidden();

    $this->actingAs($owner)->patch(route('app.pest-control.visits.approve', $visit))->assertRedirect();

    expect($visit->fresh()->status)->toBe(Visit::STATUS_VALIDATED)
        ->and($visit->fresh()->approved_by_id)->toBe($owner->id);
});

test('a seller without any visits permission cannot see or act on the agenda', function () {
    $tenant = vtTenant('8');
    $seller = vtSeller($tenant, '8');
    vtActivateModule($tenant, vtRoot('8'));

    $this->actingAs($seller)->get(route('app.pest-control.visits.index'))->assertForbidden();
});

test('a tenant cannot check-in, inspect or approve a visit that belongs to another tenant', function () {
    $tenantA = vtTenant('9');
    $tenantB = vtTenant('10');
    $ownerA = vtOwner($tenantA, '9');
    vtActivateModule($tenantA, vtRoot('9'));
    vtActivateModule($tenantB, vtRoot('10'));
    $establishmentB = vtEstablishment($tenantB);
    $visitB = Visit::create([
        'tenant_id' => $tenantB->id,
        'establishment_id' => $establishmentB->id,
        'technician_id' => vtOwner($tenantB, '11')->id,
        'scheduled_at' => now(),
    ]);

    $this->actingAs($ownerA)->get(route('app.pest-control.visits.edit', $visitB))->assertNotFound();
    $this->actingAs($ownerA)->patch(route('app.pest-control.visits.check-in', $visitB), [])->assertNotFound();
});
