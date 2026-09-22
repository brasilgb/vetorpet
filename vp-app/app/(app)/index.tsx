import { useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAuth } from '@/lib/auth';
import { onAgendaChange } from '@/lib/pest-control/agenda-watch';
import { fetchAgenda, fetchVisitDetail } from '@/lib/pest-control/api';
import {
  getCachedVisitDetail,
  listCachedAgenda,
  listConflictedInspections,
  listPendingCheckins,
  listPendingCheckouts,
  listPendingSignatures,
  replaceAgenda,
  saveVisitDetail,
} from '@/lib/pest-control/db';
import { seedInspectionsFromVisitDetail } from '@/lib/pest-control/inspections';
import { addressLine, openInMaps } from '@/lib/pest-control/maps';
import { syncNow } from '@/lib/pest-control/sync';
import type { AgendaVisit } from '@/lib/pest-control/types';

const STATUS_LABELS: Record<string, string> = {
  scheduled: 'Agendada',
  draft: 'Rascunho',
  in_progress: 'Em andamento',
  completed: 'Concluída',
  synced: 'Sincronizada',
  validated: 'Validada',
  canceled: 'Cancelada',
};

function formatScheduledAt(iso: string): string {
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));
}

/** Rótulo do botão principal do card, de acordo com o andamento do atendimento (ver visita/[uuid]/index.tsx). */
function visitActionLabel(visit: AgendaVisit): string {
  if (visit.checkout_at) return 'Ver atendimento';
  if (visit.checkin_at) return 'Continuar atendimento';
  return 'Iniciar atendimento';
}

