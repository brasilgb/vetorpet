<?php

use App\Models\PestControl\ControlPoint;
use App\Models\PestControl\Establishment;
use App\Models\PestControl\PestSpecies;
use App\Models\PestControl\Product;
use App\Models\PestControl\Technician;
use App\Models\PestControl\Visit;
use App\Models\PestControl\VisitInspection;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\TenantModuleService;
use Laravel\Sanctum\Sanctum;

function inspectionTenant(string $suffix): Tenant
{
    return Tenant::create([
        'company' => "Empresa Inspecao {$suffix}",
        'cnpj' => "5555500000{$suffix}",
        'email' => "inspecao{$suffix}@example.com",
        'status' => 1,
        'payment' => true,
        'expiration_date' => now()->addYear(),
        'plan_type' => Tenant::PLAN_INDIVIDUAL,
    ]);
}

function inspectionRoot(string $suffix): User
{
    return User::withoutGlobalScopes()->create([
        'name' => "Root Inspecao {$suffix}",
        'email' => "root-inspecao-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_ROOT,
        'status' => true,
    ]);
}

function inspectionTechnician(Tenant $tenant, string $suffix): User
{
    $user = User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => "Técnico Inspecao {$suffix}",
        'email' => "tecnico-inspecao-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_SELLER,
        'status' => 1,
    ]);

    Technician::create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

    return $user;
}

function inspectionEstablishment(Tenant $tenant): Establishment
{
    return Establishment::create([
        'tenant_id' => $tenant->id,
        'name' => 'Escola Inspecao',
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
        'checkin_radius_meters' => 100,
    ]);
}

function inspectionVisit(Tenant $tenant, Establishment $establishment, User $technician): Visit
{
    return Visit::create([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'technician_id' => $technician->id,
        'scheduled_at' => now()->addHour(),
        'service_type' => 'Dedetização',
        'status' => Visit::STATUS_IN_PROGRESS,
    ]);
}

function inspectionPoint(Tenant $tenant, Establishment $establishment, array $overrides = []): ControlPoint
{
    return ControlPoint::create(array_merge([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'code' => 'P-01',
        'label' => 'Ponto 1',
        'category_key' => 'roedores',
        'display_order' => 1,
        'required' => true,
        'active' => true,
    ], $overrides));
}

test('a technician records an inspection for their own visit and it is idempotent per point', function () {
    $tenant = inspectionTenant('1');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, inspectionRoot('1'));
    $establishment = inspectionEstablishment($tenant);
    $technician = inspectionTechnician($tenant, '1');
    $visit = inspectionVisit($tenant, $establishment, $technician);
    $point = inspectionPoint($tenant, $establishment);
    $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Raticida A', 'active' => true]);

    Sanctum::actingAs($technician);

    $payload = [
        'product_id' => $product->id,
        'consumption_type' => 'bloco',
        'consumption_code' => VisitInspection::CONSUMPTION_HALF,
        'replaced' => false,
        'device_condition' => 'Íntegro',
        'live_count' => 0,
        'dead_count' => 1,
        'notes' => 'Ponto revisado sem problemas.',
    ];

    $response = $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/points/{$point->id}/inspection", $payload)->assertOk();
    expect($response->json('inspection.consumption_code'))->toBe('0.5');

    // Reenviar o mesmo ponto atualiza, não duplica (upsert por visit_id + control_point_id). Informar o
    // client_known_updated_at correto (ver Etapa 7 / MobileInspectionConflictTest) evita o 409 de conflito.
    $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/points/{$point->id}/inspection", [
        ...$payload,
        'consumption_code' => VisitInspection::CONSUMPTION_FULL,
        'replaced' => true,
        'client_known_updated_at' => $response->json('inspection.updated_at'),
    ])->assertOk();

    expect(VisitInspection::where('visit_id', $visit->id)->where('control_point_id', $point->id)->count())->toBe(1);
    expect(VisitInspection::where('visit_id', $visit->id)->first()->consumption_code)->toBe('1');
});

test('consumption code 1 or E requires replacement, matching the domain rule from app-tecnico.md', function () {
    $tenant = inspectionTenant('2');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, inspectionRoot('2'));
    $establishment = inspectionEstablishment($tenant);
    $technician = inspectionTechnician($tenant, '2');
    $visit = inspectionVisit($tenant, $establishment, $technician);
    $point = inspectionPoint($tenant, $establishment);

    Sanctum::actingAs($technician);

    $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/points/{$point->id}/inspection", [
        'consumption_code' => VisitInspection::CONSUMPTION_SPOILED,
        'replaced' => true,
    ])->assertOk();

    $inspection = VisitInspection::where('visit_id', $visit->id)->first();
    expect($inspection->requiresReplacement())->toBeTrue();
});

test('species found are recorded and replaced on resubmission', function () {
    $tenant = inspectionTenant('3');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, inspectionRoot('3'));
    $establishment = inspectionEstablishment($tenant);
    $technician = inspectionTechnician($tenant, '3');
    $visit = inspectionVisit($tenant, $establishment, $technician);
    $point = inspectionPoint($tenant, $establishment);
    $species = PestSpecies::create(['tenant_id' => $tenant->id, 'category_key' => 'roedores', 'name' => 'Rato de telhado', 'active' => true]);

    Sanctum::actingAs($technician);

    $response = $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/points/{$point->id}/inspection", [
        'consumption_code' => VisitInspection::CONSUMPTION_NONE,
        'species' => [
            ['species_id' => $species->id, 'live_count' => 2, 'dead_count' => 0],
        ],
    ])->assertOk();

    expect($response->json('inspection.species_found'))->toHaveCount(1);
});

test('a point that does not belong to the visit establishment cannot be inspected', function () {
    $tenant = inspectionTenant('4');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, inspectionRoot('4'));
    $establishment = inspectionEstablishment($tenant);
    $otherEstablishment = inspectionEstablishment($tenant);
    $technician = inspectionTechnician($tenant, '4');
    $visit = inspectionVisit($tenant, $establishment, $technician);
    $foreignPoint = inspectionPoint($tenant, $otherEstablishment);

    Sanctum::actingAs($technician);

    $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/points/{$foreignPoint->id}/inspection", [
        'consumption_code' => VisitInspection::CONSUMPTION_NONE,
    ])->assertNotFound();
});

test('a technician can inspect a point on a visit assigned to another technician', function () {
    $tenant = inspectionTenant('5');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, inspectionRoot('5'));
    $establishment = inspectionEstablishment($tenant);
    $technicianA = inspectionTechnician($tenant, '5a');
    $technicianB = inspectionTechnician($tenant, '5b');
    $visit = inspectionVisit($tenant, $establishment, $technicianB);
    $point = inspectionPoint($tenant, $establishment);

    Sanctum::actingAs($technicianA);

    $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/points/{$point->id}/inspection", [
        'consumption_code' => VisitInspection::CONSUMPTION_NONE,
    ])->assertOk();
});

test('not_inspected requires a justification reason', function () {
    $tenant = inspectionTenant('6');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, inspectionRoot('6'));
    $establishment = inspectionEstablishment($tenant);
    $technician = inspectionTechnician($tenant, '6');
    $visit = inspectionVisit($tenant, $establishment, $technician);
    $point = inspectionPoint($tenant, $establishment);

    Sanctum::actingAs($technician);

    $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/points/{$point->id}/inspection", [
        'not_inspected' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['not_inspected_reason']);

    $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/points/{$point->id}/inspection", [
        'not_inspected' => true,
        'not_inspected_reason' => 'Portão trancado, sem acesso ao ponto.',
    ])->assertOk();
});
