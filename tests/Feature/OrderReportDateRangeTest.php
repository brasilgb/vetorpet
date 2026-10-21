<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

test('order report filters orders by an inclusive date range', function () {
    $tenant = Tenant::create([
        'company' => 'Empresa relatório',
        'cnpj' => '70000000000001',
        'email' => 'empresa-relatorio@example.com',
        'status' => 1,
        'payment' => true,
        'expiration_date' => now()->addYear(),
    ]);
    $owner = User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Gestor relatório',
        'email' => 'gestor-relatorio@example.com',
        'password' => 'password',
        'roles' => User::ROLE_OWNER,
        'status' => true,
    ]);
    $customer = new Customer([
        'user_id' => $owner->id,
        'name' => 'Cliente relatório',
        'cnpj' => '70000000000002',
    ]);
    $customer->tenant_id = $tenant->id;
    $customer->save();

    $insideRange = new Order([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'order_number' => 501,
        'total' => 100,
        'status' => '1',
    ]);
    $insideRange->tenant_id = $tenant->id;
    $insideRange->created_at = '2026-06-15 12:00:00';
    $insideRange->updated_at = '2026-06-15 12:00:00';
    $insideRange->save();

    $outsideRange = new Order([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'order_number' => 502,
        'total' => 200,
        'status' => '1',
    ]);
    $outsideRange->tenant_id = $tenant->id;
    $outsideRange->created_at = '2026-07-01 12:00:00';
    $outsideRange->updated_at = '2026-07-01 12:00:00';
    $outsideRange->save();

    $this->actingAs($owner)
        ->get(route('app.orders.report', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('app/orders/order-reports')
            ->where('filters.start_date', '2026-06-01')
            ->where('filters.end_date', '2026-06-30')
            ->has('orders', 1)
            ->where('orders.0.id', $insideRange->id));

    Sanctum::actingAs($owner);
    $this->postJson('/api/dateorders', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'])
        ->assertOk()
        ->assertJsonCount(1, 'orders')
        ->assertJsonPath('orders.0.id', $insideRange->id)
        ->assertJsonPath('sumTotal', '100.00');
});

test('order report rejects an end date before the start date', function () {
    $tenant = Tenant::create([
        'company' => 'Empresa período inválido',
        'cnpj' => '70000000000003',
        'email' => 'periodo-invalido@example.com',
        'status' => 1,
        'payment' => true,
        'expiration_date' => now()->addYear(),
    ]);
    $owner = User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Gestor período inválido',
        'email' => 'gestor-periodo-invalido@example.com',
        'password' => 'password',
        'roles' => User::ROLE_OWNER,
        'status' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('app.orders.report', ['start_date' => '2026-06-30', 'end_date' => '2026-06-01']))
        ->assertSessionHasErrors('end_date');
});

test('order report filters orders by seller', function () {
    $tenant = Tenant::create([
        'company' => 'Empresa relatório vendedor',
        'cnpj' => '70000000000004',
        'email' => 'empresa-relatorio-vendedor@example.com',
        'status' => 1,
        'payment' => true,
        'expiration_date' => now()->addYear(),
    ]);
    $owner = User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Gestor relatório vendedor',
        'email' => 'gestor-relatorio-vendedor@example.com',
        'password' => 'password',
        'roles' => User::ROLE_OWNER,
        'status' => true,
    ]);
    $seller = User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Vendedor relatório',
        'email' => 'vendedor-relatorio@example.com',
        'password' => 'password',
        'roles' => User::ROLE_SELLER,
        'status' => true,
    ]);
    $customer = new Customer([
        'user_id' => $owner->id,
        'name' => 'Cliente relatório vendedor',
        'cnpj' => '70000000000005',
    ]);
    $customer->tenant_id = $tenant->id;
    $customer->save();

    $orders = [];
    foreach ([[$owner, 601], [$seller, 602]] as [$user, $number]) {
        $order = new Order([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'order_number' => $number,
            'total' => 100,
            'status' => '1',
        ]);
        $order->tenant_id = $tenant->id;
        $order->created_at = '2026-06-15 12:00:00';
        $order->updated_at = '2026-06-15 12:00:00';
        $order->save();
        $orders[$number] = $order;
    }

    $this->actingAs($owner)
        ->get(route('app.orders.report', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'user_id' => $seller->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('app/orders/order-reports')
            ->where('filters.user_id', $seller->id)
            ->where('canManageTeam', true)
            ->has('orders', 1)
            ->where('orders.0.id', $orders[602]->id));
});
