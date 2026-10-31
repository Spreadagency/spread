# Internal API: الإعداد والاستخدام (المرحلة 3)

الـ API ده **لخادم الـ MCP بس**، مش للمتصفح.
العنوان: `https://ai.spreadagency.net/internal-api/v1/index.php?r=<المسار>`

## الحماية (7 طبقات)
1. **IP:** الطلبات مسموحة بس من IP خادم الـ MCP (الـ VPS): `INTERNAL_API_IPS`.
2. **التوكن:** `Authorization: Bearer <INTERNAL_API_TOKEN>`. لو الاستضافة شالت الهيدر ده، استخدم `X-Internal-Token`.
3. **الصلاحية:** `X-Scope: read | ai_write | draft` ولازم تطابق المسار. العمليات اللي بتكتب لازم `POST`.
4. **مستخدم MySQL لكل صلاحية:** المنع في MySQL نفسه.

   | الصلاحية | قاعدة المنصة | قاعدة الـ AI |
   |---|---|---|
   | `read` | SELECT بس | SELECT |
   | `ai_write` | **ولا حاجة** | SELECT · INSERT · UPDATE (**من غير DELETE**) |
   | `draft` | **ولا حاجة** | SELECT · INSERT |

5. **حد للطلبات:** 300 طلب في الدقيقة لكل IP (`iapi_rate_per_min`). والتوكن الغلط بيتسجّل.
6. **إخفاء البيانات الشخصية:**
   - الإيميلات والأرقام بتتخفى في أي رد: `0101***678` و`a***@gmail.com`.
   - التوكنات والباسوردات بتتشال من الرد خالص.
7. **سجل:** كل طلب بيتسجّل في `mcp_audit_log`، وبيظهر في الأدمن ← «إعداد الـ MCP».

**النتيجة:** الـ MCP مايقدرش يحذف أي حاجة، ومايقدرش يلمس الكريدت ولا الدفع ولا الاشتراكات ولا الحسابات ولا الأفلييت. كل اللي بيقدر يعمله في المنصة إنه يقرا. وأي تعديل بيروح مسودة الأول، ومابيتطبّقش غير لما الأدمن يوافق.

---

## الإعداد على cPanel (مرة واحدة)

### 1) قاعدة الـ AI
cPanel ← **MySQL® Databases** ← Create New Database ← `spread_ai`.
cPanel بيضيف اسم الحساب قدامها، فهتبقى مثلًا `spreadagency_spread_ai`.

### 2) المستخدمين التلاتة
من نفس الصفحة ← **Add New User**: اعمل 3 مستخدمين، كل واحد بباسورد قوي مختلف.
مثلًا: `spreadagency_mcpr` و `spreadagency_mcpa` و `spreadagency_mcpd`.

### 3) الصلاحيات (Add User To Database)
| المستخدم | القاعدة | الصلاحيات اللي تعلّم عليها **بس** |
|---|---|---|
| `mcpr` (read) | قاعدة المنصة | **SELECT** |
| `mcpr` (read) | قاعدة الـ AI | **SELECT** |
| `mcpa` (ai_write) | قاعدة الـ AI | **SELECT · INSERT · UPDATE** |
| `mcpd` (draft) | قاعدة الـ AI | **SELECT · INSERT** |
| مستخدم المنصة الحالي | قاعدة الـ AI | **ALL PRIVILEGES** (الأدمن بيعرض ويوافق) |

⚠️ ماتديش `mcpa` ولا `mcpd` أي صلاحية على قاعدة المنصة، وماتعلّمش على DELETE لأي واحد فيهم.

> على VPS عندك عليه صلاحية root: استخدم `sql/2026_mcp_db_users.example.sql` بعد ما تغيّر الأسماء والباسوردات.

### 4) `includes/config.php`
ضيف في آخر الملف. الشكل موجود في `config.sample.php`:
```php
define('MCP_AI_DB_NAME', 'spreadagency_spread_ai');
define('MCP_DB_READ_USER', 'spreadagency_mcpr');  define('MCP_DB_READ_PASS', '…');
define('MCP_DB_AI_USER', 'spreadagency_mcpa');    define('MCP_DB_AI_PASS', '…');
define('MCP_DB_DRAFT_USER', 'spreadagency_mcpd'); define('MCP_DB_DRAFT_PASS', '…');
define('INTERNAL_API_TOKEN', '…');   // 64 حرف عشوائي — نفس القيمة في .env بتاع خادم الـ MCP
define('INTERNAL_API_IPS', 'IP_الـVPS');
```
توليد توكن (على أي جهاز):
```
php -r "echo bin2hex(random_bytes(32));"
```

### 5) الجداول والتأكد
1. الأدمن ← **AI CENTER ← إعداد الـ MCP** ← «إنشاء جداول قاعدة الـ AI».
2. بعدها «🔒 اختبار حدود الصلاحيات»: لازم يطلع **14 / 14 مرفوض**.
3. ولازم كل الصفوف في «الحالة» تبقى ✅.

