<?php

use App\Models\Admin\Plan;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MercadoPagoService;
use App\Support\PlanLimits;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function paymentAccount(): array
{
    $plan = Plan::where('slug', 'solo')->firstOrFail();
    $period = $plan->periods()->where('interval_count', 1)->firstOrFail();
    $period->update(['price' => 59.90]);

    $tenant = Tenant::create([
        'company' => 'Empresa Pix',
        'cnpj' => '04252011000110',
        'email' => 'pix@example.com',
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
        'name' => 'Responsável Pix',
        'email' => 'responsavel-pix@example.com',
        'password' => 'password',
        'roles' => User::ROLE_OWNER,
        'status' => 1,
    ]);

    return [$plan, $period, $tenant, $user];
}

function signedWebhookHeaders(string $paymentId, string $secret): array
{
    $timestamp = (string) ((int) (microtime(true) * 1000));
    $requestId = (string) Str::uuid();
    $manifest = 'id:'.strtolower($paymentId).';request-id:'.$requestId.';ts:'.$timestamp.';';

    return [
        'x-request-id' => $requestId,
        'x-signature' => 'ts='.$timestamp.',v1='.hash_hmac('sha256', $manifest, $secret),
    ];
}

test('subscription remains accessible during three day grace period and blocks afterwards', function () {
    [, , $tenant] = paymentAccount();

    $tenant->update([
        'trial_ends_at' => now()->subDays(2),
        'expiration_date' => now()->subDays(2),
        'payment' => false,
    ]);

    $limits = PlanLimits::forTenant($tenant->fresh());
    expect($limits->isInGracePeriod())->toBeTrue()
        ->and($limits->subscriptionBlockedReason())->toBeNull()
        ->and($limits->graceDaysRemaining())->toBeGreaterThanOrEqual(1);

    $tenant->update([
        'trial_ends_at' => now()->subDays(4),
        'expiration_date' => now()->subDays(4),
    ]);

    $expiredLimits = PlanLimits::forTenant($tenant->fresh());
    expect($expiredLimits->isInGracePeriod())->toBeFalse()
        ->and($expiredLimits->subscriptionBlockedReason())->toBe('Período de teste expirado');
});

