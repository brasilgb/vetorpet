<?php

namespace App\Http\Controllers\Api\PestControl;

use App\Http\Controllers\Controller;
use App\Http\Requests\PestControl\VisitInspectionRequest;
use App\Models\PestControl\ControlPoint;
use App\Models\PestControl\Visit;
use App\Models\PestControl\VisitInspection;
use App\Services\PestControl\PestControlVisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Inspeção por ponto do app do técnico (Etapa 4 do app-tecnico.md). Reaproveita
 * VisitInspectionRequest e PestControlVisitService::recordInspection tal e
 * qual o painel web usa — inclusive o upsert por (visit_id, control_point_id),
 * que já torna reenviar a mesma inspeção idempotente. Foto ainda não entra
 * aqui: captura/compressão/fila de upload são a Etapa 5.
 *
 * Detecção de conflito (Etapa 7): o app manda `client_known_updated_at`, o
 * `updated_at` da inspeção que ele tinha quando começou a editar. Se já
 * existir uma inspeção no servidor mais nova que isso — outra origem
 * (painel web, outro aparelho) mexeu no meio do caminho —, a gravação é
 * recusada com 409 em vez de aplicar "última gravação vence" silenciosamente.
 */
class VisitInspectionController extends Controller
{
    public function __construct(private readonly PestControlVisitService $visitService) {}

    public function store(VisitInspectionRequest $request, Visit $visit, ControlPoint $point): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isPestControlTechnician(), 404);
        abort_unless($point->establishment_id === $visit->establishment_id, 404);

        $existing = VisitInspection::where('visit_id', $visit->id)->where('control_point_id', $point->id)->first();
        $clientKnownUpdatedAt = $request->validated('client_known_updated_at');

        if ($existing && (! $clientKnownUpdatedAt || ! $existing->updated_at->equalTo(Carbon::parse($clientKnownUpdatedAt)))) {
            return response()->json([
                'conflict' => true,
                'message' => 'Este ponto foi alterado por outra origem desde a última sincronização.',
                'server_inspection' => $existing->fresh('speciesFound'),
            ], 409);
        }

        $inspection = $this->visitService->recordInspection($visit, $point, $request->validated(), $user);

        return response()->json(['inspection' => $inspection, 'conflict' => false]);
    }
}
