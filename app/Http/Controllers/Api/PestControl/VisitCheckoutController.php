<?php

namespace App\Http\Controllers\Api\PestControl;

use App\Http\Controllers\Controller;
use App\Http\Requests\PestControl\VisitCheckoutRequest;
use App\Models\PestControl\Visit;
use App\Services\PestControl\PestControlVisitService;
use Illuminate\Http\JsonResponse;

/**
 * Check-out do app do técnico (Etapa 6 do app-tecnico.md). Reaproveita
 * PestControlVisitService::checkOut tal e qual o painel web — inclusive a
 * regra de que só é possível encerrar uma visita já com check-in feito.
 * A checagem de "pontos obrigatórios pendentes" é feita no app (os dados já
 * estão todos baixados no aparelho) e, quando o técnico segue mesmo assim,
 * a justificativa entra no próprio campo `summary` — não há necessidade de
 * uma coluna nova só para isso.
 */
class VisitCheckoutController extends Controller
{
    public function __construct(private readonly PestControlVisitService $visitService) {}

    public function store(VisitCheckoutRequest $request, Visit $visit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isPestControlTechnician(), 404);

        $visit = $this->visitService->checkOut($visit, $request->validated(), $user);

        return response()->json(['visit' => $visit]);
    }
}
