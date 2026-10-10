# مراجعة نقل الحسابات والشبكة والموظفين والصلاحيات

تاريخ المراجعة: 2026-10-06، بتوقيت بغداد.

هذا الملف يحوّل وظائف الحسابات القديمة إلى قائمة تنفيذ ومراجعة. قراءة الكود تثبت وجود المنطق المحلي، ولا تثبت نجاح كل تفاعل في المتصفح. لا يُعتبر أي تعديل تجاري منفذًا في Laravel لمجرد وجوده في النسخة القديمة.

## القرارات المعتمدة

- ثلاث بوابات منفصلة: مدير النظام، الوكلاء، ونقاط البيع.
- بوابة الوكلاء تشمل الرئيسي والفرعي والفرع الفرعي؛ البوابة لا تعطيهم الصلاحيات نفسها.
- Laravel يفرض نطاق البيانات والصلاحيات، وVue يعرض المحتوى المسموح.
- الحساب التجاري مستقل عن المستخدم والعضوية والصلاحية.
- **نقطة البيع مسموح أن تتبع أي مستوى من الوكلاء: الرئيسي أو الفرعي أو الفرع الفرعي. وافق المستخدم على ذلك.**
- إلغاء التخزين المحلي والمحاكاة من التشغيل الجديد، مع الاحتفاظ بـ`frontend/legacy/` مرجعًا مؤقتًا خارج بناء البوابات الجديدة.
- لا نقل للتبعية أو منح صلاحيات إضافية اعتمادًا على قيم يرسلها العميل دون فحص السيرفر.

## حالة التنفيذ الحالية

الأساس المنشور أولًا يشمل الدخول والخروج و`/me`، وتحديد البوابة من المضيف، وقراءة وإنشاء الحسابات ضمن النطاق. دليله التاريخي في [سجل تحقق الأساس](FOUNDATION_VERIFICATION.md).

أضيف في المصدر للدفعة الثانية: حقول الوكلاء وPOS الأساسية، البحث والتنقل والشجرة، تعديل البيانات ومعرف الدخول والإيقاف والتفعيل وصلاحيات التابع، الموظفون وأنواع الصلاحيات ونطاقهم، وتعديل اسم المالك الحالي والصور والمستمسكات الخاصة. اجتازت هذه الدفعة 148 اختبار باك / 820 assertion و35 اختبار واجهة وبناء البوابات الثلاث، ونُشرت فعليًا في release `20261006-accounts-02`. نجح 26 فحص HTTP وsmoke MariaDB والواجهات الأساسية؛ لا يعني ذلك اكتمال جميع وظائف الأصل. [تفاصيل الأدلة وحدودها](ACCOUNT_PHASE2_VERIFICATION.md).

الحساب التجاري مستقل عن المستخدم والعضوية. إنشاء الحساب يحتاج owner و`account.create` وأبًا صحيحًا داخل شجرة المنفذ. النظام ينشئ رئيسيًا، والرئيسي ينشئ فرعيًا/POS، والفرعي ينشئ فرعًا فرعيًا/POS، والفرع الفرعي ينشئ POS. يمكن اختيار أب تابع داخل الشجرة بالمسار الصحيح؛ لا يفترض أن الأب هو المنفذ نفسه.

تبقى تسجيل المنتجات وإتاحة الفئات وregistration والمندوبون ونوع POS وإثبات الجهاز واستعادة المرور والأرشفة والتصدير والحضور والمالية في دفعات مستقلة. لذلك إنشاء سجلات الحسابات وصورها لا يغلق ACC-04/05/06 بكل تبعياتها. الفروق الدقيقة مع حقول الأصل وقواعده موثقة في [مراجعة مطابقة الدفعة الثانية](ACCOUNT_PHASE2_PARITY.md)، وعقد المسارات في [openapi.yaml](../contracts/openapi.yaml).

## خريطة المصدر وكيف يتداخل

جميع المسارات التالية نسبة إلى جذر المشروع الحالي:

