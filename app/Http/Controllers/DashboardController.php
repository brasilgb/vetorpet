<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Flex;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Services\SampleData\SampleDataService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    public function index(Request $request, SampleDataService $sampleDataService)
    {
        $start = now()->startOfMonth();
        $end = now()->endOfDay();
        $ordersQuery = Order::visibleTo()->whereBetween('created_at', [$start, $end]);
        $validOrders = (clone $ordersQuery)->where('status', '!=', '4');
        $ordersCount = (clone $validOrders)->count();
        $salesTotal = (float) (clone $validOrders)->sum('total');
        $campaignOrders = (clone $validOrders)->whereNotNull('campaign_id');
        $campaignSalesTotal = (float) (clone $campaignOrders)->sum('total');
        $topSeller = $this->sellerRanking($validOrders)->first();

        return Inertia::render('app/dashboard/index', [
            'summary' => [
                'sales_total' => $salesTotal,
                'orders_count' => $ordersCount,
                'average_ticket' => $ordersCount ? $salesTotal / $ordersCount : 0,
                'customers_count' => (clone $validOrders)->distinct('customer_id')->count('customer_id'),
                'pending_count' => (clone $ordersQuery)->whereIn('status', ['1', '2'])->count(),
                'canceled_count' => (clone $ordersQuery)->where('status', '4')->count(),
                'inactive_customers' => Customer::visibleTo()->whereDoesntHave('orders', fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(60)))->count(),
                'flex_balance' => (float) (Flex::first()?->value ?? 0),
                'top_seller' => $topSeller ? ['name' => $topSeller->user?->name ?? 'Sem vendedor', 'total' => (float) $topSeller->total] : null,
                'campaign_sales_total' => $campaignSalesTotal,
                'campaign_orders_count' => (clone $campaignOrders)->count(),
                'campaign_sales_share' => $salesTotal > 0 ? ($campaignSalesTotal / $salesTotal) * 100 : 0,
            ],
            'campaignSales' => (clone $campaignOrders)
                ->select('campaign_id', DB::raw('count(*) as orders_count'), DB::raw('sum(total) as total'))
                ->with('campaign:id,name')
                ->groupBy('campaign_id')
                ->orderByDesc('total')
                ->limit(5)
                ->get(),
            'recentOrders' => (clone $ordersQuery)->with('customer', 'user:id,name', 'campaign:id,name')->latest()->limit(8)->get(),
            'statusBreakdown' => $this->statusBreakdown($ordersQuery),
            'sampleData' => $this->sampleDataState($request, $sampleDataService),
        ]);
    }

    /**
     * Dados de exemplo da avaliação: só o responsável pela conta vê o aviso e
     * as ações de criar/remover.
     */
    private function sampleDataState(Request $request, SampleDataService $sampleDataService): ?array
    {
        $user = $request->user();

        if (! $user->tenant_id || ! $user->isOwner()) {
            return null;
        }

        $hasSampleData = $sampleDataService->hasSampleData($user->tenant);

        return [
            'active' => $hasSampleData,
            'canCreate' => ! $hasSampleData && $user->tenant->isOnTrial() && $sampleDataService->canSeed($user->tenant),
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $orders = $this->filteredOrders($filters)->with('customer.region', 'user')->latest()->get();

        return response()->streamDownload(function () use ($orders) {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Pedido', 'Cliente', 'Regiao', 'Vendedor', 'Status', 'Total', 'Emissao']);

            foreach ($orders as $order) {
                fputcsv($output, [
                    $order->order_number,
                    $order->customer?->name,
                    $order->customer?->region?->name,
                    $order->user?->name,
                    $order->status,
                    number_format((float) $order->total, 2, '.', ''),
                    $order->created_at?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($output);
        }, 'relatorio-vendas.csv', ['Content-Type' => 'text/csv']);
    }

    private function filters(Request $request): array
    {
        return [
            'start_date' => $request->date('start_date')?->toDateString() ?? now()->startOfMonth()->toDateString(),
            'end_date' => $request->date('end_date')?->toDateString() ?? now()->toDateString(),
            'user_id' => auth()->user()->canManageTeam() ? ($request->integer('user_id') ?: null) : auth()->id(),
            'region_id' => $request->integer('region_id') ?: null,
            'category' => $request->get('category') ?: null,
        ];
    }

    private function filteredOrders(array $filters): Builder
    {
        return Order::visibleTo()
            ->whereBetween('created_at', [
                Carbon::parse($filters['start_date'])->startOfDay(),
                Carbon::parse($filters['end_date'])->endOfDay(),
            ])
            ->when($filters['user_id'], fn (Builder $query) => $query->where('user_id', $filters['user_id']))
            ->when($filters['region_id'], fn (Builder $query) => $query->whereHas('customer', fn (Builder $query) => $query->where('region_id', $filters['region_id'])))
            ->when($filters['category'], fn (Builder $query) => $query->whereHas('orderItems.product', fn (Builder $query) => $query->where('category', $filters['category'])));
    }

    private function filterOptions(): array
    {
        return [
            'users' => auth()->user()->canManageTeam()
                ? User::where('tenant_id', auth()->user()->tenant_id)->where('status', true)->orderBy('name')->get(['id', 'name'])
                : collect([auth()->user()]),
            'regions' => auth()->user()->canManageTeam()
                ? Region::where('status', true)->orderBy('name')->get(['id', 'name'])
                : auth()->user()->regions()->where('status', true)->orderBy('name')->get(['regions.id', 'regions.name']),
            'categories' => Product::whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->values(),
        ];
    }

    private function statusBreakdown(Builder $ordersQuery)
    {
        return (clone $ordersQuery)
            ->select('status', DB::raw('count(*) as orders_count'), DB::raw('sum(total) as total'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();
    }

    private function sellerRanking(Builder $ordersQuery)
    {
        return (clone $ordersQuery)
            ->select('user_id', DB::raw('count(*) as orders_count'), DB::raw('sum(total) as total'), DB::raw('avg(total) as average_ticket'))
            ->with('user:id,name')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(8)
            ->get();
    }

    private function topProducts(Builder $ordersQuery)
    {
        $orderIds = (clone $ordersQuery)->pluck('id');

        return DB::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('order_items.order_id', $orderIds)
            ->select('products.id', 'products.name', 'products.category', 'products.brand', DB::raw('sum(order_items.quantity) as quantity'), DB::raw('sum(order_items.total) as total'))
            ->groupBy('products.id', 'products.name', 'products.category', 'products.brand')
            ->orderByDesc('total')
            ->limit(10)
            ->get();
    }

    private function salesByRegion(Builder $ordersQuery)
    {
        $orderIds = (clone $ordersQuery)->pluck('id');

        return DB::table('orders')
            ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
            ->leftJoin('regions', 'regions.id', '=', 'customers.region_id')
            ->whereIn('orders.id', $orderIds)
            ->select(DB::raw("coalesce(regions.name, 'Sem região') as label"), DB::raw('count(*) as orders_count'), DB::raw('sum(orders.total) as total'))
            ->groupBy('label')
            ->orderByDesc('total')
            ->get();
    }

    private function salesByProductField(Builder $ordersQuery, string $field)
    {
        $orderIds = (clone $ordersQuery)->pluck('id');

        return DB::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('order_items.order_id', $orderIds)
            ->select(DB::raw("coalesce(products.$field, 'Sem {$field}') as label"), DB::raw('sum(order_items.total) as total'), DB::raw('sum(order_items.quantity) as quantity'))
            ->groupBy('label')
            ->orderByDesc('total')
            ->limit(8)
            ->get();
    }
}
