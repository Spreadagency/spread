<?php
declare(strict_types=1);

/** الربط والـ API: AI provider (Gemini / OpenAI / OpenRouter), prompts, limits, Turnstile, webhooks. Owner only. */
require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

admin_require('owner');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_post('owner');
    foreach (['webhook_n8n_url', 'webhook_crm_url'] as $k) {
        $v = trim((string) ($_POST[$k] ?? ''));
        if ($v !== '' && (!filter_var($v, FILTER_VALIDATE_URL) || !str_starts_with($v, 'https://'))) {
            flash('err', 'لينك الويب هوك لازم يكون https://');
            redirect('integrations.php');
        }
    }
    if (trim((string) ($_POST['gemini_prompt'] ?? 'x')) === '') {
        flash('err', 'برومبت التوليد مينفعش يبقى فاضي.');
        redirect('integrations.php');
    }
    foreach (['gemini_model', 'gemini_safety_model', 'openai_model', 'openai_safety_model', 'openrouter_model', 'openrouter_safety_model'] as $k) {
        if (isset($_POST[$k]) && !preg_match('#^[A-Za-z0-9._:/-]{0,100}$#', trim((string) $_POST[$k]))) {
            flash('err', 'اسم الموديل فيه حروف مش مسموحة: ' . $k);
            redirect('integrations.php');
        }
    }
    if (!array_key_exists($_POST['ai_provider'] ?? '', AiService::PROVIDERS)) {
        unset($_POST['ai_provider']);
    }
    if (!in_array($_POST['openai_quality'] ?? '', ['low', 'medium', 'high'], true)) {
        unset($_POST['openai_quality']);
    }
    $changed = save_settings_from_post(
        ['ai_provider', 'openai_model', 'openai_safety_model', 'openai_quality', 'openrouter_model', 'openrouter_safety_model',
         'gemini_model', 'gemini_safety_model', 'gemini_prompt', 'gemini_safety_prompt', 'turnstile_site_key', 'webhook_n8n_url', 'webhook_crm_url'],
        ['gemini_auto_retry', 'webhook_on_lead', 'webhook_on_whatsapp'],
        ['gemini_api_key', 'openai_api_key', 'openrouter_api_key', 'turnstile_secret', 'webhook_secret'],
        ['gemini_timeout' => [20, 170], 'limit_daily_global' => [0, 100000], 'limit_per_ip' => [0, 1000], 'limit_per_cookie' => [0, 1000], 'limit_per_phone' => [1, 100], 'limit_leads_per_ip_hour' => [1, 10000]]
    );
    admin_log('integrations_update', implode(', ', $changed));
    flash('ok', 'اتحفظت إعدادات الربط');
    if (!AiService::keySet()) {
        flash('err', 'المصدر المختار (' . AiService::label() . ') ملوش API key — التوليد مش هيشتغل لحد ما تضيفه.');
    }
    redirect('integrations.php');
}

function last_test(string $key): string
{
    $t = json_decode((string) Settings::get('test_' . $key, ''), true);
    return $t ? '<span class="status ' . ($t['ok'] ? 'ok' : 'err') . '"><i></i>' . e($t['message']) . '</span> <span class="muted">· ' . e(rel_time($t['at'])) . '</span>' : '';
}