| المصدر | المسؤولية والدوال المهمة |
| --- | --- |
| `frontend/legacy/src/services/bootstrap.js` | يستورد وحدات قديمة تعتمد على globals؛ لا تستورد البوابات الجديدة هذا الملف. |
| `frontend/legacy/src/services/application.js:186` | schema الوكلاء؛ `:245` schema نقطة البيع؛ `:299` schema المستخدمين القديم. |
| `frontend/legacy/src/services/application.js:710` | `visibleAgents` و`visiblePOS`؛ الفلترة تتم بعد أن تكون البيانات كلها داخل المتصفح. |
| `frontend/legacy/src/services/application.js:1260` | `openEdit`، `saveEntity:1289`، `toggleEntity:1396`، `logoutPOS:1408`، `logoutAll:1416`. |
| `frontend/legacy/src/services/network-accounts.js:13` | `validate`، `attach:170`، `permissionTarget:226`، `candidateRules:249`، `saveNetworkPermissions:304`؛ يغلف `openEdit:537` و`saveEntity:570`. |
| `frontend/legacy/src/services/network-tree.js:33` | `Drill` للبحث والتنقل؛ `networkTree` داخل `install:172`؛ `agentExportRows:346` و`exportAgentNetwork:400`. |
| `frontend/legacy/src/services/staff-model.js:64` | `scopedUser`، `normalizeProfile:93`، `saveProfile:144`، `toggleProfile:170`، `deleteProfile:185`، `validateEmployee:195`، `saveEmployee:326`. |
| `frontend/legacy/src/services/staff-ui.js:5` | `renameOwnerAccount`؛ `permissionUsers` و`accountDetails` و`permissionProfiles` داخل `install:38`؛ `reviewPermissions`، `savePermissionProfile`، `saveStaff`، وأحداث إغلاق النوافذ. |
| `frontend/legacy/src/services/access.js:294` | `staffAccount`، `managementRole:300`، `defaults:395`، `branchCreationBlocked:498`، `localCan:506`، `networkPath:544`، `can:573`، `scope:649`، `save:684`. |
| `frontend/legacy/src/services/enhancements.js:1070` | `validateEntity` يفحص صلاحيات حقول الجهاز والموقع والمستمسكات؛ `validateAction:1169` يحمي إيقاف الموظف والمدير الأخير. |
| `frontend/legacy/src/services/pos-register.js:16` | `created` يستنتج تاريخ الإنشاء من السجل؛ table `rows`/`online`/`load`/`toggle`/`details`؛ `install:185` يضيف طلب إعادة التعيين. |
| `frontend/legacy/src/services/seller-accounts.js:4` | `sellerID`، `saleAccount:13`، `requireOwnSeller:19`، `ownsSale` و`canViewSaleCards`؛ مهم لعزل هوية البائع، دون نقل المالية في هذه المرحلة. |
| `frontend/legacy/src/services/password-admin.js:69` | `request`، `requestFromLogin:91`، `target:123`، `verify:159`، `complete`، `directReset`، `findByPhone:410`؛ تغيير المرور عبر نموذج التعديل ممنوع. |
| `frontend/legacy/src/services/representatives-ui.js:3` | `readFile`، محرر الوثائق والصورة الشخصية، اختيار المندوبين، وغلاف `saveEntity:267`. |
| `frontend/legacy/src/services/agent-products.js:50` | `categoryTarget`، `categoryRules:70`، `networkCategoryOptions:81`، `saveNetworkCategories:114`؛ تقاطع إتاحة المنتجات عبر الأسلاف. |
| `frontend/legacy/src/services/account-time-controls.js:31` | `clock` و`reason` و`saveAccountTimePolicy:80`؛ قيود ساعات وصلاحية وخمول محلية. |
| `frontend/legacy/src/services/security-controls.js:75` | `addSecurityStop` و`resumeSecurityStop:105` و`checkOperation:115`؛ توقيف دخول أو عمليات بنطاق عام أو مخصص. |
| `frontend/legacy/src/services/network-archive.js:16` | `target` و`check:29` و`archiveNetwork:99`؛ الأرشفة تعطل الحساب والمستخدمين وتحفظ التاريخ. |
| `frontend/legacy/src/services/operations-engine.js:129` | `accountCheck`؛ `checkDevice:776` و`configureDevice:823` يفحصان بيانات مسجلة محليًا. |
| `frontend/legacy/src/services/login-screen.js:2` | serial محلي؛ استعادة مستخدم من `sessionStorage`، حفظ اسم الدخول، ودخول تجريبي. كلها مستبعدة من التشغيل الجديد. |
| `frontend/legacy/src/services/completion-engine.js:67` | `challenge` يعيد `demoCode`؛ `createSession:106` ينشئ جلسة محلية؛ ليست خدمة تحقق خارجية. |
| `frontend/legacy/src/App.vue:932` | نموذج الموظف؛ `:1052` صلاحيات التابع؛ `:1107` بداية نموذج السجل العام؛ يغطي حقول الحساب والجهاز والمرفقات. |
| `frontend/legacy/src/components/NetworkDrill.vue`، `NetworkBranch.vue`، `NetworkOutline.vue`، `NetworkPointsTable.vue` | واجهات الشجرة والتفاصيل والإجراءات ونقاط البيع. |
| `frontend/legacy/src/components/dialogs/{AccountDetailsDialog,POSDetailsDialog,PermissionReviewDialog,ProfileReviewDialog}.vue` | عرض تفاصيل الحساب ونقطة البيع ومراجعة الصلاحيات؛ تعتمد على `$root` والحالة العامة القديمة. |

تغليف الدوال متسلسل: تعديل `saveEntity` في ملف واحد لا يزيل القواعد المضافة في الملفات اللاحقة. النقل يجمع السلوك النهائي في Actions/Policies واضحة بدل نسخ هذه السلسلة.

## قائمة نقل على مستوى الإجراء

الحالة `[x]` تعني تنفيذ وتحقق الجزء المحدد لهذه الدفعة مع الدليل أدناه. الحالة `[~]` تعني جزءًا منفذًا مع تبعيات أو إثبات متبقٍ، و`[ ]` يعني انتظار النقل. لا تعني علامة الجزء المنجز اكتمال المالية أو كل تفاصيل الأصل.

