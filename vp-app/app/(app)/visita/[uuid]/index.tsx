import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { PhotoEvidenceSection } from '@/components/pest-control/PhotoEvidenceSection';
import { fetchVisitDetail } from '@/lib/pest-control/api';
import {
  getCachedVisitDetail,
  hasPendingCheckin,
  type LocalInspection,
  listLocalInspections,
  saveVisitDetail,
} from '@/lib/pest-control/db';
import { seedInspectionsFromVisitDetail } from '@/lib/pest-control/inspections';
import { addressLine, openInMaps } from '@/lib/pest-control/maps';
import type { ControlPoint, MediaCategory, VisitDetail } from '@/lib/pest-control/types';

const CATEGORY_LABELS: Record<string, string> = {
  roedores: 'Roedores',
  moscas: 'Moscas',
  insetos: 'Insetos',
};

// "Situação do local" e "serviço concluído" são evidências da visita como
// um todo (ver app-tecnico.md, seção EVIDÊNCIAS) — as demais categorias são
// específicas de um ponto e ficam na tela de inspeção do ponto.
const VISIT_PHOTO_CATEGORIES: { value: MediaCategory; label: string }[] = [
  { value: 'situacao_local', label: 'Situação do local' },
  { value: 'servico_concluido', label: 'Serviço concluído' },
];

function formatScheduledAt(iso: string): string {
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'full', timeStyle: 'short' }).format(new Date(iso));
}

function pointStatusLabel(local: LocalInspection | undefined): { label: string; className: string } {
  if (!local) return { label: 'Pendente', className: 'text-neutral-500' };
  if (local.syncStatus === 'conflict') return { label: 'Conflito: precisa de decisão', className: 'text-red-600' };
  if (local.draft.not_inspected) return { label: 'Ocorrência: não acessado', className: 'text-red-600' };
  return {
    label: local.syncStatus === 'synced' ? 'Revisado' : 'Revisado (aguardando sincronização)',
    className: 'text-green-700',
  };
}


