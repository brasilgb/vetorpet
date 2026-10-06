<?php

use App\Models\PestControl\AuditLog;
use App\Models\PestControl\ControlPoint;
use App\Models\PestControl\Establishment;
use App\Models\PestControl\Visit;
use App\Models\PestControl\VisitInspection;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\PestControl\PestControlPermissions;
use App\Services\TenantModuleService;

function epTenant(string $suffix): Tenant
{
    return Tenant::create([
        'company' => "Empresa EP {$suffix}",
        'cnpj' => "5555500000{$suffix}",
        'email' => "ep-{$suffix}@example.com",
        'status' => 1,
        'payment' => true,
        'expiration_date' => now()->addYear(),
        'plan_type' => Tenant::PLAN_INDIVIDUAL,
    ]);
}

function epOwner(Tenant $tenant, string $suffix): User
{
    return User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => "Dono EP {$suffix}",
        'email' => "owner-ep-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_OWNER,
        'status' => 1,
    ]);
}

function epSeller(Tenant $tenant, string $suffix): User
{
    return User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => "Vendedor EP {$suffix}",
        'email' => "seller-ep-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_SELLER,
        'status' => 1,
    ]);
}

function epRoot(string $suffix): User
{
    return User::withoutGlobalScopes()->create([
        'name' => "Root EP {$suffix}",
        'email' => "root-ep-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_ROOT,
        'status' => true,
    ]);
}

function epActivateModule(Tenant $tenant, User $root): void
{
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, $root);
}

test('a user with units.manage can create, edit and delete an establishment, and actions are audited', function () {
    $tenant = epTenant('1');
    $owner = epOwner($tenant, '1');
    epActivateModule($tenant, epRoot('1'));

    $this->actingAs($owner)->post(route('app.pest-control.establishments.store'), [
        'name' => 'Escola Municipal',
        'internal_code' => 'ESC-01',
        'checkin_radius_meters' => 100,
    ])->assertRedirect(route('app.pest-control.establishments.index'));

    $establishment = Establishment::where('tenant_id', $tenant->id)->firstOrFail();
    expect($establishment->name)->toBe('Escola Municipal')
        ->and(AuditLog::where('action', 'establishment.created')->where('subject_id', $establishment->id)->exists())->toBeTrue();

    $this->actingAs($owner)->patch(route('app.pest-control.establishments.update', $establishment), [
        'name' => 'Escola Municipal Central',
        'internal_code' => 'ESC-01',
    ])->assertRedirect();

    expect($establishment->fresh()->name)->toBe('Escola Municipal Central')
        ->and(AuditLog::where('action', 'establishment.updated')->where('subject_id', $establishment->id)->exists())->toBeTrue();

    $this->actingAs($owner)->delete(route('app.pest-control.establishments.destroy', $establishment))->assertRedirect();

    expect(Establishment::find($establishment->id))->toBeNull()
        ->and(AuditLog::where('action', 'establishment.deleted')->where('subject_id', $establishment->id)->exists())->toBeTrue();
});

test('deleting an establishment with control points is blocked', function () {
    $tenant = epTenant('2');
    $owner = epOwner($tenant, '2');
    epActivateModule($tenant, epRoot('2'));

    $establishment = Establishment::create(['tenant_id' => $tenant->id, 'name' => 'Frigorífico']);
    ControlPoint::create(['tenant_id' => $tenant->id, 'establishment_id' => $establishment->id, 'code' => 'P-01']);

    $this->actingAs($owner)
        ->delete(route('app.pest-control.establishments.destroy', $establishment))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Establishment::find($establishment->id))->not->toBeNull();
});

test('a seller without any units permission is forbidden from the establishments area', function () {
    $tenant = epTenant('3');
    $seller = epSeller($tenant, '3');
    epActivateModule($tenant, epRoot('3'));

    $this->actingAs($seller)->get(route('app.pest-control.establishments.index'))->assertForbidden();
});

test('units.view grants read access but not create/edit/delete', function () {
    $tenant = epTenant('4');
    $seller = epSeller($tenant, '4');
    $owner = epOwner($tenant, '4');
    epActivateModule($tenant, epRoot('4'));
    app(PestControlPermissions::class)->grant($seller, 'pest_control.units.view', $owner);

    $establishment = Establishment::create(['tenant_id' => $tenant->id, 'name' => 'Aviário']);

    $this->actingAs($seller)->get(route('app.pest-control.establishments.index'))->assertOk();
    $this->actingAs($seller)->get(route('app.pest-control.establishments.create'))->assertForbidden();
    $this->actingAs($seller)
        ->post(route('app.pest-control.establishments.store'), ['name' => 'Novo'])
        ->assertForbidden();
    $this->actingAs($seller)
        ->delete(route('app.pest-control.establishments.destroy', $establishment))
        ->assertForbidden();
});

