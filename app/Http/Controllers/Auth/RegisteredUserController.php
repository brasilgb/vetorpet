<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SampleData\SampleDataService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/register');
    }

    /**
     * Handle an incoming registration request.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'company' => 'required|string|max:255|unique:tenants,company',
            'cnpj' => 'required|string|cnpj|unique:tenants,cnpj',
            'phone' => 'required|string|max:20',
            'whatsapp' => 'required|string|max:20',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'account_type' => ['required', Rule::in([Tenant::PLAN_INDIVIDUAL, Tenant::PLAN_TEAM])],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'sample_data' => ['sometimes', 'boolean'],
        ], [], [
            'name' => 'Nome',
            'email' => 'E-mail',
            'password' => 'Senha',
            'company' => 'Razão Social',
            'cnpj' => 'CNPJ',
            'phone' => 'Telefone',
            'whatsapp' => 'WhatsApp',
            'account_type' => 'Tipo de conta',
            'sample_data' => 'Dados de exemplo',
        ]);

        [$tenant, $user] = DB::transaction(function () use ($data) {
            $planType = $data['account_type'];
            $plan = Plan::where('is_public', true)
                ->where('account_type', $planType)
                ->orderBy('price')
                ->firstOrFail();
            $trialEndsAt = now()->addDays(Tenant::TRIAL_DAYS);

            $tenant = Tenant::create([
                'company' => $data['company'],
                'cnpj' => $data['cnpj'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'whatsapp' => $data['whatsapp'],
                'status' => 1,
                'payment' => false,
                'expiration_date' => $trialEndsAt,
                'trial_ends_at' => $trialEndsAt,
                'plan' => $plan->id,
                'billing_period_id' => null,
                'plan_type' => $planType,
            ]);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'telephone' => $data['phone'],
                'whatsapp' => $data['whatsapp'],
                'password' => Hash::make($data['password']),
                'tenant_id' => $tenant->id,
                'roles' => User::ROLE_OWNER,
                'status' => 1,
            ]);

            $tenant->update(['owner_user_id' => $user->id]);

            return [$tenant, $user];
        });

        // Dados de exemplo são opcionais e nunca podem impedir o cadastro.
        if ($request->boolean('sample_data')) {
            try {
                app(SampleDataService::class)->seed($tenant, $user);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        event(new Registered($user));
        Auth::login($user);

        return redirect()
            ->route('app.dashboard')
            ->with('message', 'Conta criada com sucesso! Seu período de teste foi iniciado.');
    }
}
