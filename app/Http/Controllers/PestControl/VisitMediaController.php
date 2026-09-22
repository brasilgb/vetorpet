<?php

namespace App\Http\Controllers\PestControl;

use App\Http\Controllers\Controller;
use App\Models\PestControl\Visit;
use App\Models\PestControl\VisitMedia;
use App\Services\PestControl\PestControlAuditLogger;
use App\Services\PestControl\PestControlPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Evidências (fotos/anexos) soltas da visita, não amarradas a um ponto de
 * controle específico (ex.: foto geral do local, nota fiscal do produto).
 */
class VisitMediaController extends Controller
{
    public function __construct(
        private readonly PestControlPermissions $permissions,
        private readonly PestControlAuditLogger $auditLogger,
    ) {}

    public function store(Request $request, Visit $visit): RedirectResponse
    {
        abort_unless($this->permissions->has($request->user(), 'pest_control.visits.edit'), 403);
        abort_if($visit->status === Visit::STATUS_CANCELED, 409, 'Esta visita foi cancelada e não pode mais ser alterada.');

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,pdf', 'max:5120'],
            'caption' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $file = $request->file('file');
        $media = VisitMedia::create([
            'tenant_id' => $visit->tenant_id,
            'visit_id' => $visit->id,
            'type' => $file->extension() === 'pdf' ? VisitMedia::TYPE_ATTACHMENT : VisitMedia::TYPE_PHOTO,
            'path' => $file->store('pest-control/visits', 'public'),
            'caption' => $data['caption'] ?? null,
            'taken_at' => now(),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'uploaded_by_id' => $request->user()->id,
        ]);

        $this->auditLogger->log($visit->tenant, $request->user(), 'visit.media.uploaded', $media);

        return back()->with('success', 'Evidência anexada com sucesso!');
    }

    public function destroy(Request $request, Visit $visit, VisitMedia $media): RedirectResponse
    {
        abort_unless($this->permissions->has($request->user(), 'pest_control.visits.edit'), 403);
        abort_unless($media->visit_id === $visit->id, 404);

        Storage::disk('public')->delete($media->path);
        $this->auditLogger->log($visit->tenant, $request->user(), 'visit.media.deleted', $media);
        $media->delete();

        return back()->with('success', 'Evidência removida com sucesso!');
    }
}
