import SampleDataNotice from '@/components/sample-data-notice';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem, SharedData } from '@/types';
import { statusOrderByValue } from '@/Utils/functions';
import { maskMoney } from '@/Utils/mask';
import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, BadgeDollarSign, Ban, ChartNoAxesCombined, CircleDollarSign, Clock3, CreditCard, Megaphone, ReceiptText, ShoppingCart, Store, Trophy, UsersRound, WalletCards } from 'lucide-react';
import moment from 'moment';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: route('app.dashboard') }];
const executivePeriod = new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' }).format(new Date());

export default function Dashboard({ summary, campaignSales, recentOrders, statusBreakdown, sampleData = null }: any) {
    const { auth } = usePage<SharedData>().props;
    return <AppLayout breadcrumbs={breadcrumbs}>
        <Head title="Dashboard" />
        <div className="flex flex-col gap-5 p-4">
            <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                <div><h1 className="text-2xl font-semibold">Visão geral</h1><p className="text-sm text-muted-foreground">Resumo executivo de {executivePeriod}.</p></div>
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline"><Link href={route('app.reports.sellers')}><UsersRound className="h-4 w-4" />Vendedores</Link></Button>
                    <Button asChild variant="outline"><Link href={route('app.reports.expenses')}><ReceiptText className="h-4 w-4" />Despesas</Link></Button>
                    <Button asChild><Link href={route('app.reports.sales')}><ChartNoAxesCombined className="h-4 w-4" />Vendas</Link></Button>
                </div>
            </div>

            <SampleDataNotice state={sampleData} />

            {auth.subscriptionInGracePeriod && (
                <div className="flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-950 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex gap-3">
                        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                        <div>
                            <div className="font-medium">Assinatura em período de tolerância</div>
                            <div className="text-sm text-amber-800">
                                Regularize o pagamento para evitar o bloqueio em {auth.subscriptionGraceDaysRemaining}{' '}
                                {auth.subscriptionGraceDaysRemaining === 1 ? 'dia' : 'dias'}.
                            </div>
                        </div>
                    </div>
                    <Button asChild className="shrink-0 bg-amber-600 text-white hover:bg-amber-700">
                        <Link href={route('app.subscription.index')}>
                            <CreditCard className="h-4 w-4" />
                            Pagar agora
                        </Link>
                    </Button>
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Metric icon={<CircleDollarSign />} label="Vendas no mês" value={`R$ ${maskMoney(summary.sales_total)}`} helper="Pedidos cancelados não entram" />
                <Metric icon={<ShoppingCart />} label="Pedidos válidos" value={summary.orders_count} helper={`${summary.pending_count} aguardando andamento`} />
                <Metric icon={<ReceiptText />} label="Ticket médio" value={`R$ ${maskMoney(summary.average_ticket)}`} helper="Média por pedido válido" />
                <Metric icon={<Store />} label="Clientes atendidos" value={summary.customers_count} helper="Clientes únicos no mês" />
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2"><CardHeader><CardTitle className="text-base">Pontos de atenção</CardTitle></CardHeader><CardContent className="grid gap-3 sm:grid-cols-3"><Attention icon={<Clock3 />} label="Pedidos pendentes" value={summary.pending_count} href={route('app.orders.index')} /><Attention icon={<Ban />} label="Cancelados no mês" value={summary.canceled_count} href={route('app.reports.sales')} danger /><Attention icon={<Store />} label="Clientes inativos" value={summary.inactive_customers} href={route('app.intelligence.index')} /></CardContent></Card>
                <Card><CardHeader><CardTitle className="text-base">Destaque do mês</CardTitle></CardHeader><CardContent>{summary.top_seller ? <div className="flex items-center gap-3"><div className="rounded-full bg-amber-500/10 p-3 text-amber-600"><Trophy /></div><div><div className="font-semibold">{summary.top_seller.name}</div><div className="text-sm text-muted-foreground">R$ {maskMoney(summary.top_seller.total)} em vendas</div></div></div> : <Empty />}</CardContent></Card>
            </div>

            <Card>
                <CardHeader className="flex-row items-center justify-between"><CardTitle className="flex items-center gap-2 text-base"><Megaphone className="h-5 w-5 text-primary" />Vendas de campanhas</CardTitle><Button asChild size="sm" variant="ghost"><Link href={route('app.reports.sales', { campaign_id: '' })}>Ver relatório<ArrowRight className="h-4 w-4" /></Link></Button></CardHeader>
                <CardContent>
                    <div className="mb-4 grid gap-3 sm:grid-cols-3">
                        <CampaignMetric label="Faturamento" value={`R$ ${maskMoney(summary.campaign_sales_total)}`} />
                        <CampaignMetric label="Pedidos" value={summary.campaign_orders_count} />
                        <CampaignMetric label="Participação nas vendas" value={`${Number(summary.campaign_sales_share).toFixed(1)}%`} />
                    </div>
                    {campaignSales.length ? <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">{campaignSales.map((row: any) => <Link key={row.campaign_id} href={route('app.reports.sales', { campaign_id: row.campaign_id })} className="flex items-center justify-between rounded-lg border p-3 transition-colors hover:bg-muted/40"><div><div className="font-medium">{row.campaign?.name ?? 'Campanha removida'}</div><div className="text-xs text-muted-foreground">{row.orders_count} pedido(s)</div></div><strong>R$ {maskMoney(row.total)}</strong></Link>)}</div> : <Empty />}
                </CardContent>
            </Card>

            <div className="grid gap-4 xl:grid-cols-3">
                <Card className="xl:col-span-2"><CardHeader className="flex-row items-center justify-between"><CardTitle className="text-base">Pedidos recentes</CardTitle><Button asChild size="sm" variant="ghost"><Link href={route('app.orders.index')}>Ver pedidos<ArrowRight className="h-4 w-4" /></Link></Button></CardHeader><CardContent className="space-y-2">{recentOrders.length ? recentOrders.map((order: any) => <div key={order.id} className="flex flex-col justify-between gap-2 rounded-lg border p-3 sm:flex-row sm:items-center"><div><div className="font-medium">Pedido #{order.order_number} · {order.customer?.name ?? 'Cliente não informado'}</div><div className="text-xs text-muted-foreground">{order.user?.name ?? 'Sem vendedor'} · {moment(order.created_at).format('DD/MM/YYYY HH:mm')}{order.campaign ? ` · ${order.campaign.name}` : ''}</div></div><div className="flex items-center justify-between gap-3"><Badge variant={String(order.status) === '4' ? 'destructive' : 'secondary'}>{statusOrderByValue(order.status)}</Badge><strong>R$ {maskMoney(order.total)}</strong></div></div>) : <Empty />}</CardContent></Card>
                <Card><CardHeader><CardTitle className="text-base">Situação dos pedidos</CardTitle></CardHeader><CardContent className="space-y-3">{statusBreakdown.length ? statusBreakdown.map((row: any) => <div key={row.status} className="flex items-center justify-between rounded-lg border p-3"><div><div className="font-medium">{statusOrderByValue(row.status)}</div><div className="text-xs text-muted-foreground">R$ {maskMoney(row.total)}</div></div><Badge variant="secondary">{row.orders_count}</Badge></div>) : <Empty />}</CardContent></Card>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Card><CardContent className="flex items-center gap-3 pt-6"><WalletCards className="text-primary" /><div><div className="text-sm text-muted-foreground">Saldo Flex interno</div><div className="text-xl font-semibold">R$ {maskMoney(summary.flex_balance)}</div></div></CardContent></Card>
                {auth.canManageTeam && <Card><CardContent className="flex items-center justify-between gap-3 pt-6"><div className="flex items-center gap-3"><BadgeDollarSign className="text-primary" /><div><div className="font-medium">Comissões da equipe</div><div className="text-sm text-muted-foreground">Previsão, realizado e detalhamento</div></div></div><Button asChild variant="outline" size="sm"><Link href={route('app.commissions.index')}>Abrir</Link></Button></CardContent></Card>}
            </div>
        </div>
    </AppLayout>;
}

function Metric({ icon, label, value, helper }: any) { return <Card><CardHeader className="flex-row items-start justify-between pb-2"><div><div className="text-sm text-muted-foreground">{label}</div><div className="mt-2 text-2xl font-semibold">{value}</div></div><span className="text-primary">{icon}</span></CardHeader><CardContent className="text-xs text-muted-foreground">{helper}</CardContent></Card>; }
function Attention({ icon, label, value, href, danger = false }: any) { return <Link href={href} className="flex items-center justify-between rounded-lg border p-3 transition-colors hover:bg-muted/40"><div className="flex items-center gap-2"><span className={danger ? 'text-destructive' : 'text-primary'}>{icon}</span><span className="text-sm">{label}</span></div><strong className={danger ? 'text-destructive' : ''}>{value}</strong></Link>; }
function CampaignMetric({ label, value }: any) { return <div className="rounded-lg bg-muted/40 p-3"><div className="text-xs text-muted-foreground">{label}</div><div className="mt-1 text-xl font-semibold">{value}</div></div>; }
function Empty() { return <div className="text-sm text-muted-foreground">Sem dados no período.</div>; }
