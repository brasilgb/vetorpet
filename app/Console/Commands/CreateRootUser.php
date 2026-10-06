<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateRootUser extends Command
{
    protected $signature = 'sales:create-root';

    protected $description = 'Cria o usuario root do sistema';

    public function handle(): int
    {
        if (User::withoutGlobalScopes()->whereNull('tenant_id')->where('roles', User::ROLE_ROOT)->exists()) {
            $this->error('Ja existe um usuario root cadastrado.');

            return self::FAILURE;
        }

        $name = $this->ask('Nome');
        $email = $this->ask('E-mail');
        $password = $this->secret('Senha (minimo de 8 caracteres)');
        $passwordConfirmation = $this->secret('Confirme a senha');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [], [
            'name' => 'nome',
            'email' => 'e-mail',
            'password' => 'senha',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::withoutGlobalScopes()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'status' => 1,
            'roles' => User::ROLE_ROOT,
            'tenant_id' => null,
        ]);

        $this->info('Usuario root criado com sucesso.');

        return self::SUCCESS;
    }
}
