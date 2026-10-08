<?php /* Steps 2–4: upload, generating, error, result. Shown after the lead form. */ ?>
<!-- ======================= TOOL (steps 2–4) ======================= -->
<section class="tool section" id="tool" hidden aria-labelledby="toolTitle">
  <div class="container">
    <ol class="stepper" aria-label="خطوات المحاكاة">
      <li class="st done" data-st="1"><i><svg class="ic sm"><use href="#i-check"/></svg></i><span>بياناتك</span></li>
      <li class="st-line done" aria-hidden="true"></li>
      <li class="st cur" data-st="2"><i>2</i><span>صورتك</span></li>
      <li class="st-line" aria-hidden="true"></li>
      <li class="st" data-st="3"><i>3</i><span>النتيجة</span></li>
    </ol>

    <div class="sr-only" aria-live="polite" id="live"></div>

    <!-- 2a: upload (empty) -->
    <div class="tool-grid" data-view="upload">
      <div class="tool-copy">
        <h2 class="h2" id="toolTitle">ارفع صورتك يا <span data-first-name>بطل</span></h2>
        <p class="lead">صورة واحدة واضحة كفاية. خلّي بالك من النقط دي عشان النتيجة تطلع أدق:</p>
        <ul class="tips">
          <li class="tip"><span class="iconbox"><svg class="ic"><use href="#i-sun"/></svg></span><div>إضاءة كويسة<small>قدام شباك أو نور أبيض، من غير ظل تقيل</small></div></li>
          <li class="tip"><span class="iconbox"><svg class="ic"><use href="#i-body"/></svg></span><div>الصورة كاملة من الراس للرجل<small>واقف مستقيم والكاميرا في مستوى الصدر</small></div></li>
          <li class="tip"><span class="iconbox"><svg class="ic"><use href="#i-smile"/></svg></span><div>وشك واضح<small>من غير نضارة شمس أو حاجة مغطية الوش</small></div></li>
        </ul>
        <p class="msg"><svg class="ic xs"><use href="#i-lock"/></svg>صورتك سرّية وبتتستخدم لإنشاء المحاكاة بس.</p>
      </div>
      <div class="card stage">
        <label class="drop" id="drop" tabindex="0" role="button" aria-label="اختار صورة أو اسحبها هنا">
          <span class="iconbox"><svg class="ic lg"><use href="#i-upload"/></svg></span>
          <b class="h3">اسحب صورتك هنا أو اختارها</b>
          <span class="muted small">JPG · PNG · WEBP · HEIC — لحد 10 ميجا</span>
          <span class="drop-actions">
            <span class="btn btn-primary btn-sm" id="pickBtn"><svg class="ic sm"><use href="#i-upload"/></svg>اختار صورة</span>
            <span class="btn btn-ghost btn-sm" id="camBtn"><svg class="ic sm"><use href="#i-camera"/></svg>صوّر دلوقتي</span>
          </span>
          <span class="msg err" id="e-file" hidden></span>
        </label>
        <input type="file" id="fileInput" accept="image/*" class="sr-only" tabindex="-1">
        <input type="file" id="camInput" accept="image/*" capture="user" class="sr-only" tabindex="-1">
      </div>
    </div>

    <!-- 2b: preview -->
    <div class="tool-grid" data-view="preview" hidden>
      <div class="tool-copy">
        <h2 class="h2">الصورة جاهزة، يلا نولّد</h2>
        <p class="lead">راجع الصورة وبعدين دوس ولّد، والنتيجة بتظهر في ثواني.</p>
        <ul class="tips">
          <li class="tip"><span class="iconbox"><svg class="ic"><use href="#i-sun"/></svg></span>إضاءة كويسة</li>
          <li class="tip"><span class="iconbox"><svg class="ic"><use href="#i-body"/></svg></span>الصورة كاملة</li>
          <li class="tip"><span class="iconbox"><svg class="ic"><use href="#i-smile"/></svg></span>وش واضح</li>
        </ul>
        <p class="msg"><svg class="ic xs"><use href="#i-lock"/></svg>صورتك سرّية وبتتستخدم لإنشاء المحاكاة بس.</p>
        <button class="btn btn-primary btn-lg btn-block" id="genBtn"><svg class="ic"><use href="#i-spark"/></svg>ولّد الصورة</button>
      </div>
      <div class="card stage">
        <div class="stage-in">
          <img class="preview-img" id="previewImg" alt="الصورة اللي اخترتها">
          <span class="chip ok stage-badge"><svg class="ic sm"><use href="#i-check"/></svg>اتحمّلت بنجاح</span>
        </div>
        <div class="stage-foot">
          <span id="fileMeta">صورة واحدة · جاهزة للمحاكاة</span>
          <button class="link" id="changeBtn" type="button"><svg class="ic xs" style="vertical-align:-3px"><use href="#i-refresh"/></svg> غيّر الصورة</button>
        </div>
      </div>
    </div>

    <!-- 3: generating -->
    <div class="tool-grid" data-view="loading" hidden>
      <div class="tool-copy">
        <h2 class="h2">ثواني ونوريك النتيجة…</h2>
        <p class="lead">بنجهّز المحاكاة بالذكاء الاصطناعي، متقفلش الصفحة.</p>
        <div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="bar"><i></i></div>
        <div class="tint fact">
          <span class="eyebrow" style="display:flex;gap:8px;align-items:center"><svg class="ic sm"><use href="#i-info"/></svg>تعرف إن</span>
          <p id="factText"></p>
          <div class="dots" id="factDots"></div>
        </div>
      </div>
      <div class="card stage">
        <div class="stage-in" style="background:var(--sky-100)">
          <div class="ring">
            <svg viewBox="0 0 196 196" width="196" height="196"><circle cx="98" cy="98" r="88" fill="none" stroke="#E8F3FB" stroke-width="10"/><circle id="ringArc" cx="98" cy="98" r="88" fill="none" stroke="#1F73B7" stroke-width="10" stroke-linecap="round" stroke-dasharray="553" stroke-dashoffset="553"/></svg>
            <div><b id="pct" class="ltr">0%</b><small>جاري التوليد</small></div>
          </div>
        </div>
      </div>
    </div>

    <!-- error / rejected / cap -->
    <div data-view="error" hidden style="max-width:560px;margin:0 auto;display:flex;flex-direction:column;gap:20px;text-align:center">
      <div class="alert" role="alert"><svg class="ic"><use href="#i-alert"/></svg><span id="errShort">مقدرناش نكمّل التوليد المرة دي.</span></div>
      <div class="err-art"><span><svg class="ic lg"><use href="#i-refresh"/></svg></span></div>
      <h2 class="h2" id="errTitle">حصلت مشكلة بسيطة، جرّب تاني</h2>
      <p class="lead" id="errBody">ممكن يكون النت ضعيف أو الصورة مش واضحة بما يكفي. جرّب تاني، ولو فضلت المشكلة جرّب صورة تانية بإضاءة أحسن ووش واضح.</p>
      <ul class="tint checklist" style="padding:16px 20px;text-align:right">
        <li><svg class="ic sm"><use href="#i-check"/></svg>تأكد إن الصورة كاملة والوش ظاهر</li>
        <li><svg class="ic sm"><use href="#i-check"/></svg>استخدم إضاءة كويسة من غير ظل تقيل</li>
        <li><svg class="ic sm"><use href="#i-check"/></svg>اتأكد من اتصال الإنترنت</li>
      </ul>
      <button class="btn btn-primary btn-lg btn-block" id="retryBtn"><svg class="ic"><use href="#i-refresh"/></svg>حاول تاني</button>
      <button class="btn btn-ghost btn-lg btn-block" id="reuploadBtn">ارفع صورة تانية</button>
      <a href="#" class="btn btn-block" style="color:var(--wa)" data-wa data-track="whatsapp_click"><svg class="ic sm"><use href="#i-wa"/></svg>لسه فيه مشكلة؟ كلّمنا على واتساب</a>
    </div>

    <!-- 4: result -->
    <div class="tool-grid" data-view="result" hidden>
      <div class="card stage">
        <div class="stage-in" style="aspect-ratio:4/5">
          <div class="ba" id="ba" style="--pos:50%">
            <div class="after" id="afterLayer"></div>
            <div class="before" id="beforeLayer"></div>
            <span class="ba-label b">قبل</span>
            <span class="ba-label a">بعد (محاكاة)</span>
            <span class="ba-mark" id="baMark"><img src="<?= e(asset((string) Settings::get('logo'))) ?>" alt=""></span>
            <input type="range" min="0" max="100" value="50" id="baRange" aria-label="قارن بين قبل وبعد — حرّك يمين وشمال">
            <span class="handle"><span class="knob"><svg class="ic"><use href="#i-lr"/></svg></span></span>
            <p class="ba-note"><svg class="ic sm"><use href="#i-info"/></svg><span data-disclaimer><?= e(Settings::get('disclaimer_text')) ?></span></p>
          </div>
        </div>
      </div>
      <div class="tool-copy">
        <span class="chip ok" style="align-self:flex-start"><svg class="ic sm"><use href="#i-check"/></svg>المحاكاة جاهزة</span>
        <h2 class="h2">دا شكلك المتوقع بعد التخسيس</h2>
        <p class="lead">اسحب الخط في النص عشان تقارن بين الصورتين.</p>
        <a href="#" class="btn btn-wa btn-lg btn-block" data-wa data-track="whatsapp_click"><svg class="ic"><use href="#i-wa"/></svg>اسأل الدكتور على واتساب</a>
        <p class="msg" style="justify-content:center;margin-top:-6px">هنفتحلك واتساب برسالة جاهزة باسمك</p>
        <div class="actions">
          <a href="<?= e(Settings::get('website_url', '') ?: '#') ?>" class="btn btn-secondary btn-sm" data-website data-track="website_click" target="_blank" rel="noopener"><svg class="ic sm"><use href="#i-globe"/></svg>زيارة الموقع</a>
          <button class="btn btn-ghost btn-sm" id="dlBtn"><svg class="ic sm"><use href="#i-download"/></svg>حمّل الصورة</button>
          <button class="btn btn-ghost btn-sm" id="shareBtn"><svg class="ic sm"><use href="#i-share"/></svg>شارك</button>
        </div>
        <div class="tint encourage">
          <span class="iconbox sm"><svg class="ic"><use href="#i-chat"/></svg></span>
          <div><b class="h3" style="display:block"><?= e(Settings::get('encouragement_title')) ?></b><p class="muted"><?= e(Settings::get('encouragement_text')) ?></p></div>
        </div>
      </div>
    </div>
  </div>
</section>
