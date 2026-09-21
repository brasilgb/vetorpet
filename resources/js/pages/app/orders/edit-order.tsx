import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { maskMoney, maskSignedMoney, signedMoneyToNumber } from '@/Utils/mask';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CircleHelp, Plus, Printer, Save, Trash2 } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('app.dashboard') },
    { title: 'Pedidos', href: route('app.orders.index') },
    { title: 'Editar pedido', href: '#' },
];

function AdjustmentLabel() {
    return <div className="flex items-center gap-1.5"><Label>Ajuste individual (R$)</Label><Tooltip><TooltipTrigger asChild><button type="button" aria-label="Ajuda sobre o ajuste individual" className="text-muted-foreground hover:text-foreground"><CircleHelp className="h-4 w-4" /></button></TooltipTrigger><TooltipContent>Negativo dá desconto; positivo acrescenta.</TooltipContent></Tooltip></div>;
}

function MaskedAdjustmentInput({ value, onChange, disabled = false }: { value: string | number; onChange: (value: number | string) => void; disabled?: boolean }) {
    const rawValue = String(value ?? '');

    return <Input disabled={disabled} type="text" inputMode="decimal" placeholder="R$ 0,00" value={maskSignedMoney(rawValue)} onChange={(event) => {
        const signedDigits = `${event.target.value.includes('-') ? '-' : ''}${event.target.value.replace(/\D/g, '')}`;
        onChange(typeof value === 'number' ? signedMoneyToNumber(signedDigits) : signedDigits);
    }} />;
}

