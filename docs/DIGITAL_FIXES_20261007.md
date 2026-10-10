# إصلاح الرابعة وTopup — 2026-10-07

الإصدار المنشور: **20261007-digital-12a** للباكند والبوابات الثلاث. هذه متابعة تنفيذية للتدقيق السابق، وليست إعلان تشغيل الربط التجاري أو اكتمال النظام كله.

| البند | التعديل |
|---|---|
| F1 | حفظ رد شراء الرابعة مشفرًا قبل التحقق من حقوله، وحفظ الرمز الصالح قبل فحص البيانات الثانوية. الرد غير المطابق يبقى للمراجعة. |
| F2 | إعادة فحص الجلسة وإيقافات التشغيل والربط وبصمة التوكن والمنح والفئة وسعرها وإصدارها بعد eligibility وقبل تسجيل بدء الإرسال المالي. |
| F3 | إبطال الرصيد ووقت جلبه عند تبديل التوكن. فحص البصمة الموجود في balance يمنع الرد المتأخر من المفتاح السابق. |
| F4 | تمييز الإجمالي التقديري عن الكلفة المؤكدة، واتفاق السجل مع Dashboard. totalAmount غير موثق بوضوح ككلفة تسوية للوكيل؛ يحفظ في الرد المشفر ولا يصنف كتسوية مؤكدة. |
| F5 | إظهار وقت جلب رصيد الربط وأقدم وقت جلب ضمن إجمالي الأرصدة. |
| F6 | تمييز الفئات المرتبطة حاليًا عن جلب جديد. |
| F7 | الخدمة والوكيل والتوكن وحالة التفعيل في صف للحاسوب وعمود للهاتف. |
| F8 | حفظ رد Topup مشفرًا قبل التحليل ومرجع نجاح الشركة كدليل، دون وصفه كبطاقة ذات PIN مفقود. |
| F9 | استعادة آخر عملية غير مقروءة من السيرفر، ومنع طلب جديد حتى تأكيد الاطلاع على العملية المكتملة وبدء بيع جديد صراحة. المعلقة لا يمكن تجاوزها، ونفس request_id لا يعيد الإرسال. صفحة البيع تستعيد النتيجة حتى إن أوقفت الفئة. |
| F10 | البيع الرقمي من الفئات الموحدة؛ خدمات API لنقطة البيع تعرض السجل ورابط البيع. |

## التحقق

- 65 اختبار باكند و1125 تحققًا ناجحًا، تشمل 12 حالة إضافية؛ اتصالات الشركة محاكاة وSQLite مؤقتة.
- 157 اختبار واجهة ناجحًا وبناء admin وagents وpos. تشغيل Pint وفق تعليمات المشروع.
- مطابقة بصمات 624 ملفًا منشورًا، وعدم تغير بصمات بيانات الحسابات والمستخدمين والربط والمنح والطلبات والمحافظ والقيود المالية وملف البيئة.
- MySQL الحية: حقل acknowledged_at وفهرسه، مخزن الرد MEDIUMTEXT، ومحفزات حماية digital_orders الأربعة موجودة. عدد الطلبات الرقمية صفر.
- معاينة حية لإعداد الربط بعرض 1280 و390؛ لم تحفظ إعدادات أو مبيعات أثناء المعاينة.
- ظهر 403 مؤقت بسبب إذن ملفات HTML عند النشر الأول، وصحح إلى 644. سكربت النشر النهائي يضبط الإذن قبل التفعيل.
- أدلة التنفيذ داخل deployment/.local: digital-fixes-final-tests.log وdigital-full-front.log وdigital-build.log وdigital-12a-deploy.log وdigital-12a-verification.json وdigital-12a-http-report.json.

## الترحيل والنسخ الاحتياطية

الترحيل 2026_10_07_104238_add_acknowledged_at_to_digital_orders يضيف حالة الاطلاع على النتيجة، ويوسع مخزن الرد في MySQL فقط؛ تبقى SQLite وقيودها دون إعادة بناء الجدول. العمليات القديمة المكتملة تعتبر مقروءة، والمعلقة تبقى للاستعادة. الكود السابق يقبل بقاء العمود الإضافي عند الحاجة للرجوع.

نسخة المصدر: C:/Users/PRO/Desktop/masal-backups/masal-before-full-system-20261007-104151-UTC.tar.gz، بصمة SHA256: 9aa0f7a4bd0d9c1e5f08cc91b279071891933c52bf170cd36914fe7640efd2a5. توجد أيضًا نسخة SQLite محلية مفحوصة السلامة.

