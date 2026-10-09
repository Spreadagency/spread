<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(t('t_maint_title')) ?> — <?= e(Settings::get('doctor_name')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
</head>
<body>
<main class="container" style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;text-align:center">
  <img src="<?= e(asset((string) Settings::get('logo'))) ?>" alt="<?= e(Settings::get('doctor_name')) ?>" style="height:72px;width:auto">
  <h1 class="h2"><?= e(t('t_maint_title')) ?></h1>
  <?php if (t('t_maint_text') !== ''): ?><p class="lead"><?= e(t('t_maint_text')) ?></p><?php endif; ?>
  <?php if (whatsapp_link() !== '#'): ?><a class="btn btn-wa" href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener"><?= e(t('t_header_whatsapp')) ?></a><?php endif; ?>
</main>
</body>
</html>
