# خادم الـ MCP: الأدوات (المرحلة 4)

العنوان: `https://mcp.ai.spreadagency.net/mcp` (Streamable HTTP).
الكود في `mcp-server/`، والتركيب في `docs/DEPLOY.md`.

```
Claude ──OAuth/Bearer──▶ خادم الـ MCP (VPS) ──توكن + X-Scope──▶ Internal API (الموقع) ──مستخدم MySQL محدود──▶ القواعد
```

الخادم **مابيكلمش MySQL أبدًا**: كل حاجة بتعدي على الـ Internal API، والمنع الحقيقي في MySQL نفسه (المرحلة 3).

## الصلاحيات (OAuth scopes)
| الصلاحية | بتفتح | Internal API |
|---|---|---|
| `spread:read` | أدوات القراءة (17) | `read` |
| `spread:evaluate` | التقييم والتسجيل (5) | `ai_write` (قاعدة الـ AI بس) |
| `spread:draft` | المسودات (4)، **مقفولة لحد المرحلة 6** (`MCP_ENABLE_DRAFT_TOOLS`) | `draft` (قاعدة الـ AI بس) |

- صاحب الموقع بيختار الصلاحيات في صفحة الموافقة، والقراءة إجبارية.
- التوكن بيشوف **الأدوات المسموحة له بس**: توكن قراءة مابيظهرلوش أي أداة كتابة أصلًا.
- الـ Bearer الثابت (`MCP_STATIC_TOKENS`) واخد الصلاحيات التلاتة.

**ممنوع خالص**، ومفيش أداة ليه: الحذف · الكريدت · الدفع · الاشتراكات · الحسابات والباسوردات · رصيد الأفلييت · نشر برومبت أو تطبيق أي تعديل من غير موافقة الأدمن.

## أدوات القراءة: `spread:read`
| الأداة | بتعمل إيه | أهم المدخلات |
|---|---|---|
| `spread_health` | حالة الـ Internal API وقاعدة الـ AI | — |
| `spread_get_metrics` | القمع والجودة والكريدت والـ AI لكل مزود + مقارنة بالفترة اللي قبلها + أي تغيير أكبر من 20% | `date_from` `date_to` (افتراضي: امبارح) |
| `spread_list_posts` | المنشورات بفلاتر | `date_from` `date_to` `rating` `min_regenerations` `prompt_version_id` `provider` `brand_id` `format` `unevaluated_only` `limit≤50` `cursor` |
| `spread_get_post` | المنشور كامل + **صور التصميمات نفسها** | `post_id` `include_images` `max_images≤6` |
| `spread_get_image` | صورة تصميم أو صورة بلاغ (`media?path=uploads/…`) | `path` |
| `spread_get_brand` | الهوية كاملة والخدمات والعملاء والشخصيات وذاكرة البراند (الأرقام والإيميلات متخفية) | `brand_id` |
| `spread_list_prompt_templates` | البرومبتات: المفعّل والتجربة + أرقام كل إصدار | — |
| `spread_get_prompt_version` | نص إصدار وأرقامه | `id` |
| `spread_get_ab_results` | المفعّل مقابل التجربة + `sample_ok` | `template_key` |
| `spread_list_style_presets` | استايلات التصميم | `kind` |
| `spread_list_issues` | بلاغات العملاء + السياق + صورة الشاشة + التحليل | `status` `limit` `cursor` |
| `spread_get_ai_errors` | أخطاء التوليد متجمّعة | `date` `provider` |
| `spread_list_change_requests` | الاقتراحات ومعاها المرفوض وملاحظة الأدمن | `status` `limit` `cursor` |
| `spread_list_reports` | التقارير اللي اتحفظت | `type` `limit` `cursor` |
| `spread_get_security_logs` | دخول فاشل · نشاط الـ rate limit · كريدت غير طبيعي · أخطاء PHP | `date` |
| `spread_list_security_findings` | ملاحظات الأمان لكل مصدر | `source` = zap · dependency · code_review · config · logs |
| `spread_get_e2e_results` | نتايج الاختبارات الكاملة (المرحلة 8 بتملاها) | `date` |

