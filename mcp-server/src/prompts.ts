/**
 * البرومبتات الجاهزة: المراجعة اليومية (بتتعرض في شات Claude) + تقييم منشور واحد.
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
2. \`spread_list_reports\` (type="daily", limit=1) — آخر تقرير، علشان ماتكررش نفس التوصيات.
3. المنشورات: \`spread_list_posts\` (date_from=date_to=${day}, rating="down") وبعدها (unevaluated_only=true). اختار لحد ${n} منشورات (الأولوية: 👎، بعدين إعادة توليد كتير، بعدين غير متقيّمة)، وافتح كل واحد بـ \`spread_get_post\` (بالصور) ${evalStep}.
4. البلاغات: \`spread_list_issues\` (status="new")${opts.canWrite ? " وحلّل كل واحد بـ `spread_analyze_issue`" : ""}.
5. \`spread_get_ai_errors\` (date=${day}) و\`spread_get_security_logs\` (date=${day}).
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
