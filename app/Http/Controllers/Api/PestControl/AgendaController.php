<?php

namespace App\Http\Controllers\Api\PestControl;

use App\Http\Controllers\Controller;
use App\Models\PestControl\Lookup;
use App\Models\PestControl\PestSpecies;
use App\Models\PestControl\Product;
use App\Models\PestControl\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agenda e detalhe da visita para o aplicativo do técnico (Etapa 2 do
 * app-tecnico.md). Atendimento é direcionado pessoalmente: a visita não é
 * pré-atribuída a um técnico específico, então todo técnico do tenant vê a
 * agenda inteira (o isolamento por tenant continua garantido pela trait
 * Tenantable nas queries) e pode assumir qualquer visita pelo app.
 *
 * Ainda é só leitura (download para uso offline). Check-in, inspeções,
 * fotos e assinatura chegam nas etapas seguintes (3-6).
 */
class AgendaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isPestControlTechnician(), 403);

        $from = $request->date('from') ?? now()->startOfDay();
        $to = $request->date('to');

        $visits = Visit::with('establishment:id,name,street,number,district,city,state,zip_code,latitude,longitude,checkin_radius_meters')
            ->where('scheduled_at', '>=', $from)
            ->when($to, fn ($query) => $query->where('scheduled_at', '<=', $to->endOfDay()))
            ->orderBy('scheduled_at')
            ->paginate(20);

        $visits->getCollection()->transform(fn (Visit $visit) => $this->summarize($visit));

        return response()->json($visits);
    }

    public function show(Request $request, Visit $visit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isPestControlTechnician(), 404);

        $visit->load([
            'establishment.controlPoints' => fn ($query) => $query->where('active', true)->orderBy('display_order'),
            'inspections.speciesFound',
            'inspections.media',
            'media' => fn ($query) => $query->whereNull('inspection_id'),
            'signatures' => fn ($query) => $query->where('superseded', false),
        ]);

        return response()->json([
            'visit' => $visit,
            'products' => Product::where('active', true)->orderBy('name')->get(['id', 'name', 'default_consumption_type', 'unit']),
            'species' => PestSpecies::where('active', true)->orderBy('name')->get(['id', 'name', 'category_key']),
            'consumption_types' => Lookup::where('group', Lookup::GROUP_CONSUMPTION_TYPE)->where('active', true)->orderBy('order')->get(['key', 'name']),
            'point_categories' => Lookup::where('group', Lookup::GROUP_POINT_CATEGORY)->where('active', true)->orderBy('order')->get(['key', 'name']),
            'device_conditions' => config('pest_control.default_device_conditions', []),
        ]);
    }

    private function summarize(Visit $visit): array
    {
        return [
            'id' => $visit->id,
            'uuid' => $visit->uuid,
            'scheduled_at' => $visit->scheduled_at,
            'service_type' => $visit->service_type,
            'status' => $visit->status,
            'checkin_at' => $visit->checkin_at,
            'checkout_at' => $visit->checkout_at,
            'establishment' => $visit->establishment,
        ];
    }
}
