<?php

namespace App\Models\PestControl;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ControlPoint extends Model
{
    use Tenantable;

    protected $table = 'pest_control_control_points';

    protected $fillable = [
        'tenant_id',
        'establishment_id',
        'code',
        'label',
        'category_key',
        'default_product_id',
        'latitude',
        'longitude',
        'photo_path',
        'instructions',
        'display_order',
        'required',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'display_order' => 'integer',
            'required' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function defaultProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'default_product_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(VisitInspection::class);
    }
}
