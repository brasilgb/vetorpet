<?php

namespace App\Services;

use App\Models\Admin\Period;
use App\Models\Admin\Plan;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoService
{
    public function __construct()
    {
        MercadoPagoConfig::setAccessToken((string) config('services.mercadopago.access_token'));
    }

    public function createPixPayment(array $request, string $idempotencyKey): mixed
    {
        $options = new RequestOptions;
        $options->setCustomHeaders(['x-idempotency-key' => $idempotencyKey]);

        return (new PaymentClient)->create($request, $options);
    }

    public function getPayment(string|int $paymentId): mixed
    {
        return (new PaymentClient)->get($paymentId);
    }

    public function syncPaymentStatus(Payment $payment, mixed $gatewayPayment = null): Payment
    {
        if ($payment->approved_at !== null) {
            return $payment;
        }

        try {
            $gatewayPayment ??= $this->getPayment($payment->payment_id);
        } catch (\Throwable $exception) {
            Log::warning('Não foi possível obter dados do pagamento no Mercado Pago.', [
                'payment_id' => $payment->payment_id,
                'error' => $exception->getMessage(),
            ]);

            return $payment;
        }

        if (! $gatewayPayment) {
            return $payment;
        }

        $status = (string) ($gatewayPayment->status ?? 'unknown');

        if ($status === 'approved') {
            DB::transaction(function () use ($payment, $gatewayPayment, $status) {
                $lockedPayment = Payment::lockForUpdate()->find($payment->id);
                if (! $lockedPayment || $lockedPayment->approved_at !== null) {
                    return;
                }

                $plan = Plan::find($lockedPayment->plan_id);
                $period = Period::where('plan_id', $lockedPayment->plan_id)->find($lockedPayment->period_id);

                if (! $plan || ! $period) {
                    Log::error('Plano ou período não encontrado ao aprovar pagamento Mercado Pago.', [
                        'payment_id' => $lockedPayment->payment_id,
                        'plan_id' => $lockedPayment->plan_id,
                        'period_id' => $lockedPayment->period_id,
                    ]);

                    return;
                }

                $tenant = Tenant::lockForUpdate()->find($lockedPayment->tenant_id);
                if (! $tenant) {
                    Log::error('Tenant não encontrado ao aprovar pagamento Mercado Pago.', [
                        'payment_id' => $lockedPayment->payment_id,
                        'tenant_id' => $lockedPayment->tenant_id,
                    ]);

                    return;
                }

                // O pagamento renova a partir do vencimento, mantendo o dia do ciclo,
                // mesmo quando pago durante a carência ou depois do bloqueio.
                $base = $tenant->expiration_date?->copy() ?? now();

                $tenant->update([
                    'plan' => $plan->id,
                    'billing_period_id' => $period->id,
                    'plan_type' => $plan->account_type,
                    'payment' => true,
                    'trial_ends_at' => null,
                    'status' => 1,
                    'expiration_date' => $base->addMonthsNoOverflow((int) $period->interval_count),
                ]);

                $lockedPayment->update([
                    'status' => $status,
                    'approved_at' => now(),
                    'raw_response' => json_decode(json_encode($gatewayPayment), true),
                ]);
            });
        } else {
            $payment->update([
                'status' => $status,
                'raw_response' => json_decode(json_encode($gatewayPayment), true),
            ]);
        }

        return $payment->fresh();
    }
}

