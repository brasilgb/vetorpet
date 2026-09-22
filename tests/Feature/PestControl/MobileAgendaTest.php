<?php

use App\Models\PestControl\Establishment;
use App\Models\PestControl\Technician;
use App\Models\PestControl\Visit;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\TenantModuleService;
use Laravel\Sanctum\Sanctum;

function agendaTenant(string $suffix): Tenant
{
    return Tenant::create([
        'company' => "Empresa Agenda {$suffix}",
        'cnpj' => "3333300000{$suffix}",
        'email' => "agenda{$suffix}@example.com",
        'status' => 1,
        'payment' => true,
        'expiration_date' => now()->addYear(),
        'plan_type' => Tenant::PLAN_INDIVIDUAL,
    ]);
}

function agendaRoot(string $suffix): User
{
    return User::withoutGlobalScopes()->create([
        'name' => "Root Agenda {$suffix}",
        'email' => "root-agenda-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_ROOT,
        'status' => true,
    ]);
}

function agendaTechnician(Tenant $tenant, string $suffix): User
{
    $user = User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => "Técnico Agenda {$suffix}",
        'email' => "tecnico-agenda-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_SELLER,
        'status' => 1,
    ]);

    Technician::create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

    return $user;
}

function agendaEstablishment(Tenant $tenant): Establishment
{
    return Establishment::create([
        'tenant_id' => $tenant->id,
        'name' => 'Escola Agenda',
        'street' => 'Rua das Flores',
        'number' => '100',
        'district' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'zip_code' => '01000-000',
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
        'checkin_radius_meters' => 100,
    ]);
}

function agendaVisit(Tenant $tenant, Establishment $establishment, User $technician, array $overrides = []): Visit
{
    return Visit::create(array_merge([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'technician_id' => $technician->id,
        'scheduled_at' => now()->addDay(),
        'service_type' => 'Dedetização',
        'status' => Visit::STATUS_SCHEDULED,
    ], $overrides));
}

test('a technician sees every upcoming visit in the agenda, regardless of assigned technician', function () {
    $tenant = agendaTenant('1');
    $root = agendaRoot('1');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, $root);

    $establishment = agendaEstablishment($tenant);
    $technicianA = agendaTechnician($tenant, '1a');
    $technicianB = agendaTechnician($tenant, '1b');

    $ownVisit = agendaVisit($tenant, $establishment, $technicianA);
    $othersVisit = agendaVisit($tenant, $establishment, $technicianB);
    agendaVisit($tenant, $establishment, $technicianA, ['scheduled_at' => now()->subDay()]);

    Sanctum::actingAs($technicianA);

    $response = $this->getJson('/api/pest-control/v1/agenda')->assertOk();
    $uuids = collect($response->json('data'))->pluck('uuid');

    expect($uuids)->toHaveCount(2)->toContain($ownVisit->uuid)->toContain($othersVisit->uuid);
    expect($response->json('data.0.establishment.name'))->toBe('Escola Agenda');
});

test('the agenda endpoint is invisible without the module active and denies non-technicians', function () {
    $tenant = agendaTenant('2');
    $establishment = agendaEstablishment($tenant);
    $technician = agendaTechnician($tenant, '2');
    $seller = User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Vendedor Agenda 2',
        'email' => 'seller-agenda-2@example.com',
        'password' => 'password',
        'roles' => User::ROLE_SELLER,
        'status' => 1,
    ]);

    Sanctum::actingAs($technician);
    $this->getJson('/api/pest-control/v1/agenda')->assertNotFound();

    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, agendaRoot('2'));

    Sanctum::actingAs($seller);
    $this->getJson('/api/pest-control/v1/agenda')->assertForbidden();

    Sanctum::actingAs($technician);
    $this->getJson('/api/pest-control/v1/agenda')->assertOk();
});

test('a technician can download the full detail of any visit in their tenant', function () {
    $tenant = agendaTenant('3');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, agendaRoot('3'));

    $establishment = agendaEstablishment($tenant);
    $technicianA = agendaTechnician($tenant, '3a');
    $technicianB = agendaTechnician($tenant, '3b');

    $ownVisit = agendaVisit($tenant, $establishment, $technicianA);
    $othersVisit = agendaVisit($tenant, $establishment, $technicianB);

    Sanctum::actingAs($technicianA);

    $response = $this->getJson("/api/pest-control/v1/agenda/{$ownVisit->uuid}")->assertOk();
    expect($response->json('visit.uuid'))->toBe($ownVisit->uuid);
    expect($response->json('visit.establishment.control_points'))->toBeArray();
    expect($response->json('consumption_types'))->toBeArray();

    $this->getJson("/api/pest-control/v1/agenda/{$othersVisit->uuid}")->assertOk();
});

test('a visit from another tenant is never reachable even with a guessed uuid', function () {
    $tenantA = agendaTenant('4a');
    $tenantB = agendaTenant('4b');
    app(TenantModuleService::class)->activate($tenantA, TenantModule::KEY_PEST_CONTROL, agendaRoot('4a'));
    app(TenantModuleService::class)->activate($tenantB, TenantModule::KEY_PEST_CONTROL, agendaRoot('4b'));

    $technicianA = agendaTechnician($tenantA, '4a');
    $technicianB = agendaTechnician($tenantB, '4b');
    $establishmentB = agendaEstablishment($tenantB);
    $visitB = agendaVisit($tenantB, $establishmentB, $technicianB);

    Sanctum::actingAs($technicianA);
    $this->getJson("/api/pest-control/v1/agenda/{$visitB->uuid}")->assertNotFound();
});

test('/api/user exposes active modules and the technician flag for the app', function () {
    $tenant = agendaTenant('5');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, agendaRoot('5'));
    $technician = agendaTechnician($tenant, '5');

    Sanctum::actingAs($technician);

    $response = $this->getJson('/api/user')->assertOk();

    expect($response->json('active_modules'))->toContain('pest_control');
    expect($response->json('is_pest_control_technician'))->toBeTrue();
    expect($response->json('pest_control_permissions'))->toBeArray();
});
