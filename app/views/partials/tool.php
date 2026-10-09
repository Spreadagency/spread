<?php /* Steps 2–4: upload, generating, error, result. Shown after the lead form. */ ?>
<!-- ======================= TOOL (steps 2–4) ======================= -->
<section class="tool section" id="tool" hidden aria-labelledby="toolTitle">
  <div class="container">
    <ol class="stepper" aria-label="خطوات المحاكاة">
      <li class="st done" data-st="1"><i><svg class="ic sm"><use href="#i-check"/></svg></i><span><?= e(t('t_step_1')) ?></span></li>
      <li class="st-line done" aria-hidden="true"></li>
      <li class="st cur" data-st="2"><i>2</i><span><?= e(t('t_step_2')) ?></span></li>
      <li class="st-line" aria-hidden="true"></li>
      <li class="st" data-st="3"><i>3</i><span><?= e(t('t_step_3')) ?></span></li>
    </ol>

    <div class="sr-only" aria-live="polite" id="live"></div>

    <!-- 2a: upload (empty) -->
    <div class="tool-grid" data-view="upload">
      <div class="tool-copy">
        <h2 class="h2" id="toolTitle"><?php [$tBefore, $tAfter] = array_pad(explode('{name}', t('t_upload_title'), 2), 2, null); ?><?= e($tBefore) ?><?php if ($tAfter !== null): ?><span data-first-name><?= e(t('t_upload_name_fallback')) ?></span><?= e($tAfter) ?><?php endif; ?></h2>
        <?php if (t('t_upload_lead') !== ''): ?><p class="lead"><?= e(t('t_upload_lead')) ?></p><?php endif; ?>
        <ul class="tips">
          <?php foreach ([1 => 'sun', 2 => 'body', 3 => 'smile'] as $n => $icon): if (t("t_tip{$n}_title") === '') continue; ?>
          <li class="tip"><span class="iconbox"><svg class="ic"><use href="#i-<?= $icon ?>"/></svg></span><div><?= e(t("t_tip{$n}_title")) ?><?php if (t("t_tip{$n}_text") !== ''): ?><small><?= e(t("t_tip{$n}_text")) ?></small><?php endif; ?></div></li>
          <?php endforeach; ?>
        </ul>
        <?php if (t('t_privacy_note') !== ''): ?><p class="msg"><svg class="ic xs"><use href="#i-lock"/></svg><?= e(t('t_privacy_note')) ?></p><?php endif; ?>
      </div>
      <div class="card stage">
        <label class="drop" id="drop" tabindex="0" role="button" aria-label="<?= e(t('t_drop_title')) ?>">
          <span class="iconbox"><svg class="ic lg"><use href="#i-upload"/></svg></span>
          <b class="h3"><?= e(t('t_drop_title')) ?></b>
          <span class="muted small"><?= e(t('t_drop_formats')) ?></span>
          <span class="drop-actions">
            <span class="btn btn-primary btn-sm" id="pickBtn"><svg class="ic sm"><use href="#i-upload"/></svg><?= e(t('t_pick_button')) ?></span>
            <span class="btn btn-ghost btn-sm" id="camBtn"><svg class="ic sm"><use href="#i-camera"/></svg><?= e(t('t_camera_button')) ?></span>
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
        <h2 class="h2"><?= e(t('t_preview_title')) ?></h2>
        <?php if (t('t_preview_lead') !== ''): ?><p class="lead"><?= e(t('t_preview_lead')) ?></p><?php endif; ?>
        <ul class="tips">
          <?php foreach ([1 => 'sun', 2 => 'body', 3 => 'smile'] as $n => $icon): if (t("t_tip{$n}_title") === '') continue; ?>
          <li class="tip"><span class="iconbox"><svg class="ic"><use href="#i-<?= $icon ?>"/></svg></span><?= e(t("t_tip{$n}_title")) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php if (t('t_privacy_note') !== ''): ?><p class="msg"><svg class="ic xs"><use href="#i-lock"/></svg><?= e(t('t_privacy_note')) ?></p><?php endif; ?>
        <button class="btn btn-primary btn-lg btn-block" id="genBtn"><svg class="ic"><use href="#i-spark"/></svg><?= e(t('t_generate_button')) ?></button>
      </div>
      <div class="card stage">
        <div class="stage-in">
          <img class="preview-img" id="previewImg" alt="الصورة اللي اخترتها">
          <span class="chip ok stage-badge"><svg class="ic sm"><use href="#i-check"/></svg><?= e(t('t_preview_badge')) ?></span>
        </div>
        <div class="stage-foot">
          <span id="fileMeta"></span>
          <button class="link" id="changeBtn" type="button"><svg class="ic xs" style="vertical-align:-3px"><use href="#i-refresh"/></svg> <?= e(t('t_change_photo')) ?></button>
        </div>
      </div>
    </div>

    <!-- 3: generating -->
    <div class="tool-grid" data-view="loading" hidden>
      <div class="tool-copy">
        <h2 class="h2"><?= e(t('t_loading_title')) ?></h2>
        <?php if (t('t_loading_lead') !== ''): ?><p class="lead"><?= e(t('t_loading_lead')) ?></p><?php endif; ?>
        <div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="bar"><i></i></div>
        <div class="tint fact">
          <span class="eyebrow" style="display:flex;gap:8px;align-items:center"><svg class="ic sm"><use href="#i-info"/></svg><?= e(t('t_fact_label')) ?></span>
          <p id="factText"></p>
          <div class="dots" id="factDots"></div>
        </div>
      </div>
      <div class="card stage">
        <div class="stage-in" style="background:var(--sky-100)">
          <div class="ring">
            <svg viewBox="0 0 196 196" width="196" height="196"><circle cx="98" cy="98" r="88" fill="none" stroke="#E8F3FB" stroke-width="10"/><circle id="ringArc" cx="98" cy="98" r="88" fill="none" stroke="#1F73B7" stroke-width="10" stroke-linecap="round" stroke-dasharray="553" stroke-dashoffset="553"/></svg>
            <div><b id="pct" class="ltr">0%</b><small><?= e(t('t_loading_ring')) ?></small></div>
          </div>
        </div>
      </div>
    </div>

    <!-- error / rejected / cap -->
    <div data-view="error" hidden style="max-width:560px;margin:0 auto;display:flex;flex-direction:column;gap:20px;text-align:center">
      <div class="alert" role="alert"><svg class="ic"><use href="#i-alert"/></svg><span id="errShort"><?= e(t('t_err_failed_short')) ?></span></div>
      <div class="err-art"><span><svg class="ic lg"><use href="#i-refresh"/></svg></span></div>
      <h2 class="h2" id="errTitle"><?= e(t('t_err_failed_title')) ?></h2>
      <p class="lead" id="errBody"><?= e(t('t_err_failed_body')) ?></p>
      <ul class="tint checklist" style="padding:16px 20px;text-align:right">
        <?php foreach (['t_err_check_1', 't_err_check_2', 't_err_check_3'] as $k): if (t($k) === '') continue; ?>
        <li><svg class="ic sm"><use href="#i-check"/></svg><?= e(t($k)) ?></li>
        <?php endforeach; ?>
      </ul>
      <button class="btn btn-primary btn-lg btn-block" id="retryBtn"><svg class="ic"><use href="#i-refresh"/></svg><?= e(t('t_retry_button')) ?></button>
      <button class="btn btn-ghost btn-lg btn-block" id="reuploadBtn"><?= e(t('t_reupload_button')) ?></button>
      <a href="#" class="btn btn-block" style="color:var(--wa)" data-wa data-track="whatsapp_click"><svg class="ic sm"><use href="#i-wa"/></svg><?= e(t('t_err_whatsapp')) ?></a>
    </div>

    <!-- 4: result -->
    <div class="tool-grid" data-view="result" hidden>
      <div class="card stage">
        <div class="stage-in" style="aspect-ratio:4/5">
          <div class="ba" id="ba" style="--pos:50%">
            <div class="after" id="afterLayer"></div>
            <div class="before" id="beforeLayer"></div>
            <span class="ba-label b"><?= e(t('t_label_before')) ?></span>
            <span class="ba-label a"><?= e(t('t_label_after')) ?></span>
            <span class="ba-mark" id="baMark"><img src="<?= e(asset((string) Settings::get('logo'))) ?>" alt=""></span>
            <input type="range" min="0" max="100" value="50" id="baRange" aria-label="قارن بين قبل وبعد — حرّك يمين وشمال">
            <span class="handle"><span class="knob"><svg class="ic"><use href="#i-lr"/></svg></span></span>
            <p class="ba-note"><svg class="ic sm"><use href="#i-info"/></svg><span data-disclaimer><?= e(t('disclaimer_text')) ?></span></p>
          </div>
        </div>
      </div>
      <div class="tool-copy">
        <span class="chip ok" style="align-self:flex-start"><svg class="ic sm"><use href="#i-check"/></svg><?= e(t('t_result_chip')) ?></span>
        <h2 class="h2"><?= e(t('t_result_title')) ?></h2>
        <?php if (t('t_result_lead') !== ''): ?><p class="lead"><?= e(t('t_result_lead')) ?></p><?php endif; ?>
        <a href="#" class="btn btn-wa btn-lg btn-block" data-wa data-track="whatsapp_click"><svg class="ic"><use href="#i-wa"/></svg><?= e(t('t_result_whatsapp')) ?></a>
        <?php if (t('t_result_whatsapp_note') !== ''): ?><p class="msg" style="justify-content:center;margin-top:-6px"><?= e(t('t_result_whatsapp_note')) ?></p><?php endif; ?>
        <div class="actions">
          <a href="<?= e(Settings::get('website_url', '') ?: '#') ?>" class="btn btn-secondary btn-sm" data-website data-track="website_click" target="_blank" rel="noopener"><svg class="ic sm"><use href="#i-globe"/></svg><?= e(t('t_website_button')) ?></a>
          <button class="btn btn-ghost btn-sm" id="dlBtn"><svg class="ic sm"><use href="#i-download"/></svg><?= e(t('t_download_button')) ?></button>
          <button class="btn btn-ghost btn-sm" id="shareBtn"><svg class="ic sm"><use href="#i-share"/></svg><?= e(t('t_share_button')) ?></button>
        </div>
        <div class="tint encourage">
          <span class="iconbox sm"><svg class="ic"><use href="#i-chat"/></svg></span>
          <div><b class="h3" style="display:block"><?= e(t('encouragement_title')) ?></b><p class="muted"><?= e(t('encouragement_text')) ?></p></div>
        </div>
      </div>
    </div>
  </div>
</section>
