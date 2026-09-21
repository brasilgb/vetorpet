<?php

use App\Models\Admin\Plan;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\Tenant;
use App\Models\User;

function sellerAssignmentTenant(string $suffix): Tenant
{
    $plan = Plan::create([
        'name' => "Time {$suffix}",
        'slug' => "time-{$suffix}",
        'account_type' => Tenant::PLAN_TEAM,
        'description' => 'Plano de teste em equipe',
        'features' => [],
        'is_public' => true,
    ]);

    return Tenant::create([
        'plan' => $plan->id,
        'plan_type' => Tenant::PLAN_TEAM,
        'company' => "Empresa {$suffix}",
        'cnpj' => "7000000000000{$suffix}",
        'email' => "empresa-atribuicao-{$suffix}@example.com",
        'status' => 1,
        'payment' => true,
        'expiration_date' => now()->addYear(),
    ]);
}

function sellerAssignmentUser(Tenant $tenant, string $suffix, int $role): User
{
    return User::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => "Usuario {$suffix}",
        'email' => "usuario-atribuicao-{$suffix}@example.com",
        'password' => 'password',
        'roles' => $role,
        'status' => true,
    ]);
}

function sellerAssignmentCustomer(Tenant $tenant, User $owner, string $suffix): Customer
{
    $customer = new Customer([
        'user_id' => $owner->id,
        'name' => "Cliente {$suffix}",
        'cnpj' => "6000000000000{$suffix}",
        'email' => "cliente-atribuicao-{$suffix}@example.com",
    ]);
    $customer->tenant_id = $tenant->id;
    $customer->save();

    return $customer;
}

function sellerAssignmentProduct(Tenant $tenant, string $suffix): Product
{
    $product = new Product([
        'name' => "Produto {$suffix}",
        'reference' => "REF-ATRIB-{$suffix}",
        'description' => 'Produto de teste',
        'unity' => 'UN',
        'measure' => 1,
        'price' => 10,
        'quantity' => 10,
        'min_quantity' => 1,
        'enabled' => true,
    ]);
    $product->tenant_id = $tenant->id;
    $product->save();

    return $product;
}

test('owner assigning a seller on the panel makes the order visible to that seller', function () {
    $tenant = sellerAssignmentTenant('1');
    $owner = sellerAssignmentUser($tenant, '1-owner', User::ROLE_OWNER);
    $seller = sellerAssignmentUser($tenant, '1-seller', User::ROLE_SELLER);
    $otherSeller = sellerAssignmentUser($tenant, '1-other-seller', User::ROLE_SELLER);
    $customer = sellerAssignmentCustomer($tenant, $owner, '1');
    $product = sellerAssignmentProduct($tenant, '1');

    $this->actingAs($owner)
        ->post(route('app.orders.store'), [
            'customer_id' => $customer->id,
            'user_id' => $seller->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 10,
                'name' => $product->name,
                'total' => 10,
            ]],
        ])
        ->assertRedirect(route('app.orders.index'));

    $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($order->user_id)->toBe($seller->id);
    expect(Order::visibleTo($seller)->whereKey($order->id)->exists())->toBeTrue();
    expect(Order::visibleTo($otherSeller)->whereKey($order->id)->exists())->toBeFalse();
});

test('panel order without an assigned seller defaults to the user who created it', function () {
    $tenant = sellerAssignmentTenant('2');
    $owner = sellerAssignmentUser($tenant, '2-owner', User::ROLE_OWNER);
    $customer = sellerAssignmentCustomer($tenant, $owner, '2');
    $product = sellerAssignmentProduct($tenant, '2');

    $this->actingAs($owner)
        ->post(route('app.orders.store'), [
            'customer_id' => $customer->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 10,
                'name' => $product->name,
                'total' => 10,
            ]],
        ])
        ->assertRedirect(route('app.orders.index'));

    $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($order->user_id)->toBe($owner->id);
});

test('a seller cannot reassign a panel order to another seller', function () {
    $tenant = sellerAssignmentTenant('3');
    $owner = sellerAssignmentUser($tenant, '3-owner', User::ROLE_OWNER);
    $seller = sellerAssignmentUser($tenant, '3-seller', User::ROLE_SELLER);
    $otherSeller = sellerAssignmentUser($tenant, '3-other-seller', User::ROLE_SELLER);

    $region = new Region(['name' => 'Regiao 3', 'status' => true]);
    $region->tenant_id = $tenant->id;
    $region->save();
    $seller->regions()->attach($region->id);

    $customer = sellerAssignmentCustomer($tenant, $seller, '3');
    $customer->region_id = $region->id;
    $customer->save();
    $product = sellerAssignmentProduct($tenant, '3');

    $this->actingAs($seller)
        ->post(route('app.orders.store'), [
            'customer_id' => $customer->id,
            'user_id' => $otherSeller->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 10,
                'name' => $product->name,
                'total' => 10,
            ]],
        ])
        ->assertRedirect(route('app.orders.index'));

    $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($order->user_id)->toBe($seller->id);
});
