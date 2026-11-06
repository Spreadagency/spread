# Goldfish Annotated Review Report

**Target:** `docs/PLAN_CAMPAIGNS_V2.md` (lines 1–157 · Stage 7, round 1)
**Source:** `docs/egm-review/critic_report.md` (+ cross-references to Stages 5 and 6)

**Classification summary:** 27 PROPOSE FIX · 0 CONTEST · 0 ESCALATE · 4 DECLINE

> No contest: I checked every claim against the code and they're accurate. For example, the old flow's `cf_charge` (campaign-flow.php:92) has no idempotency key, `plan_used('designs')` counts designs even at zero cost, and `CE_SKILL_STAGE_MAP` has no stages for the old tasks.
> Several fixes depend on a business decision. In those I wrote **a default proposal** and marked it «(مقترح — §11)», so you can change it.

---

## Prioritized Findings & AI Suggestions

### [Issue 1] C-B1 [`egm-design`, `egm-structural`] — Severity: Blocker
* **Affected Scope:** [Lines 1–157](../PLAN_CAMPAIGNS_V2.md#L1-L157)
* **Discrepancy:** No «Alternatives Considered» section.
* **Rationale:** The standard fails a spec that proposes one path without comparing it to others.
* ***AI Suggestion:*** PROPOSE FIX
* ***Justification:*** The alternatives were discussed in the conversation (cache, a separate critic, image + text) but never written into the file.
* ***Proposed Edit:***
  * Location: a new section `## 1.5) البدائل اللي اتدرست` after §1.
  * Change: a table with columns «البديل · ميزته · عيبه · القرار وليه»:
    - (أ) keep the 9-step engine and simplify the screens only → rejected: cost stays at 4+ requests per post, and the design stays "image + text";
    - (ب) campaigns priced in credits + the weekly cap → rejected: the client can't see what they're buying, and the margin can't be predicted;
    - (ج) count posts and designs inside the existing quotas, no new unit → rejected: no "campaign" for the client to see and buy, and the carousel counts as 5 designs;
    - (د) a separate critic with another provider (`ce_critic_target`) → kept for the higher tiers only (§5.3): it doubles the cost per post;
    - (هـ) image + text on top (`ce-overlay.php`) → kept as an option: guaranteed spelling, but it looks "stock";
    - (و) count the campaign at creation / per post / at approval → approval chosen (§2.2), with an idea-generation limit (Issue 4).

### [Issue 2] C-B2 [`egm-design`, `egm-structural`] — Severity: Blocker
* **Affected Scope:** [Lines 5–12](../PLAN_CAMPAIGNS_V2.md#L5-L12), 95, 149
* **Discrepancy:** No problem statement, goals, or non-goals, and the scope expands silently.
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:***
  * Location: replace §1 with `## 1) المشكلة والأهداف`.
  * Change:
    - **المشكلة:** (١) the engine's design comes out as an image with no text plus a box on top, and it looks bad · (٢) the cost per post is high (4+ text requests + up to 3 images) · (٣) the 9 steps are hard on the client · (٤) the package doesn't sell a clear "campaign".
    - **الأهداف (measurable):** cost per campaign ≤ a target that comes out of the report (§3) · ≤ 2 text requests per post on average · campaign completion rate ≥ the old flow's · the 👍 rate on designs doesn't drop.
    - **مش ضمن الخطة:** changing video production · the mobile apps · changing prices of existing packages before the report · deleting the engine's code.
    - **نطاق صريح:** the Skills also go into the quick post and the studio (§7) · the 🤖 agent moves onto the new path (§4).
  * Also: a short glossary: «القديم» = `public/api/campaign-flow.php` + tables `campaigns` / `campaign_ideas` · «المحرك» = `includes/ce-*.php` + tables `ce_*`.

### [Issue 3] C-B3 [`egm-design`, `egm-structural`] — Severity: Blocker
* **Affected Scope:** [Lines 1–157](../PLAN_CAMPAIGNS_V2.md#L1-L157) (+ §10)
* **Discrepancy:** No risks inside the spec, and the dimensions it requires are missing.
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:***
  * Location: move §10 inside the spec as `## 8) المخاطر` (before the database section) and add rows:
    - **abuse of drafts and cancellation** → Issue 4;
    - **double counting** (double click · two tabs · the agent) → one idempotency key per action + `SELECT … FOR UPDATE` on the campaign row while counting;
    - **broken JSON reply from the combined call** → one retry with the error in the prompt (like `ce_content_check`), then "needs review" without counting the post;
    - **cost blow-up on carousels** → `carousel_max_slides` + a monthly cost alert per package;
    - **cache miss** → the saving isn't counted in pricing (§3);
    - **skill regressions in the old prompts** → Issue 19.

### [Issue 4] C-B4 [`egm-design`] — Severity: Blocker
* **Affected Scope:** [Lines 33–35, 62, 90](../PLAN_CAMPAIGNS_V2.md#L33-L35)
* **Discrepancy:** Free drafts = unlimited idea generation.
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:***
  * Location: §2.2, a new row + a paragraph.
  * Change: «الخطة الأولى + **إعادتين للخطة** + **5 أفكار بديلة** ببلاش لكل حملة. بعد كده بكريدت (`plan_ideas_cost` زي دلوقتي). وأقصى **2 حملة مسودة** في نفس الوقت». In §3.1 the plan count becomes «1 + إعادات (أقصى 2)».

### [Issue 5] C-M1 [`egm-design`, `egm-style`, `egm-structural`] — Severity: Major
* **Affected Scope:** [Lines 34–36](../PLAN_CAMPAIGNS_V2.md#L34-L36), 95
* **Discrepancy:** The counting point isn't tied to a state.
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** In §2.2:
  * the states: `draft → approved → (completed | cancelled)`;
  * **الخصم لحظة زرار «اعتمد الخطة»** (a new `plan_approve` action that sets `counted_at` and writes `usage_events` with unit `campaigns`);
  * cancelling before any post is written → `plan_event_release`;
  * the agent: the client approves the plan in chat before the agent writes;
  * old `campaigns` from before launch: they stay on the credits system until they finish.

### [Issue 6] C-M2 [`egm-design`, `egm-structural`] — Severity: Major
* **Affected Scope:** [Line 28](../PLAN_CAMPAIGNS_V2.md#L28), 18
* **Discrepancy:** Accumulating vs expiring, and "month" undefined.
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:** Replace line 28 with: «الشهر = كل 30 يوم من بداية الاشتراك (`starts_at`). الحملات **بتتراكم** زي الحد الأسبوعي بالظبط (`pkg_week`): في الشهر التالت مسموح لحد 3 × العدد (مقترح — §11.3). حدود الحملات على الباقة، ومدة السعر (`package_prices`) مابتغيّرهاش في الإصدار الأول.» And update §11.3 to match.

### [Issue 7] C-M3 [`egm-design`] — Severity: Major
* **Affected Scope:** [Lines 21, 169](../PLAN_CAMPAIGNS_V2.md#L21)
* **Discrepancy:** The 0 value would take campaigns away from everyone.
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** «`campaigns_month = 0` = **الحملات بالكريدت زي دلوقتي** (مفيش تغيير لأي حد). القيمة الموجبة = حملات من الباقة.» The same for the free plan, the signup package, and the "extension" period: credits.

### [Issue 8] C-M4 [`egm-design`] — Severity: Major
* **Affected Scope:** [Lines 41–42](../PLAN_CAMPAIGNS_V2.md#L41-L42)
* **Discrepancy:** Campaign designs would still count against the quota, and the carousel counts per slide.
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** «تصميم المنشور جوه حملة بيتعلّم (`content_designs.campaign_alloc = 1`) و`plan_used('designs')` بيستثنيه. الكاروسيل = تصميم واحد في عداد الحملة مهما كان عدد شرايحه (لحد `carousel_max_slides`).»

### [Issue 9] C-M5 [`egm-design`, `egm-style`, `egm-structural`] — Severity: Major
* **Affected Scope:** [Line 43](../PLAN_CAMPAIGNS_V2.md#L43)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** «الإعادة الزيادة: من الحصة العادية الأول (الشهرية والأسبوعية)، ولو خلصت بكريدت (`content_design_cost`). العميل بيشوف قبل ما يدوس هيتحسب من فين.»

### [Issue 10] C-M6 [`egm-design`, `egm-style`, `egm-structural`] — Severity: Major
* **Affected Scope:** [Lines 62–75, 81](../PLAN_CAMPAIGNS_V2.md#L62-L75)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** Two formulas:
  * **average:** the current one, with 0.3 labelled «تقدير مبدئي لحد ما `ai_runs` يجيب الرقم الحقيقي»;
  * **worst case:** خطة × (1+2 إعادات) + منشورات × [(1 + إعادات مجانية) × كتابة + (1 + إعادات تصميم) × شرايح × (كاتب التصميم + صورة) + Vision لو مفتوحة] + فيديو × سكريبت، × 1.2 للمحاولات التانية.
  * And in §3.1, the design is "a design-writer call + an image" **per slide**.

### [Issue 11] C-M7 [`egm-design`] — Severity: Major
* **Affected Scope:** [Line 74](../PLAN_CAMPAIGNS_V2.md#L74)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** «قبل الإطلاق: تقدير من أسعار `ai_prices` × توكنز متوقعة لكل خطوة. بعد الإطلاق: متوسط آخر 30 يوم. أي طلب تكلفته $0 أو من غير usage بيظهر «تكلفة مجهولة» ومابيدخلش المتوسط.»

### [Issue 12] C-M8 [`egm-design`, `egm-structural`, `egm-style`] — Severity: Major
* **Affected Scope:** [Lines 103–118](../PLAN_CAMPAIGNS_V2.md#L103-L118)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** The schema uses the same keys and scale as `CE_QA_WEIGHTS` (out of 100): 5 criteria `hook · audience_fit · value · trust · cta_business` with renormalised weights (30 · 20 · 20 · 15 · 15). `ce_qa_score` computes the total (not the model). A hard violation (forbidden word or CTA missing) = **no pass** whatever the score, the same as `ce_qa_score` today.

### [Issue 13] C-M9 [`egm-structural`, `egm-design`] — Severity: Major
* **Affected Scope:** [Lines 80, 121](../PLAN_CAMPAIGNS_V2.md#L121)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** «حد النجاح = `pass_total` بتاع مستوى الجودة (الافتراضي 85)، و`eval_ready_score` القديم بيتساب للحملات القديمة بس. عدد التحسينات التلقائية = 1، والمستوى الأعلى ممكن يخليه 2 (`qa_rounds`).»

### [Issue 14] C-M10 [`egm-design`, `egm-style`] — Severity: Major
* **Affected Scope:** [Line 123](../PLAN_CAMPAIGNS_V2.md#L123)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** Remove «ده الضعف الوحيد» and replace it with: «مخاطر التقييم الذاتي: الموديل بيدّي نفسه درجات عالية · بيكتب علشان يعدّي المقياس · لو الرد اتقطع بيضيع المنشور والتقييم مع بعض. العلاج: فحص الكود · قراءة متوسط الدرجات قدام 👍👎 كل شهر · ناقد منفصل للمستوى الأعلى.»

### [Issue 15] C-M11 [`egm-design`] — Severity: Major
* **Affected Scope:** [Lines 8, 85–96](../PLAN_CAMPAIGNS_V2.md#L85-L96)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** «الواجهة قديمة، بس التنفيذ **في الخلفية** على `ce_jobs` + الـ worker (الكتابة الجماعية والتصميم): قفل التاب مابيوقفش الشغل. كل خطوة بتتخصم أو بتتعدّ بمفتاح idempotency، والفشل الغير متوقع بيرجّع العدّ.»

### [Issue 16] C-M12 [`egm-design`, `egm-style`, `egm-structural`] — Severity: Major
* **Affected Scope:** [Lines 39–40, 52, 121–122](../PLAN_CAMPAIGNS_V2.md#L39-L40)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** a small table in §2.3:
  * a format change between post and video moves the count to the new type, if there's room;
  * deleting an idea before it's written → nothing to return · after it's written → the slot doesn't come back;
  * the client picks the number at setup (≤ the package limit) and can raise it later up to the limit at no charge;
  * the automatic improvement **doesn't** count against the free regens.

### [Issue 17] C-M13 [`egm-design`] — Severity: Major
* **Affected Scope:** [Lines 46–49](../PLAN_CAMPAIGNS_V2.md#L46-L49)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** Remove the add-ons from v1 (Stage 5 M9) and put them under «بعدين»: they need new kinds in `addon_packs` + a grant at campaign scope + a quick activation path. In v1, the client who wants more pays credits for each extra post.

### [Issue 18] C-M14 [`egm-design`, `egm-style`, `egm-structural`] — Severity: Major
* **Affected Scope:** [Line 150](../PLAN_CAMPAIGNS_V2.md#L150)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** «**هيتعمل (المرحلة 3):** نعيد ترتيب البرومبتات: ملف البراند + الـ Skill كاملة (مش أقسام المرحلة) **في الأول وثابتين**، وتعليمات الخطوة بعدهم. ده محتاج تعديل قوالب الأدمن القديمة. التوفير **مش مضمون** (الكاش عند المزوّد محتاج 1024 توكن ثابت على الأقل وبيروح بعد دقايق)، فمش داخل في التسعير. `tokens_cached` موجود فعلًا ونقيس بيه.»

### [Issue 19] C-M15 [`egm-design`, `egm-structural`] — Severity: Major
* **Affected Scope:** [Lines 144–149](../PLAN_CAMPAIGNS_V2.md#L144-L149)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:***
  * «`general` = **Skill عادية** بتتعمل من عينة جديدة `includes/ce-skills/general.md` (جديد)، وبتتفعّل باختبار الرجوع زي أي Skill. بتشتغل بس لو `ce_skill_detect` مالقاش تخصص **و** العميل مااختارش «من غير Skill» (`none` بيفضل من غير Skill).»
  * «نضيف للخريطة (`CE_SKILL_STAGE_MAP`) مراحل القديم: `campaign_ideas` · `campaign_content` · `design_base` · `quick_post` · `studio`، واختبار الرجوع يغطيهم.»

### [Issue 20] C-M16 [`egm-design`, `egm-structural`] — Severity: Major
* **Affected Scope:** [Lines 116, 136, 152–153](../PLAN_CAMPAIGNS_V2.md#L136)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:***
  * the saved style lives on the brand (`brand_profiles.design_ref_path`), not on the campaign;
  * the rejected ideas: `idea_delete` saves the title in `campaign_idea_rejects` (user_id, title, created_at), and the last 100 go into the avoid list;
  * the duplicate check: against the last 60 posts only.

### [Issue 21] C-M17 [`egm-style`, `egm-design`, `egm-structural`] — Severity: Major
* **Affected Scope:** [Lines 18–26, 67, 110, 116, 121, 137, 144, 150](../PLAN_CAMPAIGNS_V2.md#L67)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:***
  * every technical name gets a short plain-Arabic description first, with the name in brackets;
  * line 67: «من الكود (`ce_write_job` · `ce_design_job`) بالإعدادات الافتراضية: 4 لـ 11 طلب كتابة ولحد 3 صور — والإعدادات الأعلى بتوصل أكتر»;
  * line 137: «للمستوى الأعلى بس: لو المراجعة لقت كتابة غلط أو الصورة بعيدة عن الفكرة، بتتعمل إعادة واحدة».

### [Issue 22] C-N1 [`egm-design`] — Severity: Nit
* **Affected Scope:** [Lines 105, 134](../PLAN_CAMPAIGNS_V2.md#L105)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** «`design_brief` = نفس حقول `CE_BRIEF_FIELDS`» in both places.

### [Issue 23] C-N2 [`egm-design`] — Severity: Nit
* **Affected Scope:** [Line 136](../PLAN_CAMPAIGNS_V2.md#L136)
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** «الستايل المحفوظ بياخد مكان "تصميمات بتعجبك" في الـ 4 صور. والموافقة = زرار «اعتمد التصميم» جديد في خطوة التصميم.»

### [Issue 24] C-N3 [`egm-design`] — Severity: Nit
* ***AI Suggestion:*** PROPOSE FIX (covered in Issue 10: Vision is in the worst-case formula and in §3.1).

### [Issue 25] C-N4 [`egm-design`] — Severity: Nit
* **Affected Scope:** [Line 96](../PLAN_CAMPAIGNS_V2.md#L96) vs 195
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** «حملات المحرك الشغالة بتكمّل لحد ما تخلص، ومفيش حملات محرك جديدة للعملاء. بعد 60 يوم من الإطلاق شغل المحرك بيتقفل والعرض بيفضل.»

### [Issue 26] C-N5 [`egm-structural`] — Severity: Nit
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** Add «(مقترح — §11)» next to every open decision in lines 34, 42, and 44, and mention the rollover in §2 (covered by Issue 6).

### [Issue 27] C-N6 [`egm-structural`] — Severity: Nit
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** Every file name becomes a full path from the root (`includes/ce-overlay.php` · `includes/ce-learning.php` · `public/ajax/generate-design.php`).

### [Issue 28] C-N7 [`egm-structural`] — Severity: Nit
* ***AI Suggestion:*** PROPOSE FIX (covered in Issue 19: «(جديد)»).

### [Issue 29] C-N8 [`egm-structural`] — Severity: Nit
* ***AI Suggestion:*** DECLINE
* ***Justification:*** Splitting §7 into three is purely about formatting. Section 7 is short, and the fixes in Issues 18–20 already make each part clear.

### [Issue 30] C-N9 [`egm-style`] — Severity: Nit
* ***AI Suggestion:*** PROPOSE FIX
* ***Proposed Edit:*** «مضمون 100%» → «مضمون (النص بيتكتب بالكود مش بالموديل)».

### [Issue 31] C-N10 [`egm-style`] — Severity: Nit
* ***AI Suggestion:*** DECLINE (partial)
* ***Justification:*** «2 حملة» is the UI format with a number; the code handles the plural. «بيحمي التكلفة» goes away with the line 28 fix (Issue 6). «التعلّم من النتايج» goes away with the §1 rewrite (Issue 2). «اللي كتبتها» → «اللي في الباقة» gets fixed along the way.

---

## Deferred to the next stages (outside lines 1–157)
- The database section (§8 in the file) will change because of Issues 5, 8, 16, and 20. Stage 9 reviews it.
- Stage 5 M2 (two engines) is covered by Issues 15 and 25. Stage 5 M6 (the package fields copied in three places) goes to Stage 9.