| الإنجاز | الرمز والإجراء | مصدر السلوك الحالي | المطلوب في Laravel وعند الربط |
| --- | --- | --- | --- |
| [x] | ACC-01 البحث والقائمة | `application.visibleAgents/visiblePOS`، `network-tree.Drill.rows`، `pos-register.table.rows` | scope قبل query، فلاتر مسموحة، pagination وحد أقصى، عدم إرسال الشبكات الأخرى أو حقول مخفية. البحث والفلاتر والصفحات واسم الأب scoped اختبرت؛ بحث المندوبين ينتظر ACC-25. |
| [x] | ACC-02 الشجرة والتنقل | `network-tree.networkTree` و`Drill.path/open/back/branches/points` | أبناء مباشرين عند التوسيع، counts ضمن النطاق، closure سليمة، منع الدورات؛ السياق المعروض لا يكشف بيانات أسلاف خارج النطاق. |
| [~] | ACC-03 تفاصيل الحساب | `network-accounts.showNetworkDetails:499`، `pos-register.table.details`، `staff-ui.showAccountDetails` | policy خاصة بالتفاصيل وResource بقائمة حقول واضحة؛ التفاصيل الشاملة القديمة للمدير فقط؛ لا إرسال user/password objects. |
| [~] | ACC-04 إنشاء وكيل رئيسي | `network-accounts.validate/attach`، `application.saveEntity` | إدارة مخولة فقط، أب من نوع system، registration فعالة، محافظة فعالة، معاملة تنشئ الحساب والعضوية والمستخدم والسجل معًا. |
| [~] | ACC-05 إنشاء فرعي وفرع فرعي | `access.branchCreationBlocked`، `network-accounts.validate/openEdit` | الرئيسي ينشئ sub_agent؛ sub_agent ينشئ sub_branch؛ sub_branch لا ينشئ مستوى وكيل إضافيًا. التبعية والنوع لا يؤخذان من دور يختاره العميل. إنشاء تحت أب تابع داخل شجرة المنفذ يحتاج owner وaccount.create؛ لا يمتد إلى شبكة أخرى. |
| [~] | ACC-06 إنشاء نقطة بيع | `network-accounts.validate/attach`، `application.saveEntity` | السماح بأي أب main_agent/sub_agent/sub_branch طبقًا لقرار المستخدم؛ مالك الوكيل المخول يختار نفسه أو أبًا تابعًا صحيحًا داخل شجرته؛ لا الأب أو الأخ أو شبكة أخرى. فحص السيريال والتبعية وتفرّد الدخول بمعاملة واحدة. |
| [x] | ACC-07 تعديل بيانات الحساب | `network-accounts.validate`، أغلفة `saveEntity` | قائمة حقول مسموحة حسب الإجراء، notes ≤2000، المحافظة فعالة عند التغيير، فحص version/concurrency؛ رفض تغيير type/parent_id/status/productRules/permissions خلسة. |
| [x] | ACC-08 تعديل معرّف الدخول | `network-accounts.validate/attach` | القديم يسمح التعديل للمدير فقط؛ تفرّد case-insensitive لمعرّف الدخول عبر مستخدمي النظام، تطبيع واضح، قاعدة بيانات تمنع سباق طلبين. تحديد أثر تغيير المعرّف على الجلسات والإشعارات. |
| [x] | ACC-09 إيقاف وتفعيل حساب تجاري | `application.toggleEntity`، `network-archive`، `meeting-rules.checkOperation` | صلاحية وتبعية، منع الحساب الحالي والأعلى، الأرشفة ومنع تفعيل المؤرشف ينتظران ACC-28؛ تعطيل الوصول الفعلي للتابعين وإبطال الجلسات؛ إعادة التفعيل لا تزيل توقفًا مستقلًا لأحد التابعين. |
| [x] | ACC-10 قائمة موظفي الحساب | `staff-model.scopedUser`، `staff-ui.permissionUsers/filteredRows` | employees ضمن membership/account المصرح، بحث وصفحات؛ حساب نقطة البيع لا يرى موظفي نقطة أخرى. عدم الاستناد إلى managementRole وحده. |
| [x] | ACC-11 إضافة موظف | `staff-model.validateEmployee/saveEmployee` | الاسم والبريد والصلاحية النشطة والنطاق، صاحب membership من السياق، كلمة مرور على السيرفر، وعدم إنشاء owner أو عضوية أعلى من actor. لا تسريب credentials. |
| [x] | ACC-12 تعديل موظف | `staff-model.validateEmployee/saveEmployee`، `staff-ui.saveStaff` | allowlist، حماية دور/نطاق المدير والحساب الحالي؛ أذون staff.role وstaff.scope مستقلة؛ staffAccount/account_id لا يتغيران عبر تعديل عام؛ optimistic locking. |
| [x] | ACC-13 إيقاف وتفعيل موظف | `enhancements.validateAction(toggleEntity)` | staff.toggle وتبعية، عدم إيقاف الحساب الحالي أو آخر مدير فعال، session revocation؛ المستخدم الموقوف لا يبقى قادرًا على API عبر cookie سابق. |
| [x] | ACC-14 قراءة أنواع الصلاحيات | `staff-ui.permissionProfiles` و`profileMembers` و`permissionGroups` | أنواع صلاحية سياق الحساب؛ القوالب المركزية العامة مؤجلة؛ counts وأسماء المرتبطين scoped؛ permissions catalogue من السيرفر. |
| [x] | ACC-15 إضافة نوع صلاحية | `staff-model.normalizeProfile/saveProfile` | الاسم 1..80، تفرّد داخل الحساب، قائمة غير فارغة ومفاتيح معروفة وقابلة للتفويض؛ owner_account ثابت من السياق؛ action يتطلب view المناسب. |
| [x] | ACC-16 تعديل نوع صلاحية | `staff-model.canEditProfile/normalizeProfile/saveProfile`، `staff-ui.reviewPermissions/savePermissionProfile` | reason وسجل، version من السيرفر، فحص جميع العضويات المتأثرة؛ لا تعديل نوع صلاحية actor أو أشخاص خارج سلطته؛ التعديل ذري ويحدّث الصلاحيات فورًا. |
| [x] | ACC-17 تعطيل/تفعيل نوع صلاحية | `staff-model.toggleProfile` | فحص version وسلطة actor على جميع مستخدميه، عدم إعادة تفعيل grants أعلى من صلاحياته، إبطال/تقييد الجلسات وفق السياسة، حماية نوع صلاحية الإدارة الأخير. |
| [x] | ACC-18 حذف نوع غير مرتبط | `staff-model.deleteProfile` و`staff-ui.askDeleteProfile` | رفض الحذف إذا مرتبط بأي membership؛ constraint ومعاملة تمنع تعيين موظف له بالتزامن؛ لا حذف صلاحيات التدقيق أو قوالب محمية. |
| [x] | ACC-19 صلاحيات التابع | `network-accounts.permissionTarget/candidateRules/canEnable/saveNetworkPermissions` | لا تعديل الذات أو الأعلى أو شبكة أخرى؛ لا grant خارج حدود التفويض؛ deny من الأعلى نافذ؛ أسباب ونسخ؛ authority يشتق من السيرفر ولا يقبل map مزورًا من العميل. |
| [x] | ACC-20 نطاق موظف/مشرف | `access.scope/save`، `staff-model.validateEmployee` | root accounts ضمن actor scope وflag descendants واضح؛ تقاطع membership scope مع حدود الشبكة؛ لا يتوسع النطاق عند roots فارغة، ولا تتجاهل Assigned بسبب employerAccount. |
| [ ] | ACC-21 طلب واستكمال إعادة المرور | `password-admin.request/requestFromLogin/verify/complete/directReset` | قناة إرسال حقيقية، token/OTP سري أحادي ومحدود المهلة والمحاولات؛ رد موحد يمنع تعداد الحسابات؛ إبطال الجلسات وتدقيق بلا code/password. لا نقل 123456 أو demoCode. |
| [ ] | ACC-22 وقت الدخول والخمول | `account-time-controls.reason/saveAccountTimePolicy` | ساعة السيرفر وتوقيت بغداد، ساعات تمتد عبر منتصف الليل، حد صلاحية وخمول وجلسة، تحقق على كل طلب حساس؛ لا sessionStorage clocks. |
| [~] | ACC-23 سياسة جهاز نقطة البيع | `enhancements.validateEntity`، `network-accounts.validate`، `operations-engine.configureDevice/checkDevice` | device/location permissions منفصلة، إثبات جهاز فعلي إذا مطلوب، تسجيل الأجهزة/revoke/إعادة ربط وتدقيق؛ بيانات serial/IP/GPS يرسلها المتصفح ليست إثباتًا مستقلًا. |
| [x] | ACC-24 الصور والمستمسكات | `representatives-ui.readFile/docs/personalPhoto/docsViewer`، `POSDetailsDialog` | upload/download policies وstorage خاص، MIME فعلي وحجم/عدد/إجمالي وحدود، فحص الملكية وتبعية المرفق؛ لا base64 documents كاملة في /accounts. |
| [ ] | ACC-25 ربط المندوبين ونوع النقطة | `representatives-ui.picker/saveEntity` و`enhancements.validateEntity` | IDs معروفة فعالة ضمن الشبكة، pos.representatives/pos.type، FK وpivot/unique؛ لا تجاهل ID مزور بصمت، بل validation error. |
| [ ] | ACC-26 الفئات المسموحة للتابع | `agent-products.categoryTarget/categoryRules/networkCategoryOptions/saveNetworkCategories` | intersection عبر الأسلاف، authority مثبتة، لا grant لفئة غير مسموحة للمانح؛ إجراء مستقل عن profile edit، فحص تأثير إزالة إتاحة حالية. |
| [ ] | ACC-27 تصدير الشبكة والموظفين | `network-tree.agentExportRows/exportAgentNetwork`، `staff-ui.exportPermissions` | scope قبل التصدير، صلاحية export مستقلة، حقول private منفصلة، معالجة Excel formula injection؛ job كبير وتنزيل محمي بنفس النطاق. |
| [ ] | ACC-28 الأرشفة وعرض الأرشيف | `network-archive.target/check/archiveNetwork` و`NetworkArchiveConfirm` | سبب وإعادة تحقق حديثة، منع المدير/الذات، رفض وجود أبناء/عمليات مفتوحة، تعطيل الحساب والعضويات وسحب الجلسات وحفظ التاريخ؛ dependencies المالية تختبر بوحداتها لاحقًا. |
| [ ] | ACC-29 الاتصال وإنهاء جلسات النطاق | `pos-register.online`، `application.logoutPOS/logoutAll`، `completion-engine.revoke` | presence مشتقة من heartbeat موثق على السيرفر؛ revoke جلسات محددة داخل النطاق؛ تعديل online=false وحده لا ينهي اتصالًا حقيقيًا. |
| [ ] | ACC-30 فصل هوية البائع وتبديل السياق | `seller-accounts.sellerID/requireOwnSeller/ownsSale/canViewSaleCards`، `application.switchUser` | هوية الحساب من الجلسة، لا اعتماد saleForm.pos أو currentUser المرسل؛ إزالة تبديل الحساب المحلي. الانتحال الإداري إن طُلب لاحقًا إجراء منفصل ومدقق. |

