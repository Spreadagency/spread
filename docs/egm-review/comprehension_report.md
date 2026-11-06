# Stage 6 — Comprehension Test

- **Target:** `docs/PLAN_CAMPAIGNS_V2.md` · FeatureRange 1–157 · DetailedRange 158–205
- **Reviewer:** egm-comprehension (fresh context; read only the document, so the test checks whether it stands on its own)
- **Verdict:** I could paraphrase both sections, so there are no Blockers. 7 🟡 Major · 9 🟢 Nit.

## What this proposes

Subscription packages get a new allowance unit, the "campaign". Each package gets:
- a number of campaigns per month;
- a cap on posts and on video scripts per campaign;
- a number of free rewrites and redesigns per post;
- a maximum number of carousel slides.

A campaign is charged when the client approves the plan and writing starts. It is refunded if the client cancels before any post is written. Credits stay in use for work outside campaigns.

The client sees the five-step "old" campaign flow. Behind it, the "new engine" does the work: brand DNA, per-niche Skills, de-duplication, and learning from results.

Writing and self-evaluation become one model request. Code checks run after it, and one automatic improvement runs if the score is below a threshold.

Design goes back to the old approach: the model renders the full design with the Arabic text and the references, instead of a blank image with text laid on top.

The document also adds an admin cost and margin calculator, a single additive database migration, and a six-phase rollout.

## How the existing system works, per this document

Two campaign systems exist today:
- **The "old" campaign flow** (`campaign-flow.php`, `ideas_generate`, `plan_set`): a few simple steps. Its design generation (`generate-design.php`) produces full designs with Arabic text.
- **The "new engine"** (`ce_*` files and tables, `ce_campaigns`):
  - a 9-step wizard;
  - 4–11 writing requests and up to 3 images per post;
  - a text-overlay mode (`ce-overlay.php`);
  - QA weights (`ce_qa_weights`), a similarity check (`ce_similarity`), skill detection, and learning (`ce-learning.php`).

Packages (`credit_packages`) already have:
- credits;
- a weekly design cap (snapshotted into `user_plans` as `weekly_designs`);
- research quotas;
- a video discount on `video_orders`;
- a `quality_tier` that picks the models.

Prices live in `ai_prices`, per-request usage in `ai_runs`, and an admin profitability report exists.

This paraphrase is partly inferred. In particular, the document never says which system owns `campaigns` and which owns `ce_campaigns`.

---

## Findings

### 🟡 Major

1. **"Old" and "new engine" are never defined** (lines 2, 9, 67, 95–96, 168, 195).
   - The reader has to infer from file names that "new engine" = the `ce_*` code with the 9-step wizard.
   - The terms then get confused:
     - line 168: «حملات المحرك الجديد القديمة»;
     - line 95: «بدل المحرك القديم» (the old flow or the engine being replaced?).
   - The plan never says which table belongs to which system (line 164 extends `campaigns`, line 168 keeps `ce_campaigns`).
   - **Fix:** a short glossary near §1: old flow = these files and tables; new engine = these files and tables; what moves from where to where.

2. **The moment a campaign is charged is vague** (lines 34, 90–91, 200).
   - «العميل وافق على الخطة وبدأ يكتب المحتوى» joins two separate events: approving and starting to write.
   - None of the 5 steps in §4 is an explicit approval action, so the trigger could be leaving step 2, writing the first post, or a button.
   - The refund rule («قبل ما أي منشور يتكتب», line 35) depends on the same point.
   - **Fix:** name the exact trigger and the UI action that fires it.

3. **The monthly allowance and its reset aren't described** (lines 28, 52, 202, §8).
   - The plan points to «نفس طريقة الحد الأسبوعي الموجودة» without describing it.
   - It isn't clear:
     - whether "month" means a calendar month or the subscription's anniversary;
     - how "1 of 2 this month" is counted;
     - whether `counted_at` is the field used for that count (§8 adds no period field).

4. **"Higher packages" is never defined** (lines 123, 137, 191–192).
   - The separate critic and Vision are tied to a tier that isn't defined: `quality_tier`, named packages, or a new flag? And §8 adds no field for it.
   - Line 137 reads as an incomplete condition. It probably means "one redo only if…", but the sentence doesn't say so.

5. **The §3.2 formula doesn't match its "worst case" claim** (lines 71–75, 81). The formula leaves out:
   - free rewrites (`regens_per_piece` × posts);
   - free redesigns (`redesigns_per_piece` × posts, each with a prompt and an image);
   - optional research;
   - the critic and Vision.

   Also, "0.3 تحسين" is an average, not a worst case, and it's unclear whether it's a placeholder until the `ai_runs` averages come in.

6. **The current package economy is assumed, not explained** (lines 12, 28, 41, 43–44, 203). Used without explanation:
   - credits;
   - the weekly design cap;
   - «حصة التصميمات العادية» and «حصة الأبحاث العادية»;
   - what "research" is;
   - «المنشور السريع» and «الاستوديو».

   Line 43 («من حصة التصميمات العادية أو بكريدت») doesn't say which applies first.

7. **Scoring and code checks are vague** (lines 106, 110, 118, 121).
   - «الدرجة بتقل» doesn't say by how much or how.
   - The 0–100 `score` and the five 0–10 criteria have no stated relation.
   - «بس أقصر» doesn't say what gets shortened relative to `ce_qa_weights`.
   - The value and location of `eval_ready_score` aren't given.

### 🟢 Nit

8. **"Brand file (DNA)"** isn't defined: what it holds and where it's stored (lines 9, 150).
9. **Skills gaps** (lines 89, 164):
   - line 89 cites «أسئلة البريف من الـ Skill», and §7 doesn't list that section;
   - `skill_version_id` appears without explaining skill versioning or why a campaign pins a version.
10. **The agent «🤖 الوكيل»** isn't described: what it does today and which engine it drives (line 95).
11. **Auto-improvement vs `regens_per_piece`** (lines 24, 63, 121–122):
    - the plan implies, but doesn't state, that auto-improvement doesn't use up `regens_per_piece`;
    - it's unclear whether line 39 and the «حسّن» button (line 122) share one counter.
12. **§8 heading «ترحيل واحد + رجوع»** (line 158): no rollback procedure is described.
13. **§8 columns aren't explained** (lines 163–166):
    - why `campaigns` needs `posts_allow` / `videos_allow` when `user_plans` already has a snapshot (probably for add-ons);
    - what `usage_events` is and what a "unit" means there;
    - where the brand style that carries across campaigns is saved («والحملات اللي بعدها لو حب», line 136), given `design_ref_path` is per campaign;
    - where rejected ideas are remembered (line 153).
14. **Cache tense:** «بقى أول البرومبت» (line 150) reads as already done, while Phase 3 (line 179) lists it as future work.
15. **Deployment:** «حزمة الملفات اللي اتغيرت بس + خطوات cPanel» (line 184) assumes a deployment process that isn't explained.
16. **«تقييمات العملاء 👍👎»** (line 191) assumes an existing rating feature that isn't described.

**Recommendation:** fix findings 1–7 before approving the document.
