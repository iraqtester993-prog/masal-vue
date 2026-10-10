# حصر وظائف ماسال — حالة النقل ومرجع المصدر

التاريخ: 2026-10-06. هذا سجل الحصر والأساس الأول، وليس وصف الحالة الفعالة اليوم. استُكملت لاحقًا مراجعة التنقل الفعلي والوجهات والأزرار واعتماديات الأعمال في [الحصر الكامل الحالي](ORIGINAL_PROJECT_INVENTORY.md)، وانتقل العمل إلى الروابط النهائية وفق [سجل القطع](FINAL_DOMAIN_CUTOVER.md). أسماء بعض المكونات أدناه استبدلت في دفعات لاحقة؛ مرجع الإنجاز هو [MIGRATION_PLAN.md](../MIGRATION_PLAN.md). قراءة المصدر ليست إثبات اختبار كل تفاعل؛ أدلة الأساس التاريخية في [FOUNDATION_VERIFICATION.md](FOUNDATION_VERIFICATION.md).

بعد فصل المشروع، ملفات التنفيذ القديم في جدول المجالات تقع داخل `frontend/legacy/src/`. عددها 213 ملف مصدر محفوظة ومطابقة بايتًا لنسخة ما قبل النقل، ولا تدخل في builds البوابات الجديدة. البوابات الجديدة تعرض الهوية والحسابات وتسمح بالإنشاء الأولي وفق النطاق؛ بقية شاشات الأعمال القديمة لم تنتقل كاملة.

## ما نُفذ ونُشر وقت الأساس الأول

| الوظيفة | المصدر الجديد | التحقق وحدود الإنجاز |
|---|---|---|
| بوابات المدير والوكلاء ونقطة البيع | `frontend/portals/{admin,agents,pos}` و`frontend/src/entries` و`frontend/src/layouts` | builds مستقلة على admin-test/agents-test/pos-test عبر HTTPS؛ نفس بوابة agents للمستويات الثلاثة مع نطاقات مختلفة |
| الدخول والخروج واستعادة الجلسة الحالية | `frontend/src/modules/auth` و`frontend/src/shared/api/client.js`، `backend/routes/web.php` وخدمات المصادقة | login/logout/me بجلسة فعلية وCSRF وفرض البوابة على السيرفر؛ دخول المدير info@iraqtechno.com من المتصفح مؤكد؛ استعادة كلمة المرور غير منفذة |
| تصميم دخول ماسال والقائمة والألوان | `frontend/src/modules/auth/LoginPage.vue` و`frontend/src/layouts/PortalLayout.vue` و`frontend/src/shared/styles` | التصميم الأصلي وخط Cairo ورسوم الدخول مستعادة دون تشغيل legacy؛ لا يعني ذلك نقل كل dashboard أو شاشات الأعمال |
| قائمة الحسابات الأساسية | `frontend/src/modules/accounts/AccountsPage.vue`، APIs الحسابات في Laravel | `account.view` ونطاق السيرفر وpagination وحالات loading/empty/error وإلغاء الطلبات القديمة؛ البحث والشجرة الموسعة والتفاصيل القديمة لاحقة |
| إنشاء التابع ومستخدمه الأول | `frontend/src/modules/accounts/CreateAccountDialog.vue` و`child-types.js`، `backend/tests/Feature/AccountCreationTest.php` | `account.create` وسياق الأب؛ account/user/membership/closure/audit في معاملة واحدة؛ 201 ثم تحديث القائمة؛ لا نقل لكل حقول الحساب القديم |
| النشاط والنطاق ومنع التصعيد | سياسات وخدمات Laravel واختبارات `AccountScopeTest` و`PortalIsolationTest` و`AccountHierarchyTest` | تعطيل user/membership/account/ancestor أو سلسلة ناقصة يمنع الوصول؛ الموارد الخارجية 404؛ رفض حقول role/status/permissions؛ لا واجهة إدارة إيقاف/تفويض/نقل بعد |
| الفصل بين الذاكرة والبيانات الدائمة | session Vue وAPI client الجديدان | بيانات العمل والهوية في الذاكرة فقط؛ لا localStorage/sessionStorage/IndexedDB في الأساس؛ التخزين القديم موجود في legacy ولم نمسح بيانات المتصفح أو نستوردها |
| نشر staging والتحقق | `deployment/` وLaravel خارج المسار العام في `/home/dananiriq/masal-staging/backend/current` | release `20261006-foundation-01`، والبوابات داخل `/home/dananiriq/public_html/masal-staging/portals/{admin,agents,pos}`؛ migrations/seed/optimize وInnoDB صريح؛ 16 فحص MySQL مع rollback لجميع fixtures؛ النطاق الرئيسي والإنتاج النهائي لم يُستبدلا |

