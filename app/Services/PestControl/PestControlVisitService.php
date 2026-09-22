<?php

namespace App\Services\PestControl;

use App\Models\PestControl\ControlPoint;
use App\Models\PestControl\InspectionSpecies;
use App\Models\PestControl\Visit;
use App\Models\PestControl\VisitInspection;
use App\Models\PestControl\VisitSignature;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Regras de negócio das visitas técnicas: check-in/check-out com validação
 * de localização, inspeção estruturada por ponto e assinatura versionada.
 * Isolado dos controllers para poder ser reaproveitado tal e qual pelas
 * APIs móveis da Etapa 5.
 */
class PestControlVisitService
{
    public function __construct(private readonly PestControlAuditLogger $auditLogger) {}

    public function checkIn(Visit $visit, array $data, User $user): Visit
    {
        $this->assertNotCanceled($visit);
        $visit->loadMissing('establishment');
        $distance = $this->distanceFromEstablishment($visit, $data['latitude'] ?? null, $data['longitude'] ?? null);
        $outOfRange = $this->isOutOfRange($visit, $distance);

        $visit->fill([
            // Atendimento pessoal: a visita pode não ter técnico pré-atribuído
            // (technician_id nulo), e quem faz o check-in é quem assume o
            // atendimento. Se já havia um técnico definido, mantém.
            'technician_id' => $visit->technician_id ?? $user->id,
            'checkin_at' => $this->parseDeviceTime($data['device_time'] ?? null),
            'checkin_received_at' => now(),
            'checkin_latitude' => $data['latitude'] ?? null,
            'checkin_longitude' => $data['longitude'] ?? null,
            'checkin_accuracy_meters' => $data['accuracy_meters'] ?? null,
            'checkin_distance_meters' => $distance,
            'checkin_justification' => $data['justification'] ?? null,
            'device_id' => $data['device_id'] ?? $visit->device_id,
            'app_version' => $data['app_version'] ?? $visit->app_version,
            'offline_capture' => $data['offline_capture'] ?? $visit->offline_capture,
            'status' => Visit::STATUS_IN_PROGRESS,
        ])->save();

        $this->auditLogger->log($visit->tenant, $user, 'visit.checkin', $visit, [
            'distance_meters' => $distance,
            'out_of_range' => $outOfRange,
        ]);

        // A distância fora do raio não bloqueia o check-in: só gera uma
        // ocorrência de auditoria específica, com ou sem justificativa.
        if ($outOfRange) {
            $this->auditLogger->log($visit->tenant, $user, 'visit.checkin.out_of_range', $visit, [
                'distance_meters' => $distance,
                'radius_meters' => $visit->establishment->checkin_radius_meters,
                'justification' => $data['justification'] ?? null,
            ]);
        }

        return $visit->fresh();
    }

    public function checkOut(Visit $visit, array $data, User $user): Visit
    {
        $this->assertNotCanceled($visit);
        abort_unless($visit->isCheckedIn(), 422, 'A visita ainda não teve check-in registrado.');

        $checkoutAt = $this->parseDeviceTime($data['device_time'] ?? null);
        $visit->fill([
            'checkout_at' => $checkoutAt,
            'checkout_received_at' => now(),
            'checkout_latitude' => $data['latitude'] ?? null,
            'checkout_longitude' => $data['longitude'] ?? null,
            'checkout_accuracy_meters' => $data['accuracy_meters'] ?? null,
            'duration_seconds' => $visit->checkin_at ? (int) $checkoutAt->diffInSeconds($visit->checkin_at, absolute: true) : null,
            'summary' => $data['summary'] ?? $visit->summary,
            'status' => Visit::STATUS_COMPLETED,
        ])->save();

        $this->auditLogger->log($visit->tenant, $user, 'visit.checkout', $visit, [
            'duration_seconds' => $visit->duration_seconds,
        ]);

        return $visit->fresh();
    }

    public function recordInspection(Visit $visit, ControlPoint $point, array $data, User $user): VisitInspection
    {
        $this->assertNotCanceled($visit);

        return DB::transaction(function () use ($visit, $point, $data, $user) {
            $inspection = VisitInspection::updateOrCreate(
                ['visit_id' => $visit->id, 'control_point_id' => $point->id],
                [
                    'tenant_id' => $visit->tenant_id,
                    'technician_id' => $data['technician_id'] ?? $visit->technician_id,
                    'inspected_at' => $data['inspected_at'] ?? now(),
                    'product_id' => $data['product_id'] ?? null,
                    'consumption_type' => $data['consumption_type'] ?? null,
                    'consumption_code' => $data['consumption_code'] ?? null,
                    'replaced' => $data['replaced'] ?? false,
                    'device_condition' => $data['device_condition'] ?? null,
                    'live_count' => $data['live_count'] ?? 0,
                    'dead_count' => $data['dead_count'] ?? 0,
                    'notes' => $data['notes'] ?? null,
                    'photo_path' => $data['photo_path'] ?? null,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'not_inspected' => $data['not_inspected'] ?? false,
                    'not_inspected_reason' => $data['not_inspected_reason'] ?? null,
                ],
            );

            if (array_key_exists('species', $data)) {
                $inspection->speciesFound()->delete();
                foreach ($data['species'] as $species) {
                    InspectionSpecies::create([
                        'inspection_id' => $inspection->id,
                        'species_id' => $species['species_id'],
                        'live_count' => $species['live_count'] ?? 0,
                        'dead_count' => $species['dead_count'] ?? 0,
                    ]);
                }
            }

            $this->auditLogger->log($visit->tenant, $user, 'visit.inspection.recorded', $inspection, $data);

            return $inspection->fresh('speciesFound');
        });
    }

