# Stage 7 — Critic Review (Round 1)

- **Target:** `docs/PLAN_CAMPAIGNS_V2.md` · FeatureRange lines 1–157
- **Reviewers (all completed):**
  - egm-design (feature-spec-standard): FAIL · 3 🔴 · 14 🟡 · 5 🟢
  - egm-structural (feature-spec-standard): FAIL · 2 🔴 · 6 🟡 · 9 🟢
  - egm-style: 0 🔴 · 8 🟡 · 8 🟢
- **After deduplication:** 4 🔴 Blocker · 17 🟡 Major · 10 🟢 Nit
- **Round status:** not clean (open Blockers/Majors).

The finding IDs below are consolidated. The **Source** column points to each reviewer's original ID: `D` = design, `S` = structural, `T` = style.

---

## 🔴 Blocker

| ID | Finding | Lines | Source |
|---|---|---|---|
| **C-B1** | **No «Alternatives Considered» section.** The plan offers one path, with no evaluation or rejection of: (a) keeping the 9-step wizard and only simplifying it · (b) keeping campaigns priced in credits and capping them with the existing weekly limit · (c) counting posts and designs inside the existing quotas instead of a new unit · (d) keeping a separate critic (`ce_critic` with another provider) instead of self-evaluation · (e) image + text on top vs a full design · (f) the counting point (at creation, at approval, or per post). The standard says this fails automatically. | 1–157 | D-B1, S-B1 |
| **C-B2** | **No problem statement, goals, or non-goals.** §1 describes the solution, not the problem. Scope expands with no boundaries: line 149 (quick post and studio) and line 95 (the agent). | 5–12, 95, 149 | D-B2, S-M1, S-M2 |
| **C-B3** | **No risks section inside the spec.** §10 sits outside the range and covers none of: abusing free allowances and cancellation · concurrency and double-counting · a broken JSON reply from the combined call · cost blow-up on carousels · a cache miss · skill regressions in the old prompts. | 1–157 (188–195) | D-B2, S-B2 |
| **C-B4** | **Free drafts open unlimited idea-generation cost.** Drafts aren't counted (33), cancelling returns the campaign (35), and «فكرة بديلة» (90) is allowed. `ideas_generate` charges credits today (`idea_credits_for`, campaign-flow.php:168–169), and the plan doesn't say whether that stays. The only limit is `rate_limit` (6 per 10 minutes, about 860 calls a day). §3.1 counts one plan request only. | 33–35, 62, 90 | D-B3 (+ Stage 5 M8) |

## 🟡 Major

