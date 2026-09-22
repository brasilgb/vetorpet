<?php

use App\Models\PestControl\ControlPoint;
use App\Models\PestControl\Establishment;
use App\Models\PestControl\Technician;
use App\Models\PestControl\Visit;
use App\Models\PestControl\VisitInspection;
use App\Models\PestControl\VisitMedia;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\TenantModuleService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function mediaTenant(string $suffix): Tenant
{
    return Tenant::create([
        'company' => "Empresa Media {$suffix}",
        'cnpj' => "6666600000{$suffix}",
        'email' => "media{$suffix}@example.com",
        'status' => 1,
        'payment' => true,
        'expiration_date' => now()->addYear(),
        'plan_type' => Tenant::PLAN_INDIVIDUAL,
    ]);
}

function mediaRoot(string $suffix): User
{
    return User::withoutGlobalScopes()->create([
        'name' => "Root Media {$suffix}",
        'email' => "root-media-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_ROOT,
        'status' => true,
    ]);
}

function mediaTechnician(Tenant $tenant, string $suffix): User
{
    $user = User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => "Técnico Media {$suffix}",
        'email' => "tecnico-media-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_SELLER,
        'status' => 1,
    ]);

    Technician::create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

    return $user;
}

function mediaEstablishment(Tenant $tenant): Establishment
{
    return Establishment::create([
        'tenant_id' => $tenant->id,
        'name' => 'Escola Media',
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
        'checkin_radius_meters' => 100,
    ]);
}

function mediaVisit(Tenant $tenant, Establishment $establishment, User $technician): Visit
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

test('a technician uploads a visit-level evidence photo', function () {
    Storage::fake('public');
    $tenant = mediaTenant('1');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, mediaRoot('1'));
    $establishment = mediaEstablishment($tenant);
    $technician = mediaTechnician($tenant, '1');
    $visit = mediaVisit($tenant, $establishment, $technician);

    Sanctum::actingAs($technician);

    $response = $this->post("/api/pest-control/v1/visits/{$visit->uuid}/media", [
        'uuid' => (string) Str::uuid(),
        'file' => UploadedFile::fake()->image('local.jpg'),
        'category' => VisitMedia::CATEGORY_SITE_CONDITION,
        'latitude' => -23.5505000,
        'longitude' => -46.6333000,
        'content_hash' => 'abc123',
    ])->assertCreated();

    expect($response->json('media.category'))->toBe('situacao_local');
    expect($response->json('media.inspection_id'))->toBeNull();
    Storage::disk('public')->assertExists($response->json('media.path'));
});

test('resending the same uuid does not duplicate the upload (idempotent)', function () {
    Storage::fake('public');
    $tenant = mediaTenant('2');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, mediaRoot('2'));
    $establishment = mediaEstablishment($tenant);
    $technician = mediaTechnician($tenant, '2');
    $visit = mediaVisit($tenant, $establishment, $technician);
    $uuid = (string) Str::uuid();

    Sanctum::actingAs($technician);

    $payload = [
        'uuid' => $uuid,
        'file' => UploadedFile::fake()->image('local.jpg'),
        'category' => VisitMedia::CATEGORY_DAMAGE,
    ];

    $first = $this->post("/api/pest-control/v1/visits/{$visit->uuid}/media", $payload)->assertCreated();
    $second = $this->post("/api/pest-control/v1/visits/{$visit->uuid}/media", [...$payload, 'file' => UploadedFile::fake()->image('local.jpg')])
        ->assertOk();

    expect($second->json('media.id'))->toBe($first->json('media.id'));
    expect(VisitMedia::where('uuid', $uuid)->count())->toBe(1);
});

test('a photo tied to a point requires that point to have a synced inspection first', function () {
    Storage::fake('public');
    $tenant = mediaTenant('3');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, mediaRoot('3'));
    $establishment = mediaEstablishment($tenant);
    $technician = mediaTechnician($tenant, '3');
    $visit = mediaVisit($tenant, $establishment, $technician);
    $point = ControlPoint::create([
        'tenant_id' => $tenant->id,
        'establishment_id' => $establishment->id,
        'code' => 'P-01',
        'label' => 'Ponto 1',
        'category_key' => 'roedores',
        'display_order' => 1,
        'required' => true,
        'active' => true,
    ]);

    Sanctum::actingAs($technician);

    $this->post("/api/pest-control/v1/visits/{$visit->uuid}/media", [
        'uuid' => (string) Str::uuid(),
        'file' => UploadedFile::fake()->image('device.jpg'),
        'category' => VisitMedia::CATEGORY_DEVICE,
        'point_id' => $point->id,
    ])->assertStatus(409);

    $inspection = VisitInspection::create([
        'tenant_id' => $tenant->id,
        'visit_id' => $visit->id,
        'control_point_id' => $point->id,
        'technician_id' => $technician->id,
        'inspected_at' => now(),
        'consumption_code' => VisitInspection::CONSUMPTION_NONE,
    ]);

    $response = $this->post("/api/pest-control/v1/visits/{$visit->uuid}/media", [
        'uuid' => (string) Str::uuid(),
        'file' => UploadedFile::fake()->image('device.jpg'),
        'category' => VisitMedia::CATEGORY_DEVICE,
        'point_id' => $point->id,
    ])->assertCreated();

    expect($response->json('media.inspection_id'))->toBe($inspection->id);
});

test('a technician can upload evidence to a visit assigned to another technician', function () {
    Storage::fake('public');
    $tenant = mediaTenant('4');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, mediaRoot('4'));
    $establishment = mediaEstablishment($tenant);
    $technicianA = mediaTechnician($tenant, '4a');
    $technicianB = mediaTechnician($tenant, '4b');
    $visit = mediaVisit($tenant, $establishment, $technicianB);

    Sanctum::actingAs($technicianA);

    $this->post("/api/pest-control/v1/visits/{$visit->uuid}/media", [
        'uuid' => (string) Str::uuid(),
        'file' => UploadedFile::fake()->image('local.jpg'),
        'category' => VisitMedia::CATEGORY_SITE_CONDITION,
    ])->assertCreated();
});

test('an invalid category is rejected', function () {
    Storage::fake('public');
    $tenant = mediaTenant('5');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, mediaRoot('5'));
    $establishment = mediaEstablishment($tenant);
    $technician = mediaTechnician($tenant, '5');
    $visit = mediaVisit($tenant, $establishment, $technician);

    Sanctum::actingAs($technician);

    $this->postJson("/api/pest-control/v1/visits/{$visit->uuid}/media", [
        'uuid' => (string) Str::uuid(),
        'file' => UploadedFile::fake()->image('local.jpg'),
        'category' => 'categoria-invalida',
    ])->assertUnprocessable()->assertJsonValidationErrors(['category']);
});