الإنشاء المسموح: system→main_agent، main_agent→sub_agent/pos، sub_agent→sub_branch/pos، sub_branch→pos؛ نقطة البيع بلا أبناء. نقطة البيع تحت أي مستوى وكيل قرار معتمد. يمكن اختيار أي أب داخل شجرة المنفذ، وليس حسابه فقط. المستخدم مرتبط بعضوية واحدة في المرحلة الأولى؛ تعدد العضويات مؤجل.

المدخلات الأولية هي اسم الحساب ونوعه واسم صاحب الدخول واسم المستخدم والبريد وكلمة المرور وتأكيدها. يحدد السيرفر الدور والحالة والصلاحيات الأولية، ولا يعيد كلمة المرور في الاستجابة أو يسجلها في التدقيق. المحافظات والأجهزة والمستمسكات وإتاحة المنتجات والموظفون والتفويض تتطلب إجراءات نقل مستقلة.

نتائج التحقق: 74 اختبار باك و343 assertion ناجحة، و17 اختبار واجهة ناجحة، وبناء البوابات الثلاث وlegacy ناجح. لا تعمم هذه النتيجة على وظائف الجدول القديم التالية.

## منهج الحصر

تمت مراجعة NAV وتهيئة التطبيق وكتالوج MasalAccess وقواعد النطاق والتسجيل والتخزين. لكل مجال أدناه بديل على السيرفر واختبار قبول. يجب استكمال تتبع الدوال ومسارات الفشل والتحقق التشغيلي قبل اعتماد الحصر كاملًا.

جدول المجالات التالي مرجع للسلوك القديم وخطة البديل، وليس قائمة وحدات مكتملة. المنفذ من auth والحسابات هو الجزء المحدد أعلاه فقط. تتبع الحسابات والشبكة والموظفين والصلاحيات على مستوى الدوال والإجراءات والحقول الخاصة ومعايير القبول موجود في [ACCOUNT_FEATURE_REVIEW.md](ACCOUNT_FEATURE_REVIEW.md).