نسخة الاستضافة الخاصة: /home/dananiriq/masal-backups/before-digital-fixes-20261007-104228، تشمل MySQL والباكند والبوابات والتخزين والبيئة خارج public_html. بصماتها محفوظة في deployment/.local/backup-digital-fixes-report.json.

## ما بقي قبل البيع الفعلي

لم يستخدم توكن المستخدم ولم يرسل طلب شراء أو حجز أو تعبئة فعلي في هذا التعديل. الربط الموجود بلا توكن أو فئات، ولا توجد نقطة بيع مهيأة لهذا الاختبار. التالي: حفظ التوكن للوكيل الصحيح، جلب الكتالوج والرصيد من الاستضافة، التحقق من فئة آسيا سيل 5000 ومعناها وسعرها، إعداد نقطة بيع ومنحها الفئة، ثم مراجعة البيع وتنفيذه بتوجيه المستخدم.

403 السابق جاء من الجهاز المحلي؛ لم يختبر مصدر الاستضافة بالتوكن في هذه المرحلة. لا يوجد مسار موثق لاسترجاع التعبئة أو الاستعلام عن طلب Topup مجهول. تبقى دلالة category وتسوية الكلفة والأنواع المتاحة مرتبطة برد الشركة الفعلي وتأكيد عقدها. اختبارات تغيّر الصلاحيات محاكاة أثناء رد الشركة وليست اختبار حمل MySQL متعدد العمليات. تسجيل بدء الإرسال هو حد القرار؛ لا يمكن لإيقاف لاحق إلغاء طلب خرج للشركة.

## تصحيح الرصيد الفعلي — 20261007-topup-balance-13

- السبب المثبت من الاستضافة: GET /api/v1/inventory أعاد HTTP 200 ومحتوى JSON رقمي مباشر، بينما PDF يصف كائن remaining_balance. النقل العام كان يرفض القيمة المفردة قبل تحويلها إلى رصيد.
- التعديل محصور بقراءة Topup inventory عبر GET: قبول رقم صحيح/عشري دقيق مباشر أو الكائن الموثق؛ بقية المسارات تبقى ترفض القيمة المفردة. القيم السالبة أو ذات الدقة غير المعتمدة أو bool أو HTML أو مصفوفة/رسالة خطأ لا تحل محل الرصيد السابق.
- اختبارات: 77 ناجحة / 1267 تحققًا، منها 12 حالة إضافية. تنسيق Pint ناجح.
- نشر ملفي ProviderGateway وMasalTopupAdapter فقط في إصدار باكند مستقل، مع التحقق من بصمات الملفين قبل وبعد النقل. لم تُستبدل الواجهة أو تعدل حسابات أو توكنات.
- نسخة احتياطية قبل التعديل: /home/dananiriq/masal-backups/before-digital-fixes-20261007-112059. أدلة الاختبار والنشر: deployment/.local/inventory-fix-tests.log وtopup-balance-13-deploy.log.
- التوكنات أصبحت محفوظة بواسطة المستخدم بعد الإصدار السابق؛ كانت قراءة الرصيد مجهولة. لا شراء أو تعبئة في هذا التشخيص.
- تحقق حي من زر «تحديث رصيد الشركة» في لوحة المدير: ظهر رصيد ربط علي محمد **100,000 د.ع** بتاريخ 2026-10-07 الساعة 14:22 بغداد. صورة الإثبات deployment/.local/topup-balance-13-live.png. الفئات المرتبطة ما زالت صفرًا؛ لم ينفذ بيع.

### 2026-10-07 — Topup dashboard display 14
- Backed up production to `/home/dananiriq/masal-backups/before-digital-fixes-20261007-113204` before editing.
- Frontend-only deployment: Topup dashboard now shows the provider balance for each visible account, with fetch time, independently of confirmed spending. Unknown balances remain unknown; account balances are not summed into a potentially duplicated shared-token total. POS does not receive upstream balances.
- Balance details include remaining funds and fetch time. Empty catalog fetches show an explicit provider/category activation message.
- Read-only provider `GET products` for connection 1 returned `status: ok` and `products: []`, before application filtering. Existing balance: IQD 100,000. No provider purchase, funding, token replacement, or category mapping was performed. Provider must supply enabled categories before mapping/sale can proceed.
- Validation: 158 frontend tests pass; admin/agents/POS builds pass. Backend remains `20261007-topup-balance-13`; frontend deployment archive SHA256 `8024f5bd232fb7615c4bb4a0710d62948670e5c45d7734d7c8a88c2b022f7d65`.
- Follow-up 14a fixes SQL UTC balance timestamps before formatting in Baghdad time. Dashboard render/time tests: 3 passed. Final frontend archive SHA256: `c4e91c6e6470776b611d134c88d3acd309ea67fe78eb811dcd28425dd52bbecb`.

