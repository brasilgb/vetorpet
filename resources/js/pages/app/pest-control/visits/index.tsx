import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, Eye, Plus } from 'lucide-react';
import moment from 'moment';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('app.dashboard') },
    { title: 'Controle de Pragas', href: route('app.pest-control.index') },
    { title: 'Agenda de visitas', href: '#' },
];

const selectClassName =
    'flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm';

const statusVariant: Record<string, 'secondary' | 'destructive' | 'outline'> = {
    scheduled: 'outline',
    draft: 'outline',
    in_progress: 'secondary',
    completed: 'secondary',
    synced: 'secondary',
    validated: 'secondary',
    canceled: 'destructive',
};

export default function PestControlVisits({ visits, filters, establishments, technicians, statuses }: any) {
    const { auth } = usePage<SharedData>().props;
    const permissions: string[] = (auth as any).pestControlPermissions ?? [];
    const canCreate = permissions.includes('pest_control.visits.create');

    const applyFilter = (key: string, value: string) => {
        router.get(
            route('app.pest-control.visits.index'),
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agenda de visitas" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <CalendarDays className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Agenda de visitas</h2>
                </div>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-end lg:justify-between">
                <div className="grid w-full gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label className="text-xs text-muted-foreground">De</label>
                        <input
                            type="date"
                            className={selectClassName}
                            value={filters?.start_date ?? ''}
                            onChange={(e) => applyFilter('start_date', e.target.value)}
                        />
                    </div>
                    <div>
                        <label className="text-xs text-muted-foreground">Até</label>
                        <input
                            type="date"
                            className={selectClassName}
                            value={filters?.end_date ?? ''}
                            onChange={(e) => applyFilter('end_date', e.target.value)}
                        />
                    </div>
                    <div>
                        <label className="text-xs text-muted-foreground">Estabelecimento</label>
                        <select
                            className={selectClassName}
                            value={filters?.establishment_id ?? ''}
                            onChange={(e) => applyFilter('establishment_id', e.target.value)}
                        >
                            <option value="">Todos</option>
                            {establishments?.map((establishment: any) => (
                                <option key={establishment.id} value={establishment.id}>
                                    {establishment.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="text-xs text-muted-foreground">Técnico</label>
                        <select
                            className={selectClassName}
                            value={filters?.technician_id ?? ''}
                            onChange={(e) => applyFilter('technician_id', e.target.value)}
                        >
                            <option value="">Todos</option>
                            {technicians?.map((technician: any) => (
                                <option key={technician.id} value={technician.id}>
                                    {technician.name}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
                {canCreate && (
                    <Button asChild className="w-full whitespace-nowrap lg:w-auto">
                        <Link href={route('app.pest-control.visits.create')}>
                            <Plus className="h-4 w-4" />
                            <span>Agendar visita</span>
                        </Link>
                    </Button>
                )}
            </div>

            <div className="p-4">
                <PaginationSummary data={visits} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Agendamento</TableHead>
                                <TableHead>Estabelecimento</TableHead>
                                <TableHead>Técnico</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="min-w-[80px]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {visits?.data?.length > 0 ? (
                                visits.data.map((visit: any) => (
                                    <TableRow key={visit.id}>
                                        <TableCell>{moment(visit.scheduled_at).format('DD/MM/YYYY HH:mm')}</TableCell>
                                        <TableCell>{visit.establishment?.name}</TableCell>
                                        <TableCell>{visit.technician?.name ?? 'Atendimento pessoal'}</TableCell>
                                        <TableCell>
                                            <Badge variant={statusVariant[visit.status] ?? 'outline'}>
                                                {statuses?.[visit.status] ?? visit.status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="min-w-[80px] text-right">
                                            <Button asChild size="icon" variant="secondary" title="Ver visita">
                                                <Link href={route('app.pest-control.visits.edit', visit.id)}>
                                                    <Eye className="h-4 w-4" />
                                                </Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={5} className="h-16 text-center">
                                        Não há visitas no período selecionado.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={5}>
                                    <AppPagination data={visits} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
