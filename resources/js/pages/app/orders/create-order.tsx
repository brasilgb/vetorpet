import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { maskMoney, maskMoneyDot } from '@/Utils/mask';
import { reactSelectThemeStyles } from '@/Utils/react-select-theme';
import { Head, Link, useForm } from '@inertiajs/react';
import { Switch } from '@/components/ui/switch';
import { AlertTriangle, ArrowLeft, ClipboardList, RotateCcw, ShoppingCartIcon, UserIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import Select from 'react-select';
import { OrderSummary } from './components/OrderSummary';
import { ProductSelector } from './components/ProductSelector';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('app.dashboard'),
    },
    {
        title: 'Pedidos',
        href: route('app.orders.index'),
    },
    {
        title: 'Adicionar',
        href: '#',
    },
];

const establishmentTypeLabels: Record<string, string> = {
    petshop: 'Petshop',
    clinica_veterinaria: 'Clínica veterinária',
    agropecuaria: 'Agropecuária',
    banho_tosa: 'Banho e tosa',
    distribuidor: 'Distribuidor',
    outro: 'Outro',
};

type OrderItem = {
    product_id: number;
    quantity: number;
    price: number;
    discount_amount: number;
    name: string;
    total: string;
};

export default function CreateOrder({ customers, products, campaigns, flex, selectedCustomerId, canManageSellers, sellers }: any) {
    const initialCustomer = customers.find((customer: any) => customer.id === selectedCustomerId) ?? null;
    const [selectedCustomer, setSelectedCustomer] = useState<any | null>(initialCustomer);
    const [items, setItems] = useState<OrderItem[]>([]);

    const customerOptions = customers.map((customer: any) => ({
        value: customer.id,
        label: customer.name,
        customer,
    }));

    const selectedCustomerOption = selectedCustomer
        ? {
              value: selectedCustomer.id,
              label: selectedCustomer.name,
              customer: selectedCustomer,
          }
        : null;

    const sellerOptions = (sellers ?? []).map((seller: any) => ({ value: seller.id, label: seller.name }));

    const { data, setData, post, processing, errors } = useForm({
        customer_id: initialCustomer?.id ?? '',
        user_id: initialCustomer?.user?.id ?? '',
        campaign_id: '',
        flex: '',
        discount: '',
        adjusted_total: '',
        adjusted_total_was_edited: false as boolean,
        total: '',
        payment_condition: initialCustomer?.commercial_condition?.payment_terms ?? '',
        notes: '',
        items: [] as OrderItem[],
        is_recurring: false as boolean,
    });

    const availableCampaigns = campaigns.filter(
        (campaign: any) => campaign.commercial_condition && (campaign.audience_type === 'all' || Number(campaign.region_id) === Number(selectedCustomer?.region_id)),
    );
    const selectedCampaign = availableCampaigns.find((campaign: any) => String(campaign.id) === String(data.campaign_id)) ?? null;
    const selectedCondition = selectedCampaign?.commercial_condition ?? selectedCustomer?.commercial_condition ?? null;
    const subtotal = useMemo(() => items.reduce((sum, item) => sum + Number(item.total), 0), [items]);
    const adjustedTotal = data.adjusted_total_was_edited ? Number(data.adjusted_total || 0) : subtotal;
    const manualDiscount = Number(data.discount || 0);
    const flexAmount = Math.max(adjustedTotal - subtotal, 0);
    const priceReduction = Math.max(subtotal - adjustedTotal, 0);
    const discountAmount = priceReduction + manualDiscount;
    const availableFlex = Number(flex?.value ?? 0);
    const resultingFlex = availableFlex + flexAmount - discountAmount;
    const insufficientFlex = resultingFlex < 0;
    const commercialTotal = Math.max(adjustedTotal - manualDiscount, 0);
    const discountPercentage = subtotal > 0 ? (discountAmount / subtotal) * 100 : 0;
    const maxDiscountPercentage = Number(selectedCondition?.max_discount_percentage ?? 0);
    const minimumOrderAmount = Number(selectedCondition?.minimum_order_amount ?? 0);
    const minimumOrderQuantity = Number(selectedCampaign?.commercial_condition?.minimum_order_quantity ?? 0);
    const campaignProductIds = new Set((selectedCampaign?.products ?? []).map((product: any) => Number(product.id)));
    const campaignQuantity = items.reduce((sum, item) => sum + (campaignProductIds.has(Number(item.product_id)) ? item.quantity : 0), 0);
    const belowMinimum = selectedCampaign ? campaignQuantity < minimumOrderQuantity : commercialTotal < minimumOrderAmount;
    const commissionAmount = commercialTotal * (Number(selectedCondition?.commission_percentage ?? 0) / 100);
    const latestOrder = selectedCustomer?.latest_order;
    const pricedProducts = useMemo(
        () =>
            products.map((product: any) => {
                // Um preço especial Produto x Região ativo substitui qualquer outra regra
                // (campanha, condição comercial de cliente/região/tipo/global) — mesma
                // prioridade aplicada pelo RegionalPriceResolver no backend.
                const specialPrice = (product.special_prices ?? []).find(
                    (row: any) => Number(row.region_id) === Number(selectedCustomer?.region_id),
                );

                if (specialPrice) {
                    return {
                        ...product,
                        price: Number(Number(specialPrice.special_price).toFixed(2)),
                        base_price: product.price,
                    };
                }

                const isCampaignProduct = selectedCampaign?.products?.some((campaignProduct: any) => campaignProduct.id === product.id);
                const priceCondition = isCampaignProduct ? selectedCampaign?.commercial_condition : selectedCustomer?.commercial_condition;
                const adjustment = Number(priceCondition?.price_adjustment_percentage ?? 0);
                const price = Number(product.price) * (1 + adjustment / 100);

                return {
                    ...product,
                    price: Number(price.toFixed(2)),
                    base_price: product.price,
                };
            }),
        [products, selectedCampaign, selectedCustomer],
    );

    useEffect(() => {
        setData((currentData: any) => ({
            ...currentData,
            items,
            adjusted_total: subtotal.toFixed(2),
            adjusted_total_was_edited: false,
        }));
    }, [items, subtotal]);

    useEffect(() => {
        setData('total', commercialTotal.toFixed(2));
    }, [commercialTotal]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        post(route('app.orders.store'));
    };

    const handleProductAdd = (product: any, quantity: number, adjustmentAmount: number) => {
        const calculateTotal = (amount: number) => Math.max(Number(product.price) * amount + adjustmentAmount, 0).toFixed(2);
        setItems((prevItems) => {
            const existingItem = prevItems.find((item) => item.product_id === product.id);

            if (existingItem) {
                return prevItems.map((item) =>
                    item.product_id === product.id
                        ? {
                              ...item,
                              quantity: item.quantity + quantity,
                              price: Number(product.price),
                              discount_amount: adjustmentAmount,
                              total: calculateTotal(item.quantity + quantity),
                          }
                        : item,
                );
            }

            return [
                ...prevItems,
                {
                    product_id: product.id,
                    quantity,
                    price: Number(product.price),
                    discount_amount: adjustmentAmount,
                    name: product.name,
                    total: calculateTotal(quantity),
                },
            ];
        });
    };

    const handleProductRemove = (productId: number) => {
        setItems((prevItems) => prevItems.filter((item) => item.product_id !== productId));
    };

    const changeCustomer = (selected: any) => {
        const customer = selected?.customer ?? null;

        setSelectedCustomer(customer);
        setItems([]);
        setData((currentData: any) => ({
            ...currentData,
            customer_id: customer?.id ?? '',
            user_id: customer?.user?.id ?? '',
            campaign_id: '',
            items: [],
            total: '',
            payment_condition: customer?.commercial_condition?.payment_terms ?? '',
        }));
    };

    const changeCampaign = (campaignId: string) => {
        const campaign = availableCampaigns.find((item: any) => String(item.id) === campaignId);

        setItems([]);
        setData((currentData: any) => ({
            ...currentData,
            campaign_id: campaignId,
            items: [],
            payment_condition: campaign?.commercial_condition?.payment_terms ?? selectedCustomer?.commercial_condition?.payment_terms ?? '',
        }));
    };

    const repeatLatestOrder = () => {
        const lastItems = latestOrder?.order_items ?? [];

        setItems(
            lastItems
                .map((item: any) => {
                    const product = pricedProducts.find((currentProduct: any) => currentProduct.id === item.product_id) ?? item.product;

                    if (!product) {
                        return null;
                    }

                    return {
                        product_id: product.id,
                        quantity: Number(item.quantity),
                        price: Number(item.price ?? product.price),
                        discount_amount: Number(item.discount_percentage ?? 0) > 0
                            ? -Math.abs(Number(item.discount_amount ?? 0))
                            : Number(item.discount_amount ?? 0),
                        name: product.name,
                        total: Math.max(Number(item.price ?? product.price) * Number(item.quantity) + (Number(item.discount_percentage ?? 0) > 0 ? -Math.abs(Number(item.discount_amount ?? 0)) : Number(item.discount_amount ?? 0)), 0).toFixed(2),
                    };
                })
                .filter(Boolean),
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pedidos" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <ShoppingCartIcon className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Pedidos</h2>
                </div>
            </div>

            <div className="flex items-center justify-between p-4">
                <Button variant="default" asChild>
                    <Link href={route('app.orders.index')}>
                        <ArrowLeft className="h-4 w-4" />
                        <span>Voltar</span>
                    </Link>
                </Button>
            </div>

            <div className="p-4">
                <form onSubmit={handleSubmit} className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <UserIcon className="h-5 w-5" />
                                Cliente
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Select
                                options={customerOptions}
                                value={selectedCustomerOption}
                                onChange={changeCustomer}
                                placeholder="Selecione o cliente"
                                className="rounded-md border border-input text-sm text-foreground shadow-xs"
                                styles={reactSelectThemeStyles}
                            />
                            <InputError className="mt-2" message={errors.customer_id} />

                            {selectedCustomer && availableCampaigns.length > 0 && (
                                <div className="grid gap-2">
                                    <Label htmlFor="campaign_id">Campanha</Label>
                                    <select
                                        id="campaign_id"
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                        value={data.campaign_id}
                                        onChange={(event) => changeCampaign(event.target.value)}
                                    >
                                        <option value="">Sem campanha</option>
                                        {availableCampaigns.map((campaign: any) => (
                                            <option key={campaign.id} value={campaign.id}>{campaign.name}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.campaign_id} />
                                    {selectedCampaign && <div className="text-xs text-muted-foreground">Os produtos da campanha usarão seus valores promocionais.</div>}
                                </div>
                            )}

                            {canManageSellers && sellerOptions.length > 0 && (
                                <div className="grid gap-2">
                                    <Label htmlFor="user_id">Vendedor responsável</Label>
                                    <Select
                                        inputId="user_id"
                                        options={sellerOptions}
                                        value={sellerOptions.find((option: any) => String(option.value) === String(data.user_id)) ?? null}
                                        onChange={(selected: any) => setData('user_id', selected?.value ?? '')}
                                        placeholder="Selecione o vendedor"
                                        isClearable
                                        className="rounded-md border border-input text-sm text-foreground shadow-xs"
                                        styles={reactSelectThemeStyles}
                                    />
                                    <InputError className="mt-2" message={errors.user_id} />
                                    <div className="text-xs text-muted-foreground">
                                        O pedido aparecerá no app do vendedor selecionado. Se nenhum for escolhido, o pedido fica com você.
                                    </div>
                                </div>
                            )}

                            {selectedCustomer && (
                                <div className="grid gap-3 rounded-md border p-3 text-sm md:grid-cols-4">
                                    <div>
                                        <div className="text-muted-foreground">Perfil</div>
                                        <div className="font-medium">
                                            {establishmentTypeLabels[selectedCustomer.establishment_type] ?? 'Tipo não informado'}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground">Região</div>
                                        <div className="font-medium">{selectedCustomer.region?.name ?? '-'}</div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground">Visita</div>
                                        <div className="font-medium">
                                            {[selectedCustomer.preferred_visit_days, selectedCustomer.preferred_visit_time]
                                                .filter(Boolean)
                                                .join(' | ') || 'Sem preferência'}
                                        </div>
                                    </div>
                                    <div className="flex items-center justify-start md:justify-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={repeatLatestOrder}
                                            disabled={!latestOrder?.order_items?.length}
                                            className="w-full md:w-auto"
                                        >
                                            <RotateCcw className="h-4 w-4" />
                                            Repetir último pedido
                                        </Button>
                                    </div>
                                    {latestOrder && (
                                        <div className="text-muted-foreground md:col-span-4">
                                            Último pedido #{latestOrder.order_number} com {latestOrder.order_items?.length ?? 0} item(ns).
                                        </div>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <ProductSelector products={pricedProducts} onAddProduct={handleProductAdd} />
                    <Card><CardContent className="flex items-center justify-between pt-6"><div><Label>Pedido recorrente mensal</Label><p className="text-sm text-muted-foreground">Lembrar a próxima entrega em um mês.</p></div><Switch checked={data.is_recurring} onCheckedChange={(checked) => setData('is_recurring', checked)} /></CardContent></Card>
                    <OrderSummary items={items} onRemoveItem={handleProductRemove} />

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <ClipboardList className="h-5 w-5" />
                                Resumo comercial
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {selectedCondition && (
                                <div className="mb-4 grid gap-3 rounded-md border p-3 text-sm md:grid-cols-5">
                                    <div>
                                        <div className="text-muted-foreground">Condição</div>
                                        <div className="font-medium">{selectedCondition.name}</div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground">Desconto máximo</div>
                                        <div className="font-medium">{Number(selectedCondition.max_discount_percentage).toFixed(2)}%</div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground">{selectedCampaign ? 'Quantidade mínima' : 'Pedido mínimo'}</div>
                                        <div className="font-medium">{selectedCampaign ? `${minimumOrderQuantity} unidade(s)` : `R$ ${maskMoney(selectedCondition.minimum_order_amount)}`}</div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground">Prazo</div>
                                        <div className="font-medium">{selectedCondition.payment_terms || 'Não definido'}</div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground">Comissão prevista</div>
                                        <div className="font-medium">R$ {maskMoney(commissionAmount.toFixed(2))}</div>
                                    </div>
                                </div>
                            )}

                            {((discountPercentage > maxDiscountPercentage || belowMinimum) && selectedCondition) || insufficientFlex ? (
                                <div className="mb-4 flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                    <div>
                                        {selectedCondition && discountPercentage > maxDiscountPercentage && (
                                            <div>Desconto atual de {discountPercentage.toFixed(2)}% acima do limite permitido.</div>
                                        )}
                                        {selectedCondition && belowMinimum && (
                                            <div>{selectedCampaign ? `Quantidade mínima não atingida. Faltam ${minimumOrderQuantity - campaignQuantity} unidade(s).` : 'Total comercial abaixo do pedido mínimo desta condição.'}</div>
                                        )}
                                        {insufficientFlex && <div>O desconto excede o saldo Flex disponível.</div>}
                                    </div>
                                </div>
                            ) : null}

                            <div className="grid gap-4 md:grid-cols-6">
                                <div className="flex flex-col justify-center gap-1">
                                    <Label>Flex disponível{flex?.is_admin_override && ' (Universal)'}</Label>
                                    <Badge variant="default" className="w-fit text-base">
                                        R$ {maskMoney(flex ? flex?.value : '0.00')}
                                    </Badge>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="adjusted_total">Valor ajustado</Label>
                                    <Input
                                        type="text"
                                        id="adjusted_total"
                                        value={maskMoney(data.adjusted_total)}
                                        onChange={(e) => setData((currentData: any) => ({
                                            ...currentData,
                                            adjusted_total: maskMoneyDot(e.target.value) ?? '',
                                            adjusted_total_was_edited: true,
                                        }))}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="discount">Desconto manual</Label>
                                    <Input
                                        type="text"
                                        id="discount"
                                        value={maskMoney(data.discount)}
                                        onChange={(e) => setData('discount', maskMoneyDot(e.target.value) ?? '')}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="payment_condition">Pagamento</Label>
                                    <Input
                                        type="text"
                                        id="payment_condition"
                                        value={data.payment_condition}
                                        onChange={(e) => setData('payment_condition', e.target.value)}
                                        placeholder="Ex.: 28/35 dias"
                                    />
                                </div>
                                <div className="grid gap-2 md:col-span-3">
                                    <Label htmlFor="notes">Observações</Label>
                                    <Textarea
                                        id="notes"
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        placeholder="Observações do pedido, combinados ou instruções de entrega"
                                    />
                                    <InputError message={errors.notes} />
                                </div>
                                <div className="flex flex-col justify-center gap-1">
                                    <span className="text-sm text-muted-foreground">Subtotal</span>
                                    <strong>R$ {maskMoney(subtotal.toFixed(2))}</strong>
                                </div>
                                <div className="flex flex-col justify-center gap-1">
                                    <span className="text-sm text-muted-foreground">Valor total</span>
                                    <strong>R$ {maskMoney(commercialTotal.toFixed(2))}</strong>
                                </div>
                                <div className="flex flex-col justify-center gap-1">
                                    <span className="text-sm text-muted-foreground">Flex gerado</span>
                                    <strong>R$ {maskMoney(flexAmount.toFixed(2))}</strong>
                                </div>
                                <div className="flex flex-col justify-center gap-1">
                                    <span className="text-sm text-muted-foreground">Ajustes/Descontos</span>
                                    <strong>R$ {maskMoney(discountAmount.toFixed(2))}</strong>
                                </div>
                                <div className="flex flex-col justify-center gap-1">
                                    <span className="text-sm text-muted-foreground">Saldo após o pedido</span>
                                    <strong className={insufficientFlex ? 'text-red-600' : ''}>R$ {maskMoney(Math.max(resultingFlex, 0).toFixed(2))}</strong>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing || items.length === 0 || insufficientFlex || belowMinimum}>
                            Finalizar pedido
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
