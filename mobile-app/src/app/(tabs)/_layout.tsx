import { Feather } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { Tabs } from 'expo-router/tabs';
import { View, type ColorValue } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { IconName } from '@/components/ui';
import { colors, fonts, gradients, shadow } from '@/theme/tokens';

function TabIcon({ name, color, focused, primary }: { name: IconName; color: ColorValue; focused: boolean; primary?: boolean }) {
  if (primary) {
    return (
      <LinearGradient colors={gradients.brand} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={[{ width: 48, height: 48, borderRadius: 16, alignItems: 'center', justifyContent: 'center', marginTop: -14 }, shadow.raised]}>
        <Feather name={name} size={24} color={colors.white} />
      </LinearGradient>
    );
  }
  return (
    <View style={{ alignItems: 'center', opacity: focused ? 1 : 0.9 }}>
      <Feather name={name} size={22} color={color as string} />
    </View>
  );
}

export default function TabsLayout() {
  const insets = useSafeAreaInsets();
  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.blue,
        tabBarInactiveTintColor: colors.mute3,
        tabBarLabelStyle: { fontFamily: fonts.bodyMedium, fontSize: 11, lineHeight: 16, marginTop: 2 },
        tabBarStyle: {
          backgroundColor: colors.card,
          borderTopColor: colors.line,
          height: 66 + insets.bottom,
          paddingTop: 8,
          paddingBottom: Math.max(insets.bottom, 10),
        },
        sceneStyle: { backgroundColor: colors.bg },
      }}>
      <Tabs.Screen name="home" options={{ title: 'الرئيسية', tabBarIcon: (p) => <TabIcon name="home" {...p} /> }} />
      <Tabs.Screen name="projects" options={{ title: 'مشاريعي', tabBarIcon: (p) => <TabIcon name="layers" {...p} /> }} />
      <Tabs.Screen
        name="create"
        options={{ title: 'اصنع', tabBarIcon: (p) => <TabIcon name="plus" {...p} primary />, tabBarAccessibilityLabel: 'اصنع محتوى جديد' }}
      />
      <Tabs.Screen name="publishing" options={{ title: 'النشر', tabBarIcon: (p) => <TabIcon name="send" {...p} /> }} />
      <Tabs.Screen name="profile" options={{ title: 'حسابي', tabBarIcon: (p) => <TabIcon name="user" {...p} /> }} />
    </Tabs>
  );
}