export default function EditOrder({ order, customers, products, flex }: any) {
    const canceled = String(order.status) === '4';
    const { data, setData, put, processing, errors } = useForm({
        customer_id: String(order.customer_id),
        items: order.order_items.map((item: any) => ({
            product_id: Number(item.product_id),
            quantity: Number(item.quantity),
            discount_amount: Number(item.discount_percentage ?? 0) > 0 ? -Math.abs(Number(item.discount_amount ?? 0)) : Number(item.discount_amount ?? 0),
        })),
        adjusted_total: Number(order.adjusted_total ?? order.subtotal ?? order.total).toFixed(2),
        discount: Math.max(Number(order.adjusted_total ?? order.total) - Number(order.total), 0).toFixed(2),
        payment_condition: order.payment_condition ?? '',
        notes: order.notes ?? '',
    });
    const [productId, setProductId] = useState('');
    const [productSearch, setProductSearch] = useState('');
    const [quantity, setQuantity] = useState('1');
    const [itemDiscount, setItemDiscount] = useState('');
    const selectedCustomer = customers.find((customer: any) => String(customer.id) === data.customer_id);
    const adjustment = Number(selectedCustomer?.commercial_condition?.price_adjustment_percentage ?? 0);
    const rows = useMemo(() => data.items.map((item: any) => {
        const product = products.find((candidate: any) => Number(candidate.id) === item.product_id);
        // Um preço especial Produto x Região ativo substitui a condição comercial — mesma
        // prioridade aplicada pelo RegionalPriceResolver no backend, que recalcula o preço
        // real ao salvar; aqui é só para exibir a prévia correta.
        const specialPrice = (product?.special_prices ?? []).find(
            (row: any) => Number(row.region_id) === Number(selectedCustomer?.region_id),
        );
        const price = specialPrice
            ? Number(specialPrice.special_price)
            : Math.round(Number(product?.price ?? 0) * (1 + adjustment / 100) * 100) / 100;
        return { ...item, product, price, total: Math.max(price * item.quantity + Number(item.discount_amount ?? 0), 0) };
    }), [adjustment, data.items, products, selectedCustomer]);
    const subtotal = rows.reduce((sum: number, item: any) => sum + item.total, 0);
    const payable = Math.max(Number(data.adjusted_total || 0) - Number(data.discount || 0), 0);
    const filteredProducts = products.filter((product: any) => {
        const term = productSearch.trim().toLocaleLowerCase('pt-BR');
        return !term || product.name?.toLocaleLowerCase('pt-BR').includes(term) || product.reference?.toLocaleLowerCase('pt-BR').includes(term);
    });

    const addProduct = () => {
        const id = Number(productId);
        const amount = Math.max(Number(quantity), 1);
        const adjustmentAmount = signedMoneyToNumber(itemDiscount);
        if (!id) return;
        const current = data.items.find((item: any) => item.product_id === id);
        setData('items', current
            ? data.items.map((item: any) => item.product_id === id ? { ...item, quantity: item.quantity + amount, discount_amount: adjustmentAmount } : item)
            : [...data.items, { product_id: id, quantity: amount, discount_amount: adjustmentAmount }]);
        setProductId('');
        setProductSearch('');
        setQuantity('1');
        setItemDiscount('');
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('app.orders.update', order.id));
    };

    return <AppLayout breadcrumbs={breadcrumbs}>
        <Head title={`Editar pedido #${order.order_number}`} />
        <form onSubmit={submit} className="flex flex-col gap-4 p-4">
            <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div><h1 className="text-2xl font-semibold">Editar pedido #{order.order_number}</h1><p className="text-sm text-muted-foreground">Altere o cliente, os itens e os valores do pedido.</p></div>
                <div className="flex flex-wrap gap-2"><Button asChild type="button" variant="outline"><Link href={route('app.orders.index')}><ArrowLeft className="h-4 w-4" />Voltar</Link></Button><Button asChild type="button" variant="outline"><a href={route('app.orders.print', order.id)} target="_blank" rel="noreferrer"><Printer className="h-4 w-4" />Imprimir pedido</a></Button><Button type="submit" disabled={processing || canceled || !data.items.length}><Save className="h-4 w-4" />{processing ? 'Salvando...' : 'Salvar alterações'}</Button></div>
            </div>
            {canceled && <div className="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">Pedidos cancelados ficam disponíveis apenas para consulta.</div>}

            <Card><CardHeader><CardTitle className="text-base">Cliente e condição</CardTitle></CardHeader><CardContent className="grid gap-4 md:grid-cols-2"><div className="grid gap-2"><Label>Cliente</Label><select disabled={canceled} value={data.customer_id} onChange={(e) => setData('customer_id', e.target.value)} className="h-9 rounded-md border bg-transparent px-3 text-sm">{customers.map((customer: any) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</select><InputError message={errors.customer_id} /></div><div className="grid gap-2"><Label>Condição de pagamento</Label><Input disabled={canceled} value={data.payment_condition} onChange={(e) => setData('payment_condition', e.target.value)} /></div><div className="grid gap-2 md:col-span-2"><Label htmlFor="notes">Observações</Label><Textarea id="notes" disabled={canceled} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Observações do pedido, combinados ou instruções de entrega" /><InputError message={errors.notes} /></div></CardContent></Card>

            <Card><CardHeader><CardTitle className="text-base">Itens do pedido</CardTitle></CardHeader><CardContent className="space-y-4">
                {!canceled && <div className="grid items-end gap-3 rounded-lg border p-3 md:grid-cols-[minmax(0,1fr)_120px_180px_auto]"><div className="grid min-w-0 gap-2"><Label>Produto</Label><Input value={productSearch} onChange={(e) => setProductSearch(e.target.value)} placeholder="Pesquise por referência ou nome" /><select value={productId} onChange={(e) => setProductId(e.target.value)} className="h-9 rounded-md border bg-transparent px-3 text-sm"><option value="">{filteredProducts.length ? 'Selecione o produto...' : 'Nenhum produto encontrado'}</option>{filteredProducts.map((product: any) => <option key={product.id} value={product.id}>{product.reference} · {product.name} · R$ {maskMoney(product.price)}</option>)}</select></div><div className="grid gap-2"><Label>Quantidade</Label><Input type="number" min="1" value={quantity} onChange={(e) => setQuantity(e.target.value)} /></div><div className="grid gap-2"><AdjustmentLabel /><MaskedAdjustmentInput value={itemDiscount} onChange={(value) => setItemDiscount(String(value))} /></div><Button type="button" onClick={addProduct} disabled={!productId} className="w-full whitespace-nowrap bg-emerald-600 text-white hover:bg-emerald-700 md:w-auto"><Plus className="h-4 w-4" />Adicionar ao pedido</Button></div>}
                <div className="overflow-x-auto rounded-lg border"><Table><TableHeader><TableRow><TableHead>Produto</TableHead><TableHead className="w-32">Quantidade</TableHead><TableHead className="w-40">Ajuste (R$)</TableHead><TableHead>Unitário</TableHead><TableHead>Total</TableHead><TableHead className="w-16" /></TableRow></TableHeader><TableBody>{rows.map((item: any) => <TableRow key={item.product_id}><TableCell><div className="font-medium">{item.product?.name ?? 'Produto indisponível'}</div><div className="text-xs text-muted-foreground">Ref. {item.product?.reference ?? '-'}</div></TableCell><TableCell><Input disabled={canceled} type="number" min="1" value={item.quantity} onChange={(e) => setData('items', data.items.map((current: any) => current.product_id === item.product_id ? { ...current, quantity: Math.max(Number(e.target.value), 1) } : current))} /></TableCell><TableCell><MaskedAdjustmentInput disabled={canceled} value={item.discount_amount} onChange={(value) => setData('items', data.items.map((current: any) => current.product_id === item.product_id ? { ...current, discount_amount: Number(value) } : current))} /></TableCell><TableCell>R$ {maskMoney(item.price)}</TableCell><TableCell className="font-medium">R$ {maskMoney(item.total)}</TableCell><TableCell>{!canceled && <Button type="button" size="icon" variant="ghost" onClick={() => setData('items', data.items.filter((current: any) => current.product_id !== item.product_id))}><Trash2 className="h-4 w-4 text-destructive" /></Button>}</TableCell></TableRow>)}</TableBody></Table></div><InputError message={errors.items} />
            </CardContent></Card>

            <Card><CardHeader><CardTitle className="text-base">Valores</CardTitle></CardHeader><CardContent className="grid gap-4 md:grid-cols-4"><Value label="Subtotal recalculado" value={`R$ ${maskMoney(subtotal)}`} /><div className="grid gap-2"><Label>Total ajustado</Label><Input disabled={canceled} type="number" min="0" step="0.01" value={data.adjusted_total} onChange={(e) => setData('adjusted_total', e.target.value)} /><InputError message={errors.adjusted_total} /></div><div className="grid gap-2"><Label>Desconto manual</Label><Input disabled={canceled} type="number" min="0" step="0.01" value={data.discount} onChange={(e) => setData('discount', e.target.value)} /></div><Value label="Total do pedido" value={`R$ ${maskMoney(payable)}`} emphasis /></CardContent><CardContent className="flex flex-wrap gap-2 border-t pt-4 text-sm text-muted-foreground"><Badge variant="secondary">{flex?.is_admin_override ? 'Flex Universal' : 'Saldo Flex'}: R$ {maskMoney(flex?.value ?? 0)}</Badge><span>O estoque e o Flex anteriores serão estornados e recalculados ao salvar.</span></CardContent></Card>
        </form>
    </AppLayout>;
}

function Value({ label, value, emphasis = false }: { label: string; value: string; emphasis?: boolean }) { return <div className="rounded-lg border p-3"><div className="text-sm text-muted-foreground">{label}</div><div className={`mt-2 text-xl font-semibold ${emphasis ? 'text-emerald-600' : ''}`}>{value}</div></div>; }
