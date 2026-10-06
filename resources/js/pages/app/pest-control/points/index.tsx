import ActionDelete from '@/components/action-delete';
import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import InputSearch from '@/components/inputSearch';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Edit, MapPin, Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('app.dashboard') },
    { title: 'Controle de Pragas', href: route('app.pest-control.index') },
    { title: 'Pontos de controle', href: '#' },
];

export default function PestControlPoints({ points }: any) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pontos de controle" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <MapPin className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Pontos de controle</h2>
                </div>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="w-full min-w-0 lg:max-w-[420px] lg:flex-1">
                    <InputSearch placeholder="Buscar por código ou identificação" url="app.pest-control.points.index" />
                </div>
                <Button asChild className="w-full whitespace-nowrap sm:w-auto">
                    <Link href={route('app.pest-control.points.create')}>
                        <Plus className="h-4 w-4" />
                        <span>Novo ponto</span>
                    </Link>
                </Button>
            </div>

            <div className="p-4">
                <PaginationSummary data={points} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Código</TableHead>
                                <TableHead>Estabelecimento</TableHead>
                                <TableHead>Categoria</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="min-w-[120px]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {points?.data?.length > 0 ? (
                                points.data.map((point: any) => (
                                    <TableRow key={point.id}>
                                        <TableCell>
                                            <div className="font-medium">{point.code}</div>
                                            <div className="text-xs text-muted-foreground">{point.label || 'Sem identificação'}</div>
                                        </TableCell>
                                        <TableCell>{point.establishment?.name}</TableCell>
                                        <TableCell>{point.category_key || '-'}</TableCell>
                                        <TableCell>
                                            <Badge variant={point.active ? 'secondary' : 'destructive'}>{point.active ? 'Ativo' : 'Inativo'}</Badge>
                                        </TableCell>
                                        <TableCell className="min-w-[120px]">
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Button asChild size="icon" className="bg-orange-500 text-white hover:bg-orange-600" title="Editar">
                                                    <Link href={route('app.pest-control.points.edit', point.id)}>
                                                        <Edit className="h-4 w-4" />
                                                    </Link>
                                                </Button>
                                                <ActionDelete title="este ponto de controle" url="app.pest-control.points.destroy" param={point.id} />
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={5} className="h-16 text-center">
                                        Não há dados a serem mostrados no momento.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={5}>
                                    <AppPagination data={points} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