### 2026-10-07 — Topup category management 15
- Production backup: `/home/dananiriq/masal-backups/before-digital-fixes-20261007-130806`.
- Added an isolated `topup_categories` table and owner-only CRUD at `/api/v1/topup/categories`; category names, service types, provider references, costs, retail prices, draft/active state, optimistic versions, idempotent saves and audit entries.
- Admin route `/topup/categories`, under separate sidebar group `Topup — آسياسيل`. Category creation does not modify voucher catalog or grant sales permissions.
- Drafts may omit provider reference/prices. Activation requires them; activation here is catalog state, not a successful provider authorization.
- Validation: 159 frontend tests passed; 6 backend category/dashboard tests, 74 assertions; three portal builds passed.
- OUTSTANDING: connecting this independent catalog to agent assignment, downstream digital grants and provider execution. Existing digital connection workflow remains unchanged. No new category has been assigned or sold by this deployment. Shared balance reservation work from the revised specification is also outstanding. Do not label the revised Topup workflow complete.

### 2026-10-07 — Topup name and spending price correction 16
- User clarified local Topup categories have a name and one spending price, with no provider identifier. Removed the provider reference and separate cost/retail inputs from category management. Active categories require a positive exact decimal price; drafts may omit it. The existing cost/retail columns store the same price without changing historical digital orders.
- API rejects client-supplied remote IDs and separate prices; no synthetic provider identifier is generated. Owner-only authorization, optimistic versioning and idempotency remain enforced.
- Production backup: /home/dananiriq/masal-backups/before-digital-fixes-20261007-132219. Local source backup: C:/Users/PRO/Desktop/masal-backups/masal-before-full-system-20261007-132246-UTC.tar.gz.
- Validation: 3 backend tests / 36 assertions, 159 frontend tests, three portal builds, Pint. No live financial calls or test category writes.
- Execution blocker verified from original Masal API Documentation-v2.1.pdf: both checkEligibility and transactions document category as category_id. There is no documented amount field for initiating Topup. Local category creation must not be confused with an executable provider offer. The independent catalog still requires assignment/distribution integration; shared balance reservation work is also outstanding. No claim of completed sales flow.

### 2026-10-07 — Independent Topup distribution 17
- Backups: production /home/dananiriq/masal-backups/before-digital-fixes-20261007-133458; source C:/Users/PRO/Desktop/masal-backups/masal-before-full-system-20261007-133500-UTC.tar.gz.
- Added isolated topup_grants table. System owner assigns active manual categories to a direct main agent. Each agent can grant only its effectively allowed categories to a direct child; POS may attach to any agent level. No credential is copied to child accounts. Existing digital connection endpoint rejects child token ownership.
- Category visibility intersects every grant in the ancestor path and verifies current parent/main relationships, operational accounts and active categories. Revocation/pause propagates on the next read. Saves enforce scope, permissions, versions, runtime lock, idempotency and audit logging.
- Added admin /topup/allocation, agent /topup/distribution and POS /topup/available. Original design tokens/components retained. Topup settings now link to manual category distribution; token saves refresh provider inventory rather than requiring a products catalog. Fourth provider flow unchanged. POS Sell Topup tab links to granted categories.
- Tested 91 backend tests / 1504 assertions (including 4 new distribution tests / 98 assertions); 160 frontend tests; three portal builds; Pint. Coverage includes all hierarchy levels, direct POS at every level, upstream revoke/pause, disabled categories, cross-scope and employee restrictions, stale/idempotent writes and forbidden child credentials. Provider HTTP not used in distribution tests; no live category/grant/order created for QA.
- Backend bundle SHA256 f0092ae7c0e3b814bcf1dc905049f05b4699c6ae9a5dedf0b72cf4176dbfcef2; frontend bundle bbf99255530f227bd457935fc5be59ca3335f7810f12f405509f32858c7c7f48.
- OUTSTANDING: actual execution of manually defined name/price categories. Existing provider documentation requires category_id; no verified name/amount request contract is supplied. New distribution API explicitly reports execution_ready=false and UI shows that sales are not activated. No made-up provider IDs, synthetic catalog snapshot, balance deduction, purchase or financial retry was introduced. Shared-balance reservation/settlement and order/report integration for these categories remain pending the execution contract. Administrative distribution is complete; full Topup sales workflow is not.

- Visual follow-up 17a separates connection status text from the account selectors; frontend delta SHA256 7054d99bf320ff101d559a4e321fa33c3f0ff23338815cfe0ae1f8fbac45471e. Backend remains release17. Live proof: deployment/.local/topup-distribution-17/live-allocation.png.

