import { cssInterop } from 'nativewind';
import { Redirect } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useState } from 'react';
import { ActivityIndicator, Image, Pressable, Text, TextInput, View } from 'react-native';
import { KeyboardAwareScrollView } from 'react-native-keyboard-controller';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError, useAuth } from '@/lib/auth';

cssInterop(KeyboardAwareScrollView, {
  className: 'style',
  contentContainerClassName: 'contentContainerStyle',
});

export default function LoginScreen() {
  const { login, isAuthenticated, isLoading } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // Sessão já restaurada/ativa: nada a fazer aqui, a home cuida do resto.
  if (!isLoading && isAuthenticated) {
    return <Redirect href="/" />;
  }

  const handleSubmit = async () => {
    setError(null);
    setSubmitting(true);

    try {
      await login(email.trim(), password);
    } catch (caught) {
      if (caught instanceof ApiError) {
        const firstFieldError = caught.errors ? Object.values(caught.errors)[0]?.[0] : undefined;
        setError(firstFieldError ?? caught.message);
      } else {
        setError('Não foi possível entrar. Tente novamente.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <View className="flex-1 bg-green-950">
      <StatusBar style="light" backgroundColor="#052e16" />
      <View className="absolute -right-20 -top-24 h-72 w-72 rounded-full bg-green-800" />
      <View className="absolute -bottom-32 -left-24 h-80 w-80 rounded-full bg-green-900" />

      <SafeAreaView className="flex-1" edges={['top', 'bottom']}>
        <KeyboardAwareScrollView
          bottomOffset={100}
          contentContainerClassName="grow justify-center px-5 py-8"
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
        <View className="rounded-3xl bg-white px-6 py-8 shadow-2xl shadow-green-950/40">
          <View className="mb-8 items-center">
            <View className="mb-5 rounded-2xl border border-green-200 bg-white p-2 shadow-md shadow-green-950/15">
              <Image
                source={require('../assets/logo_pet.png')}
                accessibilityLabel="Logotipo VetorPet"
                resizeMode="cover"
                className="h-16 w-16 rounded-xl"
              />
            </View>

            <Text className="text-center text-3xl font-bold tracking-tight text-green-950">Bem-vindo de volta</Text>
            <Text className="mt-2 max-w-72 text-center text-sm leading-5 text-neutral-500">
              Acesse sua agenda e gerencie suas visitas com praticidade.
            </Text>
          </View>

          <View className="gap-5">
            <View className="gap-2">
              <Text className="text-sm font-semibold text-green-950">E-mail</Text>
              <TextInput
                value={email}
                onChangeText={setEmail}
                autoCapitalize="none"
                keyboardType="email-address"
                autoComplete="email"
                editable={!submitting}
                placeholder="seuemail@exemplo.com"
                placeholderTextColor="#86a18e"
                selectionColor="#16a34a"
                className="rounded-2xl border border-green-100 bg-green-50 px-4 py-4 text-base text-green-950"
              />
            </View>

            <View className="gap-2">
              <Text className="text-sm font-semibold text-green-950">Senha</Text>
              <TextInput
                value={password}
                onChangeText={setPassword}
                secureTextEntry
                autoComplete="password"
                editable={!submitting}
                placeholder="Digite sua senha"
                placeholderTextColor="#86a18e"
                selectionColor="#16a34a"
                className="rounded-2xl border border-green-100 bg-green-50 px-4 py-4 text-base text-green-950"
              />
            </View>

            {error ? (
              <View className="rounded-2xl border border-red-100 bg-red-50 px-4 py-3">
                <Text className="text-sm leading-5 text-red-700">{error}</Text>
              </View>
            ) : null}

            <Pressable
              onPress={handleSubmit}
              disabled={submitting || !email || !password}
              className="mt-1 min-h-14 items-center justify-center rounded-2xl bg-green-600 px-5 shadow-lg shadow-green-900/20 active:bg-green-700 disabled:opacity-50"
            >
              {submitting ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <Text className="text-base font-bold text-white">Entrar no aplicativo</Text>
              )}
            </Pressable>
          </View>

          <View className="mt-7 flex-row items-center justify-center gap-2">
            <View className="h-2 w-2 rounded-full bg-green-400" />
            <Text className="text-xs font-medium text-neutral-400">VetorPet · Controle de Pragas</Text>
          </View>
        </View>
        </KeyboardAwareScrollView>
      </SafeAreaView>
    </View>
  );
}
