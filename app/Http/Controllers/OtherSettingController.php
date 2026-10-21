<?php

namespace App\Http\Controllers;

use App\Services\TenantModuleService;
use App\Support\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OtherSettingController extends Controller
{
    public function __construct(private readonly TenantModuleService $tenantModules) {}

    public function index(Request $request): Response
    {
        $tenant = $request->user()->tenant()->with(['planModel', 'billingPeriod'])->firstOrFail();
        $planLimits = PlanLimits::forTenant($tenant);

        return Inertia::render('app/other-settings/index', [
            'tenant' => $tenant,
            'blockedReason' => $planLimits->subscriptionBlockedReason(),
            'onTrial' => $tenant->isOnTrial(),
            'currentAddons' => $tenant->billingPeriod
                ? $this->tenantModules->activeAddonsFor($tenant, $tenant->billingPeriod->interval_count)
                : [],
        ]);
    }

    /**
     * Define o Flex Universal: um valor fictício que só o admin (dono da conta) enxerga
     * como "Flex disponível" ao lançar pedidos, sem afetar o saldo real da equipe.
     */
    public function updateAdminFlex(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403, 'Somente o administrador pode definir o Flex Universal.');

        $data = $request->validate([
            'admin_flex' => ['nullable', 'numeric', 'min:0'],
        ]);

        $request->user()->tenant()->update(['admin_flex' => $data['admin_flex'] ?? null]);

        return back()->with('success', 'Flex Universal atualizado com sucesso!');
    }
}
