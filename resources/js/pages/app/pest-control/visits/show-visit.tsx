import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Camera, CheckCircle2, ClipboardList, LogIn, LogOut, MapPin, Paperclip, PenLine, Save, ShieldCheck, XCircle } from 'lucide-react';
import moment from 'moment';
import { useRef, useState } from 'react';

const selectClassName =
    'flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm';

const statusLabels: Record<string, string> = {
    scheduled: 'Agendada',
    draft: 'Rascunho',
    in_progress: 'Em andamento',
    completed: 'Concluída',
    synced: 'Sincronizada',
    validated: 'Validada',
    canceled: 'Cancelada',
};

function currentPosition(): Promise<GeolocationPosition | null> {
    return new Promise((resolve) => {
        if (!navigator.geolocation) {
            resolve(null);
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (position) => resolve(position),
            () => resolve(null),
            { enableHighAccuracy: true, timeout: 8000 },
        );
    });
}

function SignaturePad({ onCapture }: { onCapture: (dataUrl: string) => void }) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);

    const getPoint = (event: any) => {
        const canvas = canvasRef.current!;
        const rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    };

    const start = (event: any) => {
        drawing.current = true;
        const ctx = canvasRef.current!.getContext('2d')!;
        const { x, y } = getPoint(event);
        ctx.beginPath();
        ctx.moveTo(x, y);
    };

    const move = (event: any) => {
        if (!drawing.current) return;
        const ctx = canvasRef.current!.getContext('2d')!;
        const { x, y } = getPoint(event);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#111827';
        ctx.lineTo(x, y);
        ctx.stroke();
    };

    const end = () => {
        drawing.current = false;
        const canvas = canvasRef.current!;
        onCapture(canvas.toDataURL('image/png'));
    };

    const clear = () => {
        const canvas = canvasRef.current!;
        canvas.getContext('2d')!.clearRect(0, 0, canvas.width, canvas.height);
        onCapture('');
    };

    return (
        <div className="grid gap-2">
            <canvas
                ref={canvasRef}
                width={480}
                height={160}
                className="w-full touch-none rounded-md border bg-white"
                onPointerDown={start}
                onPointerMove={move}
                onPointerUp={end}
                onPointerLeave={() => drawing.current && end()}
            />
            <Button type="button" variant="outline" size="sm" onClick={clear} className="w-fit">
                Limpar assinatura
            </Button>
        </div>
    );
}

