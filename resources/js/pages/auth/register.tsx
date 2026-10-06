import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { maskCnpj, maskPhone } from '@/Utils/mask';

type RegisterForm = {
    cnpj: string;
    company: string;
    account_type: 'individual' | 'team';
    phone: string;
    whatsapp: string;
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    sample_data: boolean;
};

const businessProfiles = [
    {
        value: 'team',
        name: 'Distribuidora pet',
        description: 'Gestão da equipe de representantes, regiões, pedidos e metas.',
    },
    {
        value: 'individual',
        name: 'Veterinário ou representante autônomo',
        description: 'Para quem atende e vende sozinho e administra a própria carteira.',
    },
] as const;

export default function Register() {
    const [showPassword, setShowPassword] = useState(false);
    const [whatsappSameAsPhone, setWhatsappSameAsPhone] = useState(true);

    const { data, setData, post, processing, errors, reset, transform } = useForm<Required<RegisterForm>>({
        cnpj: '',
        company: '',
        account_type: 'team',
        phone: '',
        whatsapp: '',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        sample_data: true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((current) => ({ ...current, whatsapp: whatsappSameAsPhone ? current.phone : current.whatsapp }));
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout width="w-full max-w-4xl" title="Teste grátis por 14 dias" description="Crie sua conta em poucos passos. Não pedimos cartão de crédito.">
            <Head title="Criar uma conta" />
            <div className="max-h-[72svh] min-w-0 overflow-y-auto sm:max-h-[76svh]">
                <form className="flex flex-col gap-6" onSubmit={submit}>
                    <div className="space-y-3">
                        <Label>Qual é o seu negócio?</Label>
                        <div className="grid gap-3 md:grid-cols-2">
                            {businessProfiles.map((profile) => (
                                <button
                                    key={profile.value}
                                    type="button"
                                    disabled={processing}
                                    aria-pressed={data.account_type === profile.value}
                                    onClick={() => setData('account_type', profile.value)}
                                    className={`rounded-lg border p-4 text-left transition-colors ${data.account_type === profile.value ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:border-primary/50'}`}
                                >
                                    <div className="font-semibold">{profile.name}</div>
                                    <div className="mt-1 text-sm text-muted-foreground">{profile.description}</div>
                                </button>
                            ))}
                        </div>
                        <InputError message={errors.account_type} />
                    </div>

                    <div className="grid gap-6 md:grid-cols-3">
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="company">Empresa ou nome profissional</Label>
                            <Input
                                id="company"
                                type="text"
                                tabIndex={1}
                                autoComplete="company"
                                value={data.company}
                                onChange={(e) => setData('company', e.target.value)}
                                disabled={processing}
                                placeholder="Razão social ou nome da clínica"
                            />
                            <InputError message={errors.company} className="mt-2" />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="cnpj">CNPJ</Label>
                            <Input
                                id="cnpj"
                                type="text"
                                tabIndex={2}
                                autoComplete="cnpj"
                                value={maskCnpj(data.cnpj)}
                                onChange={(e) => setData('cnpj', e.target.value.replace(/\D/g, ''))}
                                disabled={processing}
                                placeholder="CNPJ"
                            />
                            <InputError message={errors.cnpj} className="mt-2" />
                        </div>
                    </div>

                    <div className="grid gap-6 md:grid-cols-4">
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="name">Nome completo</Label>
                            <Input
                                id="name"
                                type="text"
                                tabIndex={4}
                                autoComplete="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                disabled={processing}
                                placeholder="Nome completo"
                            />
                            <InputError message={errors.name} className="mt-2" />
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="email">E-mail</Label>
                            <Input
                                id="email"
                                type="email"
                                tabIndex={5}
                                autoComplete="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                disabled={processing}
                                placeholder="email@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="phone">Telefone *</Label>
                            <Input
                                id="phone"
                                type="tel"
                                tabIndex={6}
                                autoComplete="tel"
                                value={maskPhone(data.phone) ?? ''}
                                onChange={(e) => setData('phone', e.target.value.replace(/\D/g, ''))}
                                disabled={processing}
                                placeholder="(11) 3333-4444"
                                maxLength={15}
                            />
                            <InputError message={errors.phone} />
                        </div>

                        <div className="flex items-center gap-2 md:col-span-2 md:self-end md:pb-2">
                            <Checkbox
                                id="whatsapp_same_as_phone"
                                checked={whatsappSameAsPhone}
                                onCheckedChange={(checked) => setWhatsappSameAsPhone(checked === true)}
                                disabled={processing}
                            />
                            <Label htmlFor="whatsapp_same_as_phone" className="font-normal">
                                O telefone também é WhatsApp
                            </Label>
                        </div>

                        {!whatsappSameAsPhone && (
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="whatsapp">WhatsApp *</Label>
                            <Input
                                id="whatsapp"
                                type="tel"
                                tabIndex={7}
                                autoComplete="tel"
                                value={maskPhone(data.whatsapp) ?? ''}
                                onChange={(e) => setData('whatsapp', e.target.value.replace(/\D/g, ''))}
                                disabled={processing}
                                placeholder="(11) 99999-9999"
                                maxLength={15}
                            />
                            <InputError message={errors.whatsapp} />
                        </div>
                        )}
                        {whatsappSameAsPhone && errors.whatsapp && <InputError message={errors.whatsapp} className="md:col-span-4" />}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-end">
                        <div className="flex min-w-0 flex-col gap-2">
                            <Label htmlFor="password">Senha</Label>
                            <Input
                                id="password"
                                type={showPassword ? 'text' : 'password'}
                                tabIndex={8}
                                autoComplete="new-password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                disabled={processing}
                                placeholder="Senha"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <Button type="button" variant="ghost" size="icon" onClick={() => setShowPassword(!showPassword)} tabIndex={10}>
                            {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </Button>

                        <div className="flex min-w-0 flex-col gap-2">
                            <Label htmlFor="password_confirmation">Confirmar senha</Label>
                            <Input
                                id="password_confirmation"
                                type={showPassword ? 'text' : 'password'}
                                tabIndex={9}
                                autoComplete="new-password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                disabled={processing}
                                placeholder="Confirmar senha"
                            />
                            <InputError message={errors.password_confirmation} />
                        </div>
                    </div>

                    <div className="flex items-start gap-3 rounded-lg border p-4">
                        <Checkbox
                            id="sample_data"
                            checked={data.sample_data}
                            onCheckedChange={(checked) => setData('sample_data', checked === true)}
                            disabled={processing}
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="sample_data">Começar com dados de exemplo</Label>
                            <p className="text-sm text-muted-foreground">
                                Clientes, produtos, pedidos e visitas fictícios só na sua conta, para explorar o sistema. Você remove tudo com um clique.
                            </p>
                        </div>
                    </div>

                    <Button type="submit" className="mt-2 w-full" tabIndex={11} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        {processing ? 'Criando sua conta...' : 'Começar teste grátis'}
                    </Button>

                    <div className="text-center text-sm text-muted-foreground">
                        Já tem uma conta?{' '}
                        <TextLink href={route('login')} tabIndex={12}>
                            Entrar
                        </TextLink>
                    </div>
                </form>
            </div>
        </AuthLayout>
    );
}