test('expired individual subscription only offers individual payment plans', function () {
    [, , , $user] = paymentAccount();

    $this->actingAs($user)
        ->get(route('app.subscription.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('app/subscription/index')
            ->where('accountType', Tenant::PLAN_INDIVIDUAL)
            ->has('plans', 1)
            ->where('plans.0.account_type', Tenant::PLAN_INDIVIDUAL));
});

test('pix generation stores pending payment without activating subscription', function () {
    [$plan, $period, $tenant, $user] = paymentAccount();
    config()->set('services.mercadopago.access_token', 'test-token');
    config()->set('services.mercadopago.webhook_secret', 'test-secret');

    $gatewayPayment = (object) [
        'id' => 'mp-pending-1',
        'transaction_amount' => 59.90,
        'status' => 'pending',
        'date_of_expiration' => now()->addHour()->toIso8601String(),
        'point_of_interaction' => (object) [
            'transaction_data' => (object) [
                'qr_code_base64' => 'base64-qr-code',
                'qr_code' => 'pix-copia-e-cola',
            ],
        ],
    ];

    $service = Mockery::mock(MercadoPagoService::class);
    $service->shouldReceive('createPixPayment')->once()->withArgs(function (array $request, string $key) use ($plan, $period, $tenant) {
        expect($request['transaction_amount'])->toBe(59.90)
            ->and($request['payment_method_id'])->toBe('pix')
            ->and(json_decode($request['external_reference'], true))->toBe([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'period_id' => $period->id,
            ])
            ->and($key)->not->toBeEmpty();

        return true;
    })->andReturn($gatewayPayment);
    $this->app->instance(MercadoPagoService::class, $service);

    $this->actingAs($user)->postJson(route('app.subscription.pix'), [
        'plan_id' => $plan->id,
        'period_id' => $period->id,
    ])->assertOk()->assertJson([
        'qr_code' => 'base64-qr-code',
        'qr_code_copy_paste' => 'pix-copia-e-cola',
        'payment_id' => 'mp-pending-1',
        'status' => 'pending',
        'plan' => $plan->name,
        'period' => $period->name,
        'amount' => 59.90,
    ]);

    expect($tenant->fresh()->payment)->toBeFalse();
    $this->assertDatabaseHas('payments', [
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'period_id' => $period->id,
        'payment_id' => 'mp-pending-1',
        'status' => 'pending',
    ]);
});

test('pix generation rejects plans from another account type', function () {
    [, , , $user] = paymentAccount();
    $teamPlan = Plan::where('slug', 'equipe')->firstOrFail();
    $teamPeriod = $teamPlan->periods()->where('interval_count', 1)->firstOrFail();

    $service = Mockery::mock(MercadoPagoService::class);
    $service->shouldNotReceive('createPixPayment');
    $this->app->instance(MercadoPagoService::class, $service);

    $this->actingAs($user)->postJson(route('app.subscription.pix'), [
        'plan_id' => $teamPlan->id,
        'period_id' => $teamPeriod->id,
    ])->assertNotFound();
});

test('signed approved webhook activates subscription only once', function () {
    [$plan, $period, $tenant] = paymentAccount();
    $secret = 'webhook-secret';
    config()->set('services.mercadopago.webhook_secret', $secret);

    Payment::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'period_id' => $period->id,
        'payment_id' => 'mp-approved-1',
        'amount' => 59.90,
        'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $gatewayPayment = (object) [
        'id' => 'mp-approved-1',
        'transaction_amount' => 59.90,
        'status' => 'approved',
        'date_of_expiration' => null,
        'external_reference' => json_encode([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'period_id' => $period->id,
        ]),
    ];

    $service = Mockery::mock(MercadoPagoService::class)->makePartial();
    $service->shouldReceive('getPayment')->twice()->with('mp-approved-1')->andReturn($gatewayPayment);
    $this->app->instance(MercadoPagoService::class, $service);
    $url = route('webhooks.mercadopago').'?data.id=mp-approved-1';

    $this->postJson($url, ['type' => 'payment', 'data' => ['id' => 'mp-approved-1']], signedWebhookHeaders('mp-approved-1', $secret))
        ->assertOk()->assertJson(['status' => 'success']);
    $firstExpiration = $tenant->fresh()->expiration_date;

    $this->postJson($url, ['type' => 'payment', 'data' => ['id' => 'mp-approved-1']], signedWebhookHeaders('mp-approved-1', $secret))
        ->assertOk()->assertJson(['status' => 'success']);

    $tenant->refresh();
    expect($tenant->payment)->toBeTrue()
        ->and($tenant->billing_period_id)->toBe($period->id)
        ->and($tenant->expiration_date->equalTo($firstExpiration))->toBeTrue()
        ->and(Payment::where('payment_id', 'mp-approved-1')->firstOrFail()->approved_at)->not->toBeNull();
});

test('webhook rejects invalid signature without consulting mercado pago', function () {
    config()->set('services.mercadopago.webhook_secret', 'correct-secret');
    $service = Mockery::mock(MercadoPagoService::class);
    $service->shouldNotReceive('getPayment');
    $this->app->instance(MercadoPagoService::class, $service);

    $this->postJson(route('webhooks.mercadopago').'?data.id=mp-invalid', [
        'type' => 'payment',
        'data' => ['id' => 'mp-invalid'],
    ], [
        'x-request-id' => (string) Str::uuid(),
        'x-signature' => 'ts=123,v1=invalid',
    ])->assertUnauthorized();
});

