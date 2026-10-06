<?php

use App\Models\Customer;
use App\Models\Flex;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Region;
use App\Models\SampleRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visit;
use App\Services\SampleData\SampleDataService;
use App\Support\PlanLimits;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

/**
 * VP-DEMO-002: avaliação pelo cadastro SaaS + trial existentes, com dados de
 * exemplo opcionais, isolamento entre tenants e correção de privilégios de
 * usuário sem tenant.
 */
function trialSignup(string $suffix, string $cnpj, bool $sampleData, string $accountType = Tenant::PLAN_TEAM): User
{
    test()->post('/register', [
        'name' => "Responsável {$suffix}",
        'email' => "trial-{$suffix}@example.com",
        'company' => "Distribuidora {$suffix}",
        'cnpj' => $cnpj,
        'phone' => '11999990000',
        'whatsapp' => '11999990000',
        'account_type' => $accountType,
        'password' => 'password',
        'password_confirmation' => 'password',
        'sample_data' => $sampleData,
    ])->assertRedirect(route('app.dashboard', absolute: false));

    auth()->logout();
    session()->flush();

    return User::withoutGlobalScopes()->where('email', "trial-{$suffix}@example.com")->firstOrFail();
}

function trialCount(string $model, int $tenantId): int
{
    return $model::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
}

test('signup reuses the saas trial and can seed sample data only in the new tenant', function () {
    $ownerA = trialSignup('a', '11.222.333/0001-81', true);
    $ownerB = trialSignup('b', '45.398.765/0001-60', false, Tenant::PLAN_INDIVIDUAL);
    $tenantA = $ownerA->tenant;
    $tenantB = $ownerB->tenant;

    expect($tenantA->isOnTrial())->toBeTrue()
        ->and($tenantA->payment)->toBeFalse()
        ->and($tenantA->billing_period_id)->toBeNull()
        ->and(now()->diffInDays($tenantA->trial_ends_at))->toBeGreaterThanOrEqual(13)
        ->and($tenantB->plan_type)->toBe(Tenant::PLAN_INDIVIDUAL)
        ->and($ownerA->isOwner())->toBeTrue()
        ->and($ownerA->isSuperAdmin())->toBeFalse();

    expect(trialCount(Region::class, $tenantA->id))->toBe(2)
        ->and(trialCount(Customer::class, $tenantA->id))->toBe(5)
        ->and(trialCount(Product::class, $tenantA->id))->toBe(8)
        ->and(trialCount(Order::class, $tenantA->id))->toBe(6)
        ->and(trialCount(OrderItem::class, $tenantA->id))->toBe(13)
        ->and(trialCount(Visit::class, $tenantA->id))->toBe(4)
        ->and(SampleRecord::where('tenant_id', $tenantA->id)->count())->toBe(25);

    // Nada foi criado no outro tenant nem sem tenant.
    foreach ([Region::class, Customer::class, Product::class, Order::class, OrderItem::class, Visit::class] as $model) {
        expect(trialCount($model, $tenantB->id))->toBe(0)
            ->and($model::withoutGlobalScopes()->whereNull('tenant_id')->count())->toBe(0);
    }

    // Todas as referências dos dados de exemplo ficam dentro do mesmo tenant.
    $orders = Order::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->with(['orderItems' => fn ($query) => $query->withoutGlobalScopes()])->get();
    foreach ($orders as $order) {
        expect(Customer::withoutGlobalScopes()->find($order->customer_id)->tenant_id)->toBe($tenantA->id)
            ->and((float) $order->total)->toBe(round((float) $order->orderItems->sum('total'), 2));

        foreach ($order->orderItems as $item) {
            expect(Product::withoutGlobalScopes()->find($item->product_id)->tenant_id)->toBe($tenantA->id);
        }
    }

    // Sem contatos reais: nada de onde partir mensagem, cobrança ou integração.
    Customer::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->get()->each(function (Customer $customer) {
        expect($customer->email)->toEndWith('@exemplo.invalid')
            ->and($customer->phone)->toBeNull()
            ->and($customer->whatsapp)->toBeNull()
            ->and($customer->cnpj)->toBeNull()
            ->and($customer->name)->toContain(SampleDataService::LABEL);
    });

    // Pedidos de exemplo não consomem os limites comerciais do plano.
    expect(PlanLimits::forTenant($tenantA)->usage())->toMatchArray([
        'customers' => 0,
        'products' => 0,
        'orders_month' => 0,
        'visits_month' => 0,
    ]);

    // Nenhum pagamento foi criado pelo cadastro/dados de exemplo.
    expect(Payment::query()->count())->toBe(0);
});

