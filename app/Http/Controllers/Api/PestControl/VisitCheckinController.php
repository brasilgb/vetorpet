<?php

namespace App\Http\Controllers\Api\PestControl;

use App\Http\Controllers\Controller;
use App\Http\Requests\PestControl\VisitCheckinRequest;
use App\Models\PestControl\Visit;
use App\Services\PestControl\PestControlVisitService;
use Illuminate\Http\JsonResponse;

/**
 * Check-in do app do técnico (Etapa 3 do app-tecnico.md). Reaproveita
 * integralmente PestControlVisitService::checkIn — a mesma regra de
 * distância/raio do painel web, propositalmente escrita para servir "tal e
 * qual" as APIs móveis (ver o docblock do serviço).
 */
class VisitCheckinController extends Controller
{
    public function __construct(private readonly PestControlVisitService $visitService) {}

    public function store(VisitCheckinRequest $request, Visit $visit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isPestControlTechnician(), 404);
        abort_if(
            in_array($visit->status, [Visit::STATUS_COMPLETED, Visit::STATUS_SYNCED, Visit::STATUS_VALIDATED, Visit::STATUS_CANCELED], true),
            409,
            'Esta visita não pode receber check-in.',
        );

        $visit = $this->visitService->checkIn($visit, $request->validated(), $user);

        return response()->json(['visit' => $visit->loadMissing('establishment:id,name,checkin_radius_meters')]);
    }
}
