import AppearanceTabs from '@/components/appearance-tabs';
import { Icon } from '@/components/icon';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem, SharedData } from '@/types';
import { maskCpfCnpj, maskMoney, maskMoneyDot } from '@/Utils/mask';
import { Head, useForm, usePage } from '@inertiajs/react';
import { CogIcon, CreditCard, Palette, Wallet } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('app.dashboard') },
    { title: 'Configurações', href: '#' },
    { title: 'Outras configurações', href: route('app.other-settings.index') },
];

type Addon = { module_key: string; label: string; amount: number };

const sumAddons = (addons: Addon[]) => addons.reduce((sum, addon) => sum + Number(addon.amount), 0);

export default function OtherSettings({ tenant, blockedReason, onTrial, currentAddons = [] }: any) {
    const { auth } = usePage<SharedData>().props;
    const plan = tenant.plan_model;
    const period = tenant.billing_period;
    const licenseEnd = onTrial ? tenant.trial_ends_at : tenant.expiration_date;

    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        admin_flex: tenant.admin_flex ?? '',
    });

    const submitAdminFlex = (e: any) => {
        e.preventDefault();
        patch(route('app.other-settings.admin-flex.update'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Outras configurações" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={CogIcon} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Outras configurações</h2>
                </div>
            </div>

            <div className="space-y-6 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Palette className="h-5 w-5" />
                            Tema do painel
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <p className="text-sm text-muted-foreground">Escolha como o painel de controle será exibido neste dispositivo.</p>
                        <AppearanceTabs />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <CreditCard className="h-5 w-5" />
                            Licença de uso do sistema
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <div className="text-xs text-muted-foreground">Empresa</div>
                                <div className="font-medium">{tenant.company}</div>
                                <div className="text-sm text-muted-foreground">{maskCpfCnpj(tenant.cnpj) ?? tenant.cnpj}</div>
                            </div>
                            <div>
                                <div className="text-xs text-muted-foreground">Plano</div>
                                <div className="font-medium">{plan?.name ?? 'Avaliação'}</div>
                                <div className="text-sm text-muted-foreground">
                                    {plan?.account_type === 'team' ? 'Equipe' : 'Vendedor individual'}
                                </div>
                            </div>
                            <div>
                                <div className="text-xs text-muted-foreground">Ciclo</div>
                                <div className="font-medium">{period?.name ?? (onTrial ? 'Período de teste' : 'Não definido')}</div>
                                {period && (
                                    <div className="text-sm text-muted-foreground">
                                        R$ {maskMoney(Number(period.price) + sumAddons(currentAddons))}
                                        {(currentAddons as Addon[]).map((addon) => (
                                            <div key={addon.module_key} className="text-xs">
                                                inclui {addon.label}: R$ {maskMoney(addon.amount)}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <div>
                                <div className="text-xs text-muted-foreground">Situação</div>
                                <div className="mt-1">
                                    <Badge variant={blockedReason ? 'destructive' : 'secondary'}>
                                        {onTrial ? 'Em avaliação' : (blockedReason ?? 'Licença ativa')}
                                    </Badge>
                                </div>
                                <div className="mt-1 text-sm text-muted-foreground">
                                    {licenseEnd ? `Até ${new Date(licenseEnd).toLocaleDateString('pt-BR')}` : 'Vencimento não definido'}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {auth.isOwner && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Wallet className="h-5 w-5" />
                                Flex Universal
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <p className="text-sm text-muted-foreground">
                                Defina um valor fictício de Flex que só você, como administrador, verá como "Flex disponível" ao adicionar
                                pedidos — sem consumir o saldo Flex real e compartilhado da equipe. Deixe em branco para usar o saldo real.
                            </p>
                            <form onSubmit={submitAdminFlex} className="flex flex-col gap-4 sm:flex-row sm:items-end">
                                <div className="grid max-w-xs gap-2">
                                    <Label htmlFor="admin_flex">Valor do Flex Universal (R$)</Label>
                                    <Input
                                        type="text"
                                        id="admin_flex"
                                        inputMode="numeric"
                                        placeholder="Saldo real da equipe"
                                        value={maskMoney(data.admin_flex) ?? ''}
                                        onChange={(e) => setData('admin_flex', maskMoneyDot(e.target.value) ?? '')}
                                    />
                                    <InputError message={errors.admin_flex} />
                                </div>
                                <div className="flex items-center gap-3">
                                    <Button type="submit" disabled={processing}>
                                        Salvar
                                    </Button>
                                    {recentlySuccessful && <span className="text-sm text-muted-foreground">Salvo!</span>}
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