## ربط الدفعة المنفذة بالدليل

هذه خريطة المراجعة بتاريخ 2026-10-06. كل أسماء الاختبارات التالية موجودة في `backend/tests/Feature` واجتازت تشغيل الدفعة الكامل: 148 اختبارًا و820 assertion. يثبت الاختبار الجزء المذكور، ولا يغلق بقية تبعيات الرمز الجزئي. دليل الواجهة وHTTP وMariaDB والبصمات في [سجل التحقق المنشور](ACCOUNT_PHASE2_VERIFICATION.md).

| إجراءات ACC | المسار الجديد بعد `/api/v1` | التنفيذ وحدود السلطة | اختبارات مرجعية من الدفعة |
|---|---|---|---|
| 01، 02، وجزء 03 | `GET /accounts` و`GET /accounts/{id}` | `AccountController` و`AccountScope` و`AccountPolicy::view`؛ `NetworkWorkspace.vue` | `AccountManagementTest::test_account_filters_parent_and_counts_only_expose_own_network`، `test_search_matches_original_city_owner_and_scoped_parent_without_leaking_other_networks`؛ `AccountListQueryTest::test_larger_account_page_uses_bounded_queries_and_keeps_counts_in_own_network` |
| أجزاء 04، 05، 06 | `POST /accounts` | `CreateAccount` و`AccountHierarchy` و`AccountPolicy::create`؛ `CreateAccountDialog.vue` | `AccountCreationTest::test_agents_create_valid_direct_children`، `test_main_agent_can_create_lower_child_under_a_descendant_parent`، `test_audit_failure_rolls_back_the_entire_creation`؛ `AccountManagementTest::test_business_create_requires_original_fields_and_active_city_on_server` |
| 07 | `PATCH /accounts/{id}` | `AccountManagementController::update` و`ManagementAuthority`؛ `NetworkWorkspace.vue` | `AccountManagementTest::test_account_update_is_scoped_versioned_and_syncs_owner_name_without_password_change`، `test_general_patch_rejects_privilege_and_identity_keys`، `test_audit_failure_rolls_back_edit_and_session_revocation` |
| 08 | `PATCH /accounts/{id}/login` | `AccountManagementController::login`؛ system owner فقط مع `account.login` | `AccountManagementTest::test_login_change_is_admin_owner_only_scoped_and_revokes_previous_session`، `test_switching_email_identifier_to_username_revokes_alias_and_accepts_only_new_identifier` |
| 09 | `PATCH /accounts/{id}/status` | `AccountManagementController::status` و`SessionRevoker` | `AccountManagementTest::test_disabling_parent_revokes_descendant_sessions_and_reactivation_preserves_independent_stop` |
| 10، 11، 12، 13 | `/accounts/{id}/staff`؛ item `PATCH` و`/status` | `StaffController` و`ManagementAuthority::member/scope`؛ `StaffPage.vue` و`StaffDialog.vue` | `StaffManagementTest::test_staff_creation_hashes_original_password_assigns_only_employee_and_returns_no_credentials`، `test_staff_update_cannot_change_account_role_password_or_owner_and_revokes_session`، `test_staff_status_is_versioned_denies_disabled_profile_and_preserves_owner`، `test_staff_and_profile_pagination_excludes_members_outside_actor_full_scope` |
| 14، 15، 16، 17، 18 | `/permission-catalog` و`/accounts/{id}/permission-profiles`؛ item `PATCH/DELETE` و`/status` | `PermissionProfileController::target` و`ManagementAuthority::member/grants/permissionChanges`؛ `ProfilesPage.vue` | `StaffManagementTest::test_profile_name_is_unique_per_account_and_versioned_update_is_atomic`، `test_profile_change_is_immediate_and_never_grants_more_than_current_owner_ceiling`، `test_disabled_profile_blocks_existing_session_and_reenable_does_not_restore_old_session`، `test_linked_profile_delete_conflicts_and_unlinked_profile_delete_preserves_audit` |
| 19 | `PATCH /accounts/{id}/permissions` | `AccountManagementController::permissions` و`ManagementAuthority::permissionLimits/permissionChanges`؛ `PermissionEditor.vue` | `AccountManagementTest::test_lower_authority_cannot_remove_higher_deny_but_higher_can_replace_target_lower_rule`، `test_restoring_parent_permission_does_not_remove_independent_child_deny`، `test_editing_another_permission_does_not_take_ownership_of_unchanged_lower_deny` |
| 20 | موظفو الحساب `POST/PATCH` مع `scope_roots/include_descendants` | `ManagementAuthority::scope` و`AccountScope`؛ أذونا `staff.role` و`staff.scope` مستقلان | `StaffManagementTest::test_employee_roots_empty_and_descendants_false_never_expand_scope`، `test_staff_scope_reassignment_needs_separate_scope_permission`، `test_profile_reassignment_requires_role_permission_without_blocking_name_edit` |
| جزء 23 | بيانات `serial/device_model/app_version/city/address` في تعديل الحساب | `AccountManagementController::update`؛ `pos.device/pos.location`، دون إثبات جهاز | `AccountManagementTest::test_pos_device_location_permissions_and_serial_uniqueness_are_enforced` |
| 24 | `/accounts/{id}/attachments` وitem `DELETE` و`/content` | `AccountAttachmentController` و`AccountPolicy::viewAttachments/manageAttachments`؛ `AccountAttachments.vue` | `AccountAttachmentTest::test_foreign_network_and_mismatched_attachment_ids_return_404`، `test_invalid_file_or_metadata_returns_422_without_writing`، `test_audit_failure_rolls_back_replacement_and_removes_new_file_only`، `test_delete_removes_file_and_records_reason_with_new_version` |
| اسم المالك الحالي | `PATCH /auth/profile` | `AuthController::profile`؛ الاسم فقط ونسخة العضوية؛ `SelfProfileDialog.vue` | `AccountManagementTest::test_owner_can_change_own_name_only_with_membership_version` |