> لو حصل «Access denied» وانت بتنشئ الجداول: مستخدم المنصة مش واخد صلاحية على قاعدة الـ AI (الخطوة 3، آخر سطر).

### 6) الاختبار الكامل (اختياري، من SSH أو Terminal في cPanel)
```
php tests/internal-api-smoke.php https://ai.spreadagency.net
```
- بيجرّب كل المسارات، ويثبت حدود الصلاحيات، ويتأكد إن السجل شغال، وبيمسح بيانات الاختبار في الآخر.
- لازم يتشغّل من IP مسموح، يعني ضيف IP السيرفر نفسه في `INTERNAL_API_IPS` وقت الاختبار.

---

## المسارات
| المسار | الصلاحية | بيعمل إيه |
|---|---|---|
| `health` | read | الحالة + هل الجداول موجودة |
| `metrics` | read | القمع (تسجيل ← هوية ← أفكار ← اختيار ← منشورات ← تصميمات ← جدولة ← نشر) · إعادة التوليد · التعديل اليدوي · التقييمات · الكريدت · أخطاء وسرعة كل مزود. `date_from/date_to` (افتراضي: امبارح) + مقارنة بالفترة اللي قبلها + أي تغيير أكبر من 20% |
| `posts` | read | فلاتر: `date_from` `date_to` `rating=up/down/none` `min_regenerations` `prompt_version_id` `provider` `brand_id` `format` `unevaluated_only=1` · `limit≤50` `cursor` |
| `post` | read | `post_id`: النص · التصميمات (+ تقييمها) · الفكرة · الهوية وقتها · الاستايل · البرومبت المستخدم · النسخ · التعديلات اليدوية (قبل ← بعد) · التقييمات · آخر 20 منشور للبراند (للتكرار) |
| `media` | read | `path`: الصورة نفسها بالتوكن (ملفات `uploads/` بس) |
| `brand` | read | `brand_id`: الهوية كاملة · الخدمات · المواضيع · العملاء المثاليين · الشخصيات · الأشكال · المستندات · ذاكرة البراند |
| `prompt_templates` · `prompt_version` | read | المفعّل ونسخ التجربة + أرقام كل إصدار (منشورات · 👍👎 · إعادة توليد · متوسط تقييم Claude) |
| `ab_results` | read | `template_key`: المفعّل مقابل التجربة + هل العينة كفاية (`ab_min_sample` = 30) |
| `style_presets` | read | استايلات التصميم |
| `issues` | read | `status`: البلاغات + السياق + صورة الشاشة + التحليل |
| `ai_errors` | read | `date` `provider`: الأخطاء متجمّعة |
| `change_requests` | read | `status`: الاقتراحات ومعاها ملاحظة الأدمن (علشان المرفوض مايتعادش) |
| `reports` | read | التقارير اللي اتحفظت |
| `security_logs` | read | `date`: دخول فاشل بالـ IP · نشاط الـ rate limit · استهلاك كريدت غير طبيعي · أخطاء PHP |
| `security_scan` · `dependency_audit` · `e2e_results` | read | نتايج فحوصات الأمان والـ E2E (المرحلة 8 بتملاها) |
| `save_evaluation` | ai_write | `post_id` + `content_scores` (8 معايير من 1 لـ 10) + `design_scores` (6) + نقط القوة والضعف والتعديل. الإجمالي = 0.6 محتوى + 0.4 تصميم (`mcp_rubric_weights`) |
| `analyze_issue` | ai_write | تصنيف البلاغ وخطورته وسببه والحل والبلاغات المرتبطة |
| `save_report` | ai_write | `daily/weekly/security` (تقرير واحد لكل نوع في اليوم، والحفظ التاني بيحدّثه) |
| `save_security_finding` · `save_integrity_issue` | ai_write | تحديث لو المشكلة موجودة (من غير تكرار) |
| `propose_change` | draft | اقتراح بالأدلة. **المرفوض قبل كده بنفس الأدلة بيترفض تلقائيًا** (`already_rejected`)، والمكرر المستني مراجعة مابيتسجلش تاني |
| `draft_prompt` · `draft_style` · `draft_reply` | draft | مسودات مستنية موافقة الأدمن |
| `test_regenerate` · `integrity_check` | — | المرحلة 6 والمرحلة 8 |

الأخطاء بترجع نص عربي واضح فيه الحل، ومعاه `code`: `auth` · `scope_mismatch` · `not_found` · `invalid` · `already_rejected` · `db_denied` · `rate_limit`.

## الملفات
- `public/internal-api/v1/index.php` + `.htaccess`
- `includes/internal-api.php`: البوابة والحماية والإخفاء والسجل.
- `includes/internal-api-routes.php`: المسارات.
- `includes/mcp-config.php`: قاعدة الـ AI، واتصال لكل صلاحية، واختبار الصلاحيات.
- `sql/2026_mcp_ai_db.sql` (+ `_rollback`) و`sql/2026_mcp_db_users.example.sql`
- `admin/mcp-setup.php`
- `tests/internal-api-smoke.php` و`tests/internal-api.http`
