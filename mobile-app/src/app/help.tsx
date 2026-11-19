import * as WebBrowser from 'expo-web-browser';
import { Linking } from 'react-native';

import { Screen, TopBar } from '@/components/screen';
import { AppText, Button, Card } from '@/components/ui';
import { useBootstrap } from '@/lib/queries';

const FAQ: [string, string][] = [
  ['هل حساب التطبيق هو نفس حساب الموقع؟', 'أيوه — نفس الحساب والرصيد والمنشورات والتصميمات. أي حاجة تعملها هنا بتظهر على الموقع والعكس.'],
  ['الرصيد بيتخصم إمتى؟', 'بعد ما الذكاء الاصطناعي يخلّص العملية. لو العملية فشلت الرصيد بيرجع تلقائيًا، ولو النت فصل وعدت المحاولة مش هيتخصم منك مرتين.'],
  ['ليه التصميم محتاج اشتراك؟', 'كتابة المنشورات متاحة للكل، وتحويلها لتصميم ونشرها تلقائيًا ضمن الاشتراك.'],
  ['إزاي أربط صفحة فيسبوك؟', 'من «النشر» ← «اربط صفحة». بتفتح صفحة فيسبوك الرسمية للموافقة على الصلاحيات، وبعدها الصفحة بتظهر في التطبيق.'],
  ['إزاي أحذف حسابي؟', 'من «حسابي» ← «إعدادات الحساب والأمان» ← «حذف الحساب». بنحتفظ بالحساب 14 يوم علشان لو غيّرت رأيك.'],
];

export default function Help() {
  const boot = useBootstrap();
  const links = boot.data?.links;
  return (
    <Screen header={<TopBar title="المساعدة والدعم" />}>
      <Card style={{ gap: 10 }}>
        <AppText variant="title">محتاج مساعدة؟ فريقنا معاك</AppText>
        <AppText variant="caption">كلّم خدمة العملاء وهنرد عليك في أسرع وقت.</AppText>
        <Button title="تواصل مع الدعم" icon="message-circle" onPress={() => links && (links.support.startsWith('https://wa.me') ? Linking.openURL(links.support) : WebBrowser.openBrowserAsync(links.support))} disabled={!links} />
        <Button title="دليل الاستخدام الكامل" kind="ghost" icon="book-open" size="sm" onPress={() => links && WebBrowser.openBrowserAsync(links.help)} disabled={!links} />
      </Card>
      {FAQ.map(([q, a]) => (
        <Card key={q} style={{ gap: 6 }}>
          <AppText variant="bodyStrong">{q}</AppText>
          <AppText variant="caption">{a}</AppText>
        </Card>
      ))}
    </Screen>
  );
}