    public function sign(Visit $visit, array $data, User $user): VisitSignature
    {
        $this->assertNotCanceled($visit);

        return DB::transaction(function () use ($visit, $data, $user) {
            $visit->signatures()->where('superseded', false)->update(['superseded' => true]);
            $nextVersion = (int) $visit->signatures()->max('version') + 1;

            $signature = VisitSignature::create([
                'tenant_id' => $visit->tenant_id,
                'visit_id' => $visit->id,
                'version' => $nextVersion,
                'responsible_name' => $data['responsible_name'],
                'responsible_role' => $data['responsible_role'] ?? null,
                'responsible_document' => $data['responsible_document'] ?? null,
                'signature_path' => $this->storeSignatureImage($visit, $data['signature']),
                'compliance_text' => $data['compliance_text'] ?? null,
                'notes' => $data['notes'] ?? null,
                'signed_at' => now(),
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'content_hash' => $this->signatureContentHash($visit, $nextVersion),
                'superseded' => false,
                'captured_by_id' => $user->id,
            ]);

            $this->auditLogger->log($visit->tenant, $user, 'visit.signed', $signature, [
                'version' => $nextVersion,
                'responsible_name' => $signature->responsible_name,
            ]);

            return $signature;
        });
    }

    public function approve(Visit $visit, User $user): Visit
    {
        abort_unless(in_array($visit->status, [Visit::STATUS_COMPLETED, Visit::STATUS_SYNCED], true), 422, 'Somente visitas concluídas podem ser aprovadas.');

        $visit->update([
            'status' => Visit::STATUS_VALIDATED,
            'approved_by_id' => $user->id,
            'approved_at' => now(),
        ]);

        $this->auditLogger->log($visit->tenant, $user, 'visit.approved', $visit);

        return $visit->fresh();
    }

    public function cancel(Visit $visit, User $user, ?string $reason): Visit
    {
        $visit->update([
            'status' => Visit::STATUS_CANCELED,
            'canceled_reason' => $reason,
        ]);

        $this->auditLogger->log($visit->tenant, $user, 'visit.canceled', $visit, ['reason' => $reason]);

        return $visit->fresh();
    }

    /**
     * Visita cancelada é estado final: nenhuma escrita (check-in, check-out,
     * inspeção, assinatura) pode seguir depois disso, seja pelo painel web
     * ou pelo app do técnico — os dois passam por aqui.
     */
    private function assertNotCanceled(Visit $visit): void
    {
        abort_if($visit->status === Visit::STATUS_CANCELED, 409, 'Esta visita foi cancelada e não pode mais ser alterada.');
    }

    private function parseDeviceTime(?string $deviceTime): Carbon
    {
        return $deviceTime ? Carbon::parse($deviceTime) : now();
    }

    private function distanceFromEstablishment(Visit $visit, ?float $lat, ?float $lng): ?float
    {
        $establishment = $visit->establishment;

        if ($lat === null || $lng === null || $establishment?->latitude === null || $establishment?->longitude === null) {
            return null;
        }

        return round(PestControlGeo::distanceMeters(
            (float) $establishment->latitude,
            (float) $establishment->longitude,
            $lat,
            $lng,
        ), 2);
    }

    private function isOutOfRange(Visit $visit, ?float $distance): bool
    {
        $radius = $visit->establishment?->checkin_radius_meters;

        return $distance !== null && $radius !== null && $distance > $radius;
    }

    /**
     * Aceita tanto um data URI (assinatura capturada em canvas, formato mais
     * comum do painel/app) quanto um caminho já salvo em disco.
     */
    private function storeSignatureImage(Visit $visit, string $signature): string
    {
        if (! str_starts_with($signature, 'data:image')) {
            return $signature;
        }

        [$meta, $content] = explode(',', $signature, 2);
        $extension = str_contains($meta, 'png') ? 'png' : 'jpg';
        $path = 'pest-control/signatures/'.$visit->uuid.'-'.Str::random(8).'.'.$extension;

        Storage::disk('public')->put($path, base64_decode($content));

        return $path;
    }

    private function signatureContentHash(Visit $visit, int $version): string
    {
        $snapshot = [
            'visit_uuid' => $visit->uuid,
            'version' => $version,
            'establishment_id' => $visit->establishment_id,
            'technician_id' => $visit->technician_id,
            'checkin_at' => optional($visit->checkin_at)->toIso8601String(),
            'checkout_at' => optional($visit->checkout_at)->toIso8601String(),
            'inspections' => $visit->inspections()->pluck('id')->all(),
            'signed_at' => now()->toIso8601String(),
        ];

        return hash('sha256', json_encode($snapshot));
    }
}
