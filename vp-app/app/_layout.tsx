import '../global.css';

import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { KeyboardProvider } from 'react-native-keyboard-controller';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { AuthProvider } from '@/lib/auth';

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <KeyboardProvider>
        <AuthProvider>
          <StatusBar style="dark" backgroundColor="#f0fdf4" />
          <Stack screenOptions={{ headerShown: false }} />
        </AuthProvider>
      </KeyboardProvider>
    </SafeAreaProvider>
  );
}