إضافة الموظف وحفظ ملاحظاته ومعاينة نوع الصلاحية والتنقل وعرض صور التفاصيل اختبرت من المتصفح كما يسجل دليل النشر. رفع صورة من UI نفسه ما زال غير مجرب؛ نجح رفعها وتنزيلها وحذفها عبر HTTP. قيود التفرّد والنسخ والرجوع داخل المعاملة لا تعني إجراء سباق طلبات متوازية فعليًا.

## السلوك القديم الذي لا ينقل كما هو

1. **الفرع الفرعي لا يملك نوعًا مستقلًا.** `type='فرعي'` و`role='sub'`، والمستوى يُستنتج من الأب. الباك الجديد يستخدم `sub_branch` صراحة، ويطابق قيود الإنشاء والتبعية.
2. **نطاق موظف الوكيل يتجاهل التقييد الفردي.** `access.scope` عند وجود `staffAccount` غير system يبدأ بكل شبكة صاحب العمل ويعود قبل معالجة `access.scope.roots` و`descendants`. النطاق الجديد يجب أن يكون تقاطعًا، لا اتساعًا تلقائيًا.
3. **empty scope قد يصبح صلاحية شاملة.** الموظف المركزي بلا roots يحصل على جميع الوكلاء في `access.scope`. أي سلوك شامل يحتاج صلاحية صريحة؛ القيمة الفارغة افتراضيًا تعني لا نطاق.
4. **الدور الإداري مستنتج من صاحب العمل.** `managementRole` يحول employee النظام إلى owner، وemployee الوكيل إلى main/sub. هذا لتكييف العرض القديم؛ لا يمنح الباك الدور الإداري بسبب جهة الموظف وحدها.
5. **بيانات كل الحسابات موجودة لدى العميل.** `visibleAgents/visiblePOS` وpermission filtering تمنع العرض فقط. بعد النقل الفلترة تتم قبل إرجاع البيانات من Laravel، بما في ذلك counters/search/export/dialogs.
6. **المستخدم التجاري مرتبط محليًا بسجل واحد.** `linked` يبحث عن أول user main/sub/pos؛ `attach` ينشئ user عند إنشاء الكيان. الجديد يستعمل عضوية/مالكًا محددًا ولا يفترض أول مستخدم في جدول.
7. **حفظ الكيان وربط المستخدم ليسا معاملة قاعدة بيانات.** `network-accounts.saveEntity` يحفظ الكيان عبر سلسلة أغلفة ثم يستدعي `attach`. الباك يجب أن يرجع عن جميع التغييرات عند فشل أي جزء.
8. **قواعد الصلاحيات موزعة على الأسلاف.** networkRules/candidateRules وlocal profile/defaults وcanDelegate تحسب نتيجة مركبة. نحتفظ بقاعدة عدم تجاوز منع الأعلى ونوثق استعادة الحقوق؛ لا ننقل JSON بأسماء authority عشوائية.
9. **المستوى الأعلى لا يملك البطاقة تلقائيًا.** seller-accounts يقيد بيانات البطاقات والوصل وإعادة الطباعة بصاحب البيع مع sell.receipt/data.pin. مراقبة تابع لا تعني الوصول إلى PIN. لا يتغير ذلك دون قرار.
10. **إعادة المرور محاكاة.** `verify` يقبل 123456، وطلبات reset تسجل demo:true. `completion-engine.challenge` يعيد demoCode. حظر التغيير من نافذة edit موجود، لكنه لا يعوّض مزود إرسال حقيقيًا.
11. **الدخول المستعاد محليًا ليس جلسة موثوقة.** `login-screen` يحفظ user id واسم الدخول وserial محليًا. الجديد يعتمد session cookie من السيرفر و/me؛ لا يستورد أيًا من هذه القيم.
12. **online=true ليس اتصالًا مثبتًا.** startLocalPOSSession يسجل local-demo؛ logoutPOS/logoutAll يعدلان flags محلية. لا نعرضهما باعتبارهما تسجيل دخول/خروج حقيقيًا.
13. **حقول الجهاز متداخلة.** serialBinding/currentDeviceSerial وbindingRequired/boundSerial مساران منفصلان؛ بعض تغييرات network-accounts تطلب device وlocation معًا. نفصل ما يعد تعريف جهاز، وما يعد موقعًا، وما يحتاج إثباتًا.
14. **رفع الصور فحص عميل فقط.** readFile يفحص نوع file المعلن وحجم 700000 بايت للصورة، دون حد واضح لمجموع الملفات أو عددها؛ saveEntity يحتفظ بصور base64. سياسة التحميل والتنزيل على السيرفر مستقلة.
15. **مستمسكات نقطة البيع تظهر في تفاصيلها.** POSDetailsDialog يستدعي viewer؛ إخفاء محرر الوثائق بشرط pos.documents لا يمنع إرسال الصور أو عرضها. نحتاج view/download permissions منفصلة.
16. **تفضيلات أعمدة نقطة البيع محلية.** masal-pos-columns-{user} في localStorage. تُنقل لاحقًا إلى تفضيلات مستخدم السيرفر أو تبقى في الذاكرة أثناء الجلسة.
17. **أنواع صلاحيات نموذجية لها مسار تهيئة محلي.** staff-model.initialize يضيف ROLE-READ/SALES/SUPPORT إذا كانت permissionProfiles غير معرّفة أصلًا؛ لا يستبدل مصفوفة موجودة ولو فارغة. القوالب الجديدة قرار مصمم وseeder اختياري، وليست تشغيلًا يخترع بيانات عند كل حالة ناقصة.
18. **الأرشفة ليست حذفًا فعليًا.** تعطل المستخدم والحساب وتحتفظ بالسجلات، وترفض أبناء وأرصدة ومخزون/عمليات مفتوحة. لم نفحص قواعد التسوية المالية هنا؛ لا نختصرها إلى delete row.

