<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CommercialCondition;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Pricing\RegionalPriceResolver;
use App\Support\FlexBalance;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OrderUpdateService
{
    public function __construct(private readonly RegionalPriceResolver $priceResolver) {}

    public function update(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ((string) $order->status === '4') {
                throw new RuntimeException('Pedidos cancelados não podem ser editados.');
            }

            $order->load('orderItems');
            foreach ($order->orderItems as $oldItem) {
                Product::query()->lockForUpdate()->find($oldItem->product_id)?->increment('quantity', $oldItem->quantity);
            }
            FlexBalance::release($order);

            $customer = Customer::visibleTo()->findOrFail($data['customer_id']);
            $campaign = $order->campaign_id
                ? Campaign::with(['products:id', 'commercialCondition'])->find($order->campaign_id)
                : null;
            if ($campaign?->audience_type === 'region' && $campaign->region_id !== $customer->region_id) {
                throw new RuntimeException('A campanha não é válida para a região deste cliente.');
            }
            $customerCondition = CommercialCondition::resolveForCustomer($customer);
            $condition = $campaign?->commercialCondition ?? $customerCondition;
            $campaignProductIds = $campaign?->products->modelKeys() ?? [];
            $subtotal = 0;
            $campaignQuantity = 0;
            $items = [];

            foreach ($data['items'] as $item) {
                $product = Product::query()->lockForUpdate()->findOrFail($item['product_id']);
                $quantity = (int) $item['quantity'];

                // Estoque insuficiente não bloqueia o pedido: o saldo fica negativo
                // e o pedido passa a valer como uma pré-venda até a reposição.
                $itemCondition = $campaign && in_array($product->id, $campaignProductIds, true)
                    ? $campaign->commercialCondition
                    : $customerCondition;
                // Um preço especial Produto x Região ativo substitui qualquer regra (cliente,
                // região, tipo de estabelecimento, global ou campanha) para aquele item.
                $price = $this->priceResolver->effectivePriceForSale($product, $customer->region, $itemCondition);
                $grossItemTotal = round($price * $quantity, 2);
                $itemAdjustment = array_key_exists('discount_amount', $item)
                    ? round((float) $item['discount_amount'], 2)
                    : -round($grossItemTotal * ((float) ($item['discount_percentage'] ?? 0) / 100), 2);
                if ($grossItemTotal + $itemAdjustment < 0) {
                    throw new RuntimeException('O desconto individual não pode superar o valor do item.');
                }
                $discountPercentage = array_key_exists('discount_amount', $item)
                    ? ($grossItemTotal > 0 ? round(($itemAdjustment / $grossItemTotal) * 100, 2) : 0)
                    : round((float) ($item['discount_percentage'] ?? 0), 2);
                $itemTotal = round($grossItemTotal + $itemAdjustment, 2);
                $subtotal += $itemTotal;
                if ($campaign && in_array($product->id, $campaignProductIds, true)) {
                    $campaignQuantity += $quantity;
                }
                $items[] = [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'discount_percentage' => $discountPercentage,
                    'discount_amount' => array_key_exists('discount_amount', $item) ? $itemAdjustment : abs($itemAdjustment),
                    'name' => $product->name,
                    'total' => $itemTotal,
                ];
                $product->decrement('quantity', $quantity);
            }

            $subtotal = round($subtotal, 2);
            $adjustedTotal = round((float) ($data['adjusted_total'] ?? $subtotal), 2);
            $manualDiscount = round((float) ($data['discount'] ?? 0), 2);

            if ($manualDiscount > $adjustedTotal) {
                throw new RuntimeException('O desconto não pode ser maior que o valor ajustado.');
            }

            $flex = max(round($adjustedTotal - $subtotal, 2), 0);
            $priceReduction = max(round($subtotal - $adjustedTotal, 2), 0);
            $discount = round($priceReduction + $manualDiscount, 2);
            $total = max(round($adjustedTotal - $manualDiscount, 2), 0);

            if ($condition) {
                $discountPercentage = $subtotal > 0 ? ($discount / $subtotal) * 100 : 0;
                if ($discountPercentage > $condition->maximumAdditionalDiscountPercentage()) {
                    throw new RuntimeException('Desconto acima do limite permitido para este cliente.');
                }
                if ($campaign && $campaignQuantity < (int) $condition->minimum_order_quantity) {
                    throw new RuntimeException('Quantidade mínima da campanha não atingida.');
                }
                if (! $campaign && $total < (float) $condition->minimum_order_amount) {
                    throw new RuntimeException('Pedido abaixo do valor mínimo da condição comercial.');
                }
            }

            $commissionPercentage = (float) ($condition?->commission_percentage ?? 0);
            $flexContext = FlexBalance::contextFor(auth()->user());
            $order->update([
                'customer_id' => $customer->id,
                ...(array_key_exists('user_id', $data) ? ['user_id' => $data['user_id']] : []),
                'commercial_condition_id' => $condition?->id,
                'subtotal' => $subtotal,
                'adjusted_total' => $adjustedTotal,
                'flex' => $flex,
                'uses_admin_flex' => $flexContext['is_admin_override'],
                'discount' => $discount,
                'total' => $total,
                'payment_condition' => $data['payment_condition'] ?? $condition?->payment_terms,
                'notes' => $data['notes'] ?? null,
                'commission_percentage' => $commissionPercentage,
                'commission_amount' => round($total * ($commissionPercentage / 100), 2),
                'is_recurring' => (bool) ($data['is_recurring'] ?? false),
                'next_delivery_at' => ($data['is_recurring'] ?? false) ? ($order->next_delivery_at ?? now()->addMonthNoOverflow()) : null,
            ]);
            $order->orderItems()->delete();
            $order->orderItems()->createMany($items);
            FlexBalance::commit($flexContext, $flex, $discount);

            return $order->load('customer.region', 'orderItems', 'commercialCondition');
        });
    }
}
