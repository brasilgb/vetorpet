import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, Save } from 'lucide-react';

const selectClassName =
    'flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('app.dashboard') },
    { title: 'Controle de Pragas', href: route('app.pest-control.index') },
    { title: 'Agenda de visitas', href: route('app.pest-control.visits.index') },
    { title: 'Agendar visita', href: '#' },
];

export default function CreatePestControlVisit({ establishments, technicians, serviceTypes }: any) {
    const preselectedEstablishmentId = new URLSearchParams(window.location.search).get('establishment_id') ?? '';

    const { data, setData, post, processing, errors } = useForm({
        establishment_id: preselectedEstablishmentId,
        technician_id: '',
        scheduled_at: '',
        service_type: '',
        notes: '',
    });

    const handleSubmit = (event: any) => {
        event.preventDefault();
        post(route('app.pest-control.visits.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agendar visita" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <CalendarDays className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Agendar visita</h2>
                </div>
            </div>

            <div className="p-4">
                <Button asChild>
                    <Link href={route('app.pest-control.visits.index')}>
                        <ArrowLeft className="h-4 w-4" />
                        <span>Voltar</span>
                    </Link>
                </Button>
            </div>

            <div className="p-4">
                <div className="rounded-lg border p-4">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="establishment_id">Estabelecimento</Label>
                                <select
                                    id="establishment_id"
                                    className={selectClassName}
                                    value={data.establishment_id}
                                    onChange={(e) => setData('establishment_id', e.target.value)}
                                >
                                    <option value="">Selecione</option>
                                    {establishments?.map((establishment: any) => (
                                        <option key={establishment.id} value={establishment.id}>
                                            {establishment.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.establishment_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="technician_id">Técnico (opcional)</Label>
                                <select
                                    id="technician_id"
                                    className={selectClassName}
                                    value={data.technician_id}
                                    onChange={(e) => setData('technician_id', e.target.value)}
                                >
                                    <option value="">Atendimento pessoal (sem técnico definido)</option>
                                    {technicians?.map((technician: any) => (
                                        <option key={technician.id} value={technician.id}>
                                            {technician.name}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-xs text-muted-foreground">
                                    Sem técnico definido, a visita aparece na agenda de todos e é assumida por quem fizer o atendimento.
                                </p>
                                <InputError message={errors.technician_id} />
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="scheduled_at">Data e hora</Label>
                                <Input
                                    id="scheduled_at"
                                    type="datetime-local"
                                    value={data.scheduled_at}
                                    onChange={(e) => setData('scheduled_at', e.target.value)}
                                />
                                <InputError message={errors.scheduled_at} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="service_type">Tipo de serviço</Label>
                                <Input
                                    id="service_type"
                                    list="service-type-suggestions"
                                    value={data.service_type}
                                    onChange={(e) => setData('service_type', e.target.value)}
                                />
                                <datalist id="service-type-suggestions">
                                    {serviceTypes?.map((type: string) => (
                                        <option key={type} value={type} />
                                    ))}
                                </datalist>
                                <InputError message={errors.service_type} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes">Observações</Label>
                            <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                            <InputError message={errors.notes} />
                        </div>

                        <div className="flex justify-end">
                            <Button type="submit" disabled={processing}>
                                <Save className="h-4 w-4" />
                                <span>Agendar</span>
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