## البيانات الخاصة وحدود الاستجابة

| البيانات | التعامل المطلوب |
| --- | --- |
| password/hash/salt/credentials، remember/session tokens، reset proofs وOTP وrecovery hashes | لا تُرسل ضمن أي Resource للحساب/الموظف ولا تحفظ في audit payload. passwords تصل فقط في الإجراء الخاص ولا يعاد عرضها. |
| login/email/phone والاسم الشخصي لصاحب المكتب والعنوان | owner login/email خارج index ويظهران في detail/mutation ضمن account.view؛ بيانات المكتب الأساسية مثل phone/owner_name تدخل قائمة حسابات scoped كما يحتاج العرض الأصلي. التصدير لم ينقل، ولا تظهر بيانات أب خارج النطاق. |
| documents/personalImage/photoImages | storage خاص وروابط محمية قصيرة أو controller؛ permission خاصة للعرض والتنزيل؛ لا public URL دائم أو base64 في قائمة الحسابات. |
| serial/boundSerial/installationId/certificateHint | تفاصيل جهاز لموظف مخول؛ أي شهادة/سر جهاز فعلي لا يعرض. serial المرسل لا يعتبر إثبات هوية. |
| GPS/lat/lng/allowedCity/lastSeen/IP/session metadata | نطاق مراقبة منفصل، حقول قليلة بالقائمة؛ لا نشر موقع الأجهزة خارج الشبكة. |
| networkRules/access/profile grants والتدقيق وأسباب الحظر | قراءة للمخولين ضمن scope؛ ownerAccount/authority fields لا يقبل تعديلها من payload عام. |
| productRules/allowedProductIds | إتاحة كتالوج ضمن نطاق الحساب؛ إدارة في action مستقلة لا ضمن patch عشوائي للحساب. |
| PIN/CVC/بطاقة/وصل/تكلفة/ربح | ليست ضمن مورد الحساب العام؛ نقل قواعد الوصول في وحدات المخزون/البيع لاحقًا، مع الحفاظ على قاعدة صاحب البيع. |

