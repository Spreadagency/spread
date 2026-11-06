# Stage 5 — Initial Zoom Out Review

- **Target:** `docs/PLAN_CAMPAIGNS_V2.md` (full file, lines 1–205)
- **Reviewer:** egm-zoom-out (fresh context, no conversation history)
- **Standard:** `zoom-out-standard.md`
- **GoldfishContext:** `includes/plans.php` · `includes/packages.php` · `includes/credits.php` · `public/api/campaign-flow.php` · `public/ajax/generate-design.php` · `includes/ce-design.php` · `includes/ce-write.php` · `includes/ce-skills.php` · `docs/CONTENT_ENGINE_AUDIT.md`

**Verdict:** Passes the context check. The direction holds: keep the old flow's UX, add the new engine's strengths, cut the request count. But several items the plan treats as new work already exist, the campaign entitlement logic has no defined home, and the plan leaves two campaign engines running side by side.

**Totals:** 0 🔴 Blocker · 9 🟡 Major · 5 🟢 Nit

---

## 🟡 Major

### M1 — The problem statement is thin, and the plan quietly reverses the audit roadmap
- **Where:** §1 (lines 5–12), §4 line 96.
- **Problem:** §1 lists features but never says why the 9-step wizard is leaving the client's menu. `CONTENT_ENGINE_AUDIT.md` §د plans phases D–I around that engine: Wizard, Matrix, Plan Gate, Writer→Critic with up to 2 rounds, and Vision QA. This plan undoes much of that, for example by merging the writer and the critic into one self-evaluated call. The only hint at a reason is line 67. There are no success metrics: no target cost per campaign, no acceptable change in quality scores, no completion-rate goal.
- **Fix:** Add a short "why" section that names the problems (cost, client drop-off, design quality from "blank image + text") with numbers from `ai_runs`. Say which audit phases are cancelled or replaced. Add 2–3 measurable acceptance criteria.

### M2 — Two campaign engines stay alive, and the plan contradicts itself about the old one
- **Where:** §4 line 96 ("للعرض بس") vs §10 line 195 ("بتكمّل زي ما هي"), §8 line 168.
- **Problem:** One line says view only, the other says running campaigns keep executing. If they keep executing, the system still has to maintain `ce_campaigns`, `ce_write_job`, `ce_design_job` and `cron/ce-worker.php`, plus the old flow, plus a third hybrid where the old flow calls `ce_*` internals. That is two campaign models, two evaluation paths, and two design pipelines.
- **Fix:** Pick one behaviour. The recommended one: running `ce_campaigns` finish, no new ones start, and after a set date the job handlers become read-only. Also state which way the dependency runs. A small shared module extracted from `ce-write.php` / `ce-skills.php` is better than calling `ce_*` internals that are about to be frozen.

### M3 — Campaign entitlement logic has no home, and it collides with design-quota counting
- **Where:** §2.3 lines 41 and 43, §4, Phase 4.
- **Problem:**
  - **Design counting:** campaign designs go through `generate-design.php`, which calls `plan_gate($uid, 'designs')` (line 119). `plan_used('designs')` (plans.php:132–146) counts every AI design, and `pkg_weekly_designs` (packages.php:175–186) uses the same count. So "a free design outside the weekly cap" means changing how designs are counted everywhere, including the percentages on «باقتي».
  - **Post charging:** campaign posts are charged today through `cf_charge(cost_for_format(...))` (campaign-flow.php:292–293). The plan doesn't say where the decision "free within the campaign allowance, otherwise credits" gets made. Without one place, the rule will be scattered across `campaign-flow.php`, `generate-design.php`, and the agent.
- **Fix:** One interface, for example `campaign_allowance` with gate, consume, and release, built on the existing `plan_event` / `usage_events` / `plan_counted` (plans.php:295–350). Both paths call it. Also define the exclusion rule inside `plan_used('designs')`.

### M4 — Several items listed as new already exist
- **Where:** §5.1, §5.2, §5.3 line 123, §6 line 135, §7 lines 148 and 154.
- **What already exists:**
  - **Carousel first slide as reference:** already in `generate-design.php:100–111` (with lines 206–207).
  - **`design_brief`:** the old flow already returns `design` / `image_prompt` (`contents.design_direction`), and the engine has `CE_BRIEF_FIELDS` (ce-write.php:29) plus normalisation in `ce_content_check`. Extend one of them, not a third shape.
  - **Code checks (§5.2):** already in `ce_content_check` / `ce_content_flags` (ce-write.php:219–283). `ce_qa_score` (286–309) already computes the verdict in code without trusting the model, which also answers the self-evaluation risk in §10.
  - **Separate critic with a different model:** already exists as `ce_critic_target` + `ce_critic_other_provider` (325–342).
  - **Dedup:** `ce_used_hooks` (102–116) already merges the engine's ideas with the old `contents` text.
  - **Golden examples:** `golden_ce_ids` are engine IDs, not old `contents` IDs. The mapping work is missing from the plan.
- **Fix:** Next to each item, name the function being reused, and change the work from "build" to "wire into the old flow".

### M5 — The plan bypasses skill versioning and the regression test
- **Where:** §7 line 148.
- **Problem:** Skills live in the database (`ce_skills` + versions), and activating one requires a regression test (`ce_skill_activate`). Files in `includes/ce-skills/` are only import samples (`ce_skill_import_samples`). Treating a file as the runtime source creates a second, unversioned path. Also, `skill = 'none'` currently means no skill at all (`ce_skill_for_dna`), and the general skill changes that meaning.
- **Fix:** Ship `general.md` as one more sample, imported as a normal skill (`general`) through the existing save, test, and activate flow. Use it only when `ce_skill_detect` returns null and the client hasn't explicitly chosen `none`.