### 2026-10-07 — Immediate Topup debit clarification and contract recheck
- User requires immediate API fulfillment and debit from the shared main-agent Topup balance for every successful POS sale; later Excel settlement does not defer fulfillment. A local accounting debit alone must never be represented as a completed phone recharge.
- Fresh verified local source backup: C:/Users/PRO/Desktop/masal-backups/masal-before-full-system-20261007-141553-UTC.tar.gz (SHA256 3b99c115e718ffecf0e86fe51812b0ee12107386f5a15c128a8bc34a9f47dff5); accompanying local SQLite backup passed integrity_check. This is not a fresh production database backup.
- Re-read all PDF pages and current DigitalOperations/TopupDistribution implementations. PDF transaction request lists mobile, type, category (category_id), and optional user/agent metadata; no amount request field. Original digital-services.js gateway also forwards remoteId as category and does not send the local category price. Current manual grants remain disconnected from the purchase path.
- Required external evidence: one valid request example for a 5,000 IQD Topup using this contract, specifically the category value or documented amount field. Do not infer that a local database ID or price is accepted as category_id. No live purchase, balance mutation, application-code change, or deployment was performed in this recheck.

### 2026-10-08 — Navigation and stale-content audit
- Confirmed shared RouterView instances were reused across different paths (reference catalogs, accounts, stock). These pages keep old rows until replacement requests resolve. Added a user/path component key so a different page starts with fresh state and runs existing unmount cleanup. Query-only navigation retains existing page behavior.
- API client now uses no-store and rejects a caller-aborted response after JSON parsing, preventing late cancelled reads from returning stale data. No localStorage/sessionStorage/service-worker usage found in frontend/src.
- Production admin /dashboard HTML returned Cache-Control no-store/no-cache/must-revalidate; one unauthenticated HTML timing was 0.339 seconds. This does not measure authenticated APIs or prove overall navigation speed.
- Remaining performance finding: StockWorkspace active inventory tab loops through both statuses and up to 500 pages per status before client-side pagination. This requires server-side combined status filtering; not changed in this audit.
- Validation: 165 frontend tests passed (includes new late-body cancellation regression); all three portal builds passed. Changes are LOCAL ONLY, not deployed or browser-verified. Existing unrelated Topup edits were not deployed.
- Source/SQLite backup: masal-before-full-system-20261007-215656-UTC.tar.gz and local-before-full-system-20261007-215656-UTC.sqlite under C:/Users/PRO/Desktop/masal-backups. Additional exact pre-edit files under deployment/.local/navigation-audit-20261008.

### 2026-10-08 — Navigation fixes deployed (19)
- Deployed fresh route instances by user/path, API no-store and post-body abort checking, and clearing old stock tab data. Active inventory now uses one paginated server request for Loaded/Partially Used instead of fetching every matching page in the browser; account scoping remains enforced.
- Validation: 165 frontend tests passed; Stock suite 41 passed, 1 skipped, 582 assertions; three portal builds passed; Pint passed. Added server pagination/scope regression.
- Production backup: /home/dananiriq/masal-backups/before-digital-fixes-20261007-220133 (UTC folder timestamp). Backend current: releases/20261008-navigation-19. Only StockController changed in backend; no database migration.
- Admin/agents/POS version.json matches local build, HTML has no-store, entry assets returned 200. Initial asset directory permissions caused 403 during verification; corrected directories to 755 and files to 644, then all portal checks passed. Public site unchanged. Authenticated browser navigation timing has not been measured; no claim that all performance causes are eliminated.

### 2026-10-08 — Live navigation investigation and frontend 20a
- Signed into the production admin portal using the authorized account and navigated Dashboard -> Wallets -> Dashboard before changes. Observed old Dashboard still visible immediately after Wallets click while the lazy module loaded; no browser errors. Warm dashboard visible measurement ~1086ms includes UI-tool overhead.
- Prefetch authorized component code when opening the navigation/menu group (and on group hover/focus), honoring existing data-saver policy. Hide the prior route during a pending path change, retaining existing component cleanup and independent data fetching.
- Removed initial portal loading text. Made read-loading paragraphs visually hidden (still accessible) across 31 components, preserving conditional rendering and write/transaction status messages. No legacy reference files deleted; they are not part of portal runtime imports.
- Wallet options and balances now load concurrently; suppress premature no-accounts/zero-count text while the initial read runs.
- Validation: 165 frontend tests passed, all three portal builds passed. Production backup before-digital-fixes-20261007-220809 (UTC folder stamp). Frontend 20a deployed; backend remains navigation-19. All portal version manifests and entry assets verified over HTTPS.
- Live checks after deployment: Wallet structure visible ~391ms after click in this run; Agents actual account data visible ~1212ms. Different destinations/conditions mean these are observations, not a controlled percentage improvement. No purchase or data mutation performed; successful sign-in creates a normal session.
- Screenshot deployment/.local/navigation-live-20/live-wallets.png. Actual API latency still exists; no promise of instantaneous data or verification of every route. No errors in inspected browser console.

