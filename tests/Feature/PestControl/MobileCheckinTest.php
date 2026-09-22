<?php

use App\Models\PestControl\AuditLog;
use App\Models\PestControl\Establishment;
use App\Models\PestControl\Technician;
use App\Models\PestControl\Visit;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\TenantModuleService;
use Laravel\Sanctum\Sanctum;

function checkinTenant(string $suffix): Tenant
{
    return Tenant::create([
        'company' => "Empresa Checkin {$suffix}",
        'cnpj' => "4444400000{$suffix}",
        'email' => "checkin{$suffix}@example.com",
        'status' => 1,
        'payment' => true,
        'expiration_date' => now()->addYear(),
        'plan_type' => Tenant::PLAN_INDIVIDUAL,
    ]);
}

function checkinRoot(string $suffix): User
{
    return User::withoutGlobalScopes()->create([
        'name' => "Root Checkin {$suffix}",
        'email' => "root-checkin-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_ROOT,
        'status' => true,
    ]);
}

function checkinTechnician(Tenant $tenant, string $suffix): User
{
    $user = User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => "Técnico Checkin {$suffix}",
        'email' => "tecnico-checkin-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_SELLER,
        'status' => 1,
    ]);

    Technician::create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

    return $user;
}

function checkinEstablishment(Tenant $tenant, array $overrides = []): Establishment
{
    return Establishment::create(array_merge([
        'tenant_id' => $tenant->id,
        'name' => 'Escola Checkin',
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
        'checkin_radius_meters' => 100,
    ], $overrides));
}

function checkinVisit(Tenant $tenant, Establishment $establishment, User $technician, array $overrides = []): Visit
{
    return Visit::create(array_merge([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'technician_id' => $technician->id,
        'scheduled_at' => now()->addHour(),
        'service_type' => 'Dedetização',
        'status' => Visit::STATUS_SCHEDULED,
    ], $overrides));
}

test('a technician checks in within the establishment radius and no out-of-range audit is raised', function () {
    $tenant = checkinTenant('1');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, checkinRoot('1'));
    $establishment = checkinEstablishment($tenant);
    $technician = checkinTechnician($tenant, '1');
    $visit = checkinVisit($tenant, $establishment, $technician);

    Sanctum::actingAs($technician);

    $response = $this->patchJson("/api/pest-control/v1/visits/{$visit->uuid}/check-in", [
        'device_time' => now()->toIso8601String(),
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
        'accuracy_meters' => 8.5,
        'device_id' => 'device-abc',
        'app_version' => '1.0.0',
    ])->assertOk();

    expect($response->json('visit.status'))->toBe(Visit::STATUS_IN_PROGRESS);
    expect($response->json('visit.checkin_at'))->not->toBeNull();

    expect(AuditLog::where('action', 'visit.checkin.out_of_range')->exists())->toBeFalse();
});

test('a technician checking in outside the radius is not blocked but the divergence is audited', function () {
    $tenant = checkinTenant('2');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, checkinRoot('2'));
    $establishment = checkinEstablishment($tenant);
    $technician = checkinTechnician($tenant, '2');
    $visit = checkinVisit($tenant, $establishment, $technician);

    Sanctum::actingAs($technician);

    // ~1.1km de distância do estabelecimento cadastrado.
    $response = $this->patchJson("/api/pest-control/v1/visits/{$visit->uuid}/check-in", [
        'latitude' => -23.5600000,
        'longitude' => -46.6333000,
        'justification' => 'Portão principal fechado, check-in feito da entrada lateral.',
    ])->assertOk();

    expect($response->json('visit.status'))->toBe(Visit::STATUS_IN_PROGRESS);
    expect($response->json('visit.checkin_distance_meters'))->toBeGreaterThan(100);

    $auditLog = AuditLog::where('action', 'visit.checkin.out_of_range')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->changes['justification'])->toBe('Portão principal fechado, check-in feito da entrada lateral.');
});

test('check-in never fabricates coordinates: a device without GPS can check in with only a justification', function () {
    $tenant = checkinTenant('3');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, checkinRoot('3'));
    $establishment = checkinEstablishment($tenant);
    $technician = checkinTechnician($tenant, '3');
    $visit = checkinVisit($tenant, $establishment, $technician);

    Sanctum::actingAs($technician);

    $response = $this->patchJson("/api/pest-control/v1/visits/{$visit->uuid}/check-in", [
        'justification' => 'GPS indisponível no local, sem sinal.',
        'offline_capture' => true,
    ])->assertOk();

    expect($response->json('visit.checkin_latitude'))->toBeNull();
    expect($response->json('visit.checkin_longitude'))->toBeNull();
    expect($response->json('visit.checkin_distance_meters'))->toBeNull();
});

test('a technician can check in on a visit assigned to another technician, assuming the attendance personally', function () {
    $tenant = checkinTenant('4');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, checkinRoot('4'));
    $establishment = checkinEstablishment($tenant);
    $technicianA = checkinTechnician($tenant, '4a');
    $technicianB = checkinTechnician($tenant, '4b');
    $visit = checkinVisit($tenant, $establishment, $technicianB);

    Sanctum::actingAs($technicianA);

    $response = $this->patchJson("/api/pest-control/v1/visits/{$visit->uuid}/check-in", [
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
    ])->assertOk();

    expect($response->json('visit.technician_id'))->toBe($technicianB->id);
});

test('a technician assumes an unassigned visit personally on check-in', function () {
    $tenant = checkinTenant('5');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, checkinRoot('5'));
    $establishment = checkinEstablishment($tenant);
    $technician = checkinTechnician($tenant, '5');
    $visit = checkinVisit($tenant, $establishment, $technician, ['technician_id' => null]);

    Sanctum::actingAs($technician);

    $response = $this->patchJson("/api/pest-control/v1/visits/{$visit->uuid}/check-in", [
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
    ])->assertOk();

    expect($response->json('visit.technician_id'))->toBe($technician->id);
});

test('a completed visit cannot receive a new check-in', function () {
    $tenant = checkinTenant('5');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, checkinRoot('5'));
    $establishment = checkinEstablishment($tenant);
    $technician = checkinTechnician($tenant, '5');
    $visit = checkinVisit($tenant, $establishment, $technician, ['status' => Visit::STATUS_COMPLETED]);

    Sanctum::actingAs($technician);

    $this->patchJson("/api/pest-control/v1/visits/{$visit->uuid}/check-in", [
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
    ])->assertStatus(409);
});
