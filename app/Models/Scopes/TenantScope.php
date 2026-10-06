<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (auth()->hasUser()) {
            // Usuário sem tenant que não é root não enxerga registro algum:
            // nunca cai no filtro "tenant_id IS NULL" do administrador.
            if (auth()->user()->isOrphan()) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where('tenant_id', auth()->user()->tenant_id);
        } elseif (checkTenantId()) {
            $builder->where('tenant_id', session('tenant_id'));
        }
    }
}