### 2026-10-08 — Account list startup 21
- Removed unnecessary account list dependency on options and root-detail requests. List begins immediately; options and authorized root context/detail load alongside it. Aborted/unmounted initialization does not assign root/options or run quick-create. No persistent response cache added.
- 165 frontend tests and three builds passed. Production backup before-digital-fixes-20261007-221512. Frontend21 deployed; backend remains19. All three version manifests verified.
- Authenticated browser navigation Wallets -> Agents: actual Ali account row visible in ~816ms including automation overhead, versus previous ~1212ms observation. Single samples are not a latency guarantee. Reload and list display confirmed; screenshot deployment/.local/navigation-21/agents.png.

### 2026-10-08 — Manual refresh only (22)
- User explicitly rejects release notifications and automatic refresh. Removed release-update runtime, installer, injection and banner from all portal entry points; removed obsolete release-update tests while retaining route-prefetch coverage in route-prefetch.test.js. No polling version.json or automatic release reload remains in portal runtime.
- Three builds and prefetch test passed; live version manifests match. Deployed frontend22 with prior portals archived at /home/dananiriq/masal-backend/.local/manual-refresh-22/previous-portals.tar.gz. Backend unchanged. Existing open browser was not reloaded; user will refresh manually to load this change.

## 2026-10-08 — Performance audit 23

- User scope: dashboard cards, report center, navigation throughout the system, deployment to final portals; no release banner or automatic reload.
- Backup: `/home/dananiriq/masal-backups/before-digital-fixes-20261007-222531` (database, backend, storage, all portals). Local changed-source backup: `deployment/.local/performance-23/before.zip`.
- Root cause: repeated permission and account-operational queries inside the same request. Added a request-local authorization read memo enabled only by authenticated GET middleware and removed in `finally`. No cross-request cache; write requests bypass it.
- Reports options and summary requests now run concurrently. Dashboard chart computes daily amount and transaction count in one query per day.
- API cancellation and timeout now remain active through response-body download, preventing abandoned requests from continuing after route changes.
- Server-side read-only measurements, same current database: dashboard 278 queries / 106 ms -> 58 / 50 ms; report datasets 781 / 286 ms -> 141 / 105 ms. These exclude network/browser and report subcount queries.
- Dashboard payloads (excluding generated timestamp) and report counts match SHA-256 before/after for system owner, main agent, and POS.
- Narrow backend regression: 27 tests, 554 assertions passed, including permission revocation on the next HTTP request and no memoization of write requests.
- Detailed logs and deployment material: `deployment/.local/performance-23/`. Final full-suite and live verification results recorded below after completion.

### Final verification — performance 23

- Activated backend `/home/dananiriq/masal-backend/releases/20261008-performance-23`; all three final portal version manifests match their local builds.
- Full backend suite: 608 tests, 604 passed, 5629 assertions, 4 conditional skips. Subsequently explicitly ran StockVolumeTest with PHP 512 MiB: passed (11 assertions), 50,000 cards, 9.54 seconds, peak phases 200/212/150 MiB on local SQLite. The three remaining skips require private Linux cPanel restore verification; not claimed as executed.
- Frontend: 164/164 tests passed; all three portal builds passed. No dependency or database schema changes.
- Live post-deploy service profile: dashboard 58 queries / 42 ms; report datasets 141 / 99 ms (server processing only).
- Browser click-to-real-data observations: dashboard 916 -> 649 ms; report groups 3295 -> 1634 ms. Single before/after observations, affected by network and browser asset cache, not a universal SLA.
- Authenticated live admin smoke: 36 page routes loaded with content, zero alerts, no remaining read-loading nodes and zero browser error logs. Raw routes and full-navigation timings (including reload/bootstrap/network-idle wait) in `deployment/.local/performance-23/live-pages.json`. Main-agent report opened and rendered its actual two rows.
- Main-agent and POS dashboard/report service results compared before/after against the same database with identical hashes; live interactive smoke was in the administrator portal. This audit did not execute provider purchases, financial transfers or destructive actions.
- No release banner, version polling, or automatic page reload. Screenshot: `deployment/.local/performance-23/dashboard-live.png`.