function InspectionDialog({ visitId, point, inspection, products, species, consumptionTypes, deviceConditions }: any) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, transform } = useForm({
        inspected_at: '',
        product_id: inspection?.product?.id ?? inspection?.product_id ?? '',
        consumption_type: inspection?.consumption_type ?? '',
        consumption_code: inspection?.consumption_code ?? '',
        replaced: inspection?.replaced ?? false,
        device_condition: inspection?.device_condition ?? '',
        live_count: inspection?.live_count ?? 0,
        dead_count: inspection?.dead_count ?? 0,
        notes: inspection?.notes ?? '',
        photo: null as File | null,
        not_inspected: inspection?.not_inspected ?? false,
        not_inspected_reason: inspection?.not_inspected_reason ?? '',
        species: (inspection?.species_found ?? []).map((row: any) => ({
            species_id: row.species?.id,
            live_count: row.live_count,
            dead_count: row.dead_count,
        })),
        latitude: '',
        longitude: '',
    });

    const addSpeciesRow = () => setData('species', [...data.species, { species_id: '', live_count: 0, dead_count: 0 }]);
    const updateSpeciesRow = (index: number, field: string, value: any) => {
        const rows = [...data.species];
        rows[index] = { ...rows[index], [field]: value };
        setData('species', rows);
    };
    const removeSpeciesRow = (index: number) =>
        setData(
            'species',
            data.species.filter((_: any, i: number) => i !== index),
        );

    const handleSubmit = async (event: any) => {
        event.preventDefault();
        const position = await currentPosition();
        transform((current: any) => ({
            ...current,
            inspected_at: new Date().toISOString(),
            latitude: position?.coords.latitude ?? '',
            longitude: position?.coords.longitude ?? '',
        }));
        post(route('app.pest-control.visits.inspections.store', [visitId, point.id]), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant={inspection ? 'secondary' : 'default'}>
                    <ClipboardList className="h-4 w-4" />
                    {inspection ? 'Editar inspeção' : 'Inspecionar'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-[560px]">
                <DialogHeader>
                    <DialogTitle>
                        Ponto {point.code} {point.label ? `— ${point.label}` : ''}
                    </DialogTitle>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="flex items-center justify-between rounded-md border p-3">
                        <Label htmlFor={`not_inspected-${point.id}`}>Ponto não inspecionado</Label>
                        <Switch
                            id={`not_inspected-${point.id}`}
                            checked={data.not_inspected}
                            onCheckedChange={(checked: boolean) => setData('not_inspected', checked)}
                        />
                    </div>

                    {data.not_inspected ? (
                        <div className="grid gap-2">
                            <Label htmlFor="not_inspected_reason">Justificativa</Label>
                            <Textarea
                                id="not_inspected_reason"
                                value={data.not_inspected_reason}
                                onChange={(e) => setData('not_inspected_reason', e.target.value)}
                            />
                            <InputError message={errors.not_inspected_reason} />
                        </div>
                    ) : (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label>Produto utilizado</Label>
                                    <select
                                        className={selectClassName}
                                        value={data.product_id}
                                        onChange={(e) => setData('product_id', e.target.value)}
                                    >
                                        <option value="">Nenhum</option>
                                        {products?.map((product: any) => (
                                            <option key={product.id} value={product.id}>
                                                {product.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="grid gap-2">
                                    <Label>Tipo de consumo/isca</Label>
                                    <select
                                        className={selectClassName}
                                        value={data.consumption_type}
                                        onChange={(e) => setData('consumption_type', e.target.value)}
                                    >
                                        <option value="">Selecione</option>
                                        {consumptionTypes?.map((type: any) => (
                                            <option key={type.key} value={type.key}>
                                                {type.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label>Consumo (legenda)</Label>
                                    <select
                                        className={selectClassName}
                                        value={data.consumption_code}
                                        onChange={(e) => setData('consumption_code', e.target.value)}
                                    >
                                        <option value="">Selecione</option>
                                        <option value="0">0 — sem consumo</option>
                                        <option value="0.5">0,5 — até meio bloco/sachê</option>
                                        <option value="1">1 — consumo total, exige troca</option>
                                        <option value="E">E — produto estragado, exige troca</option>
                                    </select>
                                    <InputError message={errors.consumption_code} />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Condição do dispositivo</Label>
                                    <select
                                        className={selectClassName}
                                        value={data.device_condition}
                                        onChange={(e) => setData('device_condition', e.target.value)}
                                    >
                                        <option value="">Selecione</option>
                                        {deviceConditions?.map((condition: string) => (
                                            <option key={condition} value={condition}>
                                                {condition}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>

                            <div className="flex items-center justify-between rounded-md border p-3">
                                <Label htmlFor={`replaced-${point.id}`}>Troca realizada</Label>
                                <Switch
                                    id={`replaced-${point.id}`}
                                    checked={data.replaced}
                                    onCheckedChange={(checked: boolean) => setData('replaced', checked)}
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label>Vivos (total)</Label>
                                    <Input
                                        type="number"
                                        min={0}
                                        value={data.live_count}
                                        onChange={(e) => setData('live_count', Number(e.target.value))}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Mortos (total)</Label>
                                    <Input
                                        type="number"
                                        min={0}
                                        value={data.dead_count}
                                        onChange={(e) => setData('dead_count', Number(e.target.value))}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center justify-between">
                                    <Label>Pragas encontradas</Label>
                                    <Button type="button" size="sm" variant="outline" onClick={addSpeciesRow}>
                                        Adicionar
                                    </Button>
                                </div>
                                {data.species.map((row: any, index: number) => (
                                    <div key={index} className="grid grid-cols-[1fr_80px_80px_32px] items-center gap-2">
                                        <select
                                            className={selectClassName}
                                            value={row.species_id}
                                            onChange={(e) => updateSpeciesRow(index, 'species_id', e.target.value)}
                                        >
                                            <option value="">Espécie</option>
                                            {species?.map((item: any) => (
                                                <option key={item.id} value={item.id}>
                                                    {item.name}
                                                </option>
                                            ))}
                                        </select>
                                        <Input
                                            type="number"
                                            min={0}
                                            placeholder="Vivos"
                                            value={row.live_count}
                                            onChange={(e) => updateSpeciesRow(index, 'live_count', Number(e.target.value))}
                                        />
                                        <Input
                                            type="number"
                                            min={0}
                                            placeholder="Mortos"
                                            value={row.dead_count}
                                            onChange={(e) => updateSpeciesRow(index, 'dead_count', Number(e.target.value))}
                                        />
                                        <Button type="button" size="icon" variant="ghost" onClick={() => removeSpeciesRow(index)}>
                                            <XCircle className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>

                            <div className="grid gap-2">
                                <Label>Foto do ponto (opcional)</Label>
                                <Input type="file" accept="image/*" onChange={(e) => setData('photo', e.target.files?.[0] ?? null)} />
                                <InputError message={errors.photo} />
                            </div>
                        </>
                    )}

                    <div className="grid gap-2">
                        <Label>Observações</Label>
                        <Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            <Save className="h-4 w-4" />
                            Salvar inspeção
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function ShowPestControlVisit({ visit, products, species, consumptionTypes, deviceConditions }: any) {
    const { auth } = usePage<SharedData>().props;
    const permissions: string[] = (auth as any).pestControlPermissions ?? [];
    const canEdit = permissions.includes('pest_control.visits.edit');
    const canApprove = permissions.includes('pest_control.visits.approve');

    const [checkinJustification, setCheckinJustification] = useState('');
    const [checkoutSummary, setCheckoutSummary] = useState(visit.summary ?? '');
    const [signatureData, setSignatureData] = useState('');
    const signatureForm = useForm({
        responsible_name: '',
        responsible_role: '',
        responsible_document: '',
        compliance_text: 'Declaro estar ciente e de acordo com o serviço realizado conforme descrito nesta visita.',
        notes: '',
        signature: '',
        latitude: undefined as number | undefined,
        longitude: undefined as number | undefined,
    });
    const mediaForm = useForm({ file: null as File | null, caption: '' });

    const doCheckIn = async () => {
        const position = await currentPosition();
        router.patch(
            route('app.pest-control.visits.check-in', visit.id),
            {
                device_time: new Date().toISOString(),
                latitude: position?.coords.latitude,
                longitude: position?.coords.longitude,
                accuracy_meters: position?.coords.accuracy,
                justification: checkinJustification || undefined,
            },
            { preserveScroll: true },
        );
    };

    const doCheckOut = async () => {
        const position = await currentPosition();
        router.patch(
            route('app.pest-control.visits.check-out', visit.id),
            {
                device_time: new Date().toISOString(),
                latitude: position?.coords.latitude,
                longitude: position?.coords.longitude,
                accuracy_meters: position?.coords.accuracy,
                summary: checkoutSummary || undefined,
            },
            { preserveScroll: true },
        );
    };

    const doApprove = () => router.patch(route('app.pest-control.visits.approve', visit.id), {}, { preserveScroll: true });

    const doCancel = () => {
        const reason = window.prompt('Motivo do cancelamento (opcional):') ?? '';
        router.patch(route('app.pest-control.visits.cancel', visit.id), { reason }, { preserveScroll: true });
    };

    const submitSignature = async (event: any) => {
        event.preventDefault();
        if (!signatureData) return;
        const position = await currentPosition();
        signatureForm.transform((data) => ({
            ...data,
            signature: signatureData,
            latitude: position?.coords.latitude,
            longitude: position?.coords.longitude,
        }));
        signatureForm.post(route('app.pest-control.visits.signature.store', visit.id), {
            preserveScroll: true,
            onSuccess: () => {
                signatureForm.reset('responsible_name', 'responsible_role', 'responsible_document', 'notes');
                setSignatureData('');
            },
        });
    };

    const submitMedia = (event: any) => {
        event.preventDefault();
        mediaForm.post(route('app.pest-control.visits.media.store', visit.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => mediaForm.reset(),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: route('app.dashboard') },
        { title: 'Controle de Pragas', href: route('app.pest-control.index') },
        { title: 'Agenda de visitas', href: route('app.pest-control.visits.index') },
        { title: `Visita #${visit.id}`, href: '#' },
    ];

    const controlPoints = visit.establishment?.control_points ?? [];
    const inspectionByPoint: Record<number, any> = {};
    (visit.inspections ?? []).forEach((inspection: any) => {
        inspectionByPoint[inspection.control_point_id] = inspection;
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Visita #${visit.id}`} />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <ClipboardList className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Visita — {visit.establishment?.name}</h2>
                    <Badge variant="secondary">{statusLabels[visit.status] ?? visit.status}</Badge>
                </div>
            </div>

            <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                <Button asChild variant="default">
                    <Link href={route('app.pest-control.visits.index')}>
                        <ArrowLeft className="h-4 w-4" />
                        <span>Voltar</span>
                    </Link>
                </Button>

                {canEdit && visit.status !== 'canceled' && (
                    <div className="flex flex-wrap gap-2">
                        {!visit.checkin_at && (
                            <Button type="button" variant="secondary" onClick={doCheckIn}>
                                <LogIn className="h-4 w-4" />
                                Check-in
                            </Button>
                        )}
                        {visit.checkin_at && !visit.checkout_at && (
                            <Button type="button" variant="secondary" onClick={doCheckOut}>
                                <LogOut className="h-4 w-4" />
                                Check-out
                            </Button>
                        )}
                        {canApprove && ['completed', 'synced'].includes(visit.status) && (
                            <Button type="button" onClick={doApprove}>
                                <ShieldCheck className="h-4 w-4" />
                                Aprovar/Validar
                            </Button>
                        )}
                        <Button type="button" variant="destructive" onClick={doCancel}>
                            <XCircle className="h-4 w-4" />
                            Cancelar
                        </Button>
                    </div>
                )}
            </div>

            <div className="grid gap-4 p-4 xl:grid-cols-3">
                <section className="rounded-lg border p-4 xl:col-span-1">
                    <h3 className="mb-3 flex items-center gap-2 text-base font-semibold">
                        <MapPin className="h-4 w-4" /> Dados da visita
                    </h3>
                    <dl className="space-y-2 text-sm">
                        <div>
                            <dt className="text-muted-foreground">Estabelecimento</dt>
                            <dd>{visit.establishment?.name}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Técnico</dt>
                            <dd>{visit.technician?.name ?? 'Atendimento pessoal (ainda não atribuído)'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Agendamento</dt>
                            <dd>{moment(visit.scheduled_at).format('DD/MM/YYYY HH:mm')}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Tipo de serviço</dt>
                            <dd>{visit.service_type || '-'}</dd>
                        </div>
                        {visit.notes && (
                            <div>
                                <dt className="text-muted-foreground">Observações</dt>
                                <dd>{visit.notes}</dd>
                            </div>
                        )}
                    </dl>

                    {!visit.checkin_at && canEdit && (
                        <div className="mt-4 grid gap-2">
                            <Label>Justificativa de distância (se necessário)</Label>
                            <Textarea value={checkinJustification} onChange={(e) => setCheckinJustification(e.target.value)} />
                        </div>
                    )}

                    <div className="mt-4 space-y-2 border-t pt-3 text-sm">
                        {visit.checkin_at && (
                            <div className="flex items-center gap-2">
                                <LogIn className="h-4 w-4 text-muted-foreground" />
                                <span>Check-in: {moment(visit.checkin_at).format('DD/MM/YYYY HH:mm')}</span>
                                {visit.checkin_distance_meters !== null && (
                                    <Badge variant="outline">{Number(visit.checkin_distance_meters).toFixed(0)}m do local</Badge>
                                )}
                            </div>
                        )}
                        {visit.checkin_justification && <p className="text-xs text-muted-foreground">Justificativa: {visit.checkin_justification}</p>}
                        {visit.checkout_at && (
                            <div className="flex items-center gap-2">
                                <LogOut className="h-4 w-4 text-muted-foreground" />
                                <span>Check-out: {moment(visit.checkout_at).format('DD/MM/YYYY HH:mm')}</span>
                                {visit.duration_seconds !== null && <Badge variant="outline">{Math.round(visit.duration_seconds / 60)} min</Badge>}
                            </div>
                        )}
                        {visit.approved_by && (
                            <div className="flex items-center gap-2 text-green-700">
                                <CheckCircle2 className="h-4 w-4" />
                                <span>Validada por {visit.approved_by.name}</span>
                            </div>
                        )}
                        {visit.canceled_reason && <p className="text-xs text-destructive">Cancelamento: {visit.canceled_reason}</p>}
                    </div>

                    {visit.checkin_at && !visit.checkout_at && canEdit && (
                        <div className="mt-4 grid gap-2">
                            <Label>Resumo do check-out</Label>
                            <Textarea value={checkoutSummary} onChange={(e) => setCheckoutSummary(e.target.value)} />
                        </div>
                    )}
                </section>

                <section className="rounded-lg border p-4 xl:col-span-2">
                    <h3 className="mb-3 flex items-center gap-2 text-base font-semibold">
                        <ClipboardList className="h-4 w-4" /> Pontos de controle
                    </h3>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Código</TableHead>
                                <TableHead>Local</TableHead>
                                <TableHead>Situação</TableHead>
                                <TableHead className="text-right"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {controlPoints.length > 0 ? (
                                controlPoints.map((point: any) => {
                                    const inspection = inspectionByPoint[point.id];
                                    return (
                                        <TableRow key={point.id}>
                                            <TableCell>{point.code}</TableCell>
                                            <TableCell>{point.label || '-'}</TableCell>
                                            <TableCell>
                                                {inspection ? (
                                                    inspection.not_inspected ? (
                                                        <Badge variant="destructive">Não inspecionado</Badge>
                                                    ) : (
                                                        <Badge variant="secondary">
                                                            Inspecionado{inspection.consumption_code ? ` (${inspection.consumption_code})` : ''}
                                                        </Badge>
                                                    )
                                                ) : (
                                                    <Badge variant="outline">Pendente</Badge>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {canEdit && (
                                                    <InspectionDialog
                                                        visitId={visit.id}
                                                        point={point}
                                                        inspection={inspection}
                                                        products={products}
                                                        species={species}
                                                        consumptionTypes={consumptionTypes}
                                                        deviceConditions={deviceConditions}
                                                    />
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={4} className="h-16 text-center">
                                        Este estabelecimento não tem pontos de controle cadastrados.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </section>

                <section className="rounded-lg border p-4 xl:col-span-2">
                    <h3 className="mb-3 flex items-center gap-2 text-base font-semibold">
                        <Camera className="h-4 w-4" /> Evidências da visita
                    </h3>
                    <div className="mb-4 flex flex-wrap gap-3">
                        {(visit.media ?? []).map((media: any) => (
                            <a key={media.id} href={media.url} target="_blank" rel="noreferrer" className="block">
                                {media.type === 'photo' ? (
                                    <img src={media.url} alt={media.caption || 'Evidência'} className="h-20 w-20 rounded-md border object-cover" />
                                ) : (
                                    <div className="flex h-20 w-20 items-center justify-center rounded-md border">
                                        <Paperclip className="h-6 w-6" />
                                    </div>
                                )}
                            </a>
                        ))}
                        {(visit.media ?? []).length === 0 && <p className="text-sm text-muted-foreground">Nenhuma evidência anexada ainda.</p>}
                    </div>
                    {canEdit && (
                        <form onSubmit={submitMedia} className="flex flex-wrap items-end gap-3">
                            <div className="grid gap-2">
                                <Label>Arquivo</Label>
                                <Input
                                    type="file"
                                    accept="image/*,application/pdf"
                                    onChange={(e) => mediaForm.setData('file', e.target.files?.[0] ?? null)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label>Legenda</Label>
                                <Input value={mediaForm.data.caption} onChange={(e) => mediaForm.setData('caption', e.target.value)} />
                            </div>
                            <Button type="submit" disabled={mediaForm.processing || !mediaForm.data.file}>
                                Anexar
                            </Button>
                        </form>
                    )}
                </section>

                <section className="rounded-lg border p-4 xl:col-span-1">
                    <h3 className="mb-3 flex items-center gap-2 text-base font-semibold">
                        <PenLine className="h-4 w-4" /> Assinatura e aceite
                    </h3>

                    <div className="mb-4 space-y-2">
                        {(visit.signatures ?? []).map((signature: any) => (
                            <div key={signature.id} className="rounded-md border p-2 text-xs">
                                <div className="flex items-center justify-between">
                                    <span className="font-medium">
                                        v{signature.version} — {signature.responsible_name}
                                    </span>
                                    {!signature.superseded && <Badge variant="secondary">Vigente</Badge>}
                                </div>
                                <span className="text-muted-foreground">{moment(signature.signed_at).format('DD/MM/YYYY HH:mm')}</span>
                                {signature.signature_url && (
                                    <img src={signature.signature_url} alt="Assinatura" className="mt-1 h-12 border bg-white" />
                                )}
                            </div>
                        ))}
                        {(visit.signatures ?? []).length === 0 && <p className="text-sm text-muted-foreground">Nenhuma assinatura registrada.</p>}
                    </div>

                    {canEdit && (
                        <form onSubmit={submitSignature} className="space-y-3">
                            <div className="grid gap-2">
                                <Label>Nome do responsável</Label>
                                <Input
                                    value={signatureForm.data.responsible_name}
                                    onChange={(e) => signatureForm.setData('responsible_name', e.target.value)}
                                />
                                <InputError message={signatureForm.errors.responsible_name} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Função/cargo</Label>
                                <Input
                                    value={signatureForm.data.responsible_role}
                                    onChange={(e) => signatureForm.setData('responsible_role', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label>Documento (opcional)</Label>
                                <Input
                                    value={signatureForm.data.responsible_document}
                                    onChange={(e) => signatureForm.setData('responsible_document', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label>Texto de conformidade</Label>
                                <Textarea
                                    value={signatureForm.data.compliance_text}
                                    onChange={(e) => signatureForm.setData('compliance_text', e.target.value)}
                                />
                            </div>
                            <SignaturePad onCapture={setSignatureData} />
                            <InputError message={signatureForm.errors.signature} />
                            <Button type="submit" disabled={signatureForm.processing || !signatureData}>
                                <Save className="h-4 w-4" />
                                Registrar assinatura
                            </Button>
                        </form>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