test('dashboard offers sample data only to the owner of a new empty trial account', function () {
    $ownerA = trialSignup('dash-a', '11.222.333/0001-81', true);
    $ownerB = trialSignup('dash-b', '45.398.765/0001-60', false);

    $this->actingAs($ownerA)->get(route('app.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sampleData.active', true)
            ->where('sampleData.canCreate', false));

    $this->actingAs($ownerB)->get(route('app.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sampleData.active', false)
            ->where('sampleData.canCreate', true));

    // Conta que já tem dados reais não recebe dados de exemplo.
    $this->actingAs($ownerB)->post(route('app.customers.store'), ['name' => 'Cliente real B'])->assertRedirect();
    $this->actingAs($ownerB)->post(route('app.sample-data.store'))->assertSessionHas('error');
    expect(SampleRecord::where('tenant_id', $ownerB->tenant_id)->count())->toBe(0)
        ->and(trialCount(Customer::class, $ownerB->tenant_id))->toBe(1);
});

test('a tenant cannot read change or delete sample data of another tenant (web and api)', function () {
    $ownerA = trialSignup('iso-a', '11.222.333/0001-81', true);
    $ownerB = trialSignup('iso-b', '45.398.765/0001-60', true);
    $customerA = Customer::withoutGlobalScopes()->where('tenant_id', $ownerA->tenant_id)->firstOrFail();
    $productA = Product::withoutGlobalScopes()->where('tenant_id', $ownerA->tenant_id)->firstOrFail();
    $orderA = Order::withoutGlobalScopes()->where('tenant_id', $ownerA->tenant_id)->firstOrFail();
    $visitA = Visit::withoutGlobalScopes()->where('tenant_id', $ownerA->tenant_id)->firstOrFail();
    $regionA = Region::withoutGlobalScopes()->where('tenant_id', $ownerA->tenant_id)->firstOrFail();

    $this->actingAs($ownerB);
    $this->get(route('app.customers.show', $customerA))->assertNotFound();
    $this->get(route('app.products.show', $productA))->assertNotFound();
    $this->get(route('app.orders.show', $orderA))->assertNotFound();
    $this->get(route('app.visits.show', $visitA))->assertNotFound();
    $this->get(route('app.regions.edit', $regionA))->assertNotFound();
    $this->patch(route('app.customers.update', $customerA), ['name' => 'Invadido'])->assertNotFound();
    $this->delete(route('app.customers.destroy', $customerA))->assertNotFound();
    $this->delete(route('app.products.destroy', $productA))->assertNotFound();
    $this->delete(route('app.orders.destroy', $orderA))->assertNotFound();

    // Listagens, dashboard e relatórios de B só enxergam os dados de B.
    $this->get(route('app.customers.index'))
        ->assertInertia(fn (Assert $page) => $page->where('customers.total', 5));
    $totalB = (float) Order::withoutGlobalScopes()->where('tenant_id', $ownerB->tenant_id)->where('status', '!=', '4')
        ->where('created_at', '>=', now()->startOfMonth())->sum('total');
    $this->get(route('app.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('summary.sales_total', fn ($value) => abs((float) $value - $totalB) < 0.01));
    $this->get(route('app.reports.sales', ['start_date' => now()->subMonths(2)->toDateString(), 'end_date' => now()->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->where('summary.orders_count', 5));

    // Exclusão de dados de exemplo de B não toca nos de A.
    $this->delete(route('app.sample-data.destroy'))->assertRedirect();
    expect(SampleRecord::where('tenant_id', $ownerA->tenant_id)->count())->toBe(25)
        ->and(trialCount(Customer::class, $ownerA->tenant_id))->toBe(5)
        ->and($customerA->fresh()->name)->toContain(SampleDataService::LABEL);

    // API (app de vendas) com token de B.
    Sanctum::actingAs($ownerB);
    $this->getJson("/api/customers/{$customerA->id}")->assertNotFound();
    $this->getJson("/api/products/{$productA->id}")->assertNotFound();
    $this->getJson("/api/orders/{$orderA->id}")->assertNotFound();
    $this->deleteJson("/api/customers/{$customerA->id}")->assertNotFound();
    $this->getJson('/api/alldata')->assertOk()
        ->assertJsonMissing(['name' => $customerA->name]);
    expect($customerA->fresh())->not->toBeNull();
});

test('sample data can be manipulated and purged without touching real records', function () {
    $owner = trialSignup('purge', '11.222.333/0001-81', true);
    $tenantId = $owner->tenant_id;
    $other = trialSignup('purge-other', '45.398.765/0001-60', true);
    $this->actingAs($owner);
    Flex::create(['value' => 0]);

    $sampleCustomer = Customer::where('name', 'like', 'Pet Shop Bom Amigo%')->firstOrFail();
    $sampleProduct = Product::where('reference', 'EX-001')->firstOrFail();
    $untouchedCustomer = Customer::where('name', 'like', 'Banho e Tosa%')->firstOrFail();

    // Manipulação: editar um cliente de exemplo e usar cliente/produto de
    // exemplo num pedido real.
    $this->patch(route('app.customers.update', $sampleCustomer), ['name' => 'Pet Shop Bom Amigo editado (exemplo)'])
        ->assertRedirect();
    $this->post(route('app.orders.store'), [
        'customer_id' => $sampleCustomer->id,
        'items' => [[
            'product_id' => $sampleProduct->id,
            'quantity' => 1,
            'price' => (float) $sampleProduct->price,
            'name' => $sampleProduct->name,
            'total' => (float) $sampleProduct->price,
        ]],
    ])->assertRedirect(route('app.orders.index'));
    $realOrder = Order::whereNotIn('id', SampleRecord::where('record_type', 'order')->pluck('record_id'))->firstOrFail();

    // Registros reais independentes.
    $realRegion = new Region(['name' => 'Região real', 'status' => true]);
    $realRegion->save();
    $realCustomer = new Customer(['name' => 'Cliente real', 'region_id' => $realRegion->id]);
    $realCustomer->save();
    $realProduct = new Product(['name' => 'Produto real', 'reference' => 'REAL-1', 'unity' => 'UN', 'measure' => 1, 'price' => 10, 'quantity' => 5, 'min_quantity' => 1, 'enabled' => true]);
    $realProduct->save();

    $this->delete(route('app.sample-data.destroy'))->assertRedirect()->assertSessionHas('success');

    // Reais preservados, inclusive o pedido real e seus itens.
    expect($realRegion->fresh())->not->toBeNull()
        ->and($realCustomer->fresh())->not->toBeNull()
        ->and($realProduct->fresh()->quantity)->toBe(5)
        ->and($realOrder->fresh())->not->toBeNull()
        ->and($realOrder->orderItems()->count())->toBe(1);

    // Exemplos em uso pelo pedido real são mantidos (e sua região também).
    expect($sampleCustomer->fresh())->not->toBeNull()
        ->and($sampleProduct->fresh())->not->toBeNull()
        ->and(Region::find($sampleCustomer->region_id))->not->toBeNull()
        ->and($untouchedCustomer->fresh())->toBeNull();

    // Todo o resto foi removido.
    expect(Order::count())->toBe(1)
        ->and(Visit::count())->toBe(0)
        ->and(Customer::count())->toBe(2)
        ->and(Product::count())->toBe(2)
        ->and(Region::count())->toBe(2)
        ->and(SampleRecord::where('tenant_id', $tenantId)->pluck('record_type')->sort()->values()->all())
        ->toBe(['customer', 'product', 'region']);

    // O outro tenant continua intacto.
    expect(SampleRecord::where('tenant_id', $other->tenant_id)->count())->toBe(25)
        ->and(trialCount(Order::class, $other->tenant_id))->toBe(6);

    // Repetir a exclusão é seguro (idempotente).
    $this->delete(route('app.sample-data.destroy'))->assertRedirect();
    expect($realOrder->fresh())->not->toBeNull()
        ->and(Customer::count())->toBe(2);
});

test('only the account owner can create or remove sample data', function () {
    $owner = trialSignup('seller', '11.222.333/0001-81', true);
    $seller = User::withoutGlobalScopes()->create([
        'tenant_id' => $owner->tenant_id,
        'name' => 'Vendedor',
        'email' => 'vendedor-trial@example.com',
        'password' => 'password',
        'roles' => User::ROLE_SELLER,
        'status' => 1,
    ]);

    $this->actingAs($seller)->delete(route('app.sample-data.destroy'))->assertForbidden();
    $this->actingAs($seller)->post(route('app.sample-data.store'))->assertForbidden();
    $this->actingAs($seller)->get(route('app.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('sampleData', null));

    expect(SampleRecord::where('tenant_id', $owner->tenant_id)->count())->toBe(25);
});

test('a user without tenant and without root role gets no admin privilege nor unscoped data', function () {
    $owner = trialSignup('orphan', '11.222.333/0001-81', true);
    $orphan = User::withoutGlobalScopes()->create([
        'tenant_id' => null,
        'name' => 'Usuário órfão',
        'email' => 'orphan@example.com',
        'password' => 'password',
        'roles' => User::ROLE_OWNER,
        'status' => 1,
    ]);

    expect($orphan->isSuperAdmin())->toBeFalse()
        ->and($orphan->isOrphan())->toBeTrue()
        ->and($orphan->canManageTeam())->toBeFalse()
        ->and($orphan->canManageCatalog())->toBeFalse();

    $this->actingAs($orphan);
    $this->get('/admin')->assertForbidden();
    $this->get('/admin/tenants')->assertForbidden();
    $this->get('/admin/users')->assertForbidden();
    $this->get(route('app.dashboard'))->assertForbidden();

    // Mesmo com sessão autenticada, o escopo não devolve dados de nenhum tenant.
    expect(Customer::count())->toBe(0)
        ->and(Product::count())->toBe(0)
        ->and(Order::count())->toBe(0)
        ->and(User::count())->toBe(0);

    auth()->logout();
    $this->post('/login', ['email' => 'orphan@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    // API também recusa.
    Sanctum::actingAs($orphan);
    $this->getJson('/api/customers')->assertForbidden();

    // Responsável de tenant não entra no /admin.
    auth()->forgetGuards();
    $this->actingAs($owner)->get('/admin')->assertRedirect(route('app.dashboard'));
});

test('root user keeps admin access and admin cannot create users without tenant', function () {
    $root = User::withoutGlobalScopes()->create([
        'tenant_id' => null,
        'name' => 'Root',
        'email' => 'root-trial@example.com',
        'password' => 'password',
        'roles' => User::ROLE_ROOT,
        'status' => 1,
    ]);

    expect($root->isSuperAdmin())->toBeTrue();

    $this->actingAs($root)->get('/admin')->assertOk();
    $this->get(route('app.dashboard'))->assertRedirect(route('admin.dashboard'));

    $this->post(route('admin.users.store'), [
        'name' => 'Sem empresa',
        'email' => 'sem-empresa@example.com',
        'roles' => User::ROLE_OWNER,
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertSessionHasErrors('tenant_id');

    expect(User::withoutGlobalScopes()->where('email', 'sem-empresa@example.com')->exists())->toBeFalse();
});

test('expired trial blocks the app and sample data actions but keeps the data', function () {
    $owner = trialSignup('expired', '11.222.333/0001-81', true);

    $this->travel(Tenant::TRIAL_DAYS + PlanLimits::GRACE_DAYS + 1)->days();

    $this->actingAs($owner)->get(route('app.dashboard'))->assertRedirect(route('app.subscription.index'));
    $this->get(route('app.customers.index'))->assertRedirect(route('app.subscription.index'));
    $this->delete(route('app.sample-data.destroy'))->assertRedirect(route('app.subscription.index'));
    $this->get(route('app.subscription.index'))->assertOk();

    expect(PlanLimits::forTenant($owner->tenant->fresh())->subscriptionBlockedReason())->toBe('Período de teste expirado')
        ->and(SampleRecord::where('tenant_id', $owner->tenant_id)->count())->toBe(25);

    Sanctum::actingAs($owner);
    $this->getJson('/api/customers')->assertStatus(402);
});

test('trial accounts without the pest control module do not reach vetorpest', function () {
    $owner = trialSignup('nopest', '11.222.333/0001-81', true);

    $this->actingAs($owner)->get(route('app.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.activeModules', [])
            ->where('auth.pestControlPermissions', []));

    $this->get('/app/pest-control')->assertNotFound();
    $this->get('/app/pest-control/visits')->assertNotFound();
    $this->get(route('app.auxiliary-apps.index'))
        ->assertInertia(fn (Assert $page) => $page->where('apps', fn ($apps) => collect($apps)->every(fn ($app) => ! str_contains($app['filename'] ?? '', 'pest'))));

    Sanctum::actingAs($owner);
    $this->getJson('/api/pest-control/v1/status')->assertNotFound();
    $this->getJson('/api/pest-control/v1/agenda')->assertNotFound();
    $this->getJson('/api/user')->assertOk()->assertJsonMissing(['pest_control_permissions']);
});
