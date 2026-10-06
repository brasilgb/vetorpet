<?php

namespace App\Http\Controllers;

use App\Services\SampleData\SampleDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Criação/remoção dos dados de exemplo da avaliação. Apenas o responsável
 * pela conta (owner) pode acionar; o tenant vem sempre do usuário autenticado.
 */
class SampleDataController extends Controller
{
    public function __construct(private readonly SampleDataService $sampleData) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->tenant_id && $user->isOwner(), 403);

        try {
            $this->sampleData->seed($user->tenant, $user);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Dados de exemplo criados. Eles podem ser removidos a qualquer momento.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->tenant_id && $user->isOwner(), 403);

        $result = $this->sampleData->purge($user->tenant);
        $kept = array_sum($result['kept']);

        return back()->with('success', $kept > 0
            ? "Dados de exemplo removidos. {$kept} registro(s) de exemplo foram mantidos porque estão em uso por dados reais."
            : 'Dados de exemplo removidos.');
    }
}
