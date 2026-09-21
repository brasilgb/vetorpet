<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CommercialCondition;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderUpdateService;
use App\Services\Pricing\RegionalPriceResolver;
use App\Support\FlexBalance;
use App\Support\PlanLimits;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->get('q');

        $query = Order::visibleTo()->orderBy('id', 'DESC');

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('order_number', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', function ($query) use ($search) {
                        $query->where('name', 'like', '%'.$search.'%')
                            ->orWhere('cnpj', 'like', '%'.$search.'%');
                    });
            });
        }

        $orders = $query->with('customer.region', 'user')->paginate(12);

        return Inertia::render('app/orders/index', ['orders' => $orders]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $products = Product::with(['regionPrices' => fn ($query) => $query->active()])
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => [
                ...$product->toArray(),
                'special_prices' => $product->regionPrices->map(fn ($regionPrice) => [
                    'region_id' => $regionPrice->region_id,
                    'special_price' => (float) $regionPrice->special_price,
                ])->values(),
            ]);
        $customers = Customer::visibleTo()
            ->with(['region', 'user:id,name', 'latestOrder.orderItems.product'])
            ->orderBy('name')
            ->get()
            ->each(function (Customer $customer) {
                $customer->setAttribute('commercial_condition', CommercialCondition::resolveForCustomer($customer));
            });
        $flex = FlexBalance::contextFor($request->user());
        $selectedCustomerId = request()->integer('customer_id') ?: null;
        $canManageSellers = $request->user()->canManageSellers();

        return Inertia::render('app/orders/create-order', [
            'products' => $products,
            'customers' => $customers,
            'flex' => $flex,
            'selectedCustomerId' => $selectedCustomerId,
            'campaigns' => Campaign::active()
                ->with(['products:id', 'commercialCondition'])
                ->orderBy('name')
                ->get(),
            'canManageSellers' => $canManageSellers,
            'sellers' => $canManageSellers
                ? User::where('tenant_id', $request->user()->tenant_id)
                    ->whereIn('roles', [User::ROLE_OWNER, User::ROLE_SELLER])
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, RegionalPriceResolver $priceResolver)
    {
        PlanLimits::forTenant()->ensureCanCreate('orders_month');

        $tenantId = $request->user()->tenant_id;
        $customerRule = Rule::exists('customers', 'id')->where(function ($query) use ($request, $tenantId) {
            $query->where('tenant_id', $tenantId);

            if (! $request->user()->canManageTeam()) {
                $query->whereIn('region_id', $request->user()->regions()->pluck('regions.id'));
            }
        });
        $validatedData = $request->validate([
            'customer_id' => ['required', $customerRule],
            'campaign_id' => ['nullable', Rule::exists('campaigns', 'id')->where('tenant_id', $tenantId)],
            'user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(function ($query) use ($tenantId) {
                    $query->where('tenant_id', $tenantId)->whereIn('roles', [User::ROLE_OWNER, User::ROLE_SELLER]);
                }),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'gt:0'], // 'gt:0' é uma boa adição
            'items.*.discount_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.discount_amount' => ['nullable', 'numeric'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.name' => ['required', 'string'], // min:0 não é necessário aqui
            'items.*.total' => ['required', 'numeric', 'min:0'],
            'flex' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'adjusted_total' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'adjusted_total_was_edited' => ['nullable', 'boolean'],
            'payment_condition' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_recurring' => ['nullable', 'boolean'],
        ]);

        // Somente quem pode gerenciar vendedores tem permissão para atribuir o
        // pedido a outro usuário; caso contrário, o pedido fica com quem o criou.
        $assignedSellerId = $request->user()->canManageSellers()
            ? ($validatedData['user_id'] ?? null)
            : null;

        try {
            $customer = Customer::visibleTo()->findOrFail($validatedData['customer_id']);
            $campaign = isset($validatedData['campaign_id'])
                ? Campaign::active()->with(['products:id', 'commercialCondition'])->findOrFail($validatedData['campaign_id'])
                : null;
            abort_if($campaign && ! $campaign->commercialCondition, 422, 'A campanha não possui uma regra comercial ativa.');
            abort_if($campaign?->audience_type === 'region' && $campaign->region_id !== $customer->region_id, 422, 'A campanha não é válida para a região deste cliente.');
            $customerCondition = CommercialCondition::resolveForCustomer($customer);
            $commercialCondition = $campaign?->commercialCondition ?? $customerCondition;
            $campaignProductIds = $campaign?->products->modelKeys() ?? [];

            DB::beginTransaction();

            $subtotal = 0;
            $campaignQuantity = 0;
            foreach ($validatedData['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                $itemCondition = $campaign && in_array($product->id, $campaignProductIds, true)
                    ? $campaign->commercialCondition
                    : $customerCondition;
                // Um preço especial Produto x Região ativo substitui qualquer regra (cliente,
                // região, tipo de estabelecimento, global ou campanha) para aquele item.
                $expectedPrice = $priceResolver->effectivePriceForSale($product, $customer->region, $itemCondition);

                if (abs((float) $item['price'] - $expectedPrice) > 0.01) {
                    throw new \Exception('Preço divergente da condição comercial para o produto: '.$product->name);
                }

                $grossItemTotal = round($expectedPrice * (int) $item['quantity'], 2);
                $itemAdjustment = array_key_exists('discount_amount', $item)
                    ? round((float) $item['discount_amount'], 2)
                    : -round($grossItemTotal * ((float) ($item['discount_percentage'] ?? 0) / 100), 2);
                if ($grossItemTotal + $itemAdjustment < 0) {
                    throw new \Exception('O desconto individual não pode superar o valor do item.');
                }
                $subtotal += $grossItemTotal + $itemAdjustment;
                if ($campaign && in_array($product->id, $campaignProductIds, true)) {
                    $campaignQuantity += (int) $item['quantity'];
                }
            }

            $subtotal = round($subtotal, 2);
            $manualDiscount = round((float) ($validatedData['discount'] ?? 0), 2);
            $adjustedTotal = ($validatedData['adjusted_total_was_edited'] ?? false) && isset($validatedData['adjusted_total'])
                ? round((float) $validatedData['adjusted_total'], 2)
                : round($subtotal + (float) ($validatedData['flex'] ?? 0), 2);

            if ($manualDiscount > $adjustedTotal) {
                throw new \Exception('O desconto não pode ser maior que o valor ajustado.');
            }

            $flexAmount = max(round($adjustedTotal - $subtotal, 2), 0);
            $priceReduction = max(round($subtotal - $adjustedTotal, 2), 0);
            $discountAmount = round($priceReduction + $manualDiscount, 2);
            $total = max(round($adjustedTotal - $manualDiscount, 2), 0);

            if ($commercialCondition) {
                $discountPercentage = $subtotal > 0 ? ($discountAmount / $subtotal) * 100 : 0;

                if ($discountPercentage > $commercialCondition->maximumAdditionalDiscountPercentage()) {
                    throw new \Exception('Desconto acima do limite permitido para este cliente.');
                }

                if ($campaign && $campaignQuantity < (int) $commercialCondition->minimum_order_quantity) {
                    throw new \Exception('Quantidade mínima da campanha não atingida.');
                }

                if (! $campaign && $total < (float) $commercialCondition->minimum_order_amount) {
                    throw new \Exception('Pedido abaixo do valor mínimo da condição comercial.');
                }
            }

            $commissionPercentage = (float) ($commercialCondition?->commission_percentage ?? 0);
            $commissionAmount = round($total * ($commissionPercentage / 100), 2);
            $flexContext = FlexBalance::contextFor($request->user());

            // 1. Criação do Pedido principal
            $order = Order::create([
                'user_id' => $assignedSellerId,
                'customer_id' => $validatedData['customer_id'],
                'commercial_condition_id' => $commercialCondition?->id,
                'campaign_id' => $campaign?->id,
                'order_number' => Order::exists() ? Order::latest()->first()->order_number + 1 : 1,
                'flex' => $flexAmount,
                'uses_admin_flex' => $flexContext['is_admin_override'],
                'discount' => $discountAmount,
                'subtotal' => $subtotal,
                'adjusted_total' => $adjustedTotal,
                'total' => $total,
                'status' => 1,
                'payment_condition' => $validatedData['payment_condition'] ?? $commercialCondition?->payment_terms,
                'notes' => $validatedData['notes'] ?? null,
                'commission_percentage' => $commissionPercentage,
                'commission_amount' => $commissionAmount,
                'is_recurring' => (bool) ($validatedData['is_recurring'] ?? false),
                'next_delivery_at' => ($validatedData['is_recurring'] ?? false) ? now()->addMonthNoOverflow()->toDateString() : null,
            ]);

            // 2. Preparar e criar os itens do pedido
            $orderItemsData = collect($validatedData['items'])->map(function ($item) {
                $grossItemTotal = round((float) $item['price'] * (int) $item['quantity'], 2);
                $adjustmentAmount = array_key_exists('discount_amount', $item)
                    ? round((float) $item['discount_amount'], 2)
                    : -round($grossItemTotal * ((float) ($item['discount_percentage'] ?? 0) / 100), 2);
                $discountPercentage = array_key_exists('discount_amount', $item)
                    ? ($grossItemTotal > 0 ? round(($adjustmentAmount / $grossItemTotal) * 100, 2) : 0)
                    : round((float) ($item['discount_percentage'] ?? 0), 2);

                return [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'discount_percentage' => $discountPercentage,
                    'discount_amount' => array_key_exists('discount_amount', $item) ? $adjustmentAmount : abs($adjustmentAmount),
                    'name' => $item['name'],
                    'total' => round($grossItemTotal + $adjustmentAmount, 2),
                ];
            })->toArray();

            $order->orderItems()->createMany($orderItemsData);

            // --- NOVO: LÓGICA PARA DECREMENTAR O ESTOQUE ---
            // 3. Iterar sobre os itens para validar o estoque e decrementar
            foreach ($validatedData['items'] as $item) {
                // Usamos lockForUpdate() para previnir 'race conditions',
                // onde duas pessoas compram o último item ao mesmo tempo.
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                // Estoque insuficiente não bloqueia o pedido: o saldo fica negativo
                // e o pedido passa a valer como uma pré-venda até a reposição.
                $product->decrement('quantity', $item['quantity']);
            }
            // --- FIM DA NOVA LÓGICA ---

            FlexBalance::commit($flexContext, $flexAmount, $discountAmount);

            DB::commit();

            return redirect()->route('app.orders.index')->with('success', 'Pedido criado com sucesso!');
        } catch (\Exception $e) {
            DB::rollBack();

            // Retorna com a mensagem de erro específica (inclusive a de estoque)
            return redirect()->back()->with('error', 'Ocorreu um erro: '.$e->getMessage())->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Order $order)
    {
        $this->authorizeVisibleOrder($order);

        $products = Product::with(['regionPrices' => fn ($query) => $query->active()])
            ->get()
            ->map(fn (Product $product) => [
                ...$product->toArray(),
                'special_prices' => $product->regionPrices->map(fn ($regionPrice) => [
                    'region_id' => $regionPrice->region_id,
                    'special_price' => (float) $regionPrice->special_price,
                ])->values(),
            ]);
        $customers = Customer::visibleTo()->with('region')->orderBy('name')->get()->each(fn (Customer $customer) => $customer->setAttribute('commercial_condition', CommercialCondition::resolveForCustomer($customer)));
        $flex = FlexBalance::contextFor($request->user());
        // Carrega os relacionamentos necessários no modelo já injetado pela rota.
        $order->load('customer', 'orderItems');

        // O método orderItems() retorna a relação, para obter os itens, acesse a propriedade.
        return Inertia::render('app/orders/edit-order', [
            'order' => $order,
            'products' => $products,
            'customers' => $customers,
            'flex' => $flex,
            'orderitems' => $order->orderItems,
            'users' => $this->availableUsers($request),
            'canManageTeam' => $request->user()->canManageTeam(),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Order $order)
    {
        return Redirect::route('app.orders.show', ['order' => $order->id]);
    }

    public function printOrder(Request $request, Order $order)
    {
        $this->authorizeVisibleOrder($order);

        $order->load([
            'customer.region',
            'orderItems.product',
            'user',
            'commercialCondition',
            'campaign',
        ]);

        return Inertia::render('app/orders/print-order', [
            'order' => $order,
            'tenant' => $request->user()->tenant,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Order $order)
    {
        $this->authorizeVisibleOrder($order);
        $tenantId = $request->user()->tenant_id;
        $validated = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'user_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.discount_amount' => ['nullable', 'numeric'],
            'adjusted_total' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'payment_condition' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_recurring' => ['nullable', 'boolean'],
        ]);

        // Só um admin/dono pode reatribuir o vendedor responsável pelo pedido.
        if (! $request->user()->canManageTeam() || empty($validated['user_id'])) {
            unset($validated['user_id']);
        }

        try {
            app(OrderUpdateService::class)->update($order, $validated);

            return redirect()->route('app.orders.index')->with('success', 'Pedido atualizado com sucesso!');
        } catch (\Throwable $exception) {
            return redirect()->back()->with('error', $exception->getMessage())->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    // public function destroy(Order $order)
    // {
    //     $order->delete();
    //     return redirect()->route('app.orders.index')->with('success', 'Pedido excluído com sucesso');
    // }

    /**
     * Remove o pedido do banco de dados e devolve os itens ao estoque.
     *
     * @return RedirectResponse
     */
    public function destroy(Order $order)
    {
        $this->authorizeOrderManagement();
        $this->authorizeVisibleOrder($order);

        // Alternativa: verifique se o pedido pode ser deletado
        // Por exemplo, talvez pedidos 'concluídos' não possam ser deletados.
        // if ($order->status === 'concluido') {
        //     return redirect()->back()->with('error', 'Não é possível deletar um pedido já concluído.');
        // }

        try {
            DB::beginTransaction();

            if ($order->status !== '4') {
                foreach ($order->orderItems as $item) {
                    // Encontra o produto e trava a linha para evitar problemas de concorrência
                    $product = Product::lockForUpdate()->find($item->product_id);

                    // Se o produto ainda existir, incrementa o estoque
                    if ($product) {
                        // Substitua 'stock' pelo nome da sua coluna de estoque
                        $product->increment('quantity', $item->quantity);
                    }
                }

                FlexBalance::release($order);
            }

            // 2. Deleta os registros de order_items primeiro
            $order->orderItems()->delete();

            // 3. Deleta o pedido principal
            $order->delete();

            DB::commit();

            return redirect()->route('app.orders.index')->with('success', 'Pedido deletado e estoque atualizado com sucesso!');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()->with('error', 'Erro ao deletar o pedido: '.$e->getMessage());
        }
    }

    public function setValueStatusOrder(Request $request, Order $order)
    {
        $this->authorizeOrderManagement();
        $this->authorizeVisibleOrder($order);
        $validated = $request->validate(['status' => ['required', Rule::in(['1', '2', '3', '4'])]]);

        if ($validated['status'] === '4') {
            return $this->cancelOrder($order);
        }

        $order->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Status alterado com sucesso.',
        ]);
    }

    public function cancelOrder(Order $order)
    {
        $this->authorizeOrderManagement();
        $this->authorizeVisibleOrder($order);

        // 💡 Verificação inicial: não se pode cancelar um pedido já cancelado.
        if ($order->status === '4') {
            return response()->json([
                'success' => false,
                'message' => 'Este pedido já foi cancelado.',
            ], 409); // 409 Conflict é um bom status HTTP para isso.
        }

        try {
            DB::beginTransaction();

            // 1. Itera sobre os itens do pedido para devolver ao estoque
            foreach ($order->orderItems as $item) {

                // 🛡️ lockForUpdate() previne que duas pessoas alterem o mesmo produto ao mesmo tempo
                $product = Product::lockForUpdate()->find($item->product_id);

                if ($product) {
                    // Substitua 'stock' pelo nome real da sua coluna de estoque
                    $product->increment('quantity', $item->quantity);
                }
            }

            FlexBalance::release($order);

            // 2. Atualiza o status do pedido para 'cancelado'
            $order::where('id', $order->id)->update(['status' => '4']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pedido cancelado e estoque atualizado com sucesso.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            // Em um ambiente de produção, você deveria logar este erro.
            // Log::error('Erro ao cancelar pedido: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    public function orderReport(Request $request)
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])->startOfDay()
            : now()->startOfMonth();
        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])->endOfDay()
            : now()->endOfDay();
        $orders = Order::visibleTo()
            ->with('customer.region', 'user')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->latest()
            ->get();

        return Inertia::render('app/orders/order-reports', [
            'orders' => $orders,
            'filters' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
        ]);
    }
    // public function orderDateReport($date)
    // {
    //     $orders = Order::with('customer')->whereDate('created_at', $date)->get();
    //     return Inertia::render('app/orders/order-report', ["orders" => $orders]);
    // }

    private function authorizeVisibleOrder(Order $order): void
    {
        abort_unless(Order::visibleTo()->whereKey($order->id)->exists(), 404);
    }

    private function authorizeOrderManagement(): void
    {
        abort_unless(auth()->user()?->canManageTeam(), 403);
    }

    private function availableUsers(Request $request)
    {
        if (! $request->user()->canManageTeam()) {
            return collect([$request->user()->only(['id', 'name', 'email'])]);
        }

        return User::where('tenant_id', $request->user()->tenant_id)->where('status', true)->orderBy('name')->get(['id', 'name', 'email']);
    }
}
