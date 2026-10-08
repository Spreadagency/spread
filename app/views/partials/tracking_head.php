<?php
/**
 * Pixel / GA4 / GTM snippets and the custom <head> code from the admin.
 * Each block is printed only when its ID is set.
 * @var array $S settings
 */
$pixelId = preg_replace('/\D/', '', (string) ($S['meta_pixel_id'] ?? ''));
$ga4 = preg_match('/^G-[A-Z0-9]+$/i', (string) ($S['ga4_id'] ?? '')) ? $S['ga4_id'] : '';
$gtm = preg_match('/^GTM-[A-Z0-9]+$/i', (string) ($S['gtm_id'] ?? '')) ? $S['gtm_id'] : '';
?>
<?php if ($gtm): ?>
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','<?= e($gtm) ?>');</script>
<?php endif; ?>
<?php if ($pixelId): ?>
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '<?= e($pixelId) ?>');
fbq('track', 'PageView', {}, {eventID: '<?= e($pageViewEventId) ?>'});
</script>
<?php endif; ?>
<?php if ($ga4): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($ga4) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= e($ga4) ?>');</script>
<?php endif; ?>
<?php
// Custom code is printed as-is on purpose (only owners can edit it).
echo $S['custom_head_code'] ?? '', "\n";
