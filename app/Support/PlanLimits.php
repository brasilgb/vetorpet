<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\SampleRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PlanLimits
{
    public const GRACE_DAYS = 3;

    public function __construct(private readonly Tenant $tenant)
    {
        $this->tenant->loadMissing('planModel');
    }

    public static function forTenant(?Tenant $tenant = null): self
    {
        $tenant ??= auth()->user()?->tenant;

        return new self($tenant);
    }

    public function plan()
    {
        return $this->tenant->planModel;
    }

    public function usage(): array
    {
        return [
            'users' => User::where('tenant_id', $this->tenant->id)->count(),
            'customers' => $this->withoutSampleRecords(Customer::withoutGlobalScopes(), SampleRecord::TYPE_CUSTOMER)
                ->where('tenant_id', $this->tenant->id)->count(),
            'products' => $this->withoutSampleRecords(Product::withoutGlobalScopes(), SampleRecord::TYPE_PRODUCT)
                ->where('tenant_id', $this->tenant->id)->count(),
            'orders_month' => $this->withoutSampleRecords(Order::withoutGlobalScopes(), SampleRecord::TYPE_ORDER)
                ->where('tenant_id', $this->tenant->id)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
            'visits_month' => $this->withoutSampleRecords(Visit::withoutGlobalScopes(), SampleRecord::TYPE_VISIT)
                ->where('tenant_id', $this->tenant->id)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
        ];
    }

    /**
     * Dados de exemplo (trial) não consomem os limites comerciais do plano.
     */
    private function withoutSampleRecords(Builder $query, string $type): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereNotExists(fn ($sample) => $sample->from('sample_records')
            ->where('sample_records.record_type', $type)
            ->whereColumn('sample_records.tenant_id', "{$table}.tenant_id")
            ->whereColumn('sample_records.record_id', "{$table}.id"));
    }

    public function limits(): array
    {
        $plan = $this->plan();

        return [
            'users' => $plan?->max_users,
            'customers' => $plan?->max_customers,
            'products' => $plan?->max_products,
            'orders_month' => $plan?->max_orders_per_month,
            'visits_month' => $plan?->max_visits_per_month,
        ];
    }

    public function ensureCanCreate(string $resource): void
    {
        $limit = $this->limits()[$resource] ?? null;

        if ($limit === null) {
            return;
        }

        if (($this->usage()[$resource] ?? 0) >= $limit) {
            abort(403, 'Limite do plano atingido para este recurso.');
        }
    }

    public function hasFeature(string $feature): bool
    {
        $features = $this->plan()?->features ?? [];

        return in_array($feature, $features, true);
    }

    public function subscriptionBlockedReason(): ?string
    {
        if ($this->tenant->isOnTrial()) {
            return null;
        }

        if ($this->tenant->trial_ends_at && ! $this->tenant->payment) {
            if ($this->isInGracePeriod()) {
                return null;
            }

            return 'Período de teste expirado';
        }

        if (! $this->tenant->payment) {
            if ($this->isInGracePeriod()) {
                return null;
            }

            return 'Pagamento pendente';
        }

        if ($this->tenant->expiration_date && Carbon::parse($this->tenant->expiration_date)->isPast()) {
            if ($this->isInGracePeriod()) {
                return null;
            }

            return 'Assinatura expirada';
        }

        if ((int) $this->tenant->status !== 1) {
            return 'Assinatura inativa';
        }

        return null;
    }

    public function graceEndsAt(): ?Carbon
    {
        $endsAt = $this->tenant->payment
            ? $this->tenant->expiration_date
            : ($this->tenant->trial_ends_at ?? $this->tenant->expiration_date);

        if (! $endsAt) {
            return null;
        }

        $endsAt = Carbon::parse($endsAt);
        if ($this->tenant->expiration_date && $endsAt->isSameDay(Carbon::parse($this->tenant->expiration_date))) {
            $endsAt = $endsAt->endOfDay();
        }

        return $endsAt->addDays(self::GRACE_DAYS);
    }

    public function isInGracePeriod(): bool
    {
        $licenseEndsAt = $this->tenant->payment
            ? $this->tenant->expiration_date
            : ($this->tenant->trial_ends_at ?? $this->tenant->expiration_date);
        $graceEndsAt = $this->graceEndsAt();

        return $licenseEndsAt !== null
            && Carbon::parse($licenseEndsAt)->isPast()
            && $graceEndsAt?->isFuture();
    }

    public function graceDaysRemaining(): int
    {
        if (! $this->isInGracePeriod()) {
            return 0;
        }

        return max(1, (int) ceil(now()->diffInDays($this->graceEndsAt(), false)));
    }

    public function featureLabel(string $feature): string
    {
        return Str::of($feature)->replace('_', ' ')->headline()->toString();
    }
}
