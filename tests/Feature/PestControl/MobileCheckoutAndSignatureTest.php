<?php

use App\Models\PestControl\Establishment;
use App\Models\PestControl\Technician;
use App\Models\PestControl\Visit;
use App\Models\PestControl\VisitSignature;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\TenantModuleService;
use Laravel\Sanctum\Sanctum;

function closeoutTenant(string $suffix): Tenant
{
    return Tenant::create([
        'company' => "Empresa Closeout {$suffix}",
        'cnpj' => "7778800000{$suffix}",
        'email' => "closeout{$suffix}@example.com",
        'status' => 1,
        'payment' => true,
        'expiration_date' => now()->addYear(),
        'plan_type' => Tenant::PLAN_INDIVIDUAL,
    ]);
}

function closeoutRoot(string $suffix): User
{
    return User::withoutGlobalScopes()->create([
        'name' => "Root Closeout {$suffix}",
        'email' => "root-closeout-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_ROOT,
        'status' => true,
    ]);
}

function closeoutTechnician(Tenant $tenant, string $suffix): User
{
    $user = User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => "Técnico Closeout {$suffix}",
        'email' => "tecnico-closeout-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_SELLER,
        'status' => 1,
    ]);

    Technician::create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

    return $user;
}

function closeoutEstablishment(Tenant $tenant): Establishment
{
    return Establishment::create([
        'tenant_id' => $tenant->id,
        'name' => 'Escola Closeout',
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
        'checkin_radius_meters' => 100,
    ]);
}

function closeoutVisit(Tenant $tenant, Establishment $establishment, User $technician, array $overrides = []): Visit
{
    return Visit::create(array_merge([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'technician_id' => $technician->id,
        'scheduled_at' => now()->subHour(),
        'service_type' => 'Dedetização',
        'status' => Visit::STATUS_IN_PROGRESS,
        'checkin_at' => now()->subHour(),
        'checkin_received_at' => now()->subHour(),
    ], $overrides));
}

test('a technician signs the visit and the signature is versioned', function () {
    $tenant = closeoutTenant('1');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, closeoutRoot('1'));
    $establishment = closeoutEstablishment($tenant);
    $technician = closeoutTechnician($tenant, '1');
    $visit = closeoutVisit($tenant, $establishment, $technician);

    Sanctum::actingAs($technician);

    $signatureImage = 'data:image/png;base64,'.base64_encode('fake-signature-bytes');

    $first = $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/signature", [
        'responsible_name' => 'Maria Responsável',
        'responsible_role' => 'Gerente',
        'signature' => $signatureImage,
        'compliance_text' => 'Aceito o serviço prestado.',
    ])->assertCreated();

    expect($first->json('signature.version'))->toBe(1);
    expect($first->json('signature.superseded'))->toBeFalse();

    $second = $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/signature", [
        'responsible_name' => 'Maria Responsável',
        'signature' => $signatureImage,
    ])->assertCreated();

    expect($second->json('signature.version'))->toBe(2);
    expect(VisitSignature::where('visit_id', $visit->id)->where('version', 1)->first()->superseded)->toBeTrue();
});

test('a technician checks out a visit they already checked in', function () {
    $tenant = closeoutTenant('2');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, closeoutRoot('2'));
    $establishment = closeoutEstablishment($tenant);
    $technician = closeoutTechnician($tenant, '2');
    $visit = closeoutVisit($tenant, $establishment, $technician);

    Sanctum::actingAs($technician);

    $response = $this->patchJson("/api/pest-control/v1/visits/{$visit->uuid}/check-out", [
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
        'summary' => 'Visita concluída sem ocorrências.',
    ])->assertOk();

    expect($response->json('visit.status'))->toBe(Visit::STATUS_COMPLETED);
    expect($response->json('visit.checkout_at'))->not->toBeNull();
    expect($response->json('visit.duration_seconds'))->toBeGreaterThan(0);
});

test('check-out is rejected when the visit was never checked in', function () {
    $tenant = closeoutTenant('3');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, closeoutRoot('3'));
    $establishment = closeoutEstablishment($tenant);
    $technician = closeoutTechnician($tenant, '3');
    $visit = closeoutVisit($tenant, $establishment, $technician, [
        'status' => Visit::STATUS_SCHEDULED,
        'checkin_at' => null,
        'checkin_received_at' => null,
    ]);

    Sanctum::actingAs($technician);

    $this->patchJson("/api/pest-control/v1/visits/{$visit->uuid}/check-out", [])->assertStatus(422);
});

test('a technician can sign and check out a visit assigned to another technician', function () {
    $tenant = closeoutTenant('4');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, closeoutRoot('4'));
    $establishment = closeoutEstablishment($tenant);
    $technicianA = closeoutTechnician($tenant, '4a');
    $technicianB = closeoutTechnician($tenant, '4b');
    $visit = closeoutVisit($tenant, $establishment, $technicianB);

    Sanctum::actingAs($technicianA);

    $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/signature", [
        'responsible_name' => 'Alguém',
        'signature' => 'data:image/png;base64,'.base64_encode('x'),
    ])->assertCreated();

    $this->patchJson("/api/pest-control/v1/visits/{$visit->uuid}/check-out", [])->assertOk();
});

test('a canceled visit rejects check-out and signature via the mobile API, even if it was already checked in', function () {
    $tenant = closeoutTenant('5');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, closeoutRoot('5'));
    $establishment = closeoutEstablishment($tenant);
    $technician = closeoutTechnician($tenant, '5');
    $visit = closeoutVisit($tenant, $establishment, $technician, ['status' => Visit::STATUS_CANCELED]);

    Sanctum::actingAs($technician);

    $this->patchJson("/api/pest-control/v1/visits/{$visit->uuid}/check-in", [
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
    ])->assertStatus(409);

    $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/signature", [
        'responsible_name' => 'Alguém',
        'signature' => 'data:image/png;base64,'.base64_encode('x'),
    ])->assertStatus(409);

    $this->patchJson("/api/pest-control/v1/visits/{$visit->uuid}/check-out", [])->assertStatus(409);
});
