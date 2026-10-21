import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { statusOrderByValue } from '@/Utils/functions';
import { maskMoney } from '@/Utils/mask';
import { PDFDownloadLink } from '@react-pdf/renderer';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarDaysIcon, FileOutputIcon, Search } from 'lucide-react';
import moment from 'moment';
import { FormEvent, useMemo, useState } from 'react';
import OrderReportPDF from './order-report-pdf';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('app.dashboard') },
    { title: 'Pedidos', href: route('app.orders.index') },
    { title: 'Relatório', href: '#' },
];

export function Report({ orders, summaryData }: { orders: any[]; summaryData: any }) {
    return (
        <div className="p-4 pt-0">
            <div className="rounded-lg border p-2">
                <div className="m-2 flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:justify-between">
                    <div>Pedidos: {orders.length}</div>
                    <div>Flex: R$ {maskMoney(String(summaryData.flex))}</div>
                    <div>Descontos: R$ {maskMoney(String(summaryData.discount))}</div>
                    <div className="font-semibold">Total: R$ {maskMoney(String(summaryData.total))}</div>
                </div>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Nº Pedido</TableHead>
                            <TableHead>Emissão</TableHead>
                            <TableHead>Cliente</TableHead>
                            <TableHead>Vendedor</TableHead>
                            <TableHead>Flex</TableHead>
                            <TableHead>Desconto</TableHead>
                            <TableHead>Total</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {orders.length > 0 ? orders.map((order: any) => (
                            <TableRow key={order.id}>
                                <TableCell>#{order.order_number}</TableCell>
                                <TableCell>{moment(order.created_at).format('DD/MM/YYYY')}</TableCell>
                                <TableCell>{order.customer?.name ?? '-'}</TableCell>
                                <TableCell>{order.user?.name ?? '-'}</TableCell>
                                <TableCell>R$ {maskMoney(order.flex ?? 0)}</TableCell>
                                <TableCell>R$ {maskMoney(order.discount ?? 0)}</TableCell>
                                <TableCell className="font-medium">R$ {maskMoney(order.total ?? 0)}</TableCell>
                                <TableCell>{statusOrderByValue(order.status)}</TableCell>
                            </TableRow>
                        )) : (
                            <TableRow><TableCell colSpan={8} className="h-20 text-center">Nenhum pedido encontrado no período.</TableCell></TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}

export default function OrderReports({ orders, filters, users = [], canManageTeam = false }: any) {
    const [startDate, setStartDate] = useState(filters.start_date);
    const [endDate, setEndDate] = useState(filters.end_date);
    const [userId, setUserId] = useState(filters.user_id ? String(filters.user_id) : '');
    const sellerName = useMemo(() => users.find((user: any) => user.id === filters.user_id)?.name ?? null, [users, filters.user_id]);

    const summaryData = useMemo(() => orders.reduce((summary: any, order: any) => ({
        flex: summary.flex + Number(order.flex ?? 0),
        discount: summary.discount + Number(order.discount ?? 0),
        total: summary.total + Number(order.total ?? 0),
    }), { flex: 0, discount: 0, total: 0 }), [orders]);

    const document = useMemo(() => (
        <OrderReportPDF data={orders} startDate={filters.start_date} endDate={filters.end_date} summaryData={summaryData} sellerName={sellerName} />
    ), [orders, filters.start_date, filters.end_date, summaryData, sellerName]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('app.orders.report'), { start_date: startDate, end_date: endDate, user_id: userId || undefined }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Relatório de pedidos" />
            <div className="flex min-h-16 flex-col justify-between gap-3 px-4 py-3 sm:flex-row sm:items-center">
                <div className="flex items-center gap-2">
                    <CalendarDaysIcon className="h-8 w-8" />
                    <div>
                        <h2 className="text-xl font-semibold tracking-tight">Relatório de pedidos</h2>
                        <p className="text-sm text-muted-foreground">Consulte as vendas emitidas em um intervalo de datas e por vendedor.</p>
                    </div>
                </div>
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            <div className="m-4 flex flex-col gap-3 rounded-lg border p-4 lg:flex-row lg:items-end lg:justify-between">
                <form onSubmit={submit} className={`grid gap-3 ${canManageTeam ? 'sm:grid-cols-[180px_180px_200px_auto]' : 'sm:grid-cols-[180px_180px_auto]'}`}>
                    <div className="grid gap-2">
                        <Label htmlFor="start_date">Data inicial</Label>
                        <Input id="start_date" type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="end_date">Data final</Label>
                        <Input id="end_date" type="date" value={endDate} onChange={(event) => setEndDate(event.target.value)} />
                    </div>
                    {canManageTeam && (
                        <div className="grid gap-2">
                            <Label htmlFor="user_id">Vendedor</Label>
                            <select id="user_id" className="flex h-9 rounded-md border border-input bg-transparent px-3 text-sm" value={userId} onChange={(event) => setUserId(event.target.value)}>
                                <option value="">Todos</option>
                                {users.map((user: any) => <option key={user.id} value={user.id}>{user.name}</option>)}
                            </select>
                        </div>
                    )}
                    <Button type="submit" className="self-end"><Search className="h-4 w-4" />Consultar</Button>
                </form>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <Button variant="outline" asChild>
                        <Link href={route('app.orders.index')}><ArrowLeft className="h-4 w-4" />Voltar</Link>
                    </Button>
                    <PDFDownloadLink document={document} fileName={`pedidos-${filters.start_date}-a-${filters.end_date}${filters.user_id ? `-vendedor-${filters.user_id}` : ''}.pdf`}>
                        {({ loading }) => (
                            <Button disabled={loading || orders.length === 0} className="w-full">
                                <FileOutputIcon className="h-4 w-4" />{loading ? 'Gerando PDF...' : 'Gerar PDF'}
                            </Button>
                        )}
                    </PDFDownloadLink>
                </div>
            </div>

            <div className="px-4 pb-3 text-sm text-muted-foreground">
                Período: <strong className="text-foreground">{moment(filters.start_date).format('DD/MM/YYYY')} a {moment(filters.end_date).format('DD/MM/YYYY')}</strong>
                {sellerName && <> · Vendedor: <strong className="text-foreground">{sellerName}</strong></>}
            </div>
            <Report orders={orders} summaryData={summaryData} />
        </AppLayout>
    );
}