## القرارات التجارية التي ما زالت تحتاج تثبيتًا

| القرار | ما نعرفه الآن | ما يحتاج جوابًا قبل تنفيذ المسار المتأثر |
| --- | --- | --- |
| عمق شجرة الوكلاء — محسوم لهذه الدفعة | system→main→sub→sub_branch، وPOS تحت أي مستوى وكيل | لا sub_branch مباشرة تحت main أوsystem، ولا مستوى إضافي تحت sub_branch؛ أي توسعة مستقبلية قرار مستقل. |
| موظفو نقطة البيع | هذه الدفعة تنشئ employee مستقلًا تحت حساب POS وتبقيه في بوابةpos ونطاقها | سياسة الأجهزة وعددها وتشغيل البيع/الحضور ليست مثبتة بإدارة الموظفين وحدها. |
| الموظف المخول لإدارة التابع | profile نشط وسقف owner حي ونطاق صريح؛ إنشاء الحساب وتعديل معرف التابع مقيدان بـowner | التفويض المالي والمنتجات مستقبلًا مستقل؛ System employee لا يصبحowner من جهة العمل. |
| نقل التبعية | القديم يمنع النقل من edit ويطلب تسوية مستقلة | من يبدأ/يعتمد النقل، وكيف تحفظ ملكية التاريخ والعمليات والمرفقات؛ يظل ممنوعًا حتى يوجد workflow معتمد. |
| تعطيل الحساب | API العام يعطل الدخول والتشغيل ويسحب جلسات الشجرة؛ المنفذ الفعال المخول يقرأ التابع الموقوف ضمن نطاقه | تعليق عملية بيع معينة واستثناءات الدعم والأرشفة المالية تبقى مستقلة. |
| تعديل login — محسوم لهذه الدفعة | system owner فقط مع account.login؛ تغيير واحد يمحو البديل ويسحب الجلسات | لا يسمح الوكيل أو System employee؛ إشعار البريد/recovery وإعادة تحقق الهوية مسارات لاحقة. |
| استعادة المدير | القديم يستثني owner من استعادة OTP | آلية استعادة المدير ومزود البريد/OTP، وإجراء recovery دون باب دخول بديل دائم. |
| الهاتف | حقل تواصل مطلوب وفق الأصل؛ تحقق تنسيق وحجم دون تفرّد للهاتف، وليس إثبات هوية | توحيد الصيغة العراقية وربطها بالاستعادة أو قرار تفرّدها مستقبلًا؛ معرف الدخول الحالي البريد أو username. |
| فئات المنتجات | تقاطع حدود الأسلاف واضح | هل يجوز تعطيل كل الفئات، وما أثر التعديل على الطلبات الجارية؛ الأصناف والأسعار تحتاج وحدة لاحقة. |
| الأدوار العامة | قوالب builtin القديمة عامة | هل القوالب المركزية غير قابلة للتعديل من الوكلاء، وهل ينسخها الوكيل كنوع خاص أم يرتبط بالقالب الأصلي؟ |
| أوقات العمل | القديم موظفون/مشرفون، إدارة المدير فقط | هل نحتفظ بهذا النطاق أم يسمح الوكيل المخول بإدارة وقت موظفيه؟ |
| الجهاز والموقع | لا إثبات فعلي حاليًا | هل سيبقى POS متصفحًا فقط، أم تطبيق جهاز يرسل شهادة/GPS؛ لا وعد بقراءة serial عتادي من المتصفح وحده. |
| المستمسكات | اختيارية بتصنيفات الأصل، صور ≤700000 بايت و4096×4096، حد 50، view/manage+scope، تخزين خاص | إلزام وثيقة قانونية وسياسة الاحتفاظ عند الأرشفة يحتاجان قرارًا؛ النقل بعد حفظ الحساب موثق كفرق workflow. |

## حالات القبول المطلوبة

هذه حالات قبول تستند إلى اختبارات المصدر وHTTP وMariaDB المذكورة في سجل الدفعة. `[~]` يميز تحققًا جزئيًا؛ السباقات المتوازية فعليًا والمالية والمجالات الغائبة لا تعتبر ناجحة بسبب القيود أو غياب API فقط.

