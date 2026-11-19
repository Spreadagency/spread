/**
 * البرومبتات الجاهزة: المراجعة اليومية (بتتعرض في شات Claude) + مراجعة الأمان الأسبوعية + تقييم منشور واحد.
 */
import type { McpServer } from "@modelcontextprotocol/server";
import * as z from "zod/v4";

function yesterday(): string {
  const d = new Date(Date.now() - 86400_000);
  return d.toISOString().slice(0, 10);
}

export function registerPrompts(server: McpServer, opts: { canWrite: boolean; canDraft: boolean }): void {
  server.registerPrompt(
    "daily_review",
    {
      title: "المراجعة اليومية",
      description: "تقرير اليوم: الأرقام مقابل اليوم اللي قبله، تقييم أسوأ المنشورات، البلاغات، أخطاء الـ AI، الأمان، والاقتراحات — ويتحفظ ويتعرض هنا في الشات.",
      argsSchema: z.object({
        date: z.string().optional().describe("اليوم اللي بنراجعه YYYY-MM-DD (افتراضي: امبارح)"),
        evaluate: z.string().optional().describe("عدد المنشورات اللي تتقيّم (افتراضي 5)"),
      }),
    },
    async ({ date, evaluate }) => {
      const day = date && /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : yesterday();
      const n = Math.min(15, Math.max(1, Number(evaluate) || 5));
      const save = opts.canWrite
        ? `7. احفظ التقرير بـ \`spread_save_report\` (type="daily", report_date="${day}", summary = التقرير كامل، payload = الأرقام الأساسية).`
        : "7. (الاتصال ده للقراءة بس — التقرير مش هيتحفظ، اعرضه بس.)";
      const evalStep = opts.canWrite
        ? `واحفظ تقييم كل واحد بـ \`spread_save_evaluation\` حسب \`rubric://content-quality\``
        : "وقيّم كل واحد حسب `rubric://content-quality` (من غير حفظ)";
      const draft = opts.canDraft
        ? "   - لو فيه اقتراح بأدلة قوية، سجّله بـ `spread_propose_change` (بعد ما تتأكد إنه مش مرفوض قبل كده)."
        : "   - الاقتراحات تتكتب في التقرير بس (أدوات المسودات مش متفعّلة).";
      return {
        messages: [
          {
            role: "user" as const,
            content: {
              type: "text" as const,
              text: `اعمل المراجعة اليومية لـ Spread AI عن يوم ${day}، بالمصري.

1. \`spread_get_metrics\` (date_from=date_to=${day}) — القمع والجودة والكريدت والـ AI، ومقارنة باليوم اللي قبله، والـ anomalies.
2. \`spread_list_reports\` (type="daily", limit=2) — آخر تقرير، علشان ماتكررش نفس التوصيات. لو فيه تقرير لنفس اليوم معمول «تلقائي من الموقع» (بيبدأ بـ «التقرير اليومي التلقائي»)، ابني عليه — تقريرك هيحل محله لما تحفظه.
3. المنشورات: \`spread_list_posts\` (date_from=date_to=${day}, rating="down") وبعدها (unevaluated_only=true). اختار لحد ${n} منشورات (الأولوية: 👎، بعدين إعادة توليد كتير، بعدين غير متقيّمة)، وافتح كل واحد بـ \`spread_get_post\` (بالصور) ${evalStep}.
4. البلاغات: \`spread_list_issues\` (status="new")${opts.canWrite ? " وحلّل كل واحد بـ `spread_analyze_issue`" : ""}.
5. \`spread_get_ai_errors\` (date=${day}) و\`spread_get_security_logs\` (date=${day}) و\`spread_integrity_check\` (أي مشكلة ترابط عددها أكبر من 0 تدخل في «🔒 الأمان» بالعدد والحل) و\`spread_list_security_findings\` (source="config" — اللي لسه open بس).
6. A/B: لو فيه برومبت عليه نسخة تجربة في \`spread_list_prompt_templates\`، شوف \`spread_get_ab_results\` (ماتنصحش بتغيير لو sample_ok = false).
${save}
8. اعرض التقرير هنا في الشات بالشكل ده:
   **📊 الملخص** (3 سطور) · **📈 الأرقام** (جدول: المقياس · اليوم · اللي قبله · التغيير) · **⚠️ اللي محتاج انتباه** (أي تغيير أكبر من 20%) · **✍️ الجودة** (متوسط التقييم + أسوأ منشور وسببه) · **⚑ البلاغات** · **🤖 أخطاء الـ AI** · **🔒 الأمان** · **💡 التوصيات** (3 بالكتير، كل واحدة بالدليل).
${draft}

قواعد: مفيش أي تعديل على المنصة — كله قراءة وتقييم ومسودات بس. ماتكتبش أرقام تليفونات أو إيميلات. لو أداة رجعت خطأ، اذكره في التقرير وكمّل.`,
            },
          },
        ],
      };
    },
  );

  server.registerPrompt(
    "security_review",
    {
      title: "مراجعة الأمان الأسبوعية",
      description: "الإعدادات · سلامة البيانات · ZAP · المكتبات · اختبار الموقع · السجلات — بالأولوية والحل، ويتحفظ تقرير أمان.",
      argsSchema: z.object({}),
    },
    async () => {
      const week = new Date().toISOString().slice(0, 10);
      return {
        messages: [
          {
            role: "user" as const,
            content: {
              type: "text" as const,
              text: `اعمل مراجعة الأمان الأسبوعية لـ Spread AI، بالمصري.

1. \`spread_list_reports\` (type="security", limit=1) — آخر مراجعة، علشان تقول إيه اتصلّح وإيه لسه.
2. \`spread_list_security_findings\` لكل مصدر: config · zap · dependency · code_review · logs. ركّز على open و in_progress. الـ accepted_risk و false_positive اذكر عددهم بس.
3. \`spread_integrity_check\` — أي فحص عدده أكبر من 0 بالعدد والحل (من غير أرقام عملاء في التقرير).
4. \`spread_get_e2e_results\` — الصفحات اللي فشلت وسبب كل واحدة.
5. \`spread_get_security_logs\` لآخر 3 أيام (date لكل يوم) — دخول فاشل متكرر · رفض Internal API · استهلاك كريدت غير طبيعي.
6. رتّب كل اللي لقيته: حرجة ← عالية ← متوسطة ← منخفضة، وكل بند: إيه المشكلة · ليه مهمة · الحل بالخطوات (من docs/SECURITY.md لو موجود).
${opts.canWrite ? `7. أي حاجة جديدة لقيتها بنفسك (مش متسجلة) سجّلها بـ \`spread_save_security_finding\` (source="code_review" أو "logs"). وبعدين احفظ التقرير بـ \`spread_save_report\` (type="security", report_date="${week}").` : "7. (الاتصال ده للقراءة بس — اعرض التقرير بس.)"}
${opts.canDraft ? "8. لو فيه تعديل كود أو إعداد واضح، سجّله بـ `spread_propose_change` (الأدمن بيوافق)." : ""}

قواعد: مفيش أي تعديل على المنصة. ماتكتبش أسرار أو توكنات أو إيميلات أو أرقام تليفونات حتى لو ظهرت في نتيجة. لو أداة رجعت خطأ، اذكره وكمّل.`,
            },
          },
        ],
      };
    },
  );

  server.registerPrompt(
    "evaluate_post",
    {
      title: "تقييم منشور",
      description: "تقييم منشور واحد بالمعايير (محتوى + تصميم) وحفظ التقييم.",
      argsSchema: z.object({ post_id: z.string().describe("رقم المنشور") }),
    },
    async ({ post_id }) => ({
      messages: [
        {
          role: "user" as const,
          content: {
            type: "text" as const,
            text: `قيّم منشور Spread رقم ${post_id}:
1. اقرا المعايير من \`rubric://content-quality\`.
2. \`spread_get_post\` (post_id=${post_id}) بالصور، و\`spread_get_brand\` للبراند بتاعه.
3. ادي كل معيار من 1 لـ 10 مع سبب في سطر.
${opts.canWrite ? "4. احفظ بـ `spread_save_evaluation`، واعرض الإجمالي اللي رجع." : "4. اعرض التقييم (الاتصال ده للقراءة بس)."}
5. في الآخر: أهم 3 تعديلات محددة (في البرومبت أو الاستايل أو بيانات البراند). بالمصري.`,
          },
        },
      ],
    }),
  );
}
