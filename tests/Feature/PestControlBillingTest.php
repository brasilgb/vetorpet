<?php

use App\Models\Admin\Plan;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\MercadoPagoService;
use App\Services\TenantModuleService;
use Inertia\Testing\AssertableInertia as Assert;

function billingTestAccount(string $suffix): array
{
    $plan = Plan::where('slug', 'solo')->firstOrFail();
    $period = $plan->periods()->where('interval_count', 1)->firstOrFail();
    $period->update(['price' => 59.90]);

    $tenant = Tenant::create([
        'company' => "Empresa Cobrança {$suffix}",
        'cnpj' => "3333300000{$suffix}",
        'email' => "cobranca{$suffix}@example.com",
        'phone' => '11999999999',
        'whatsapp' => '11999999999',
        'plan' => $plan->id,
        'plan_type' => Tenant::PLAN_INDIVIDUAL,
        'status' => 1,
        'payment' => false,
        'expiration_date' => now()->subDay(),
    ]);
    $user = User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => "Responsável Cobrança {$suffix}",
        'email' => "responsavel-cobranca-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_OWNER,
        'status' => 1,
    ]);
    $root = User::withoutGlobalScopes()->create([
        'name' => "Root Cobrança {$suffix}",
        'email' => "root-cobranca-{$suffix}@example.com",
        'password' => 'password',
        'roles' => User::ROLE_ROOT,
        'status' => true,
    ]);

    config()->set('services.mercadopago.access_token', 'test-token');
    config()->set('services.mercadopago.webhook_secret', 'test-secret');

    return [$plan, $period, $tenant, $user, $root];
}

function billingGatewayResponse(string $paymentId, float $amount): object
{
    return (object) [
        'id' => $paymentId,
        'transaction_amount' => $amount,
        'status' => 'pending',
        'date_of_expiration' => now()->addHour()->toIso8601String(),
        'point_of_interaction' => (object) [
            'transaction_data' => (object) [
                'qr_code_base64' => 'base64-qr-code',
                'qr_code' => 'pix-copia-e-cola',
            ],
        ],
    ];
}

test('pix amount only includes the plan price when no module is active', function () {
    [$plan, $period, $tenant, $user] = billingTestAccount('1');

    $service = Mockery::mock(MercadoPagoService::class);
    $service->shouldReceive('createPixPayment')->once()->withArgs(function (array $request) {
        expect($request['transaction_amount'])->toBe(59.90);

        return true;
    })->andReturn(billingGatewayResponse('mp-no-addon', 59.90));
    $this->app->instance(MercadoPagoService::class, $service);

    $this->actingAs($user)->postJson(route('app.subscription.pix'), [
        'plan_id' => $plan->id,
        'period_id' => $period->id,
    ])->assertOk()->assertJson(['amount' => 59.90, 'addons' => []]);

    expect(Payment::where('payment_id', 'mp-no-addon')->firstOrFail()->addons)->toBe([]);
});

test('pix amount sums the active pest control addon and discriminates it', function () {
    [$plan, $period, $tenant, $user, $root] = billingTestAccount('2');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, $root);

    $expectedTotal = 59.90 + 30.00;

    $service = Mockery::mock(MercadoPagoService::class);
    $service->shouldReceive('createPixPayment')->once()->withArgs(function (array $request) use ($expectedTotal) {
        expect($request['transaction_amount'])->toBe($expectedTotal)
            ->and($request['description'])->toContain('Controle de Pragas');

        return true;
    })->andReturn(billingGatewayResponse('mp-with-addon', $expectedTotal));
    $this->app->instance(MercadoPagoService::class, $service);

    $response = $this->actingAs($user)->postJson(route('app.subscription.pix'), [
        'plan_id' => $plan->id,
        'period_id' => $period->id,
    ])->assertOk();

    $response->assertJson([
        'amount' => $expectedTotal,
        'addons' => [[
            'module_key' => 'pest_control',
            'label' => 'Controle de Pragas',
            'amount' => 30.00,
        ]],
    ]);

    $payment = Payment::where('payment_id', 'mp-with-addon')->firstOrFail();
    expect($payment->addons)->toEqual([[
        'module_key' => 'pest_control',
        'label' => 'Controle de Pragas',
        'amount' => 30.00,
    ]]);
});

test('canceling the module removes the addon from future charges without deleting history', function () {
    [$plan, $period, $tenant, $user, $root] = billingTestAccount('3');
    $service = app(TenantModuleService::class);
    $service->activate($tenant, TenantModule::KEY_PEST_CONTROL, $root);
    $service->cancel($tenant, TenantModule::KEY_PEST_CONTROL, $root);

    $mercadoPago = Mockery::mock(MercadoPagoService::class);
    $mercadoPago->shouldReceive('createPixPayment')->once()->withArgs(function (array $request) {
        expect($request['transaction_amount'])->toBe(59.90);

        return true;
    })->andReturn(billingGatewayResponse('mp-canceled-addon', 59.90));
    $this->app->instance(MercadoPagoService::class, $mercadoPago);

    $this->actingAs($user)->postJson(route('app.subscription.pix'), [
        'plan_id' => $plan->id,
        'period_id' => $period->id,
    ])->assertOk()->assertJson(['amount' => 59.90, 'addons' => []]);

    $tenantModule = TenantModule::where('tenant_id', $tenant->id)->firstOrFail();
    expect($tenantModule->status)->toBe(TenantModule::STATUS_CANCELED)
        ->and($tenantModule->logs()->count())->toBe(2);
});

test('subscription page sends the active addons per billing cycle so the plan card shows the pix total', function () {
    [$plan, $period, $tenant, $user, $root] = billingTestAccount('4');
    app(TenantModuleService::class)->activate($tenant, TenantModule::KEY_PEST_CONTROL, $root);

    $this->actingAs($user)
        ->get(route('app.subscription.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('app/subscription/index')
            ->where('addonsByInterval.1.0.module_key', 'pest_control')
            ->where('addonsByInterval.1.0.amount', 30)
            ->where('addonsByInterval.6.0.amount', 162)
            ->where('addonsByInterval.12.0.amount', 288));
});
