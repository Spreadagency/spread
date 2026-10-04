<?php
/**
 * ودجت «اصنع منشورك الآن» — قمع تفاعلي بيوصّل للتسجيل
 * بيتستخدم في الصفحة الرئيسية وفي صفحة داخلية
 * $trialCompact = true → نسخة مختصرة للرئيسية
 */
$compact   = !empty($trialCompact);
$loginUrl  = s_setting('platform_login_url', PLATFORM_LOGIN);
$regUrl    = s_setting('platform_register_url', PLATFORM_REGISTER);
$trialApi  = s_url('ajax/trial.php');
?>
<div class="trial" id="trial">
  <!-- شريط الخطوات -->
  <div class="trial-steps" aria-hidden="true">
    <span class="ts on" data-step-dot="1"><b>1</b> بيزنسك</span>
    <span class="ts" data-step-dot="2"><b>2</b> الأفكار</span>
    <span class="ts" data-step-dot="3"><b>3</b> منشورك</span>
    <span class="ts" data-step-dot="4"><b>4</b> التصميم</span>
  </div>

  <!-- ═══ خطوة 1: بيانات البيزنس ═══ -->
  <div class="trial-step act" data-step="1">
    <h3 class="trial-h">احكيلنا عن بيزنسك</h3>
    <p class="trial-p">معلومتين بس وهنطلعلك أفكار منشورات مفصّلة على مقاسك.</p>

    <div class="trial-grid">
      <div class="f">
        <label>اسم البيزنس <span class="req">*</span></label>
        <input type="text" id="tb-name" placeholder="عيادة النور لطب الأسنان" maxlength="200">
      </div>
      <div class="f">
        <label>المجال <span class="req">*</span></label>
        <input type="text" id="tb-industry" placeholder="طب أسنان" maxlength="150" list="tb-industries">
        <datalist id="tb-industries">
          <option value="طب أسنان"><option value="مطاعم وكافيهات"><option value="ملابس وأزياء">
          <option value="عقارات"><option value="تعليم وتدريب"><option value="عيادات وتجميل">
          <option value="متجر إلكتروني"><option value="سياحة وسفر"><option value="خدمات تسويق">
        </datalist>
      </div>
      <div class="f">
        <label>جمهورك</label>
        <input type="text" id="tb-audience" placeholder="شباب ٢٥–٤٠ في المنصورة" maxlength="300">
      </div>
      <div class="f">
        <label>هدفك من المنشور</label>
        <select id="tb-goal">
          <option value="زيادة الحجوزات">زيادة الحجوزات</option>
          <option value="التعريف بالبيزنس">التعريف بالبيزنس</option>
          <option value="الإعلان عن عرض">الإعلان عن عرض</option>
          <option value="زيادة التفاعل">زيادة التفاعل</option>
          <option value="توعية الجمهور">توعية الجمهور</option>
        </select>
      </div>
      <div class="f full">
        <label>خدماتك أو منتجاتك</label>
        <textarea id="tb-services" rows="2" placeholder="تنظيف وتبييض · زراعة أسنان · تقويم شفاف" maxlength="1000"></textarea>
      </div>
      <div class="f">
        <label>النبرة</label>
        <select id="tb-tone">
          <option value="ودّي">ودّي وقريب</option>
          <option value="احترافي">احترافي</option>
          <option value="مرح">مرح وخفيف</option>
          <option value="فاخر">فاخر وراقي</option>
        </select>
      </div>
      <div class="f">
        <label>اللهجة</label>
        <select id="tb-dialect">
          <option value="egyptian">مصري</option>
          <option value="msa">فصحى مبسّطة</option>
        </select>
      </div>
    </div>

    <div class="trial-err" id="tb-err1"></div>
    <button type="button" class="btn-pill btn-blue btn-lg trial-next" onclick="trialIdeas()">
      <span>هات الأفكار</span><span>←</span>
    </button>
    <p class="trial-note">مجانًا · من غير تسجيل · بياناتك بتتحفظ لحسابك لما تسجّل</p>
  </div>

  <!-- ═══ خطوة 2: الأفكار ═══ -->
  <div class="trial-step" data-step="2">
    <h3 class="trial-h">اختار الفكرة اللي عاجباك</h3>
    <p class="trial-p">دي أفكار اتعملت مخصوص لـ <b id="tb-bizname">بيزنسك</b>.</p>
    <div class="ideas" id="tb-ideas"></div>
    <button type="button" class="btn-pill btn-outline trial-back" onclick="trialGo(1)">← رجوع</button>
  </div>

  <!-- ═══ خطوة 3: المنشور ═══ -->
  <div class="trial-step" data-step="3">
    <h3 class="trial-h">منشورك جاهز 🎉</h3>
    <p class="trial-p">ده منشور حقيقي اتكتب بالذكاء الاصطناعي لبيزنسك.</p>
    <div class="post-card">
      <div class="post-head">
        <span class="pav" id="tb-avatar">ب</span>
        <div><b id="tb-pname">بيزنسك</b><small>الآن · فيسبوك</small></div>
      </div>
      <div class="post-body" id="tb-post"></div>
      <div class="post-tags" id="tb-tags"></div>
    </div>
    <div class="trial-actions">
      <button type="button" class="btn-pill btn-outline" onclick="trialCopy(this)">📋 انسخ المنشور</button>
      <button type="button" class="btn-pill btn-blue btn-lg" onclick="trialGo(4)">
        <span>هات التصميم كمان</span><span>←</span>
      </button>
    </div>
    <button type="button" class="btn-pill btn-outline trial-back" onclick="trialGo(2)">← أفكار تانية</button>
  </div>

  <!-- ═══ خطوة 4: التصميم → التسجيل ═══ -->
  <div class="trial-step" data-step="4">
    <div class="design-lock">
      <div class="dl-visual" id="tb-orb-design"></div>
      <h3 class="trial-h">اعمل حساب وسجّل دخول لصناعة التصميم</h3>
      <p class="trial-p">
        <b>منشورك وبيانات بيزنسك هيتنقلوا معاك جوه</b> —
        وتقدر تعمل التصميم بألوان هويتك وتنشره على فيسبوك وانستجرام.
      </p>
      <ul class="dl-list">
        <li>منشورك اللي فوق محفوظ ومستنيك</li>
        <li>هوية بيزنسك اتسجلت تلقائيًا</li>
        <li>تصميمات بمقاسات السوشيال كلها</li>
        <li>نشر تلقائي في المواعيد اللي تحددها</li>
      </ul>
      <div class="trial-actions">
        <a href="#" id="tb-reg" class="btn-pill btn-blue btn-lg"><span>اعمل حساب وكمّل</span><span>←</span></a>
        <a href="#" id="tb-login" class="btn-pill btn-outline btn-lg">عندي حساب</a>
      </div>
    </div>
  </div>

  <!-- شاشة التفكير -->
  <div class="trial-load" id="tb-load" hidden>
    <div class="orb-stage" id="tb-orb"></div>
    <div class="thinking-label">
      <span class="tw">thinking</span>
      <span class="dots"><i></i><i></i><i></i></span>
    </div>
    <p id="tb-loadtxt">الذكاء الاصطناعي بيفكر...</p>
  </div>
</div>

<script>
window.TRIAL_API = <?= json_encode($trialApi) ?>;
window.TRIAL_REG = <?= json_encode($regUrl) ?>;
window.TRIAL_LOGIN = <?= json_encode($loginUrl) ?>;
</script>
