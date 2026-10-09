<?php
/** @var array $S settings */
$gtm = preg_match('/^GTM-[A-Z0-9]+$/i', (string) ($S['gtm_id'] ?? '')) ? $S['gtm_id'] : '';
$pixelId = preg_replace('/\D/', '', (string) ($S['meta_pixel_id'] ?? ''));
?>
<?php if ($gtm): ?>
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?= e($gtm) ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<?php endif; ?>
<?php if ($pixelId): ?>
<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=<?= e($pixelId) ?>&ev=PageView&noscript=1"></noscript>
<?php endif; ?>
<?php
echo $S['custom_body_code'] ?? '', "\n";
