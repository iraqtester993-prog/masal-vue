# حصر التخزين المحلي والاعتماديات المطلوب استبدالها

التاريخ: 2026-10-06. الحصر من المصدر؛ لم تقرأ بيانات المتصفح أو تمسحها.

مسارات المصدر القديم أدناه أصبحت تحت `frontend/legacy/src/services`. يحتفظ legacy بالتخزين القديم لحين اكتمال النقل؛ لا يعد حذفه من مسار البناء الجديد حذفًا لبيانات المستخدمين. المداخل الجديدة تحت `frontend/src` لا يجب أن تستخدم أي حفظ دائم لحالة الحساب أو token، وتبقى جلسة HttpOnly على السيرفر.

| التخزين | المصدر | البديل |
|---|---|---|
| masal-v1 وحالة النظام وضغط LZW | state-storage.js، application.js | جداول Laravel وعلاقاتها؛ لا state JSON كامل |
| masal-login-user | login-screen.js، account-time-controls.js، application.js | جلسة سيرفر HttpOnly والتحقق من /me |
| masal-login-name | login-screen.js | إزالة الحفظ المحلي؛ سياسة تذكر الدخول عبر السيرفر إذا اعتمدت |
| masal-device-serial | login-screen.js | تسجيل جهاز قابل للتحقق؛ لا اعتماد على قيمة محلية يكتبها العميل |
| masal-account-session-clock | account-time-controls.js | ساعة وسياسات جلسة على السيرفر |
| masal-language / masal-appearance / masal-sidebar-collapsed | application.js، vue.js | تفضيلات المستخدم في DB وحالة مؤقتة بالذاكرة |
| masal-category-columns-* | categories-ui.js | تفضيلات المستخدم في DB |
| masal-pos-columns-* | pos-register.js | تفضيلات المستخدم في DB |
| masal-report-columns-* | report-groups.js | تفضيلات التقارير في DB |
| masal-company-theme / masal-company-public-intro | company-site.js | تفضيل سيرفر للمستخدم، حالة ذاكرة للزائر |
| masal-backup-before-* | application.js | أرشيف مستقل قبل مسح البيانات، خارج المتصفح |

البحث الحالي لا يظهر IndexedDB في المصدر. يعاد الفحص بعد إضافة المكتبات لمنع persisted stores أو service worker cache يحمل بيانات خاصة.

## وظائف محلية تحتاج بديلًا

- freshStartRevision وadminLoginRevision وdemoNetworkRevision في application.js: حذف تهيئة تفريغ الحالة وبيانات المدير والعرض بعد اكتمال بديل السيرفر.
- seed في engine.js: تهيئة fixtures للاختبارات فقط؛ مدير الإنتاج ينشأ بوسيلة إدارية آمنة.
- MasalAuth في completion-engine.js: رموز وتحقق وجلسات محلية؛ يستبدل بمصادقة فعلية ولا يظهر demoCode.
- MasalDigitalServer في digital-local-bridge.js: عنوان localhost ثابت؛ يستبدل بـLaravel provider adapters.
- MasalBackupServer.create: مطلوب لكنه غير معرف بالمشروع؛ يلزم خدمة نسخ واستعادة حقيقية.
- MasalDevice/MASAL_DEVICE_SERIAL: نقاط امتداد خارجية غير كافية لإثبات جهاز في المتصفح؛ يلزم قرار حول تطبيق جهاز/وسيط أو آلية تسجيل موثوقة.
- globalThis.Masal* وMasalAppOptions: ترابط side effects وترتيب bootstrap؛ نقل تدريجي إلى modules واضحة وAPI.

## قاعدة المسح

توثق الحاجة إلى البيانات الحقيقية وتؤخذ نسخة قبل المسح. يحذف التطبيق مفاتيح ماسال المعرفة فقط عند زيارة النسخة الجديدة؛ لا localStorage.clear شامل. مفاتيح اختيار العرض والجلسة تزال أيضًا بما يتفق مع طلب عدم التخزين المحلي. لا يمسح HTTP cache للملفات العامة بلا سبب؛ جلسة Cookie المطلوبة للمصادقة تختلف عن حفظ بيانات التطبيق محليًا.

## خط أساس البناء السابق

Vite build ناجح في 2026-10-05: JavaScript 3,250,421 بايت، CSS 477,242 بايت، وأربعة خطوط مجموعها 387,072 بايت. لا قياس لزمن الاستجابة أو المستخدمين المتزامنين حتى الآن. التصغير معطل وكل الأقسام مسجلة مسبقًا. يستكمل القياس في المتصفح قبل اعتماد أهداف الأداء.