/** Agenda de visitas do técnico (Etapa 2 do app-tecnico.md): lista offline-first e download por visita. */
export default function AgendaScreen() {
  const { user, logout } = useAuth();
  const router = useRouter();

  const [visits, setVisits] = useState<AgendaVisit[]>([]);
  const [downloadedUuids, setDownloadedUuids] = useState<Set<string>>(new Set());
  const [pendingSyncUuids, setPendingSyncUuids] = useState<Set<string>>(new Set());
  const [refreshing, setRefreshing] = useState(false);
  const [downloadingUuid, setDownloadingUuid] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [newVisitsCount, setNewVisitsCount] = useState(0);

  const loadDownloadedFlags = useCallback(async (list: AgendaVisit[]) => {
    const flags = await Promise.all(
      list.map(async (visit) => [visit.uuid, (await getCachedVisitDetail(visit.uuid)) !== null] as const),
    );
    setDownloadedUuids(new Set(flags.filter(([, downloaded]) => downloaded).map(([uuid]) => uuid)));
  }, []);

  const loadPendingSyncFlags = useCallback(async () => {
    const [checkins, checkouts, signatures, conflicts] = await Promise.all([
      listPendingCheckins(),
      listPendingCheckouts(),
      listPendingSignatures(),
      listConflictedInspections(),
    ]);
    const uuids = [...checkins, ...checkouts, ...signatures, ...conflicts].map((item) => item.visitUuid);
    setPendingSyncUuids(new Set(uuids));
  }, []);

  // Mostra o que já está no aparelho imediatamente, sem esperar a rede.
  useEffect(() => {
    (async () => {
      const cached = await listCachedAgenda();
      setVisits(cached);
      await loadDownloadedFlags(cached);
      await loadPendingSyncFlags();
    })();
  }, [loadDownloadedFlags, loadPendingSyncFlags]);

  const refresh = useCallback(async () => {
    setRefreshing(true);
    setError(null);

    // Reenvia tudo o que foi feito offline antes de buscar a agenda nova
    // (mesmo agendador da sincronização automática — ver lib/pest-control/sync.ts).
    await syncNow();
    await loadPendingSyncFlags();

    try {
      const page = await fetchAgenda();
      await replaceAgenda(page.data);
      setVisits(page.data);
      await loadDownloadedFlags(page.data);
    } catch {
      // Sem internet ou servidor indisponível: fica com o que já tinha em cache.
      setError('Não foi possível atualizar a agenda agora. Mostrando os dados salvos no aparelho.');
    } finally {
      setRefreshing(false);
    }
  }, [loadDownloadedFlags, loadPendingSyncFlags]);

  useEffect(() => {
    // Busca inicial na rede ao abrir a agenda; a tela já mostrou o cache local acima.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void refresh();
  }, [refresh]);

  // Escuta em segundo plano (agenda-watch.ts): quando o poller encontra visita
  // nova, só acende o indicador no botão "Atualizar" — não mexe na lista
  // sozinho, pra tela não pular enquanto o técnico está usando.
  useEffect(() => {
    return onAgendaChange((_updatedVisits, newVisits) => {
      setNewVisitsCount((prev) => prev + newVisits.length);
    });
  }, []);

  const handleUpdate = useCallback(async () => {
    await refresh();
    setNewVisitsCount(0);
  }, [refresh]);

  const downloadDetail = useCallback(async (visit: AgendaVisit) => {
    setDownloadingUuid(visit.uuid);

    try {
      const detail = await fetchVisitDetail(visit.uuid);
      await saveVisitDetail(visit.uuid, detail);
      await seedInspectionsFromVisitDetail(visit.uuid, detail);
      setDownloadedUuids((prev) => new Set(prev).add(visit.uuid));
    } catch {
      setError('Não foi possível baixar os dados desta visita. Tente novamente com internet disponível.');
    } finally {
      setDownloadingUuid(null);
    }
  }, []);

  return (
    <SafeAreaView className="flex-1 bg-green-50" edges={['top', 'bottom']}>
      <View className="mx-5 mb-4 rounded-3xl bg-green-950 p-5">
        <View>
          <Text className="text-sm font-medium text-green-300">Olá, {user?.name}</Text>
          <Text className="mt-1 text-2xl font-bold text-white">Agenda</Text>
          <Text className="mt-1 text-sm text-green-100/70">Visitas técnicas e dados disponíveis offline.</Text>
        </View>
        <View className="mt-4 flex-row gap-2">
          <Pressable
            onPress={handleUpdate}
            disabled={refreshing}
            className="min-h-11 flex-1 flex-row items-center justify-center gap-1.5 rounded-xl bg-green-600 px-3 disabled:opacity-50"
          >
            {refreshing ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <>
                <Text className="text-sm font-semibold text-white">Atualizar</Text>
                {newVisitsCount > 0 ? <View className="h-2 w-2 rounded-full bg-amber-400" /> : null}
              </>
            )}
          </Pressable>
          <Pressable
            onPress={() => router.push('/sincronizacao')}
            className="min-h-11 flex-1 items-center justify-center rounded-xl bg-green-600 px-3"
          >
            <Text className="text-sm font-semibold text-white">Sincronizar</Text>
          </Pressable>
          <Pressable
            onPress={() => logout()}
            className="min-h-11 items-center justify-center rounded-xl border border-white/20 px-5"
          >
            <Text className="text-sm font-medium text-white">Sair</Text>
          </Pressable>
        </View>
      </View>

      {error ? (
        <View className="mx-5 mb-3 rounded-2xl border border-amber-100 bg-amber-50 p-4">
          <Text className="text-sm text-amber-800">{error}</Text>
        </View>
      ) : null}

      <FlatList
        data={visits}
        keyExtractor={(visit) => visit.uuid}
        contentContainerClassName="gap-3 px-5 pb-8"
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} colors={['#16a34a']} tintColor="#16a34a" />}
        ListEmptyComponent={
          !refreshing ? <Text className="mt-8 text-center text-neutral-500">Nenhuma visita agendada.</Text> : null
        }
        renderItem={({ item }) => {
          const downloaded = downloadedUuids.has(item.uuid);
          const downloading = downloadingUuid === item.uuid;
          const pendingSync = pendingSyncUuids.has(item.uuid);
          const canceled = item.status === 'canceled';

          return (
            <View
              className={`gap-3 rounded-2xl border p-4 shadow-sm shadow-green-950/5 ${canceled ? 'border-red-100 bg-red-50' : 'border-green-100 bg-white'}`}
            >
              <View className="flex-row items-center justify-between">
                <Text className={`text-base font-semibold ${canceled ? 'text-red-900' : 'text-green-950'}`}>
                  {item.establishment.name}
                </Text>
                <Text className={`text-xs font-medium uppercase ${canceled ? 'text-red-700' : 'text-neutral-500'}`}>
                  {STATUS_LABELS[item.status] ?? item.status}
                </Text>
              </View>

              <Pressable onPress={() => openInMaps(item.establishment)}>
                <Text className="text-sm text-green-700 underline">{addressLine(item.establishment)}</Text>
              </Pressable>

              <View className="flex-row items-center justify-between">
                <Text className="text-sm text-neutral-600">
                  {formatScheduledAt(item.scheduled_at)} · {item.service_type}
                </Text>
                {!canceled ? (
                  <Text className="text-xs text-neutral-500">{downloaded ? 'Dados baixados' : 'Não baixado'}</Text>
                ) : null}
              </View>

              {pendingSync ? (
                <Text className="text-xs font-medium uppercase text-amber-700">Dados aguardando sincronização</Text>
              ) : null}

              {canceled ? (
                <Text className="text-sm text-red-700">Visita cancelada. Não é mais possível iniciar ou continuar o atendimento.</Text>
              ) : !downloaded ? (
                <Pressable
                  onPress={() => downloadDetail(item)}
                  disabled={downloading}
                  className="min-h-12 items-center justify-center rounded-xl bg-green-600 px-4 disabled:opacity-50"
                >
                  <Text className="text-sm font-medium text-white">{downloading ? 'Baixando…' : 'Baixar para uso offline'}</Text>
                </Pressable>
              ) : (
                <Pressable
                  onPress={() => router.push(`/visita/${item.uuid}`)}
                  className="min-h-12 items-center justify-center rounded-xl bg-green-600 px-4"
                >
                  <Text className="text-sm font-semibold text-white">{visitActionLabel(item)}</Text>
                </Pressable>
              )}
            </View>
          );
        }}
      />
    </SafeAreaView>
  );
}
