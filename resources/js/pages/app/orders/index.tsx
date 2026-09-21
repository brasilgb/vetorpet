import ActionDelete from '@/components/action-delete';
import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import { AppSelect } from '@/components/app-select';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem, SharedData } from '@/types';
import { statusOrder } from '@/Utils/dataSelect';
import { statusOrderByValue } from '@/Utils/functions';
import { maskMoney } from '@/Utils/mask';
import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarDaysIcon, Pencil, Plus, Printer, ShoppingCartIcon } from 'lucide-react';
import moment from 'moment';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('app.dashboard'),
    },
    {
        title: 'Pedidos',
        href: '#',
    },
];

export default function Orders({ orders }: any) {
    const { auth } = usePage<SharedData>().props;
    const [messageStatus, setMessageStatus] = useState<string>('');

    useEffect(() => {
        const timeout = setTimeout(() => {
            setMessageStatus('');
        }, 3000);

        return () => clearTimeout(timeout);
    }, [messageStatus]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pedidos" />

            <div className="flex min-h-16 flex-col justify-between gap-3 px-4 py-3 sm:flex-row sm:items-center">
                <div className="flex items-center gap-2">
                    <Icon iconNode={ShoppingCartIcon} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Pedidos</h2>
                </div>
                <Button variant="default" asChild className="w-full whitespace-nowrap sm:w-auto">
                    <Link href={route('app.orders.create')}>
                        <Plus className="h-4 w-4" />
                        <span>Adicionar pedido</span>
                    </Link>
                </Button>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="w-full min-w-0 lg:max-w-[420px] lg:flex-1">
                    <InputSearch placeholder="Buscar pedido por número, cliente ou produto" url="app.orders.index" />
                </div>
                <div className="flex w-full flex-col gap-2 sm:flex-row lg:w-auto lg:shrink-0 lg:justify-end">
                    <Button variant="secondary" asChild className="w-full bg-emerald-500 text-white hover:bg-emerald-600 sm:w-auto">
                        <Link href={route('app.orders.report')}>
                            <CalendarDaysIcon className="h-4 w-4" />
                            <span>Relatório</span>
                        </Link>
                    </Button>
                </div>
            </div>

            <div className="p-4">
                <PaginationSummary data={orders} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Pedido</TableHead>
                                <TableHead>Valores</TableHead>
                                {!auth.isSeller && <TableHead>Vendedor</TableHead>}
                                <TableHead>Emissão</TableHead>
                                <TableHead>
                                    {messageStatus ? (
                                        <div className="rounded-md border border-green-500 bg-green-50 px-2 text-green-700">{messageStatus}</div>
                                    ) : (
                                        'Status'
                                    )}
                                </TableHead>
                                <TableHead className="min-w-[120px]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders?.data.length > 0 ? (
                                orders?.data?.map((order: any) => (
                                    <TableRow key={order.id}>
                                        <TableCell>
                                            <div className="space-y-1">
                                                <div className="font-medium">#{order.order_number}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {order?.customer?.name || 'Cliente não informado'}
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="space-y-1">
                                                <div className="font-medium">Total: R$ {maskMoney(order.total)}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    Subtotal: R$ {maskMoney(order.subtotal ?? order.total)} | Ajustado: R${' '}
                                                    {maskMoney(order.adjusted_total ?? order.total)}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    Flex: R$ {maskMoney(order.flex ?? 0)} | Ajustes/desc.: R$ {maskMoney(order.discount ?? 0)}
                                                </div>
                                            </div>
                                        </TableCell>
                                        {!auth.isSeller && (
                                            <TableCell>
                                                <span className="text-sm">{order?.user?.name ?? '-'}</span>
                                            </TableCell>
                                        )}
                                        <TableCell>{moment(order.created_at).format('DD/MM/YYYY')}</TableCell>
                                        <TableCell>
                                            {auth.isSeller || order.status == '4' ? (
                                                <Badge variant={String(order.status) === '4' ? 'destructive' : 'secondary'}>{statusOrderByValue(order.status)}</Badge>
                                            ) : (
                                                <AppSelect
                                                    setMessageStatus={setMessageStatus}
                                                    orderid={order?.id}
                                                    data={statusOrder}
                                                    title="Selecione o status"
                                                    defaultValue={order?.status}
                                                />
                                            )}
                                        </TableCell>
                                        <TableCell className="min-w-[120px]">
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Button asChild size="icon" variant="outline" title="Imprimir pedido"><a href={route('app.orders.print', order.id)} target="_blank" rel="noreferrer" aria-label={`Imprimir pedido ${order.order_number}`}><Printer className="h-4 w-4" /></a></Button>
                                                <Button asChild size="icon" variant="outline" title={String(order.status) === '4' ? 'Consultar pedido' : 'Editar pedido'}><Link href={route('app.orders.edit', order.id)} aria-label={`${String(order.status) === '4' ? 'Consultar' : 'Editar'} pedido ${order.order_number}`}><Pencil className="h-4 w-4" /></Link></Button>
                                                {!auth.isSeller && <ActionDelete title={'este pedido'} url={'app.orders.destroy'} param={order.id} />}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={auth.isSeller ? 5 : 6} className="h-16 text-center">
                                        Não há dados a serem mostrados no momento.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={auth.isSeller ? 5 : 6}>
                                    <AppPagination data={orders} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