| ID | Finding | Lines | Source |
|---|---|---|---|
| **C-M1** | **The counting point isn't tied to a real state.** «وافق على الخطة وبدأ يكتب» joins two events. The old flow has no plan-approval action (`approve` approves content, campaign-flow.php:539). Who approves when the agent runs it? What happens to old in-progress `campaigns` without `user_plan_id`? A state machine is needed: draft → approved → counted → cancelled or completed. | 34–36, 95 | D-M9, T-6, S-M3 |
| **C-M2** | **The monthly allowance contradicts itself and is undefined.** «نفس طريقة الحد الأسبوعي» is wrong, because the weekly cap accumulates (packages.php:7, 143–186) while §11.3 proposes expiry. "Month" is undefined (calendar month or from `starts_at`), and the plan doesn't say whether `package_prices` (per duration) carries the campaign fields. | 28, 18 | D-M2, S-M4 |
| **C-M3** | **"0 = no campaigns" removes campaigns from everyone at migration.** Line 21 vs line 169: today every user (legacy, synthetic, signup package, the "extension" period) can run campaigns with credits. The meaning of 0 must be defined, for example "credit-priced campaigns as before". | 21, 169 | D-M1 |
| **C-M4** | **Free campaign designs would still count against the quota.** `plan_used('designs')` counts every AI design even when `credits_used = 0` (plans.php:132–146), and `pkg_weekly_designs` uses the same count. A carousel is one row per slide today (generate-design.php:376–384), so 5 slides = 5 designs. An exclusion rule is needed (a `campaign_id` marker). | 41–42 | D-M3 (+ Stage 5 M3) |
| **C-M5** | **The order of extra redesigns is ambiguous.** «من حصة التصميمات العادية أو بكريدت»: which comes first, and who chooses? Today generate-design.php applies the quota gate and a credit charge together (119–125). | 43 | D-M4, T-5, S-N6 |
| **C-M6** | **The cost formula doesn't match its "worst case".** It leaves out: free regens and redesigns · Vision and its regen · alternative ideas · gateway retries (A3/M10) · the `design_base` writer call per slide (generate-design.php:305–331). The 0.3 has no source. | 62–75, 81 | D-M5, T-8, S-N1 |
| **C-M7** | **Calculator data is incomplete.** The combined call has no history in `ai_runs` yet, and audit M11 says some providers are priced at $0 and old images carry no cost. A bootstrap method (estimate or pilot period) and an "unknown cost" guard are needed. | 74 | D-M6 |
| **C-M8** | **Self-evaluation doesn't match the existing scale.** 5 criteria out of 10 vs 10 criteria out of 100 in `CE_QA_WEIGHTS` (weights sum to 100, ce-write.php:21–70) · `cta` doesn't exist (it's `cta_business`) · «بس أقصر» doesn't say how the weights are renormalised · «الدرجة بتقل» doesn't say by how much, or whether a hard violation blocks the post like `ce_qa_score` (306). | 106–118 | D-M7, S-M5, T-N10/N11 |
| **C-M9** | **Two pass thresholds.** `eval_ready_score` (old, default 80) vs `pass_total` (engine, 85 per quality tier). The plan doesn't say which wins, or whether the tier's `qa_rounds` overrides the "one improvement". | 80, 121 | S-M6, D-M7 |
| **C-M10** | **The self-evaluation risk is understated.** «الضعف الوحيد» isn't accurate. The same call also pushes the model to write to the rubric, makes the improvement trigger depend on a biased score, and loses the post and its evaluation together on truncation (A3). | 123 | D-M7, T-3 |
| **C-M11** | **Going back to the old synchronous flow brings back audit problems.** It's browser-driven (A5: closing the tab stops it), has no idempotency (M2: a double click charges twice), and refunds only on expected errors (M3). With the new allowance, a double click counts twice. The plan doesn't say whether the `ce_jobs` worker and idempotency stay behind the old UI. | 8, 85–96 | D-M8 |
| **C-M12** | **Per-post counting edge cases.** A format change between video and post (campaign-flow.php:287–291) · deleting an idea (`idea_delete`): does the slot come back? · is `posts_allow` what the client picks or the package maximum? · can the client add posts later without the add-on? · does the automatic improvement count against `regens_per_piece`? | 39–40, 52, 121–122 | D-M10, T-N12, S-N7 |
| **C-M13** | **The add-ons don't fit the current model.** `addon_packs.kind` supports `designs` and credits only (packages.php:92–125). `quota_overrides` has no campaign scope. Add-ons need manual approval (`billing_approve`), so a client stuck mid-campaign waits. | 46–49 | D-M11 |
| **C-M14** | **The cache claim is inaccurate.** «بقى» says it's done (audit A4 lists it as deferred). The prefix can't be identical: the Skill block sits at the end of the system prompt with sections varying by stage (`CE_SKILL_STAGE_MAP`), the brand block is built per stage, and old prompts are admin-editable templates with variables mid-text. The provider cache also needs a minimum prefix length and expires within minutes. | 150 | D-M12, T-1, S-N4 |
| **C-M15** | **Skills in the old prompts have no defined path.** `general.md` is just a sample, and activation requires a regression test (ce-skills.php:15, 204–229) · `skill = none` means no skill today (294) · `CE_SKILL_STAGE_MAP` has no stages for the old tasks (campaign ideas, writing, `design_base`, quick post, studio), so a version tested on the engine would go into the old prompts untested. | 144–149 | D-M13, S-N5 (+ Stage 5 M5) |
| **C-M16** | **The data model doesn't support the claims.** The saved style «والحملات اللي بعدها» has no field (only `design_ref_path` per campaign) · «الأفكار اللي اترفضت ماترجعش» has no storage (`idea_delete` deletes, and the avoid list only covers the last 40 titles of the same campaign) · `ce_similarity` against the whole history needs a limit, because its cost grows. | 136, 152–153, 116 | D-M14, S-M4 |
| **C-M17** | **The text is hard for the owner to read and has unsourced claims.** Unexplained terms: `ce_similarity`, `ce_qa_weights`, `eval_ready_score`, `ce_skill_detect`, `design_base`, «توكنز», «الكاش», «Vision», «Skill», «DNA» · the line 67 numbers (4–11 requests, up to 3 images) have no source, and the code allows more (`max_rounds` ≤ 4, `max_regen` ≤ 4) · line 137 is an incomplete condition. | 18–26, 67, 74, 110, 116, 121, 137, 144, 150 | T-7, T-2, T-4, D-N5, D-N3, S-N2, S-N8 |

## 🟢 Nit

| ID | Finding | Lines | Source |
|---|---|---|---|
| C-N1 | Two different `design_brief` lists (105 vs 134), while `CE_BRIEF_FIELDS` has 11 fields. Pick one. | 105, 134 | D-N1 |
| C-N2 | The saved style competes with the 4-image input cap (generate-design.php:213–218). The old flow also has no "design approved" action. | 136 | D-N2 |
| C-N3 | Vision is a separate paid call that's missing from §3.1. | 137 | D-N3 |
| C-N4 | Line 96 («للعرض بس») contradicts line 195 («بتكمّل»). | 96 | D-N4 (+ Stage 5 M2) |
| C-N5 | Decisions stated as settled while open in §11 (34, 42, 44), and rollover isn't mentioned in §2. | 34, 42, 44 | S-M3 |
| C-N6 | File references are inconsistent: full paths in some places, bare names in others. | 8, 10, 129, 138, 154 | S-N3 |
| C-N7 | `general.md` doesn't exist yet. Mark it as new. | 148 | S-N5 |
| C-N8 | §7 mixes three topics; split it into 7.1 / 7.2 / 7.3. | 142–156 | S-N9 |
| C-N9 | «مضمون 100%» overstates. | 138 | T-9 |
| C-N10 | Small wording issues: «حملتين» (53) · «اللي في الباقة» instead of «اللي كتبتها» (75) · «بيحمي التكلفة» is vague (28) · «التعلّم من النتايج» in the summary (9). | 9, 28, 53, 75 | T-13…16 |

---

## Checked and correct (not findings)
- Line 154: `ce_learning_golden_texts()` exists (ce-learning.php:410), but the IDs are engine IDs (Stage 5 M4).
- Identifiers that exist: `ce_skill_detect`, `ce_similarity`, `ce_qa_weights`, `eval_ready_score`, `design_base`, `addon_packs`, `tokens_cached`, `ideas_generate`, `plan_set`, `ce-overlay.php`, `ce-learning.php`, `my-plan.php`, `packages.php`, `profitability.php`. The only one that doesn't exist yet is `general.md`.
