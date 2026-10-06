<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\PestControl\Technician as PestControlTechnician;
use App\Traits\Tenantable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    public const ROLE_ROOT = 99;

    public const ROLE_OWNER = 1;

    public const ROLE_SELLER = 2;

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, Tenantable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'avatar',
        'email',
        'telephone',
        'whatsapp',
        'password',
        'roles',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function tenantFeedbackEntries(): HasMany
    {
        return $this->hasMany(TenantFeedbackEntry::class);
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class)->withTimestamps();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Marcação do módulo Controle de Pragas (ver App\Models\PestControl\Technician):
     * só existe quando o usuário foi cadastrado como técnico/operador de campo.
     */
    public function pestControlTechnician(): HasOne
    {
        return $this->hasOne(PestControlTechnician::class);
    }

    /**
     * Técnico/operador de campo do Controle de Pragas: acesso apenas pelo
     * aplicativo, nunca pelo painel web (ver LoginRequest::authenticate).
     */
    public function isPestControlTechnician(): bool
    {
        return $this->pestControlTechnician()->exists();
    }

    /**
     * Administrador do sistema: exige o papel root explícito. Usuário sem
     * tenant que não seja root não ganha nenhum privilégio (nem acesso ao
     * painel /admin, nem leitura sem filtro de tenant).
     */
    public function isSuperAdmin(): bool
    {
        return $this->tenant_id === null && (int) $this->roles === self::ROLE_ROOT;
    }

    /**
     * Usuário sem tenant e sem papel root: cadastro inconsistente, que não
     * deve acessar nada.
     */
    public function isOrphan(): bool
    {
        return $this->tenant_id === null && ! $this->isSuperAdmin();
    }

    public function isOwner(): bool
    {
        return (int) $this->roles === self::ROLE_OWNER;
    }

    public function isSeller(): bool
    {
        return (int) $this->roles === self::ROLE_SELLER;
    }

    public function canManageTeam(): bool
    {
        return $this->isSuperAdmin() || ($this->tenant_id !== null && $this->isOwner());
    }

    public function canManageSellers(): bool
    {
        if (! $this->tenant_id || ! $this->canManageTeam()) {
            return false;
        }

        return $this->tenant?->planModel?->account_type === Tenant::PLAN_TEAM;
    }

    public function canManageCatalog(): bool
    {
        if ($this->isOrphan()) {
            return false;
        }

        if ($this->isSuperAdmin() || $this->isOwner()) {
            return true;
        }

        $accountType = $this->tenant?->planModel?->account_type
            ?? $this->tenant?->plan_type;

        return $this->isSeller() && $accountType === Tenant::PLAN_INDIVIDUAL;
    }

    protected function avatar(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? asset('storage/'.$value) : null,
        );
    }
}