## التقييم والتسجيل: `spread:evaluate`
| الأداة | بتعمل إيه |
|---|---|
| `spread_save_evaluation` | تقييم منشور: 8 معايير محتوى + 6 تصميم (من 1 لـ 10). **الخادم هو اللي بيحسب الإجمالي** (0.6 محتوى + 0.4 تصميم) |
| `spread_analyze_issue` | تصنيف البلاغ · الخطورة · السبب · الحل · البلاغات المرتبطة (الحفظ التاني بيحدّث) |
| `spread_save_report` | تقرير يومي أو أسبوعي أو أمان (واحد لكل نوع في اليوم) |
| `spread_save_security_finding` | ملاحظة أمان (نفس المصدر + المكان = تحديث، مش تكرار) |
| `spread_save_integrity_issue` | نتيجة فحص ترابط البيانات |

## المسودات: `spread:draft` (بتتفعّل في المرحلة 6)
`spread_propose_change` · `spread_draft_prompt` · `spread_draft_style` · `spread_draft_reply`
- كلها بتسجّل **مسودة** ومابتطبّقش أي حاجة.
- الاقتراح اللي اترفض قبل كده بنفس الأدلة بيترفض تلقائي.

## الموارد والبرومبتات
| | |
|---|---|
| `rubric://content-quality` | معايير التقييم وإزاي تتحسب |
| برومبت `daily_review` | المراجعة اليومية كاملة: الأرقام ← آخر تقرير ← تقييم أسوأ المنشورات ← البلاغات ← أخطاء الـ AI ← الأمان ← A/B ← حفظ التقرير ← **عرضه في الشات** (`date` اختياري، `evaluate` = عدد المنشورات) |
| برومبت `evaluate_post` | تقييم منشور واحد وحفظه (`post_id`) |

## شكل الردود
- **JSON:** نص + `structuredContent`، وتحت 25,000 حرف. لو الرد أكبر، القوايم بتتقلّل (مش النص) ومعاها `_truncated`.
- **الصفحات:** `limit` + `cursor`، والرد فيه `next_cursor`.
- **الأخطاء:** نص عربي واضح فيه الحل، من الـ Internal API نفسه. مثال: «المنشور #99 مش موجود — هات الأرقام من مسار posts».
- **المدخلات الغلط** بتترفض قبل ما توصل للموقع (zod)، زي تقييم 15 من 10.

## الحماية في الخادم
| | |
|---|---|
| OAuth 2.1 | Authorization Code + **PKCE S256 بس** · تسجيل تلقائي (DCR) والرجوع على `claude.ai` / `claude.com` / localhost بس · `iss` في الرد (RFC 9207) |
| الموافقة | بباسورد صاحب الموقع (scrypt)، وبعد 5 محاولات غلط من نفس الـ IP (أو 20 من الكل) الدخول بيتقفل ربع ساعة |
| التوكنات | access لمدة ساعة ومقفول على `/mcp` (aud) · refresh بيتغيّر مع كل استخدام، ولو اتعاد استخدامه العيلة كلها بتتلغي · `npm run revoke-all` بيلغي كل الدخول |
| الطلبات | حد لكل عميل (240/دقيقة) ولكل IP · Host/Origin validation · الخادم على `127.0.0.1` ورا nginx |
| السجل | كل أداة بتتسجّل في `mcp_audit_log` باسم العميل (`mcp:<client>`)، وبتظهر في الأدمن ← إعداد الـ MCP |

## الملفات
- `mcp-server/src/index.ts`: HTTP و stdio · الحماية · السجل.
- `mcp-server/src/server.ts`: الأدوات حسب الصلاحية.
- `mcp-server/src/api.ts`: عميل الـ Internal API.
- `mcp-server/src/tools/`: `read.ts` · `evaluate.ts` · `drafts.ts`.
- `mcp-server/src/rubric.ts` · `mcp-server/src/prompts.ts`
- `mcp-server/src/auth/`: `oauth.ts` · `store.ts` · `crypto.ts`.
- `mcp-server/scripts/`: `smoke.ts` · `hash-password.mjs` · `revoke-all.mjs`.
- `mcp-server/deploy/`: `spread-mcp.service` · `nginx-mcp.conf`.