$used = (int) q_value("SELECT COUNT(*) FROM generations WHERE status IN ('processing','done') AND started_at >= ?", [day_start_utc()]);
$cap = Settings::int('limit_daily_global', 300);
$provider = AiService::provider();
$keyBadge = static fn (string $p): string => AiService::keySet($p) ? '<span class="status ok"><i></i>المفتاح متضاف</span>' : '<span class="status err"><i></i>مفيش مفتاح</span>';
$providerNotes = [
    'gemini' => 'Nano Banana · رخيص وسريع ومحافظ على ملامح الوش',
    'openai' => 'gpt-image-1 · جودة عالية، أبطأ وأغلى شوية',
    'openrouter' => 'مفتاح واحد لموديلات كتير (Gemini، OpenAI، …)',
];
$samplePayload = json_encode([
    'event' => 'lead', 'lead_id' => 1240, 'name' => 'أحمد السيد', 'phone' => '01012345678', 'phone_international' => '201012345678',
    'utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'sleeve_oct_lal', 'utm_content' => 'video_1', 'utm_term' => null,
    'status' => 'new', 'result_url' => url('r/9f2c…'), 'created_at' => date(DATE_ATOM), 'sent_at' => date(DATE_ATOM),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

admin_page_start('الربط والـ API', 'integrations.php');
?>
<form method="post" class="stack" style="gap:24px">
<?= csrf_field() ?>
<div class="page-head"><div><p>مصدر الذكاء الاصطناعي، البرومبت، الحدود، والويب هوكس</p></div><?= save_bar() ?></div>
<section class="card"><div class="card-h"><div class="row"><span class="ibox gold"><?= ic('spark') ?></span><div><h2>مصدر الذكاء الاصطناعي</h2><p>اختار مين اللي هيولّد الصور — كل مصدر ليه مفتاحه وموديلاته، والبرومبت مشترك</p></div></div></div>
  <div class="card-b"><div class="pick-grid" role="radiogroup" aria-label="مصدر الذكاء الاصطناعي">
    <?php foreach (AiService::PROVIDERS as $p => $label): ?>
    <label class="pick"><input type="radio" name="ai_provider" value="<?= e($p) ?>"<?= $p === $provider ? ' checked' : '' ?><?= ro() ?>>
      <span class="pick-in"><b><?= e($label) ?></b><span class="small muted"><?= e($providerNotes[$p]) ?></span><?= $keyBadge($p) ?></span></label>
    <?php endforeach; ?>
  </div></div></section>
<div class="grid g-1-1" style="align-items:start">
  <div class="stack" style="gap:24px">
    <section class="card" data-provider="gemini"><div class="card-h"><div class="row"><span class="ibox gold"><?= ic('spark') ?></span><div><h2>Google Gemini</h2><p>المفتاح متشفّر ومش بيوصل للمتصفح</p></div></div><?= $keyBadge('gemini') ?></div>
      <div class="card-b form-grid">
        <div class="full"><?= f_secret('gemini_api_key', 'API key', 'من <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a> — لازم يكون عليه Billing لتوليد الصور.') ?></div>
        <?= f_text('gemini_model', 'موديل التوليد', Settings::get('gemini_model'), ['ltr' => 1, 'mono' => 1, 'hint' => 'الافتراضي: gemini-2.5-flash-image']) ?>
        <?= f_text('gemini_safety_model', 'موديل فحص الأمان', Settings::get('gemini_safety_model'), ['ltr' => 1, 'mono' => 1, 'hint' => 'الافتراضي: gemini-2.5-flash']) ?>
        <div class="full row between wrap"><span class="small" id="out-gemini"><?= last_test('gemini') ?></span><button class="btn btn-secondary btn-sm" type="button" data-test="gemini"><?= ic('refresh', 'sm') ?>اختبر الاتصال</button></div>
      </div></section>
    <section class="card" data-provider="openai"><div class="card-h"><div class="row"><span class="ibox gold"><?= ic('spark') ?></span><div><h2>OpenAI</h2><p>تعديل الصورة بـ Images API · المفتاح متشفّر</p></div></div><?= $keyBadge('openai') ?></div>
      <div class="card-b form-grid">
        <div class="full"><?= f_secret('openai_api_key', 'API key', 'من <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a> — موديلات gpt-image محتاجة توثيق الـ Organization ورصيد.') ?></div>
        <?= f_text('openai_model', 'موديل التوليد', Settings::get('openai_model'), ['ltr' => 1, 'mono' => 1, 'hint' => 'gpt-image-1 أو gpt-image-1-mini (أرخص)']) ?>
        <?= f_text('openai_safety_model', 'موديل فحص الأمان', Settings::get('openai_safety_model'), ['ltr' => 1, 'mono' => 1, 'hint' => 'موديل بيشوف صور، مثلًا gpt-4.1-mini أو gpt-4o-mini']) ?>
        <?= f_select('openai_quality', 'جودة الصورة', (string) Settings::get('openai_quality', 'medium'), ['low' => 'Low — أرخص وأسرع', 'medium' => 'Medium — متوازن', 'high' => 'High — أحسن جودة وأغلى']) ?>
        <div class="full row between wrap"><span class="small" id="out-openai"><?= last_test('openai') ?></span><button class="btn btn-secondary btn-sm" type="button" data-test="openai"><?= ic('refresh', 'sm') ?>اختبر الاتصال</button></div>
      </div></section>
    <section class="card" data-provider="openrouter"><div class="card-h"><div class="row"><span class="ibox gold"><?= ic('spark') ?></span><div><h2>OpenRouter</h2><p>موديل بيطلّع صور عن طريق Chat Completions · المفتاح متشفّر</p></div></div><?= $keyBadge('openrouter') ?></div>
      <div class="card-b form-grid">
        <div class="full"><?= f_secret('openrouter_api_key', 'API key', 'من <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a> — اشحن رصيد (Credits) الأول.') ?></div>
        <?= f_text('openrouter_model', 'موديل التوليد', Settings::get('openrouter_model'), ['ltr' => 1, 'mono' => 1, 'hint' => 'لازم يكون موديل بيطلّع صور، مثلًا google/gemini-2.5-flash-image']) ?>
        <?= f_text('openrouter_safety_model', 'موديل فحص الأمان', Settings::get('openrouter_safety_model'), ['ltr' => 1, 'mono' => 1, 'hint' => 'موديل بيشوف صور، مثلًا google/gemini-2.5-flash']) ?>
        <div class="full row between wrap"><span class="small" id="out-openrouter"><?= last_test('openrouter') ?></span><button class="btn btn-secondary btn-sm" type="button" data-test="openrouter"><?= ic('refresh', 'sm') ?>اختبر الاتصال</button></div>
      </div></section>
    <section class="card"><div class="card-h"><div><h2>البرومبت والتشغيل</h2><p>مشترك بين كل المصادر · بالإنجليزي أفضل للنتايج</p></div></div>
      <div class="card-b stack">
        <div class="form-grid">
        <?= f_text('gemini_timeout', 'Timeout (ثانية)', (string) Settings::int('gemini_timeout', 90), ['type' => 'number', 'ltr' => 1, 'min' => 20, 'max' => 170]) ?>
        <div class="field"><label>إعادة المحاولة</label><div style="height:40px;display:flex;align-items:center"><?= f_toggle('gemini_auto_retry', 'مرة واحدة تلقائي لو فشل', Settings::bool('gemini_auto_retry')) ?></div></div>
        </div>
        <?= f_textarea('gemini_prompt', 'برومبت التوليد', Settings::get('gemini_prompt'), ['code' => 1, 'rows' => 9, 'counter' => 4000]) ?>
        <?= f_textarea('gemini_safety_prompt', 'برومبت فحص الأمان', Settings::get('gemini_safety_prompt', ''), ['code' => 1, 'rows' => 6, 'hint' => 'لازم يرجّع JSON بالشكل <span class="ltr mono">{"ok": true|false, "reason": "..."}</span>. لو فاضي، الفحص بيتلغي (مش منصوح).']) ?>
      </div></section>
  </div>
  <div class="stack" style="gap:24px">
    <section class="card"><div class="card-h"><div><h2>الحدود</h2><p>عشان مفيش تحقق برقم، الحدود دي بتحمي الرصيد</p></div></div><div class="card-b form-grid">
      <?= f_text('limit_daily_global', 'الحد اليومي للتوليد (كله)', (string) $cap, ['type' => 'number', 'ltr' => 1, 'min' => 0, 'hint' => 'لما يخلص بتظهر رسالة "الخدمة عليها ضغط" والليد بيتحفظ']) ?>
      <?= f_text('limit_per_ip', 'لكل IP في 24 ساعة', (string) Settings::int('limit_per_ip', 3), ['type' => 'number', 'ltr' => 1, 'min' => 0, 'hint' => '0 = من غير حد']) ?>
      <?= f_text('limit_per_cookie', 'لكل جهاز في 24 ساعة', (string) Settings::int('limit_per_cookie', 2), ['type' => 'number', 'ltr' => 1, 'min' => 0]) ?>
      <?= f_text('limit_per_phone', 'صور ناجحة لكل رقم', (string) Settings::int('limit_per_phone', 1), ['type' => 'number', 'ltr' => 1, 'min' => 1, 'hint' => 'تقدر تسمح بمحاولة زيادة من درج الليد']) ?>
      <?= f_text('limit_leads_per_ip_hour', 'تسجيل ليد لكل IP في الساعة', (string) Settings::int('limit_leads_per_ip_hour', 10), ['type' => 'number', 'ltr' => 1, 'min' => 1]) ?>
      <div class="field full"><span class="lbl">استهلاك النهارده</span><div class="row-16"><div class="bar gold" style="flex:1;height:10px"><i style="width:<?= min(100, $cap ? round($used / $cap * 100) : 0) ?>%"></i></div><b class="ltr"><?= $used ?> / <?= $cap ?></b></div></div>
    </div></section>
    <section class="card"><div class="card-h"><div><h2>Cloudflare Turnstile</h2><p>حماية من البوتس — لو أي حقل فاضي بيتخطى</p></div><?= Turnstile::enabled() ? '<span class="status ok"><i></i>مفعّل</span>' : '<span class="status"><i></i>مش مفعّل</span>' ?></div><div class="card-b form-grid">
      <?= f_text('turnstile_site_key', 'Site key', Settings::get('turnstile_site_key', ''), ['ltr' => 1, 'mono' => 1]) ?>
      <?= f_secret('turnstile_secret', 'Secret key') ?>
    </div></section>
    <section class="card"><div class="card-h"><div><h2>الويب هوكس</h2><p>بيتبعت JSON لـ n8n و Spread CRM</p></div></div><div class="card-b form-grid">
      <?php foreach (['n8n' => ['webhook_n8n_url', 'n8n Webhook URL'], 'crm' => ['webhook_crm_url', 'Spread CRM Webhook URL']] as $name => [$key, $label]): ?>
      <div class="full stack-8"><?= f_text($key, $label, Settings::get($key, ''), ['ltr' => 1, 'mono' => 1, 'placeholder' => 'https://…']) ?>
        <div class="row between wrap"><span class="small" id="out-webhook_<?= $name ?>"><?= last_test('webhook_' . $name) ?></span><button class="btn btn-secondary btn-sm" type="button" data-test="webhook_<?= $name ?>"><?= ic('send', 'sm') ?>جرّب الويب هوك</button></div></div>
      <?php endforeach; ?>
      <div class="full row wrap" style="gap:16px"><?= f_toggle('webhook_on_lead', 'ابعت عند ليد جديد', Settings::bool('webhook_on_lead')) ?><?= f_toggle('webhook_on_whatsapp', 'ابعت عند ضغطة واتساب', Settings::bool('webhook_on_whatsapp')) ?></div>
      <div class="full"><?= f_secret('webhook_secret', 'Secret (اختياري)', 'لو متضاف، كل طلب بيتبعت معاه <span class="ltr mono">X-Spread-Signature: sha256=HMAC(body)</span>') ?></div>
      <details class="full"><summary class="small" style="cursor:pointer;font-weight:600">شكل البيانات اللي بتتبعت (JSON)</summary><pre class="code" style="margin-top:8px;min-height:0;white-space:pre"><?= e($samplePayload) ?></pre></details>
    </div></section>
  </div>
</div>
</form>
<?php
admin_page_end();