| المجال / المسار الحالي | الإجراءات الأساسية | مصادر التنفيذ الحالية | النطاق المستهدف | البديل / اختبار القبول |
|---|---|---|---|---|
| dashboard | مؤشرات وتفاصيل | views/DashboardView.vue، services/dashboard.js | الحساب وشبكته وفق الصلاحية | aggregates API؛ أرقام الشبكة أ لا تشمل الشبكة ب |
| reports | فلاتر وتفاصيل وتصدير وطباعة | views/ReportsView.vue، services/reports.js، report-groups.js | بحسب التقرير وصلاحيات الحقول | queries وjobs؛ تطابق المجاميع والتصدير والنطاق |
| company / company-settings | عرض وتحرير بروفايل ورسائل زوار | components/CompanyPage.vue، services/company-site.js | عامة للمنشور، تحرير للمصرح | صفحة عامة وAPI للرسائل؛ لا بيانات حسابات خاصة بالصفحة العامة |
| sell / sales | بيع وإيصال وطباعة وإعادة طباعة وسجل | views/SellView.vue، SalesView.vue، services/engine.js، print-policies.js | نقطة البيع أو نطاق البيع المصرح | transaction وidempotency؛ بيع بطاقة مرة واحدة وتسجيل فشل الطباعة منفصلًا |
| inventory | دفعات وبطاقات وحركات وحجر | views/InventoryView.vue، InventoryWorkspace.vue، services/inventory-management.js | ملكية وتخصيص المخزون | inventory API؛ لا سحب بطاقة من مخزون غير مخصص |
| import | طلبيات وملفات ومعاينة واعتماد | views/ImportView.vue، LegacyForm.vue، MultiForm.vue، services/import-reader.js، multi-orders.js | الحساب وصلاحية الاعتماد | استيراد على السيرفر؛ التكرار والأخطاء لا ينشئان دفعة أو خصمًا مزدوجًا |
| products / providers / sources | فئات وشركات ومصادر وحدود | views/CatalogView.vue، CategoryTable.vue، ProviderTable.vue، OrderSources.vue، services/categories-ui.js، providers-ui.js، order-sources.js | إعدادات النظام وإتاحتها للحساب | CRUD scoped؛ منع إتاحة منتج لا يملك الأب تفويضه |
| prices | أسعار وتاريخ واقتراح واعتماد | views/PricesView.vue، PriceEditor.vue، services/price-editor.js | حساب ومستوى وصلاحيات اعتماد | pricing services؛ تثبيت سعر البيع وقت المعاملة وصحة الاعتماد |
| exceptions | نتائج طباعة واستثناءات وإعادة محاولة | App.vue، services/engine.js، print-escalation.js، daily-print.js | العملية المصرح بها | حالات سيرفر؛ لا تعيد المحاولة خصم الرصيد أو تخصيص بطاقة جديدة دون قاعدة واضحة |
| claims | مطالبات وحجر وتسوية | views/ClaimsView.vue، services/engine.js، workflow-engine.js | المالك والمراجع المصرح | workflow API؛ عكس مالي موثق دون حذف التاريخ |
| exports | طلبات وتصدير محمي | App.vue، WorkflowPanel.vue، services/workflow-engine.js، workflow-ui.js | نطاق البطاقات وحق كشف الرموز | job وتنزيل محمي؛ رابط الملف نفسه لا يتجاوز الصلاحيات |
| agents | رئيسي وفرعي وفرع فرعي وشجرة وأرشفة | NetworkDrill.vue، NetworkBranch.vue، services/network-accounts.js، network-tree.js، network-archive.js | الذات والتابعون | accounts وclosure؛ منع الدورات والوصول للأب أو شبكة مستقلة |
| pos | نقاط وأجهزة ووثائق | App.vue، PosDocumentsEditor.vue، PosSerialPolicy.vue، services/pos-register.js | الحسابات التابعة | POS API وملفات خاصة؛ الوثائق لا تصبح روابط عامة |
| representatives / posTypes | مندوبون وصور وأنواع وربط | RepresentativePicker.vue، RepresentativePhotos.vue، services/representatives-ui.js | حسب ملكية الحساب ودور إدارة الأنواع | records API؛ منع الربط بمندوب من شبكة أخرى |
| wallets | تمويل وإيداع وتحويل واعتماد وائتمان | SimpleWallets.vue، WalletAccountsTable.vue، services/simple-wallets.js، funding-rules.js، operations-engine.js | الحساب والجهة المستهدفة المصرح بها | ledger وtransactions؛ تطابق الرصيد مع القيود ومنع تكرار الخصم |
| map | مواقع وحضور وتفاصيل | views/MapView.vue، UserMap.vue، services/user-map.js | من يملك حق الموقع ونطاقه | location API محدود؛ رفض الإذن لا يعطل بقية النظام وكشف الموقع مقيد |
| support | تذاكر ورسائل ورد وتصعيد | views/SupportView.vue، SupportInbox.vue، services/support-chat.js، support-routing.js | أطراف المحادثة والإدارة المصرح لها | support API؛ لا يستطيع مستخدم قراءة محادثة أخرى بالمعرف |
| notifications | إرسال واستقبال وإرسال عام | views/NotificationsView.vue، services/activity-notifications.js، simple-notifications.js، support-broadcast.js | المرسل والمستلم ونطاق الإرسال | notifications jobs؛ الإرسال العام صلاحية مستقلة |
| users / permissions | موظفون وأدوار ونطاق ومنح ومنع | App.vue، views/PermissionsView.vue، services/staff-model.js، staff-ui.js، access.js | حساب المستخدم وإمكانات التفويض | memberships وpolicies؛ لا يمنح الموظف نفسه دورًا أعلى |
| accountTime / deletedAccounts | أوقات تشغيل وحسابات مؤرشفة | AccountTimeSettings.vue، views/DeletedAccountsView.vue، services/account-time-controls.js، network-archive.js | الإدارة المصرح بها | server time/session rules؛ لا يعتمد التنفيذ على ساعة أو sessionStorage العميل |
| digital / integrations | كتالوج وربط ومخزون وشراء وتحقق وإيصال | views/DigitalServicesView.vue، IntegrationsView.vue، DigitalServicePanel.vue، services/digital-services.js، digital-local-bridge.js | الربط الممنوح ونقطة البيع | provider adapters؛ نتيجة timeout غير المعروفة لا تعاد كشراء جديد |
| audit / monitoring | تدقيق وفحص واستثناءات | views/AuditView.vue، MonitoringView.vue، services/audit-details.js، audit-fixes.js | نطاق التدقيق وصلاحيات الحقول | immutable audit وhealth؛ لا تسجل كلمات مرور أو PIN أو أسرار API |
| security | إيقاف وتشغيل وحدود وجلسات | App.vue، CompletionPanel.vue، services/security-controls.js، completion-engine.js | سياسات نظام/حساب محددة | server policy؛ إيقاف الحساب يطبق على الطلبات القائمة |
| branding / printPolicies | شعار وتصميم وإعدادات وصل وطباعة | CardDesigner.vue، PrintPolicySettings.vue، services/card-layout.js، print-policies.js | هوية الحساب وإعداداته | settings API؛ تغير الهوية لا يعدل بيانات المعاملة التاريخية |
| governorates | تفعيل المحافظات | GovernoratesPage.vue، services/regions.js، categories-ui.js | إدارة النظام | configuration API؛ الفلترة تعرض المسموح فقط |
| backup | إنشاء واستعادة | views/BackupView.vue، services/application.js | المدير فقط مع تحقق حساس | backup service؛ استعادة DB والملفات في بيئة منفصلة ثم التحقق |
| auth / mobile POS | دخول وجلسة ولوحة هاتف | services/login-screen.js، completion-engine.js، POSMobile*.vue، digital-services.js | المستخدم وحسابه | Sanctum وlayouts؛ الدخول من رابط لوحة أخرى لا يرفع الصلاحية |

