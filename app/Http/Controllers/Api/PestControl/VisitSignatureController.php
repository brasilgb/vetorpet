<?php

namespace App\Http\Controllers\Api\PestControl;

use App\Http\Controllers\Controller;
use App\Http\Requests\PestControl\VisitSignatureRequest;
use App\Models\PestControl\Visit;
use App\Services\PestControl\PestControlVisitService;
use Illuminate\Http\JsonResponse;

/**
 * Assinatura e aceite do app do técnico (Etapa 6 do app-tecnico.md).
 * Reaproveita PestControlVisitService::sign tal e qual o painel web: nova
 * versão sempre que assina de novo, supersedendo a anterior — nenhuma
 * alteração silenciosa. Funciona sem internet como qualquer outro endpoint
 * daqui: o app salva local antes de tentar enviar (ver lib/pest-control/signature.ts).
 */
class VisitSignatureController extends Controller
{
    public function __construct(private readonly PestControlVisitService $visitService) {}

    public function store(VisitSignatureRequest $request, Visit $visit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isPestControlTechnician(), 404);

        $signature = $this->visitService->sign($visit, $request->validated(), $user);

        return response()->json(['signature' => $signature], 201);
    }
}