/** Detalhes da visita (Etapa 2) e progresso da inspeção dos pontos (Etapa 4). */
export default function VisitDetailScreen() {
  const { uuid } = useLocalSearchParams<{ uuid: string }>();
  const router = useRouter();

  const [detail, setDetail] = useState<VisitDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [notDownloaded, setNotDownloaded] = useState(false);
  const [pendingSync, setPendingSync] = useState(false);
  const [localInspections, setLocalInspections] = useState<Map<number, LocalInspection>>(new Map());

  const load = useCallback(async () => {
    setLoading(true);
    setNotDownloaded(false);

    const cached = await getCachedVisitDetail(uuid);
    if (cached) {
      setDetail(cached);
      setLoading(false);
      return;
    }

    try {
      const fresh = await fetchVisitDetail(uuid);
      await saveVisitDetail(uuid, fresh);
      await seedInspectionsFromVisitDetail(uuid, fresh);
      setDetail(fresh);
    } catch {
      setNotDownloaded(true);
    } finally {
      setLoading(false);
    }
  }, [uuid]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [load]);

  // Reflete check-in (Etapa 3) e inspeções (Etapa 4) ao voltar de outra tela, sem precisar rebaixar a visita.
  useFocusEffect(
    useCallback(() => {
      (async () => {
        const cached = await getCachedVisitDetail(uuid);
        if (cached) setDetail(cached);
        setPendingSync(await hasPendingCheckin(uuid));
        setLocalInspections(await listLocalInspections(uuid));
      })();
    }, [uuid]),
  );

  if (loading) {
    return (
      <View className="flex-1 items-center justify-center bg-white">
        <ActivityIndicator />
      </View>
    );
  }

  if (notDownloaded || !detail) {
    return (
      <View className="flex-1 items-center justify-center gap-4 bg-white px-6 pt-16">
        <Text className="text-center text-neutral-700">
          Esta visita ainda não foi baixada e não há internet disponível agora. Volte para a agenda com internet para baixar os
          dados.
        </Text>
        <Pressable onPress={() => router.back()} className="rounded-xl border border-neutral-300 px-6 py-3">
          <Text className="text-base font-medium text-green-950">Voltar</Text>
        </Pressable>
      </View>
    );
  }

  const { visit } = detail;
  const points = visit.establishment.control_points;
  const canceled = visit.status === 'canceled';

  const reviewed = points.filter((point) => localInspections.has(point.id)).length;
  const occurrences = points.filter((point) => localInspections.get(point.id)?.draft.not_inspected).length;
  const replacements = points.filter((point) => localInspections.get(point.id)?.draft.replaced).length;
  const pending = points.length - reviewed;

  return (
    <SafeAreaView className="flex-1 bg-green-50" edges={['top', 'bottom']}>
      <View className="mx-5 mb-4 gap-2 rounded-2xl border border-green-100 bg-white p-4">
        <Pressable onPress={() => router.back()} hitSlop={8} className="min-h-10 self-start justify-center pr-4">
          <Text className="text-sm text-green-700">‹ Agenda</Text>
        </Pressable>
        <Text className="text-xl font-semibold text-green-950">{visit.establishment.name}</Text>
        <Pressable onPress={() => openInMaps(visit.establishment)}>
          <Text className="text-sm text-green-700 underline">{addressLine(visit.establishment)}</Text>
        </Pressable>
        <Text className="text-sm text-neutral-600">{formatScheduledAt(visit.scheduled_at)}</Text>
        <Text className="text-sm text-neutral-600">{visit.service_type}</Text>

        {canceled ? (
          <View className="mt-2 rounded-xl border border-red-100 bg-red-50 p-3">
            <Text className="text-sm font-medium text-red-700">
              Visita cancelada. Não é mais possível iniciar ou continuar o atendimento.
            </Text>
          </View>
        ) : visit.checkin_at ? (
          <Text className="text-sm font-medium text-green-700">
            Check-in feito às {new Intl.DateTimeFormat('pt-BR', { timeStyle: 'short' }).format(new Date(visit.checkin_at))}
          </Text>
        ) : (
          <Pressable
            onPress={() => router.push(`/visita/${uuid}/check-in`)}
            className="mt-2 min-h-12 items-center justify-center rounded-xl bg-green-600 px-4"
          >
            <Text className="text-base font-medium text-white">Fazer check-in</Text>
          </Pressable>
        )}

        {visit.checkout_at ? (
          <Text className="text-sm font-medium text-green-700">
            Check-out feito às {new Intl.DateTimeFormat('pt-BR', { timeStyle: 'short' }).format(new Date(visit.checkout_at))}
          </Text>
        ) : null}

        {pendingSync ? (
          <Text className="text-xs font-medium uppercase text-amber-700">Dados aguardando sincronização</Text>
        ) : null}
      </View>

      {!canceled ? (
        <View className="border-t border-green-100 px-5 pt-4">
          <PhotoEvidenceSection visitUuid={uuid} pointId={null} categories={VISIT_PHOTO_CATEGORIES} />
        </View>
      ) : null}

      <View className="gap-2 border-t border-green-100 px-5 pt-4">
        <Text className="text-sm font-semibold uppercase text-neutral-500">
          Pontos de controle: {reviewed} de {points.length} revisados
        </Text>
        <View className="flex-row gap-4">
          <Text className="text-xs text-neutral-500">Pendentes: {pending}</Text>
          <Text className="text-xs text-red-600">Ocorrências: {occurrences}</Text>
          <Text className="text-xs text-amber-700">Substituições: {replacements}</Text>
        </View>
        <Text className="text-xs text-neutral-500">Toque em um ponto abaixo para abrir e preencher a inspeção.</Text>
      </View>

      <FlatList
        data={points}
        keyExtractor={(point: ControlPoint) => String(point.id)}
        contentContainerClassName="gap-3 px-5 pb-8 pt-3"
        renderItem={({ item }) => {
          const local = localInspections.get(item.id);
          const status = pointStatusLabel(local);
          const needsAttention = item.required && !local;

          return (
            <Pressable
              onPress={() => !canceled && router.push(`/visita/${uuid}/ponto/${item.id}`)}
              disabled={canceled}
              className={`gap-1 rounded-2xl border p-4 ${canceled ? 'opacity-50' : ''} ${needsAttention ? 'border-amber-300 bg-amber-50' : 'border-green-100 bg-white'}`}
            >
              <View className="flex-row items-center justify-between">
                <Text className="text-base font-medium text-green-950">{item.code ?? item.label}</Text>
                {item.required ? (
                  <View className="rounded-full bg-amber-100 px-2 py-0.5">
                    <Text className="text-[10px] font-bold uppercase text-amber-800">Obrigatório</Text>
                  </View>
                ) : null}
              </View>
              <Text className="text-sm text-neutral-600">{item.label}</Text>
              <Text className="text-xs text-neutral-500">{CATEGORY_LABELS[item.category_key] ?? item.category_key}</Text>
              {item.instructions ? <Text className="text-sm text-neutral-600">{item.instructions}</Text> : null}
              <View className="mt-1 flex-row items-center justify-between">
                <Text className={`text-xs font-medium uppercase ${status.className}`}>{status.label}</Text>
                <Text className="text-xl font-bold leading-none text-green-700">›</Text>
              </View>
            </Pressable>
          );
        }}
        ListEmptyComponent={
          <Text className="text-neutral-500">Nenhum ponto de controle cadastrado para este estabelecimento.</Text>
        }
        ListFooterComponent={
          !canceled && visit.checkin_at && !visit.checkout_at ? (
            <Pressable
              onPress={() => router.push(`/visita/${uuid}/resumo`)}
              className="mt-2 min-h-14 items-center justify-center rounded-2xl bg-green-600 px-4"
            >
              <Text className="text-base font-medium text-white">Resumo e encerramento da visita</Text>
            </Pressable>
          ) : null
        }
      />
    </SafeAreaView>
  );
}
