<?php

namespace App\Http\Controllers\Api\PestControl;

use App\Http\Controllers\Controller;
use App\Http\Requests\PestControl\VisitMediaUploadRequest;
use App\Models\PestControl\Visit;
use App\Models\PestControl\VisitInspection;
use App\Models\PestControl\VisitMedia;
use App\Services\PestControl\PestControlAuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * Upload de evidências do app do técnico (Etapa 5 do app-tecnico.md).
 * Idempotente por uuid gerado no aparelho: reenviar a mesma foto (ex.: app
 * não recebeu a resposta e tentou de novo) nunca duplica o arquivo no
 * servidor. Evidência de um ponto específico exige que a inspeção do ponto
 * já tenha sido sincronizada antes — mesma ordem do FUNCIONAMENTO OFFLINE
 * (inspeções antes de fotos), documentada aqui para não surpreender o app.
 */
class VisitMediaController extends Controller
{
    public function __construct(private readonly PestControlAuditLogger $auditLogger) {}

    public function store(VisitMediaUploadRequest $request, Visit $visit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isPestControlTechnician(), 404);
        abort_if($visit->status === Visit::STATUS_CANCELED, 409, 'Esta visita foi cancelada e não pode mais ser alterada.');

        $existing = VisitMedia::where('uuid', $request->validated('uuid'))->first();
        if ($existing) {
            abort_unless($existing->visit_id === $visit->id, 409, 'Este identificador de evidência já pertence a outra visita.');

            return response()->json(['media' => $existing], 200);
        }

        $inspectionId = null;
        if ($pointId = $request->validated('point_id')) {
            $inspection = VisitInspection::where('visit_id', $visit->id)->where('control_point_id', $pointId)->first();
            abort_if($inspection === null, 409, 'A inspeção deste ponto ainda não foi sincronizada.');
            $inspectionId = $inspection->id;
        }

        $file = $request->file('file');
        $media = VisitMedia::create([
            'tenant_id' => $visit->tenant_id,
            'uuid' => $request->validated('uuid'),
            'visit_id' => $visit->id,
            'inspection_id' => $inspectionId,
            'type' => VisitMedia::TYPE_PHOTO,
            'category' => $request->validated('category'),
            'path' => $file->store('pest-control/visits', 'public'),
            'content_hash' => $request->validated('content_hash'),
            'caption' => $request->validated('caption'),
            'taken_at' => $request->validated('taken_at') ?? now(),
            'latitude' => $request->validated('latitude'),
            'longitude' => $request->validated('longitude'),
            'uploaded_by_id' => $user->id,
        ]);

        $this->auditLogger->log($visit->tenant, $user, 'visit.media.uploaded', $media);

        return response()->json(['media' => $media], 201);
    }
}