- [x] AT-01 مدير ينشئ رئيسيًا؛ الوكيل أو POS لا يستطيع إنشاء رئيسي حتى بطلب API معدل.
- [x] AT-02 الرئيسي ينشئ فرعيًا، والفرعي ينشئ فرعًا فرعيًا، والفرع الفرعي يُمنع من مستوى وكيل إضافي.
- [x] AT-03 إنشاء POS مباشر تحت الرئيسي والفرعي والفرع الفرعي ينجح، وكل POS يظهر ضمن شبكة الأب الصحيح فقط.
- [x] AT-04 الوكيل لا ينشئ حسابًا تحت sibling/ancestor/شبكة أخرى؛ معرّف الأب المزوّر مرفوض.
- [~] AT-05 قيود تفرّد login/serial وrollback فشل audit/profile اختبرت، بما فيه MariaDB الفعلي؛ تشغيل طلبين متوازيين فعليًا لم يُجر بعد.
- [x] AT-06 patch عام لا يغير parent/type/owner/status/role/permissions؛ طلب تغيير التبعية يظل مرفوضًا.
- [~] AT-07 list/search/tree/detail/count/attachments تحترم scope والـIDs والفلاتر المزورة واختبرت؛ export لم ينقل بعد.
- [x] AT-08 موظف الوكيل بمهمة فرع محدد لا يرى بقية شبكة الوكيل؛ roots فارغة لا تصبح all، وdescendants=false يُطبق.
- [x] AT-09 grants لا تتجاوز actor capabilities ولا منع الأعلى؛ منع الأعلى يطبق على الموجودين فورًا واستعادته لا تلغي deny مستقلًا أدنى.
- [x] AT-10 تعديل صلاحية مستخدم actor/مدير النظام/موظف خارج النطاق يُرفض؛ نوع صلاحية مشترك خارج النطاق لا يُعدل ضمنيًا.
- [~] AT-11 profile version قديم مرفوض وتحديثه يسحب الجلسات وحذف مرتبط409؛ قيود FK والأقفال موجودة، لكن assignment/delete متوازيين فعليًا لم يجربا.
- [x] AT-12 إيقاف عضوية/حساب/أب يمنع /me وعمليات API بالكوكيز القديمة، وتنتقل الواجهة للدخول دون بيانات حساب سابق.
- [~] AT-13 إعادة التفعيل لا تعيد جلسة مسحوبة والذات/owner محميان؛ حساب archived واختبار الأرشفة ينتظران ACC-28.
- [x] AT-14 owner_user login/email خارج index، وتظهر في detail/mutation المسموحة ضمن account.view؛ passwords/hash/tokens ومسار الملف الخاص غائبة عن الموارد والتدقيق.
- [ ] AT-15 reset API لا يعدّد الحسابات؛ OTP منتهي/مستهلك/محاولاته مستنفدة مرفوض، وتغيير الهاتف يبطل الطلب؛ النجاح يبطل الجلسات.
- [ ] AT-16 وقت بغداد وحدود اليوم وساعات منتصف الليل واختلاف ساعة العميل لا تتجاوز سياسة الدخول؛ الخمول يعتمد آخر طلب موثق.
- [ ] AT-17 serial/GPS/IP مزور لا يمنح جهازًا معتمدًا؛ تغيير/سحب الجهاز يدقق وينهي الجلسة حسب السياسة.
- [x] AT-18 ملف زائد الحجم/نوع مزور/صورة لوثيقة حساب آخر يُرفض؛ download URL لا يصلح لمستخدم غير مخول.
- [ ] AT-19 تحديث ممثل/نوع POS بID خارج الشبكة/غير فعال يُرفض بvalidation؛ فئات يمنعها الأب لا يمكن إعادة منحها أدنى منه.
- [ ] AT-20 export يساوي نطاق query المعتمد ولا يحوي credentials أو بيانات حساب آخر، ويعالج خلايا تبدأ بعلامات formula.
- [ ] AT-21 presence ينتهي بعد توقف heartbeat، وإنهاء جلسة فعليًا يمنع طلبها التالي؛ إيقاف علم online فقط لا يعد نجاحًا.
- [ ] AT-22 متابعة بيع تابع لا تكشف PIN أو الوصل ولا تسمح إعادة طباعة نيابة عنه؛ identity seller من session فقط.
- [ ] AT-23 الأرشفة تتطلب سببًا/إعادة تحقق، تحفظ التاريخ، ترفض أبناء أو عمليات مانعة؛ الاعتماد على وحدة المالية يأتي في مرحلتها.
- [x] AT-24 لا استعادة login user ID من sessionStorage ولا بيانات localStorage/IndexedDB؛ ثلاث بوابات في المتصفح لا تتداخل جلساتها.

## ترتيب النقل المقترح ودليل الإنجاز

1. قراءة الحسابات والتفاصيل والشجرة paginated، ثم إنشاء الحسابات بحدود hierarchy المعتمدة.
2. تعديل البيانات غير الحساسة وإيقاف/تفعيل، مع اختبارات session revocation.
3. الموظفون وأنواع الصلاحيات ونطاق كل membership والتفويض، بواجهات واضحة بدل شبكة globals القديمة.
4. رفع المستمسكات وربط المندوبين وتفضيلات مستخدم السيرفر، ثم أجهزة الاتصال/الموقع إذا ثبتت آلية الإثبات.
5. التصدير والأرشفة بعد اكتمال dependencies التجارية؛ reset/time policies بالتوازي عندما تتوفر قناة الاستعادة والقرارات.

لكل ACC يحفظ مع دليل الدفعة: المسار الجديد، Action/Policy، اسم الاختبار ونتيجته، evidence للواجهة، تاريخ التنفيذ، والشخص/المراجعة التي اعتمدت السلوك. لا حذف للملف القديم أو علامة إنجاز حتى تنجح مسارات القراءة والتعديل والتجاوز المباشر ذات الصلة.

## سجل هذه المراجعة

- [x] مراجعة قراءة للشبكة والحسابات والموظفين والتفويض والواجهات ذات الصلة.
- [x] فصل السلوك المحلي العامل عن simulation/OTP/presence/device claims.
- [x] تسجيل قرار POS تحت أي مستوى وكيل معتمد.
- [x] استخراج 30 إجراء نقل و24 حالة قبول، مع حقول خاصة وقرارات مفتوحة.
- [x] تنفيذ وربط APIs الحسابات الأساسية والموظفين والصلاحيات والمرفقات المحددة لهذه الدفعة.
- [ ] بقية إجراءات ACC وتبعيات المنتجات والمالية والاستعادة والأرشفة والتصدير.
- [x] تحقق المصدر والبناء وHTTP وMariaDB والمتصفح للدفعة الحالية؛ تفاصيل ما نُقر وما اختبر بروتوكوليًا في سجل الدفعة.
- [ ] اختبار رفع صورة من UI نفسه، والسباقات المتوازية والاستعادة والرجوع والأحمال.

المراجعة لم تعدل legacy؛ بقيت 213 ملفًا من المصدر مطابقة للنسخة الأصلية. هذه الوثيقة تتابع الآن تنفيذ الدفعة المنشورة. فحوص MariaDB ألغت معاملاتها؛ فحوص HTTP أنشأت عينات ثم حذفت جميع عيناتها فقط، وبقي حسابا التشغيل ومستخدماهما وبيئة الاستضافة وبيانات دخول المدير كما كانت.