## ملاحظات يجب عدم فقدها عند النقل

- الأدوار الحالية تتضمن owner وsupervisor وmain وsub وpos وemployee. الفرع الفرعي ليس دورًا مستقلًا؛ يستدل عليه من parent. يجب فصل نوع الحساب عن دور الموظف في التصميم الجديد.
- قواعد access.can تقيد البيع وبعض الخدمات حتى على owner؛ لا يصح نسخ عبارة «المدير كل الصلاحيات» دون مراجعة القيود الفعلية وقرار العمل المطلوب.
- النسخ الاحتياطي في legacy يعتمد على MasalBackupServer.create غير معرف في مصدره. الخدمات الرقمية القديمة تعتمد على جسر محلي؛ أساس Laravel موجود الآن، لكن خدمة النسخ داخل التطبيق والتكاملات الرقمية لم تنتقلا إليه بعد.
- الخرائط تحتاج إذن الموقع وتتصل بمزود خرائط خارجي. الرقم التسلسلي للجهاز يحتاج وسيطًا أو تسجيل جهاز؛ المتصفح وحده لا يثبت الرقم التسلسلي الحقيقي.
- صفحة الشركة العامة تستخدم نافذة مستقلة وتسلسل كود ونسخة Vue خام؛ يلزم بديل واضح قبل تفعيل التصغير.

## متابعة اكتمال الحصر

- [x] حصر المجالات الأساسية من المصدر وتحديد بديل واختبار قبول مبدئي لكل مجال.
- [x] إعداد مراجعة عميقة للحسابات والشبكة والموظفين والصلاحيات: دوال وملفات وأفعال وحقول خاصة وقرارات معلقة وقائمة قبول، في ACCOUNT_FEATURE_REVIEW.md.
- [ ] نقل جميع بنود ACC وقبولها؛ وجود مراجعة عميقة لا يعني تنفيذها، والإنشاء الأساسي لا يكمل جميع حقول وإجراءات الإنشاء القديمة.
- [ ] حصر كل زر ودالة وحقول إدخال وحالات الفشل لبقية المجالات على حدة.
- [ ] مراجعة بيع البطاقات والتسويات والأسعار والتمويل بالتفصيل.
- [ ] اختبار كل دور في المتصفح وتوثيق المسارات الفعلية.
- [x] اختبار دخول المدير الفعلي في staging وتوثيق الاختبارات الآلية للأساس؛ لا يعتبر اختبار متصفح شاملًا لكل دور ووحدة.
- [x] تأكيد البوابات الثلاث وإنشاء التابع حسب نوع الأب وPOS تحت أي مستوى وكيل، مع عضوية واحدة للمرحلة الأولى.
- [ ] تأكيد قواعد الموظفين والتفويض والتعديل والنقل والإيقاف التجارية والبيع والمالية والحقول الحساسة قبل تنفيذها.

## المتبقي المباشر

استعادة كلمة المرور والتحقق الخارجي، تعديل الحساب ونقل تبعيته وواجهة إيقافه وإعادته، الموظفون والتفويض والأدوار التفصيلية، والمستمسكات والأجهزة والحقول التجارية. المالية والمبيعات والمخزون والأسعار والاستيراد والتقارير والدعم والتكاملات وبقية الوحدات تنتظر النقل وفق مراحل الخطة. المصدر القديم محفوظ للمقارنة؛ نُقلت desktop.ini إلى backup بدل حذفها. تنظيف بيانات المتصفح مؤجل إلى حين حصر بياناتها وأرشفتها عند الحاجة.
