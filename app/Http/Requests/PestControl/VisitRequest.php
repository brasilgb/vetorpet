<?php

namespace App\Http\Requests\PestControl;

use App\Models\PestControl\Visit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;

        return [
            'establishment_id' => [
                'required',
                Rule::exists('pest_control_establishments', 'id')->where('tenant_id', $tenantId),
            ],
            // Técnico é opcional na agenda: o atendimento é direcionado
            // pessoalmente (qualquer técnico do tenant pode assumir a visita
            // pelo app), e o technician_id só é preenchido no check-in (ver
            // PestControlVisitService). Quando informado aqui mesmo assim,
            // precisa ser um técnico/operador cadastrado no módulo (ver
            // App\Models\PestControl\Technician).
            'technician_id' => [
                'nullable',
                Rule::exists('pest_control_technicians', 'user_id')->where('tenant_id', $tenantId),
            ],
            'scheduled_at' => ['required', 'date'],
            'service_type' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in([
                Visit::STATUS_SCHEDULED,
                Visit::STATUS_DRAFT,
            ])],
        ];
    }

    public function attributes(): array
    {
        return [
            'establishment_id' => 'estabelecimento',
            'technician_id' => 'técnico',
            'scheduled_at' => 'agendamento',
            'service_type' => 'tipo de serviço',
        ];
    }
}
