<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CommercialCondition;
use App\Models\Customer;
use App\Models\Flex;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderUpdateService;
use App\Services\Pricing\RegionalPriceResolver;
use App\Support\FlexBalance;
use App\Support\PlanLimits;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;

class ApiOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $dateToFilter = Carbon::today();
        if (! Order::visibleTo()->whereDate('created_at', $dateToFilter)->exists()) {
            $dateToFilter = Order::visibleTo()->max('created_at');
        }
        if ($dateToFilter) {
            $orders = Order::visibleTo()
                ->with('customer.region')
                ->whereDate('created_at', $dateToFilter)
                ->latest()
                ->get();
        } else {
            $orders = collect();
        }
        $orders = Order::visibleTo()->with('customer.region')->get();

        return response()->json($orders);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'gt:0'], // 'gt:0' é uma boa adição
            'items.*.discount_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.discount_amount' => ['nullable', 'numeric'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.name' => ['nullable', 'string'],
            'items.*.total' => ['nullable', 'numeric', 'min:0'],
            'flex' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'total' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'total_was_edited' => ['nullable', 'boolean'],
            'payment_condition' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

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

            $flexAmount = (float) ($validatedData['flex'] ?? 0);
            $discountAmount = (float) ($validatedData['discount'] ?? 0);
            $subtotal = 0;
            $campaignQuantity = 0;
            $orderItemsData = [];

            foreach ($validatedData['items'] as $item) {
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                // Estoque insuficiente não bloqueia o pedido: o saldo fica negativo
                // e o pedido passa a valer como uma pré-venda até a reposição.
                $itemCondition = $campaign && in_array($product->id, $campaignProductIds, true)
                    ? $campaign->commercialCondition
                    : $customerCondition;
                // Um preço especial Produto x Região ativo substitui qualquer regra (cliente,
                // região, tipo de estabelecimento, global ou campanha) para aquele item.
                $price = $priceResolver->effectivePriceForSale($product, $customer->region, $itemCondition);
                $grossItemTotal = round($price * (int) $item['quantity'], 2);
                $itemAdjustment = array_key_exists('discount_amount', $item)
                    ? round((float) $item['discount_amount'], 2)
                    : -round($grossItemTotal * ((float) ($item['discount_percentage'] ?? 0) / 100), 2);
                if ($grossItemTotal + $itemAdjustment < 0) {
                    throw new \Exception('O desconto individual não pode superar o valor do item.');
                }
                $discountPercentage = array_key_exists('discount_amount', $item)
                    ? ($grossItemTotal > 0 ? round(($itemAdjustment / $grossItemTotal) * 100, 2) : 0)
                    : round((float) ($item['discount_percentage'] ?? 0), 2);
                $itemTotal = round($grossItemTotal + $itemAdjustment, 2);
                $subtotal += $itemTotal;
                if ($campaign && in_array($product->id, $campaignProductIds, true)) {
                    $campaignQuantity += (int) $item['quantity'];
                }

                $orderItemsData[] = [
                    'product_id' => $product->id,
                    'quantity' => (int) $item['quantity'],
                    'price' => $price,
                    'discount_percentage' => $discountPercentage,
                    'discount_amount' => array_key_exists('discount_amount', $item) ? $itemAdjustment : abs($itemAdjustment),
                    'name' => $product->name,
                    'total' => $itemTotal,
                ];
            }

            $subtotal = round($subtotal, 2);
            $adjustedTotal = null;

            if (($validatedData['total_was_edited'] ?? false) && isset($validatedData['total'])) {
                $adjustedTotal = round((float) $validatedData['total'], 2);
                $manualDiscount = round((float) ($validatedData['discount'] ?? 0), 2);

                if ($manualDiscount > $adjustedTotal) {
                    throw new \Exception('O desconto não pode ser maior que o total ajustado.');
                }

                $flexAmount = max(round($adjustedTotal - $subtotal, 2), 0);
                $priceReduction = max(round($subtotal - $adjustedTotal, 2), 0);
                $discountAmount = round($priceReduction + $manualDiscount, 2);
                $total = max(round($adjustedTotal - $manualDiscount, 2), 0);
            } else {
                $total = max(round($subtotal + $flexAmount - $discountAmount, 2), 0);
            }

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
                'customer_id' => $validatedData['customer_id'],
                'commercial_condition_id' => $commercialCondition?->id,
                'campaign_id' => $campaign?->id,
                'order_number' => Order::exists() ? Order::latest()->first()->order_number + 1 : 1,
                'flex' => $flexAmount,
                'uses_admin_flex' => $flexContext['is_admin_override'],
                'discount' => $discountAmount,
                'subtotal' => $subtotal,
                'adjusted_total' => $adjustedTotal ?? round($subtotal + $flexAmount, 2),
                'total' => $total,
                'status' => 1,
                'payment_condition' => $validatedData['payment_condition'] ?? $commercialCondition?->payment_terms,
                'notes' => $validatedData['notes'] ?? null,
                'commission_percentage' => $commissionPercentage,
                'commission_amount' => $commissionAmount,
            ]);

            $order->orderItems()->createMany($orderItemsData);

            // --- NOVO: LÓGICA PARA DECREMENTAR O ESTOQUE ---
            // 3. Iterar sobre os itens para validar o estoque e decrementar
            foreach ($orderItemsData as $item) {
                Product::whereKey($item['product_id'])->decrement('quantity', $item['quantity']);
            }
            // --- FIM DA NOVA LÓGICA ---

            FlexBalance::commit($flexContext, $flexAmount, $discountAmount);

            DB::commit();

            return response()->json([
                'message' => 'Pedido criado com sucesso!',
                'order' => $order->load('customer.region', 'orderItems', 'commercialCondition'),
            ], 201);
            // return redirect()->route('app.orders.index')->with('success', 'Pedido criado com sucesso!');
        } catch (\Exception $e) {
            DB::rollBack();

            // Retorna com a mensagem de erro específica (inclusive a de estoque)
            return response()->json(['message' => 'Ocorreu um erro: '.$e->getMessage()], 400);
            // return redirect()->back()->with('error', 'Ocorreu um erro: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Order $order)
    {
        $this->authorizeVisibleOrder($order);

        $products = Product::all();
        $customers = Customer::visibleTo()->get();
        $flex = Flex::first()?->value ?? 0;
        $order->load('customer.region', 'orderItems');

        return response()->json([
            'order' => $order,
            'products' => $products,
            'customers' => $customers,
            'flex' => $flex,
            'orderitems' => $order->orderItems,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Order $order)
    {
        return Redirect::route('app.orders.show', ['order' => $order->id]);
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.discount_amount' => ['nullable', 'numeric'],
            'adjusted_total' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'payment_condition' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $updatedOrder = app(OrderUpdateService::class)->update($order, $validated);

            return response()->json(['message' => 'Pedido atualizado com sucesso!', 'order' => $updatedOrder]);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Order $order)
    {
        $this->authorizeVisibleOrder($order);

        try {
            DB::beginTransaction();

            if ($order->status !== '4') {
                foreach ($order->orderItems as $item) {
                    Product::lockForUpdate()->find($item->product_id)?->increment('quantity', $item->quantity);
                }

                FlexBalance::release($order);
            }
            $order->orderItems()->delete();
            $order->delete();

            DB::commit();

            return response()->json(['message' => 'Pedido excluído com sucesso.']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function getFlex(Request $request)
    {
        return response()->json(FlexBalance::contextFor($request->user()));
    }

    public function getDateOrders(Request $request)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date', 'required_without_all:start_date,end_date'],
            'start_date' => ['nullable', 'date', 'required_with:end_date'],
            'end_date' => ['nullable', 'date', 'required_with:start_date', 'after_or_equal:start_date'],
        ]);

        $startDate = $validated['start_date'] ?? $validated['date'];
        $endDate = $validated['end_date'] ?? $validated['date'];
        $periodQuery = Order::visibleTo()->whereBetween('created_at', [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay(),
        ]);
        $sumFlex = (clone $periodQuery)->sum('flex');
        $sumDiscount = (clone $periodQuery)->sum('discount');
        $sumSubtotal = (clone $periodQuery)->sum('subtotal');
        $sumAdjustedTotal = (clone $periodQuery)->sum('adjusted_total');
        $sumTotal = (clone $periodQuery)->sum('total');
        $orders = (clone $periodQuery)->with('customer.region')->latest()->get();
        $orderData = [
            'orders' => $orders,
            'sumFlex' => $sumFlex,
            'sumDiscount' => $sumDiscount,
            'sumSubtotal' => $sumSubtotal,
            'sumAdjustedTotal' => $sumAdjustedTotal,
            'sumTotal' => $sumTotal,
        ];

        return response()->json($orderData);
    }

    public function setValueStatusOrderApp(Request $request, Order $order)
    {
        abort_unless($request->user()->canManageTeam(), 403, 'Somente administradores podem alterar o status do pedido.');
        $this->authorizeVisibleOrder($order);
        $validated = $request->validate(['status' => ['required', Rule::in(['1', '2', '3', '4'])]]);

        if ($validated['status'] === '4') {
            return $this->cancelOrderApp($order);
        }

        $order->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Status alterado com sucesso.',
        ]);
    }

    public function cancelOrderApp(Order $order)
    {
        abort_unless(request()->user()->canManageTeam(), 403, 'Somente administradores podem cancelar pedidos.');
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

    private function authorizeVisibleOrder(Order $order): void
    {
        abort_unless(Order::visibleTo()->whereKey($order->id)->exists(), 404);
    }
}
