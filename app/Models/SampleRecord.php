<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Marca um registro como dado fictício de demonstração de um tenant. Não usa
 * Tenantable de propósito: toda consulta informa o tenant explicitamente
 * (ver App\Services\SampleData\SampleDataService).
 */
class SampleRecord extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_REGION = 'region';

    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_PRODUCT = 'product';

    public const TYPE_ORDER = 'order';

    public const TYPE_VISIT = 'visit';

    protected $fillable = [
        'tenant_id',
        'record_type',
        'record_id',
    ];
}
