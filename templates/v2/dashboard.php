<?php
/**
 * Spread AI v2 — الرئيسية الجديدة (زي التصميم)
 * متغيرات: $user · $d (من dashboard_v2_data)
 */
$__start = $d['campOn'] ? 'campaigns.php?new=1' : 'create-content.php';
$h = $d['health'];
$toneCls = ['danger' => 'da-danger', 'violet' => 'da-violet', 'amber' => 'da-amber', 'blue' => 'da-blue'];
?>
<div class="dh">

    <!-- ═══ صباح الخير + الروبوت ═══ -->
    <section class="card dh-hero" style="grid-area:hero">
        <div class="dh-hero-txt">
            <h2><?= e($d['greeting']) ?> <?= e($d['first']) ?> 👋</h2>
            <p>حوّل أفكارك إلى محتوى احترافي في دقائق مع قوة الذكاء الاصطناعي.</p>
            <div class="dh-hero-cta">
                <a href="<?= url($__start) ?>" class="dh-start">ابدأ الآن <?= ui_icon('chevron', 18) ?></a>
                <span class="dh-hint"><i></i> خلّي الذكاء الاصطناعي يبدأ معك</span>
            </div>
        </div>
        <div class="dh-robot" aria-hidden="true">
            <span class="dh-robot-glow"></span>
            <picture>
                <source media="(min-width: 981px) and (prefers-reduced-motion: no-preference)" srcset="<?= url('assets/img/robot-anim.webp') ?>" type="image/webp">
                <img src="<?= url('assets/img/robot.webp') ?>" alt="" width="330" height="357" decoding="async">
            </picture>
            <span class="dh-think"><span></span></span>
        </div>
    </section>

    <!-- ═══ Brand Brain (موبايل بس — الديسكتوب في السايدبار) ═══ -->
    <a href="<?= url('brand-brain.php') ?>" class="card dh-brand" style="grid-area:brand">
        <span class="dh-brand-ic"><?= ui_icon('brain', 20) ?></span>
        <span class="dh-brand-txt">
            <span class="dh-brand-top"><b>Brand Brain</b><b><?= (int) $h['pct'] ?>%</b></span>
            <span class="bar"><span class="bar-fill" style="width:<?= (int) $h['pct'] ?>%"></span></span>
            <small><?= !empty($h['unlocked']) ? 'هويتك جاهزة — المحتوى بيطلع بأسلوبك' : 'كمّل هويتك علشان المحتوى يطلع بأسلوبك' ?></small>
        </span>
        <?= ui_icon('chevron', 18) ?>
    </a>

    <!-- ═══ يحتاج منك إجراء + رحلتك الأولى ═══ -->
    <section class="card dh-act" style="grid-area:act">
        <div class="dh-act-head">
            <h3><span class="dh-bolt">⚡</span> يحتاج منك إجراء
                <?php if ($d['actionsTotal']): ?><em class="dh-count"><?= (int) $d['actionsTotal'] ?></em><?php endif; ?></h3>
            <?php if ($d['actions']): ?><a href="<?= url('content-history.php') ?>" class="dh-more">شوف الكل ←</a><?php endif; ?>
        </div>
        <?php if ($d['actions']): ?>
            <div class="dh-chips">
                <?php foreach ($d['actions'] as $a): ?>
                    <a href="<?= url($a['url']) ?>" class="dh-chip <?= $toneCls[$a['tone']] ?? '' ?>"><b><?= (int) $a['n'] ?></b> <?= e($a['label']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php if ($d['journey']['next']): ?>
                <p class="dh-clear">✓ مفيش منشورات مستنياك — كمّل رحلتك الأولى تحت علشان تبدأ</p>
            <?php else: ?>
                <p class="dh-clear">✓ مفيش حاجة مستنياك دلوقتي — كل حاجة ماشية</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($d['journey']['next']): $j = $d['journey']; ?>
            <div class="dh-journey">
                <div class="dh-j-txt">
                    <div class="dh-j-row"><b>رحلتك الأولى</b><span><?= $j['pct'] ?>%</span></div>
                    <span class="bar"><span class="bar-fill" style="width:<?= $j['pct'] ?>%"></span></span>
                    <small>الخطوة الجاية: <?= e($j['next']['label']) ?> · <?= $j['done'] ?> من <?= $j['total'] ?></small>
                </div>
                <a href="<?= url($j['next']['url']) ?>" class="btn soft sm">كمّل من حيث وقفت</a>
            </div>
        <?php endif; ?>
    </section>

    <!-- ═══ حملة غير مكتملة ═══ -->
    <section class="card dh-camp" style="grid-area:camp">
        <?php if ($c = $d['campaign']): ?>
            <div class="dh-camp-head"><b>حملة غير مكتملة</b><small>آخر تعديل: <?= e(ui_time_ago($c['updated_at'])) ?></small></div>
            <div class="dh-camp-row">
                <span class="dh-camp-thumb"><?= ui_icon('calendar', 22) ?></span>
                <div>
                    <b class="dh-camp-title"><?= e($c['title']) ?></b>
                    <small>وصلت لمرحلة <?= e($c['stage_name']) ?> · <?= (int) $c['stage'] ?> من 6</small>
                </div>
            </div>
            <div class="dh-j-row"><span class="sub">التقدم</span><b><?= (int) $c['progress'] ?>% مكتملة</b></div>
            <span class="bar"><span class="bar-fill" style="width:<?= (int) $c['progress'] ?>%"></span></span>
            <p class="sub dh-camp-note">عندك حملة لسه مكملتش — كمّلها من حيث وقفت.</p>
            <a href="<?= url('campaign.php?id=' . (int) $c['id']) ?>" class="dh-follow">متابعة <?= ui_icon('chevron', 16) ?></a>
        <?php else: ?>
            <div class="dh-camp-head"><b>حملاتك</b></div>
            <div class="dh-camp-empty">
                <span class="dh-camp-thumb"><?= ui_icon('sparkles', 22) ?></span>
                <p>مفيش حملة شغالة دلوقتي. ابدأ حملة والـ AI يطلعلك الأفكار والمحتوى والتصميم.</p>
            </div>
            <a href="<?= url($__start) ?>" class="dh-follow"><?= $d['campOn'] ? 'حملة جديدة' : 'منشور جديد' ?> <?= ui_icon('chevron', 16) ?></a>
        <?php endif; ?>
    </section>

    <!-- ═══ ابدأ بسرعة ═══ -->
    <section class="dh-quick" style="grid-area:quick">
        <h3 class="dh-title">ابدأ بسرعة</h3>
        <div class="dh-quick-grid">
            <?php foreach ([
                ['design-studio.php', 'image', 'q-blue', 'أصنع تصميم', 'تصميم احترافي بالذكاء الاصطناعي', true],
                ['create-content.php', 'doc', 'q-teal', 'أصنع محتوى', 'منشورات وسكريبتات جاهزة', true],
                [$d['campOn'] ? 'campaigns.php?new=1' : 'content-plan.php', 'bulb', 'q-dark', 'أفكار جديدة', 'اكتشف أفكارًا لحملتك القادمة', false],
            ] as [$href, $ic, $cls, $t, $s, $needsBrand]): ?>
                <a href="<?= url($href) ?>" class="card dh-q <?= $cls ?>">
                    <span class="dh-q-top">
                        <span class="dh-q-ic"><?= ui_icon($ic, 22) ?></span>
                        <?php if ($needsBrand && empty($h['unlocked'])): ?>
                            <span class="dh-q-lock" title="النتيجة بتبقى أحسن لما هويتك تعدّي <?= (int) $h['gate'] ?>%"><?= ui_icon('lock', 12) ?> <?= (int) $h['pct'] ?>%</span>
                        <?php endif; ?>
                        <span class="dh-q-go">↖</span>
                    </span>
                    <b><?= e($t) ?></b>
                    <small><?= e($s) ?></small>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (function_exists('ui_research_on') && ui_research_on()): ?>
        <!-- ⑦-ب البحث العميق — مدخل واضح (الموبايل مالوش سايدبار) -->
        <a href="<?= url('research.php') ?>" class="card dh-rs">
            <span class="dh-q-ic"><?= ui_icon('research', 20) ?></span>
            <span><b>البحث العميق 🔬</b><small>اعرف منافسينك وجمهورك والسوق — بمصادر حقيقية</small></span>
            <span class="dh-q-go">↖</span>
        </a>
        <?php endif; ?>
    </section>

    <!-- ═══ ملخص الشهر ═══ -->
    <section class="card dh-month" style="grid-area:month">
        <div class="dh-camp-head"><b>ملخص الشهر</b><span class="dh-ai-chip"><span class="dh-spin"></span> AI بيحلل أداءك</span></div>
        <div class="dh-stats">
            <a href="<?= url('analytics.php') ?>"><span class="dh-st-ic s1"><?= ui_icon('send', 18) ?></span><b><?= $d['month']['posts'] ?></b><small>منشور هذا الشهر</small></a>
            <a href="<?= url('analytics.php') ?>"><span class="dh-st-ic s2"><?= ui_icon('chart', 18) ?></span><b><?= (int) $d['month']['usage']['pct'] ?>%</b><small>Usage</small></a>
            <a href="<?= url('analytics.php') ?>"><span class="dh-st-ic s3"><?= ui_icon('image', 18) ?></span><b><?= $d['month']['designs'] ?></b><small>تصميمات الشهر</small></a>
            <a href="<?= url('campaigns.php') ?>"><span class="dh-st-ic s4"><?= ui_icon('megaphone', 18) ?></span><b><?= $d['month']['active'] ?></b><small>حملات نشطة</small></a>
        </div>
    </section>

    <!-- ═══ أحدث المحتويات ═══ -->
    <section class="dh-recent" style="grid-area:recent">
        <div class="dh-act-head">
            <h3 class="dh-title">أحدث المحتويات</h3>
            <?php if ($d['recent']): ?><a href="<?= url('content-history.php') ?>" class="dh-more">عرض الكل ←</a><?php endif; ?>
        </div>
        <?php if ($d['recent']): ?>
            <div class="dh-recent-grid">
                <?php foreach ($d['recent'] as $r):
                    $meta = function_exists('content_status_meta') ? content_status_meta($r['st']) : ['label' => '', 'color' => '#8391A6'];
                    $title = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $r['generated_text'])));
                ?>
                    <a href="<?= url('content-view.php?id=' . (int) $r['id']) ?>" class="card dh-post">
                        <span class="dh-post-img">
                            <?= ui_icon('image', 26) ?>
                            <?php if ($r['cover']): ?>
                                <?php /* لو الملف اتمسح من السيرفر الصورة بتشيل نفسها وتظهر الأيقونة بدل صورة مكسورة */ ?>
                                <img src="<?= e(upload_url($r['cover']) ?? url('storage/' . $r['cover'])) ?>" alt="" loading="lazy" decoding="async"
                                     onerror="this.remove()">
                            <?php endif; ?>
                            <em class="dh-post-type"><?= e(function_exists('content_type_label') ? content_type_label((string) $r['content_type']) : $r['content_type']) ?></em>
                        </span>
                        <span class="dh-post-body">
                            <b><?= e(mb_substr($title !== '' ? $title : 'بدون نص', 0, 46)) ?><?= mb_strlen($title) > 46 ? '…' : '' ?></b>
                            <span class="dh-post-meta">
                                <span class="cm-st" style="--st:<?= e($meta['color']) ?>"><?= e($meta['label']) ?></span>
                                <small><?= e(ui_time_ago($r['created_at'])) ?></small>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card dh-empty">
                <span class="dh-camp-thumb"><?= ui_icon('doc', 22) ?></span>
                <p>لسه ماعملتش محتوى. ابدأ بأول منشور والـ AI يكتبه بأسلوب براندك.</p>
                <a href="<?= url('create-content.php') ?>" class="btn sm">اعمل أول منشور</a>
            </div>
        <?php endif; ?>
    </section>
</div>