### M6 — Package fields are copied in three places, breaking the existing quota pattern
- **Where:** §2.1, §8.
- **Problem:** 6 columns on `credit_packages`, the same 6 on `user_plans`, and `posts_allow` / `videos_allow` again on `campaigns`. The code already snapshots quotas as JSON (`quotas_json` + `plan_quotas_decode`) and overrides them per duration via `package_prices` (packages.php:59–76), which the plan ignores. `plan_start` (plans.php:356–392) is already a growing chain of `UPDATE` statements.
- **Fix:** One `campaign_rules_json` on the package, snapshotted once into `user_plans` from `plan_start` (or extend `quotas_json`). The campaign row keeps only what it needs (`user_plan_id`, `counted_at`). Decide whether limits vary by duration (`package_prices`) or by package only.

### M7 — The monthly-allowance rule contradicts itself
- **Where:** §2.1 line 28 vs §11 Q3 line 202.
- **Problem:** "Same as the existing weekly cap" doesn't hold. The weekly cap accumulates ("اللي ماتصرفش بيفضل", packages.php:7, `N × weekly`), but the proposal says unused campaigns expire at month end.
- **Fix:** Pick one rule. If it accumulates, use `pkg_week` with a monthly period. If it doesn't, drop the "same as weekly" claim and describe how the window is computed, because `plan_used` only knows the whole cycle.

### M8 — The counting point lets idea generation run free with no limit
- **Where:** §2.2, §4 step 2.
- **Problem:** The campaign counts only when the plan is approved, and drafts are free. But the old flow charges for ideas today (`idea_credits_for`, campaign-flow.php:168–169). Under this plan a client can regenerate a full plan over and over, an AI call each time, without ever approving. The only limit is `rate_limit` at 6 per 10 minutes. Also, "approve the plan" is a new state: the existing `approve` action (539–547) approves written content, not the plan.
- **Fix:** Cap free plan or idea regenerations per draft (for example 1 plan + N alternative ideas), or count the campaign at the first plan generation and refund on cancel. Name the state transition explicitly, for example a `plan_approve` action that sets `counted_at` and writes a `usage_events` row with unit `campaigns`.

### M9 — Optional extras are inside the core scope (YAGNI)
- **Where:** §2.3 line 44, §2.4, §3.2 lines 75–76, §5.3 line 123, §6 lines 136–138, and `regens_per_piece` / `redesigns_per_piece`.
- **Problem:** Each one adds a setting, a counter, or a code path before the core campaign unit is proven. `addon_apply` (packages.php:107–126) only knows `designs` and credits, so every new add-on kind means new logic. Two per-piece counters plus "من حصة التصميمات أو بكريدت" give three charge paths for a single redesign.
- **Fix:** v1 is campaigns per month, posts per campaign, videos per campaign, and the slide cap. Use one pool of free edits per campaign instead of two per-piece counters, with credits beyond it. Defer research-in-campaign, the new add-on kinds, the saved style across campaigns, and Vision or the critic for higher packages. For pricing, a read-only report over `ai_runs` + `ai_prices` is enough to set the numbers. A calculator inside the package editor only comes in if that report isn't enough.

---

## 🟢 Nit

- **N1 — Carousel slide limits have three sources of truth:** `carousel_limits()` (content-formats.php), `ce_content_check` (5–8 hard-coded, ce-write.php:234), and the new `carousel_max_slides`. The engine check would reject the small-package limits. **Fix:** the package value clamps `carousel_limits()`, and the shared check reads from it.
- **N2 — The self-evaluation schema doesn't match the existing scorer:** the plan uses `cta` and scores out of 10, while `ce_qa_weights` uses `cta_business`, scores out of 100, evidence and a fix per criterion, and weights that must sum to 100. **Fix:** pass a reduced weight map into `ce_qa_score`. This also fixes the averaging bug in `campaign_parse_eval` (audit M12).
- **N3 — "The plan and all the ideas in one request" ignores the current batching:** the old flow batches 5 ideas per call plus a top-up because of truncation (audit A1/A3, campaign-flow.php:180–198). 12 dated ideas in one request needs batching or a justified token budget.
- **N4 — UI copy is too detailed for an architecture plan** (§2.5 lines 52–53).
- **N5 — The provider cache is described as already done** (§7 line 150, "بقى أول البرومبت"). It only holds after the old prompts are reordered (`campaign_content_prompt`, `design_base`), and audit item A4 is still pending. **Fix:** describe it as Phase 3 work.

---

## Reuse map
| Need | Existing code |
|---|---|
| Entitlements | `plan_event` · `plan_counted` · `plan_event_release` · `usage_events` (plans.php:295–350) |
| Monthly windows | `pkg_week` (packages.php:143) |
| Rule snapshots | `quotas_json` + `plan_start` + `package_prices` |
| Content checks and scoring | `ce_content_check` · `ce_content_flags` · `ce_qa_score` · `ce_qa_fixes` · `ce_critic_target` · `ce_used_hooks` · `ce_similarity` |
| Skills | `ce_skill_block` · `ce_skill_items` · `ce_skill_questions` · `ce_skill_for_campaign` (generalise `ce_skill_bind_campaign` beyond `ce_campaigns`) + save, test, and activate for `general` |
| Carousel reference | `generate-design.php:100–111` |
| Design brief | `CE_BRIEF_FIELDS` + old `design_direction` / `image_prompt` |
