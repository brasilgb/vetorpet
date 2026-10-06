import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { FlaskConical, Sparkles, Trash2 } from 'lucide-react';
import { useState } from 'react';

export type SampleDataState = { active: boolean; canCreate: boolean } | null;

/**
 * Aviso de dados de exemplo da avaliação: permite criar (conta nova e vazia)
 * ou remover os registros fictícios sem afetar os dados reais.
 */
export default function SampleDataNotice({ state }: { state: SampleDataState }) {
    const [processing, setProcessing] = useState(false);

    if (!state || (!state.active && !state.canCreate)) {
        return null;
    }

    const options = { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) };

    if (!state.active) {
        return (
            <div className="flex flex-col gap-3 rounded-lg border border-dashed p-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex gap-3">
                    <Sparkles className="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                    <div>
                        <div className="font-medium">Quer conhecer o sistema com dados de exemplo?</div>
                        <div className="text-sm text-muted-foreground">
                            Criamos clientes, produtos, pedidos e visitas fictícios só na sua conta. Você remove tudo com um clique quando quiser.
                        </div>
                    </div>
                </div>
                <Button className="shrink-0" disabled={processing} onClick={() => router.post(route('app.sample-data.store'), {}, options)}>
                    <Sparkles className="h-4 w-4" />
                    Criar dados de exemplo
                </Button>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sky-950 sm:flex-row sm:items-center sm:justify-between dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100">
            <div className="flex gap-3">
                <FlaskConical className="mt-0.5 h-5 w-5 shrink-0 text-sky-600" />
                <div>
                    <div className="font-medium">Você está vendo dados de exemplo</div>
                    <div className="text-sm opacity-80">
                        Registros marcados com “(exemplo)” são fictícios e não geram cobranças, mensagens ou integrações. Remova-os antes de usar a conta para valer.
                    </div>
                </div>
            </div>
            <AlertDialog>
                <AlertDialogTrigger asChild>
                    <Button variant="outline" className="shrink-0" disabled={processing}>
                        <Trash2 className="h-4 w-4" />
                        Remover dados de exemplo
                    </Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remover dados de exemplo?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Somente os registros fictícios serão apagados. Seus cadastros reais não são afetados; um registro de exemplo que estiver em uso por um dado
                            real será mantido.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction onClick={() => router.delete(route('app.sample-data.destroy'), options)}>Remover</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