test('a tenant without the module active is blocked even for the owner, who has implicit full permission', function () {
    $tenant = epTenant('5');
    $owner = epOwner($tenant, '5');
    // Sem ativar o módulo desta vez.

    $this->actingAs($owner)->get(route('app.pest-control.establishments.index'))->assertNotFound();
});

test('a tenant cannot read, edit or reuse establishment ids from another tenant', function () {
    $tenantA = epTenant('6');
    $tenantB = epTenant('7');
    $ownerA = epOwner($tenantA, '6');
    epActivateModule($tenantA, epRoot('6'));
    epActivateModule($tenantB, epRoot('7'));
    $establishmentB = Establishment::create(['tenant_id' => $tenantB->id, 'name' => 'Do outro tenant']);

    $this->actingAs($ownerA)->get(route('app.pest-control.establishments.edit', $establishmentB))->assertNotFound();

    $this->actingAs($ownerA)->post(route('app.pest-control.points.store'), [
        'establishment_id' => $establishmentB->id,
        'code' => 'X-1',
    ])->assertSessionHasErrors('establishment_id');

    expect(ControlPoint::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->count())->toBe(0);
});

test('a user with points.manage can create control points and codes are unique per establishment', function () {
    $tenant = epTenant('8');
    $owner = epOwner($tenant, '8');
    epActivateModule($tenant, epRoot('8'));
    $establishment = Establishment::create(['tenant_id' => $tenant->id, 'name' => 'Petshop Central']);

    $this->actingAs($owner)->post(route('app.pest-control.points.store'), [
        'establishment_id' => $establishment->id,
        'code' => 'P-01',
        'category_key' => 'roedores',
    ])->assertRedirect(route('app.pest-control.points.index'));

    expect(ControlPoint::where('establishment_id', $establishment->id)->count())->toBe(1);

    $this->actingAs($owner)->post(route('app.pest-control.points.store'), [
        'establishment_id' => $establishment->id,
        'code' => 'P-01',
    ])->assertSessionHasErrors('code');

    expect(ControlPoint::where('establishment_id', $establishment->id)->count())->toBe(1);
});

test('deleting a control point without inspections succeeds', function () {
    $tenant = epTenant('9');
    $owner = epOwner($tenant, '9');
    epActivateModule($tenant, epRoot('9'));
    $establishment = Establishment::create(['tenant_id' => $tenant->id, 'name' => 'Clínica Sul']);
    $point = ControlPoint::create(['tenant_id' => $tenant->id, 'establishment_id' => $establishment->id, 'code' => 'P-01']);

    $this->actingAs($owner)
        ->delete(route('app.pest-control.points.destroy', $point))
        ->assertRedirect(route('app.pest-control.points.index'));

    expect(ControlPoint::find($point->id))->toBeNull();
});

test('deleting a control point with inspections is blocked', function () {
    $tenant = epTenant('10');
    $owner = epOwner($tenant, '10');
    epActivateModule($tenant, epRoot('10'));
    $establishment = Establishment::create(['tenant_id' => $tenant->id, 'name' => 'Mercado Norte']);
    $point = ControlPoint::create(['tenant_id' => $tenant->id, 'establishment_id' => $establishment->id, 'code' => 'P-01']);
    $visit = Visit::create([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'technician_id' => $owner->id,
        'scheduled_at' => now(),
        'service_type' => 'Dedetização',
        'status' => Visit::STATUS_IN_PROGRESS,
    ]);
    VisitInspection::create([
        'tenant_id' => $tenant->id,
        'visit_id' => $visit->id,
        'control_point_id' => $point->id,
        'technician_id' => $owner->id,
        'inspected_at' => now(),
        'consumption_code' => VisitInspection::CONSUMPTION_NONE,
    ]);

    $this->actingAs($owner)
        ->delete(route('app.pest-control.points.destroy', $point))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(ControlPoint::find($point->id))->not->toBeNull()
        ->and(VisitInspection::where('control_point_id', $point->id)->count())->toBe(1);
});
