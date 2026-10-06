<?php

namespace App\Http\Controllers;

use App\Models\Admin\Plan;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\MercadoPagoService;
use App\Services\TenantModuleService;
use App\Support\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly MercadoPagoService $mercadoPagoService,
        private readonly TenantModuleService $tenantModules,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $request->user()->tenant()->with([
            'planModel.periods' => fn ($query) => $query->whereIn('interval_count', [1, 6, 12]),
            'billingPeriod',
        ])->firstOrFail();

        $pendingPayments = Payment::where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->latest()
            ->get();

        foreach ($pendingPayments as $pendingPayment) {
            $this->mercadoPagoService->syncPaymentStatus($pendingPayment);
        }

        if ($pendingPayments->isNotEmpty()) {
            $tenant->refresh();
        }

        $planLimits = PlanLimits::forTenant($tenant);
        $blockedReason = $planLimits->subscriptionBlockedReason();
        $inGracePeriod = $planLimits->isInGracePeriod();
        $accountType = $tenant->plan_type
            ?: $tenant->planModel?->account_type
            ?: Tenant::PLAN_INDIVIDUAL;

        return Inertia::render('app/subscription/index', [
            'tenant' => $tenant,
            'plans' => ($blockedReason || $inGracePeriod)
                ? Plan::with(['periods' => fn ($query) => $query->whereIn('interval_count', [1, 6, 12])])
                    ->where('is_public', true)
                    ->where('account_type', $accountType)
                    ->orderBy('price')
                    ->get()
                : [],
            'accountType' => $accountType,
            'blockedReason' => $blockedReason,
            'onTrial' => $tenant->isOnTrial(),
            'inGracePeriod' => $inGracePeriod,
            'graceDaysRemaining' => $planLimits->graceDaysRemaining(),
            // Adicionais ativos por ciclo (1, 6 e 12 meses), para o card do plano
            // exibir o mesmo total que será cobrado no Pix.
            'addonsByInterval' => collect([1, 6, 12])
                ->mapWithKeys(fn (int $months) => [$months => $this->tenantModules->activeAddonsFor($tenant, $months)])
                ->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageTeam(), 403);

        $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'period_id' => ['required', 'exists:periods,id'],
        ]);

        return redirect()->route('app.subscription.index')
            ->with('error', 'A alteração do plano será concluída após a confirmação do pagamento Pix.');
    }
}