test('payment status polling syncs with mercado pago and activates subscription when approved', function () {
    [$plan, $period, $tenant, $user] = paymentAccount();
    config()->set('services.mercadopago.access_token', 'test-token');

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'period_id' => $period->id,
        'payment_id' => 'mp-poll-approved-1',
        'amount' => 59.90,
        'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $gatewayPayment = (object) [
        'id' => 'mp-poll-approved-1',
        'transaction_amount' => 59.90,
        'status' => 'approved',
    ];

    $service = Mockery::mock(MercadoPagoService::class)->makePartial();
    $service->shouldReceive('getPayment')->once()->with('mp-poll-approved-1')->andReturn($gatewayPayment);
    $this->app->instance(MercadoPagoService::class, $service);

    $this->actingAs($user)
        ->getJson(route('app.subscription.payment-status', 'mp-poll-approved-1'))
        ->assertOk()
        ->assertJson([
            'payment_id' => 'mp-poll-approved-1',
            'status' => 'approved',
            'approved' => true,
        ]);

    expect($tenant->fresh()->payment)->toBeTrue()
        ->and($payment->fresh()->approved_at)->not->toBeNull();
});

test('subscription index page automatically syncs pending payments with mercado pago', function () {
    [$plan, $period, $tenant, $user] = paymentAccount();
    config()->set('services.mercadopago.access_token', 'test-token');

    Payment::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'period_id' => $period->id,
        'payment_id' => 'mp-index-approved-1',
        'amount' => 59.90,
        'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $gatewayPayment = (object) [
        'id' => 'mp-index-approved-1',
        'transaction_amount' => 59.90,
        'status' => 'approved',
    ];

    $service = Mockery::mock(MercadoPagoService::class)->makePartial();
    $service->shouldReceive('getPayment')->once()->with('mp-index-approved-1')->andReturn($gatewayPayment);
    $this->app->instance(MercadoPagoService::class, $service);

    $this->actingAs($user)
        ->get(route('app.subscription.index'))
        ->assertOk();

    expect($tenant->fresh()->payment)->toBeTrue();
});

test('signed webhook accepts the pix total that includes active addons', function () {
    [$plan, $period, $tenant] = paymentAccount();
    $secret = 'webhook-secret';
    config()->set('services.mercadopago.webhook_secret', $secret);

    Payment::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'period_id' => $period->id,
        'payment_id' => 'mp-addon-1',
        'amount' => 89.90,
        'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $gatewayPayment = (object) [
        'id' => 'mp-addon-1',
        'transaction_amount' => 89.90,
        'status' => 'approved',
        'date_of_expiration' => null,
        'external_reference' => json_encode([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'period_id' => $period->id,
        ]),
    ];

    $service = Mockery::mock(MercadoPagoService::class)->makePartial();
    $service->shouldReceive('getPayment')->once()->with('mp-addon-1')->andReturn($gatewayPayment);
    $this->app->instance(MercadoPagoService::class, $service);

    $this->postJson(route('webhooks.mercadopago').'?data.id=mp-addon-1', ['type' => 'payment', 'data' => ['id' => 'mp-addon-1']], signedWebhookHeaders('mp-addon-1', $secret))
        ->assertOk()->assertJson(['status' => 'success']);

    expect($tenant->fresh()->payment)->toBeTrue();
});

test('webhook accepts signature timestamp in seconds and rejects stale ones', function () {
    config()->set('services.mercadopago.webhook_secret', 'webhook-secret');
    $signed = function (int $timestamp): array {
        $requestId = (string) Str::uuid();
        $manifest = 'id:mp-ts;request-id:'.$requestId.';ts:'.$timestamp.';';

        return ['x-request-id' => $requestId, 'x-signature' => 'ts='.$timestamp.',v1='.hash_hmac('sha256', $manifest, 'webhook-secret')];
    };
    $url = route('webhooks.mercadopago').'?data.id=mp-ts';

    $this->postJson($url, ['type' => 'merchant_order'], $signed(time()))
        ->assertOk()->assertJson(['status' => 'ignored']);
    $this->postJson($url, ['type' => 'merchant_order'], $signed((time() - 600) * 1000))
        ->assertUnauthorized();
});
