<?php

namespace App\Services\SampleData;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Region;
use App\Models\SampleRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dados de exemplo opcionais para a avaliação (trial) de um tenant
 * recém-cadastrado: poucas regiões, clientes, produtos, pedidos e visitas
 * fictícios, todos registrados em sample_records para poderem ser removidos
 * depois sem tocar em nenhum registro real.
 *
 * Regras de segurança:
 * - Toda consulta usa o tenant informado explicitamente (sem depender de
 *   sessão/usuário autenticado nem do TenantScope).
 * - Só gera dados em tenant vazio (sem clientes, produtos, regiões, pedidos
 *   ou visitas) e que ainda não recebeu dados de exemplo.
 * - Os dados não têm contatos reais (e-mails em domínio .invalid, sem
 *   telefone/WhatsApp/CNPJ), para que nenhum link, mensagem ou cobrança
 *   possa partir deles. Pedidos de exemplo não mexem em estoque nem Flex.
 * - A exclusão remove apenas IDs registrados; um registro de exemplo que
 *   passou a ser usado por um registro real (ex.: pedido real de um cliente
 *   de exemplo) é mantido, para não apagar nem cascatear dados reais.
 */
class SampleDataService
{
    public const LABEL = '(exemplo)';

    public function hasSampleData(Tenant $tenant): bool
    {
        return SampleRecord::where('tenant_id', $tenant->id)->exists();
    }

    public function canSeed(Tenant $tenant): bool
    {
        if ($this->hasSampleData($tenant)) {
            return false;
        }

        foreach ([Region::class, Customer::class, Product::class, Order::class, Visit::class] as $model) {
            if ($model::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, int> quantidade criada por tipo
     */
    public function seed(Tenant $tenant, User $owner): array
    {
        if ((int) $owner->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException('O usuário informado não pertence ao tenant.');
        }

        return DB::transaction(function () use ($tenant, $owner) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->first();

            if (! $this->canSeed($tenant)) {
                throw new RuntimeException('Dados de exemplo só podem ser criados em uma conta nova, sem registros.');
            }

            $regions = collect([
                ['name' => 'Região Centro '.self::LABEL, 'description' => 'Região fictícia de demonstração'],
                ['name' => 'Região Interior '.self::LABEL, 'description' => 'Região fictícia de demonstração'],
            ])->map(fn (array $data) => $this->persist(new Region($data + ['status' => true]), $tenant, SampleRecord::TYPE_REGION));

            $customers = collect([
                ['name' => 'Pet Shop Bom Amigo', 'establishment_type' => 'petshop', 'city' => 'Cidade Exemplo', 'region' => 0],
                ['name' => 'Clínica Veterinária Patinhas', 'establishment_type' => 'clinica_veterinaria', 'city' => 'Cidade Exemplo', 'region' => 0],
                ['name' => 'Banho e Tosa Cheiroso', 'establishment_type' => 'banho_tosa', 'city' => 'Vila Modelo', 'region' => 1],
                ['name' => 'Agropecuária Campo Verde', 'establishment_type' => 'agropecuaria', 'city' => 'Vila Modelo', 'region' => 1],
                ['name' => 'Hospital Veterinário Vida Animal', 'establishment_type' => 'clinica_veterinaria', 'city' => 'Cidade Exemplo', 'region' => 0],
            ])->values()->map(function (array $data, int $index) use ($tenant, $owner, $regions) {
                $customer = new Customer([
                    'user_id' => $owner->id,
                    'region_id' => $regions[$data['region']]->id,
                    'establishment_type' => $data['establishment_type'],
                    'name' => $data['name'].' '.self::LABEL,
                    'email' => 'cliente'.($index + 1).'@exemplo.invalid',
                    'state' => 'SP',
                    'city' => $data['city'],
                    'district' => 'Centro',
                    'street' => 'Rua Fictícia',
                    'number' => (string) (100 + $index),
                    'contactname' => 'Contato fictício',
                    'preferred_visit_days' => 'Terça e quinta',
                    'commercial_notes' => 'Cliente fictício criado para demonstração. Pode ser removido a qualquer momento.',
                ]);

                return $this->persist($customer, $tenant, SampleRecord::TYPE_CUSTOMER);
            });

            $products = collect([
                ['Ração Cães Adultos 15kg', 'EX-001', 'cao', 'Ração', 189.90, 40],
                ['Ração Gatos Castrados 10kg', 'EX-002', 'gato', 'Ração', 164.50, 30],
                ['Petisco Bifinho 500g', 'EX-003', 'cao', 'Petiscos', 24.90, 120],
                ['Areia Higiênica 4kg', 'EX-004', 'gato', 'Higiene', 18.70, 80],
                ['Shampoo Neutro 5L', 'EX-005', 'cao', 'Higiene', 72.00, 15],
                ['Antipulgas 3 doses', 'EX-006', 'cao', 'Farmácia', 96.40, 25],
                ['Vermífugo Gatos', 'EX-007', 'gato', 'Farmácia', 38.90, 6],
                ['Coleira Ajustável M', 'EX-008', 'cao', 'Acessórios', 29.90, 50],
            ])->map(function (array $data) use ($tenant) {
                [$name, $reference, $species, $category, $price, $quantity] = $data;

                $product = new Product([
                    'name' => $name.' '.self::LABEL,
                    'reference' => $reference,
                    'description' => 'Produto fictício de demonstração',
                    'species' => $species,
                    'category' => $category,
                    'brand' => 'Marca Exemplo',
                    'unity' => 'UN',
                    'measure' => 1,
                    'price' => $price,
                    'quantity' => $quantity,
                    'min_quantity' => 10,
                    'enabled' => true,
                ]);

                return $this->persist($product, $tenant, SampleRecord::TYPE_PRODUCT);
            });

            // Pedidos em ordem cronológica crescente: a numeração do sistema
            // usa o último pedido criado (Order::latest()) + 1.
            $orderPlans = [
                [20, 0, [[0, 2], [2, 10]], '3'],
                [15, 1, [[1, 1], [5, 2]], '3'],
                [10, 2, [[4, 3], [3, 6]], '3'],
                [6, 3, [[0, 4], [7, 5]], '4'],
                [3, 4, [[5, 3], [6, 2], [2, 6]], '2'],
                [1, 0, [[1, 2], [3, 4]], '1'],
            ];

            $orders = collect($orderPlans)->values()->map(function (array $plan, int $index) use ($tenant, $owner, $customers, $products) {
                [$daysAgo, $customerIndex, $items, $status] = $plan;
                $createdAt = now()->subDays($daysAgo)->setTime(10, 0);
                $subtotal = 0.0;
                $lines = [];

                foreach ($items as [$productIndex, $quantity]) {
                    $product = $products[$productIndex];
                    $total = round((float) $product->price * $quantity, 2);
                    $subtotal += $total;
                    $lines[] = [$product, $quantity, $total];
                }

                $subtotal = round($subtotal, 2);
                $order = new Order([
                    'user_id' => $owner->id,
                    'customer_id' => $customers[$customerIndex]->id,
                    'order_number' => $index + 1,
                    'flex' => 0,
                    'discount' => 0,
                    'subtotal' => $subtotal,
                    'adjusted_total' => $subtotal,
                    'total' => $subtotal,
                    'status' => $status,
                    'payment_condition' => '28 dias',
                    'notes' => 'Pedido fictício de demonstração.',
                    'commission_percentage' => 0,
                    'commission_amount' => 0,
                ]);
                $order->created_at = $createdAt;
                $order->updated_at = $createdAt;
                $this->persist($order, $tenant, SampleRecord::TYPE_ORDER);

                foreach ($lines as [$product, $quantity, $total]) {
                    $item = new OrderItem([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'quantity' => $quantity,
                        'price' => $product->price,
                        'total' => $total,
                    ]);
                    $item->tenant_id = $tenant->id;
                    $item->created_at = $createdAt;
                    $item->updated_at = $createdAt;
                    $item->save();
                }

                return $order;
            });

            $visits = collect([
                [-2, 1, 'scheduled', null],
                [-5, 3, 'scheduled', null],
                [3, 2, 'completed', 'no_sale'],
                [6, 4, 'completed', 'sold'],
            ])->map(function (array $data) use ($tenant, $owner, $customers) {
                [$daysAgo, $customerIndex, $status, $result] = $data;
                $scheduledAt = now()->subDays($daysAgo)->setTime(14, 0);

                $visit = new Visit([
                    'customer_id' => $customers[$customerIndex]->id,
                    'user_id' => $owner->id,
                    'scheduled_at' => $scheduledAt,
                    'check_in_at' => $status === 'completed' ? $scheduledAt : null,
                    'check_out_at' => $status === 'completed' ? $scheduledAt->copy()->addMinutes(40) : null,
                    'status' => $status,
                    'result' => $result,
                    'no_sale_reason' => $result === 'no_sale' ? 'retorno_futuro' : null,
                    'notes' => 'Visita fictícia de demonstração.',
                ]);

                return $this->persist($visit, $tenant, SampleRecord::TYPE_VISIT);
            });

            return [
                'regions' => $regions->count(),
                'customers' => $customers->count(),
                'products' => $products->count(),
                'orders' => $orders->count(),
                'visits' => $visits->count(),
            ];
        });
    }

    /**
     * Remove os dados de exemplo do tenant. Retorna o que foi removido e o que
     * foi mantido por estar em uso por registros reais.
     *
     * @return array{removed: array<string, int>, kept: array<string, int>}
     */
    public function purge(Tenant $tenant): array
    {
        return DB::transaction(function () use ($tenant) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->first();

            $removed = [];
            $kept = [];
            $ids = fn (string $type) => SampleRecord::where('tenant_id', $tenant->id)
                ->where('record_type', $type)
                ->pluck('record_id');
            $sampleProducts = $ids(SampleRecord::TYPE_PRODUCT);

            // Pedidos: removidos com os itens, exceto se o usuário incluiu
            // neles um produto real (o pedido passou a envolver dado real).
            [$orders, $keptOrders] = $this->partition($ids(SampleRecord::TYPE_ORDER), fn ($orderIds) => OrderItem::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereIn('order_id', $orderIds)
                ->where(fn ($query) => $query->whereNull('product_id')->orWhereNotIn('product_id', $sampleProducts))
                ->pluck('order_id'));
            OrderItem::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('order_id', $orders)->delete();
            $removed['orders'] = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $orders)->delete();
            $kept['orders'] = $keptOrders->count();
            $this->forget($tenant, SampleRecord::TYPE_ORDER, $orders);

            $removed['visits'] = Visit::withoutGlobalScopes()->where('tenant_id', $tenant->id)
                ->whereIn('id', $ids(SampleRecord::TYPE_VISIT))->delete();
            $this->forget($tenant, SampleRecord::TYPE_VISIT, $ids(SampleRecord::TYPE_VISIT));

            [$customers, $keptCustomers] = $this->partition($ids(SampleRecord::TYPE_CUSTOMER), fn ($customerIds) => collect()
                ->merge(DB::table('orders')->where('tenant_id', $tenant->id)->whereIn('customer_id', $customerIds)->pluck('customer_id'))
                ->merge(DB::table('visits')->where('tenant_id', $tenant->id)->whereIn('customer_id', $customerIds)->pluck('customer_id'))
                ->merge(DB::table('commercial_conditions')->whereIn('customer_id', $customerIds)->pluck('customer_id')));
            $removed['customers'] = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $customers)->delete();
            $kept['customers'] = $keptCustomers->count();
            $this->forget($tenant, SampleRecord::TYPE_CUSTOMER, $customers);

            [$products, $keptProducts] = $this->partition($sampleProducts, fn ($productIds) => collect()
                ->merge(DB::table('order_items')->whereIn('product_id', $productIds)->pluck('product_id'))
                ->merge(DB::table('product_region_prices')->whereIn('product_id', $productIds)->pluck('product_id'))
                ->merge(DB::table('campaign_product')->whereIn('product_id', $productIds)->pluck('product_id'))
                ->merge(DB::table('campaigns')->whereIn('product_id', $productIds)->pluck('product_id')));
            $removed['products'] = Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $products)->delete();
            $kept['products'] = $keptProducts->count();
            $this->forget($tenant, SampleRecord::TYPE_PRODUCT, $products);

            [$regions, $keptRegions] = $this->partition($ids(SampleRecord::TYPE_REGION), fn ($regionIds) => collect()
                ->merge(DB::table('customers')->whereIn('region_id', $regionIds)->pluck('region_id'))
                ->merge(DB::table('commercial_conditions')->whereIn('region_id', $regionIds)->pluck('region_id'))
                ->merge(DB::table('product_region_prices')->whereIn('region_id', $regionIds)->pluck('region_id'))
                ->merge(DB::table('campaigns')->whereIn('region_id', $regionIds)->pluck('region_id'))
                ->merge(DB::table('region_user')->whereIn('region_id', $regionIds)->pluck('region_id')));
            $removed['regions'] = Region::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $regions)->delete();
            $kept['regions'] = $keptRegions->count();
            $this->forget($tenant, SampleRecord::TYPE_REGION, $regions);

            return ['removed' => $removed, 'kept' => array_filter($kept)];
        });
    }

    private function persist($model, Tenant $tenant, string $type)
    {
        $model->tenant_id = $tenant->id;
        $model->save();

        SampleRecord::create([
            'tenant_id' => $tenant->id,
            'record_type' => $type,
            'record_id' => $model->id,
        ]);

        return $model;
    }

    /**
     * Separa os IDs entre removíveis e em uso, dado um resolvedor que devolve
     * os IDs que ainda têm dependentes.
     */
    private function partition($ids, \Closure $inUse): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id);

        if ($ids->isEmpty()) {
            return [collect(), collect()];
        }

        $used = collect($inUse($ids))->map(fn ($id) => (int) $id)->unique();

        return [$ids->diff($used)->values(), $ids->intersect($used)->values()];
    }

    private function forget(Tenant $tenant, string $type, $ids): void
    {
        SampleRecord::where('tenant_id', $tenant->id)
            ->where('record_type', $type)
            ->whereIn('record_id', collect($ids))
            ->delete();
    }
}
