(function () {
  const rows = `
سيتم ربطها لاحقًا|Will be connected later|دواتر دەبەسترێتەوە

بطاقات متاحة للبيع|Cards available for sale|کارتە بەردەستەکان بۆ فرۆشتن
تكلفة البطاقات المتاحة|Available cards cost|تێچووی کارتە بەردەستەکان
بطاقات محجورة أو مصدّرة|Quarantined or exported cards|کارتە ڕاگیراو یان هەناردەکراوەکان
ترتيب بيع البطاقات|Card sale order|ڕیزبەندی فرۆشتنی کارتەکان
الأقرب انتهاءً أولًا|Earliest expiry first|نزیکترین بەسەرچوون یەکەم
عند تساوي الانتهاء، تُختار البطاقة الأقدم إدخالًا تلقائيًا|For matching expiry dates, the oldest imported card is selected automatically|ئەگەر بەرواری بەسەرچوون یەکسان بوو، کۆنترین کارتی هاوردەکراو خۆکارانە هەڵدەبژێردرێت
المتاح للبيع / إجمالي الدفعة|Available for sale / batch total|بەردەست بۆ فرۆشتن / کۆی کۆمەڵە

الصلاحيات المحددة فقط|Selected permissions only|تەنها دەسەڵاتە هەڵبژێردراوەکان
أنواع الصلاحيات|Permission types|جۆرەکانی دەسەڵات
أنواع صلاحيات مسماة تُسند إلى الموظفين|Named permission types assigned to employees|جۆری دەسەڵاتی ناونراو بۆ کارمەندان
نوع الصلاحية|Permission type|جۆری دەسەڵات
اسم الصلاحية|Permission name|ناوی دەسەڵات
إضافة صلاحية|Add permission type|زیادکردنی جۆری دەسەڵات
إضافة صلاحية جديدة|New permission type|جۆری نوێی دەسەڵات
أنواع الصلاحيات المحفوظة|Saved permission types|جۆرە پاشەکەوتکراوەکانی دەسەڵات
أنشئ نوع صلاحية باسم واضح، وحدد إجراءاته، ثم أسنده إلى موظف أو أكثر.|Name a permission type, select its actions, then assign it to one or more employees.|جۆری دەسەڵاتێک ناوبنێ، کردارەکانی هەڵبژێرە و بە کارمەندێک یان زیاتر بیسپێرە.
مثال: محاسب، مدير مبيعات، موظف دعم|For example: Accountant, Sales manager, Support|بۆ نموونە: ژمێریار، بەڕێوەبەری فرۆشتن، پشتگیری
نوع صلاحية فعال|Active permission type|جۆری دەسەڵاتی چالاک
موظف مرتبط|Assigned employees|کارمەندی پەیوەست
سيطبق التعديل على جميع الموظفين المرتبطين بهذا النوع.|Changes apply to every employee assigned this permission type.|گۆڕانکاری بۆ هەموو کارمەندانی پەیوەست بە ئەم جۆرە جێبەجێ دەکرێت.
عرض فقط: لا يمكن تعديل نوع صلاحية حسابك الحالي أو موظفين خارج نطاقك.|Read only: you cannot change your own permission type or those assigned outside your scope.|تەنها بینین: ناتوانیت جۆری دەسەڵاتی خۆت یان کارمەندی دەرەوەی مەوداکەت بگۆڕیت.
مسح الاختيارات|Clear selections|سڕینەوەی هەڵبژاردنەکان
حفظ نوع الصلاحية|Save permission type|پاشەکەوتکردنی جۆری دەسەڵات
سبب تعديل الصلاحية؛ اختياري عند الإنشاء|Reason for changes; optional for a new type|هۆکاری گۆڕانکاری؛ لە دروستکردنی نوێدا ئارەزوومەندانەیە
مراجعة نوع الصلاحية|Review permission type|پێداچوونەوەی جۆری دەسەڵات
صلاحية محددة|Selected permissions|دەسەڵاتی هەڵبژێردراو
تأكيد حفظ نوع الصلاحية|Confirm permission type|پەسەندکردنی جۆری دەسەڵات
البريد الإلكتروني|Email address|ئیمەیڵ
كلمة المرور|Password|وشەی نهێنی
كلمة مرور جديدة|New password|وشەی نهێنی نوێ
اتركها فارغة للإبقاء على كلمة المرور|Leave empty to keep the current password|بەتاڵی بهێڵەوە بۆ هێشتنەوەی وشەی نهێنی ئێستا
8 أحرف على الأقل|At least 8 characters|لانیکەم 8 پیت
إظهار|Show|پیشاندان
إخفاء|Hide|شاردنەوە
اختر نوع الصلاحية|Select a permission type|جۆری دەسەڵات هەڵبژێرە
صلاحيات الدور الحالي|Current role permissions|دەسەڵاتەکانی ڕۆڵی ئێستا
أنشئ نوع الصلاحية من شاشة أنواع الصلاحيات، ثم اختره للموظف.|Create a permission type on the Permission types screen, then select it for the employee.|جۆری دەسەڵات لە شاشەی جۆرەکانی دەسەڵات دروست بکە، پاشان بۆ کارمەند هەڵیبژێرە.
نطاق بيانات الموظف|Employee data scope|مەودای داتای کارمەند
حدد الوكلاء الذين يستطيع الموظف الوصول إلى بياناتهم. دون اختيار لن تظهر له بيانات وكلاء.|Select the agents whose data this employee can access. No selection means no agent data access.|بریکارەکان هەڵبژێرە کە کارمەند دەتوانێت داتاکانیان ببینێت. بەبێ هەڵبژاردن داتای بریکار پیشان نادرێت.
حفظ الموظف|Save employee|پاشەکەوتکردنی کارمەند
إضافة موظف|Add employee|زیادکردنی کارمەند
تعديل الموظف|Edit employee|دەستکاری کارمەند
جارٍ الحفظ…|Saving…|پاشەکەوت دەکرێت…
محددة|Set|دیاریکراو
غير محددة|Not set|دیاری نەکراو
تم حفظ بيانات الموظف|Employee saved|کارمەند پاشەکەوت کرا
تم حفظ نوع الصلاحية|Permission type saved|جۆری دەسەڵات پاشەکەوت کرا
تحديد كل الصلاحيات|Select all permissions|هەڵبژاردنی هەموو دەسەڵاتەکان
يشمل جميع المجموعات حتى مع وجود بحث أو فلتر|Includes all groups, even when searching or filtering|هەموو گرووپەکان دەگرێتەوە، تەنانەت لە کاتی گەڕان یان پاڵاوتن
إلغاء تحديد الكل|Deselect all|لابردنی هەموو هەڵبژاردنەکان
استعادة صلاحيات الدور|Reset to role permissions|گەڕاندنەوە بۆ دەسەڵاتەکانی ڕۆڵ
تحديد كل صلاحيات|Select all permissions for|هەڵبژاردنی هەموو دەسەڵاتەکانی
تحديد كل صلاحيات المجموعة|Select all in this group|هەڵبژاردنی هەمووی ئەم گرووپە
لمدير النظام فقط|System owner only|تەنها بۆ بەڕێوەبەری سیستەم
ضع علامة ✓ للسماح، وأزلها للمنع. تحديد إجراء يفعّل عرض وحدته تلقائيًا؛ إلغاء عرض الوحدة يلغي إجراءاتها.|Check ✓ to allow, uncheck to deny. Selecting an action enables its module view; disabling module view disables its actions.|بۆ ڕێگەدان ✓ دابنێ و بۆ قەدەغەکردن لایببە. هەڵبژاردنی کردار بینینی بەشەکە چالاک دەکات؛ ناچالاککردنی بینینی بەش کردارەکانیش ناچالاک دەکات.
الوكلاء المكلف بهم الموظف أو المشرف|Agents assigned to the employee or supervisor|بریکارانی سپێردراو بە کارمەند یان سەرپەرشتیار
تقارير تفصيلية للمبيعات والمخزون والمحافظ والشبكة والتشغيل|Detailed sales, inventory, wallet, network and operations reports|ڕاپۆرتی وردی فرۆشتن و کۆگا و جزدان و تۆڕ و کارکردن
إدارة دقيقة لصلاحيات كل موظف ونطاق بياناته|Fine-grained employee permissions and data scope|بەڕێوەبردنی وردی دەسەڵات و مەودای داتای کارمەند
سجل محاولات الطباعة|Print attempt history|مێژووی هەوڵەکانی چاپ
محاولات الطباعة للعمليات المطابقة لفترة البيع.|Print attempts for sales matching the selected sales period.|هەوڵەکانی چاپ بۆ فرۆشتنەکانی ماوەی دیاریکراو.
تفاصيل التوريد والطلبيات|Purchases and orders|وردەکاری کڕین و داواکاری
الأسطر المرفوضة|Rejected rows|ڕیزە ڕەتکراوەکان
تكلفة الوحدة|Unit cost|تێچووی یەکە
مصاريف التوريد|Procurement expenses|خەرجی دابینکردن
التكلفة الإجمالية|Total cost|کۆی تێچوو
حركات التحميل خلال الفترة المحددة؛ الدفعات الملغاة تظهر بحالتها دون اعتبارها شراءً صافيًا.|Loads in the selected period; cancelled batches remain labelled and are not treated as net purchases.|بارکردنەکانی ماوەی دیاریکراو؛ کۆمەڵە هەڵوەشاوەکان کڕینی خالص نین.
تتبع البطاقات دون الرموز|Card tracking without codes|بەدواداچوونی کارت بەبێ کۆد
حالة البطاقات الحالية؛ رموز PIN وCVC مستبعدة دائمًا من التقارير.|Current card state; PIN and CVC codes are always excluded from reports.|دۆخی ئێستای کارت؛ کۆدی PIN وCVC هەمیشە لە ڕاپۆرتەکان لادەبرێن.
دليل المنتجات وحدود البيع|Product catalog and sales limits|لیستی بەرهەم و سنوورەکانی فرۆشتن
دليل المزودين|Provider directory|لیستی دابینکەران
تفاصيل ردود الدعم|Support reply details|وردەکاری وەڵامەکانی پشتگیری
تفاصيل تخصيص صلاحيات الموظفين|Employee permission overrides|تایبەتمەندکردنی دەسەڵاتی کارمەندان
التخصيص|Override|تایبەتمەندکردن
النتيجة الفعلية|Effective result|ئەنجامی جێبەجێکراو
رمز الصلاحية|Permission key|کۆدی دەسەڵات
مركز التقارير|Report center|ناوەندی ڕاپۆرتەکان
مركز التقارير الشامل|Complete report center|ناوەندی گشتگیری ڕاپۆرتەکان
تقرير النظام الشامل|Complete system report|ڕاپۆرتی گشتگیری سیستەم
إدارة صلاحيات الموظفين|Employee permissions|دەسەڵاتەکانی کارمەندان
الموظف|Employee|کارمەند
موظف النظام|System employee|کارمەندی سیستەم
موظف جديد|New employee|کارمەندی نوێ
الصلاحيات|Permissions|دەسەڵاتەکان
تصدير الصلاحيات|Export permissions|هەناردەی دەسەڵاتەکان
صلاحيات مسموحة|Allowed permissions|دەسەڵاتە ڕێپێدراوەکان
صلاحيات ممنوعة|Denied permissions|دەسەڵاتە قەدەغەکراوەکان
تغييرات غير محفوظة|Unsaved changes|گۆڕانکارییە پاشەکەوتنەکراوەکان
نطاق البيانات|Data scope|مەودای داتا
نطاق مخصص|Custom scope|مەودای تایبەت
تضمين الوكلاء الفرعيين|Include sub-agents|بریکارە لاوەکییەکانیش
حسب نطاق الدور الحالي|Current role scope|مەودای ڕۆڵی ئێستا
كل الوكلاء|All agents|هەموو بریکارەکان
البحث في الصلاحيات|Search permissions|گەڕان لە دەسەڵاتەکان
اسم الوحدة أو الإجراء أو رمز الصلاحية|Module, action or permission key|ناوی بەش، کردار یان کۆدی دەسەڵات
طريقة العرض|Display filter|پاڵاوتنی پیشاندان
كل الصلاحيات|All permissions|هەموو دەسەڵاتەکان
الإجراءات الحساسة|Sensitive actions|کردارە هەستیارەکان
التخصيصات فقط|Overrides only|تەنها تایبەتمەندکراوەکان
قالب صلاحيات|Permission template|قاڵبی دەسەڵات
قراءة وتقارير|Read and report|خوێندنەوە و ڕاپۆرت
موظف مبيعات|Sales operator|کارمەندی فرۆشتن
مراجع مالي|Financial reviewer|پێداچوونەوەی دارایی
موظف دعم|Support operator|کارمەندی پشتگیری
تطبيق القالب على المسودة|Apply template to draft|جێبەجێکردنی قاڵب لە ڕەشنووس
سماح للمجموعة|Allow group|ڕێگەدان بە گرووپ
منع للمجموعة|Deny group|قەدەغەکردنی گرووپ
حسب الدور|Inherit role|بەپێی ڕۆڵ
الدور|Role|ڕۆڵ
سماح|Allow|ڕێگەدان
منع|Deny|قەدەغە
مسموح فعليًا|Effective: allowed|ڕێپێدراوە
ممنوع فعليًا|Effective: denied|قەدەغەکراوە
إجراء حساس|Sensitive action|کرداری هەستیار
سبب التغيير|Reason for change|هۆکاری گۆڕانکاری
اذكر سبب منح أو سحب الصلاحيات|Explain why permissions are being changed|هۆکاری گۆڕینی دەسەڵاتەکان بنووسە
إلغاء التغييرات|Discard changes|هەڵوەشاندنەوەی گۆڕانکاری
مراجعة وحفظ الصلاحيات|Review and save permissions|پێداچوونەوە و پاشەکەوتکردنی دەسەڵات
مراجعة صلاحيات الموظف|Review employee permissions|پێداچوونەوەی دەسەڵاتی کارمەند
تأكيد صلاحيات الموظف|Confirm employee permissions|پەسەندکردنی دەسەڵاتی کارمەند
يتضمن التغيير تعديل نطاق البيانات|This change also modifies data scope|ئەم گۆڕانکارییە مەودای داتاش دەگۆڕێت
إعداد التقرير|Report configuration|ڕێکخستنی ڕاپۆرت
إعادة ضبط|Reset filters|ڕێکخستنەوەی پاڵاوتن
آخر 7 أيام|Last 7 days|دوایین 7 ڕۆژ
هذا الشهر|This month|ئەم مانگە
كل الفترات|All time|هەموو کاتەکان
نوع التقرير|Report type|جۆری ڕاپۆرت
التقرير الشامل|Complete report|ڕاپۆرتی گشتگیر
كل النطاق المسموح|All permitted scope|هەموو مەودای ڕێپێدراو
حالة البيع|Sale status|دۆخی فرۆشتن
أقسام التقرير|Report sections|بەشەکانی ڕاپۆرت
بتوقيت بغداد|Baghdad time|بە کاتی بەغدا
تصدير كل الأقسام CSV|Export all sections as CSV|هەناردەی هەموو بەشەکان بە CSV
تنزيل التقرير المنسق|Download formatted report|داگرتنی ڕاپۆرتی ڕێکخراو
طباعة / حفظ PDF|Print / Save PDF|چاپ / پاشەکەوتی PDF
بحث داخل التقرير|Search within report|گەڕان لە ڕاپۆرت
الأعمدة|Columns|ستوونەکان
تصدير الجدول الحالي|Export current table|هەناردەی خشتەی ئێستا
لا توجد سجلات ضمن الفلاتر الحالية|No records match these filters|هیچ تۆمارێک لەگەڵ ئەم پاڵاوتنانە ناگونجێت
عدد السجلات|Rows per page|ژمارەی تۆمار لە لاپەڕە
السابق|Previous|پێشوو
لا توجد أقسام تقارير مسموحة لهذا الحساب|No report sections are permitted for this account|هیچ بەشی ڕاپۆرتێک بۆ ئەم هەژمارە ڕێپێنەدراوە
سجل المبيعات التفصيلي|Detailed sales ledger|تۆماری وردی فرۆشتن
العمليات|Transactions|مامەڵەکان
البطاقات المباعة|Cards sold|کارتە فرۆشراوەکان
تكلفة المباع|Cost of goods sold|تێچووی فرۆشراو
نسبة الربح %|Gross margin %|ڕێژەی قازانج %
أداء الوكلاء|Agent performance|ئەدای بریکارەکان
أداء نقاط البيع|POS performance|ئەدای خاڵەکانی فرۆشتن
المحمّل|Loaded|بارکراو
متاح صالح|Valid available|بەردەستی بەسەرنەچوو
منتهي|Expired|بەسەرچوو
صادر|Issued|دەرکراو
محجور|Quarantined|جیاکراوە
مصدّر|Exported|هەناردەکراو
ملغى|Cancelled|هەڵوەشاوەتەوە
حالات أخرى|Other states|دۆخەکانی تر
قيمة المخزون المتبقي|Remaining inventory value|بەهای کۆگای ماوە
كشف أرصدة المحافظ|Wallet balance statement|کشفی باڵانسی جزدانەکان
رصيد افتتاح الفترة|Opening balance|باڵانسی سەرەتای ماوە
رصيد إقفال الفترة|Closing balance|باڵانسی کۆتایی ماوە
الوارد|Credits|هاتوو
الصادر|Debits|دەرچوو
تفاصيل القيود المالية|Financial ledger entries|تۆمارە وردە داراییەکان
القيد|Entry|تۆمار
المبلغ الموقع|Signed amount|بڕ بە نیشانە
الطرف المقابل|Counterparty|لایەنی بەرامبەر
الأجهزة ونقاط البيع|Devices and POS|ئامێرەکان و خاڵەکانی فرۆشتن
قائمة الأسعار الحالية|Current price list|لیستی نرخی ئێستا
تاريخ السريان|Effective date|بەرواری جێبەجێبوون
طلبات اعتماد الأسعار|Price approval requests|داواکاری پەسەندکردنی نرخ
مقدم الطلب|Requested by|داواکار
المعتمد|Approved by|پەسەندکەر
عدد التغييرات|Changes|ژمارەی گۆڕانکاری
تاريخ التسوية|Settlement date|بەرواری یەکلاییکردنەوە
سجل تصدير البطاقات|Card export history|مێژووی هەناردەی کارت
عدد الردود|Replies|ژمارەی وەڵام
الموظفون والصلاحيات الفعلية|Employees and effective permissions|کارمەندان و دەسەڵاتە جێبەجێکراوەکان
نطاق الوكلاء|Agent scope|مەودای بریکارەکان
الصلاحيات الفعلية|Effective permissions|دەسەڵاتە جێبەجێکراوەکان
منح مخصص|Explicit grants|ڕێگەدانی تایبەت
منع مخصص|Explicit denials|قەدەغەی تایبەت
إعدادات التشغيل|Operating settings|ڕێکخستنەکانی کارکردن
إعدادات الربط|Integration settings|ڕێکخستنەکانی بەستنەوە
سجل الإشعارات|Notification history|مێژووی ئاگادارکردنەوە
الإعداد|Setting|ڕێکخستن
أعد بواسطة|Prepared by|ئامادەکراوە لەلایەن
وقت الإنشاء|Generated at|کاتی دروستکردن
البداية|Beginning|سەرەتا
الآن|Now|ئێستا
لا توجد سجلات|No records|هیچ تۆمارێک نییە
البيانات الحساسة|Sensitive data|داتای هەستیار
عرض|View|بینین
إضافة|Create|زیادکردن
تفعيل|Change activation|گۆڕینی چالاکی
تفاصيل|View details|بینینی وردەکاری
حجر|Quarantine|جیاکردنەوە
قالب|Download template|داگرتنی قاڵب
معاينة|Preview|پێشبینین
اعتماد|Approve|پەسەندکردن
اقتراح|Propose|پێشنیار
تراجع|Reverse|گەڕاندنەوە
تشفير|Encrypt and export|کۆدکردن و هەناردە
إضافة رئيسي|Create main agent|زیادکردنی بریکاری سەرەکی
إنهاء جلسة|End session|کۆتایی دان بە دانیشتن
تحويل|Transfer|گواستنەوە
إيداع|Deposit|دانانی پارە
رد|Reply|وەڵام
تصعيد|Escalate|بەرزکردنەوە
إرسال|Send|ناردن
إرسال عام|Broadcast|ناردنی گشتی
تغيير الدور|Change role|گۆڕینی ڕۆڵ
تغيير النطاق|Change scope|گۆڕینی مەودا
منح ومنع|Grant and revoke|ڕێگەدان و سەندنەوە
سياسات|Edit policies|دەستکاری سیاسەتەکان
إنهاء الكل|End all sessions|کۆتایی دان بە هەموو دانیشتنەکان
تنزيل|Download|داگرتن
استعادة|Restore|گەڕاندنەوە
رموز البطاقات|Card codes|کۆدەکانی کارت
الربح|Profit|قازانج
عرض الوصل|View receipt|بینینی پسووڵە
طباعة الوصل|Print receipt|چاپی پسووڵە
نتيجة الطباعة|Record print result|تۆماری ئەنجامی چاپ
تعديل الفاصل بين العمليات|Edit transaction interval|دەستکاری ماوەی نێوان مامەڵەکان
تعديل مهلة تنبيه الانتهاء|Edit expiry warning interval|دەستکاری ماوەی ئاگادارکردنەوەی بەسەرچوون
تعديل حد المزود اليومي|Edit provider daily limit|دەستکاری سنووری ڕۆژانەی دابینکەر
تعديل حد إعادة الطباعة|Edit reprint limit|دەستکاری سنووری دووبارەچاپ
تعديل أقل إصدار للتطبيق|Edit minimum app version|دەستکاری کەمترین وەشانی بەرنامە
تعديل مهلة الخمول|Edit idle timeout|دەستکاری ماوەی بێکاری
تعديل عناوين الشبكة المحظورة|Edit blocked IP addresses|دەستکاری ناونیشانە قەدەغەکراوەکان
تعديل بيانات الجهاز|Edit device information|دەستکاری زانیاری ئامێر
تعديل الموقع الجغرافي|Edit geographic location|دەستکاری شوێنی جوگرافی
تعديل حد طباعة الجهاز|Edit device reprint limit|دەستکاری سنووری چاپی ئامێر
تعديل أقل سعر بيع مسموح|Edit contractual price floor|دەستکاری کەمترین نرخی گرێبەست
تعديل حدود بيع الفئة|Edit product sales limits|دەستکاری سنوورەکانی فرۆشتنی بەرهەم
تعديل قالب الوصل|Edit receipt template|دەستکاری قاڵبی پسووڵە
تعديل الشعار واللون|Edit logo and color|دەستکاری لۆگۆ و ڕەنگ
تحكم بكل إجراء، وحدد نطاق البيانات لكل موظف. المنع المخصص يتقدم على صلاحيات الدور.|Control each action and each employee's data scope. Explicit denials override role permissions.|هەر کردارێک و مەودای داتای هەر کارمەندێک دیاری بکە. قەدەغەی تایبەت پێش دەسەڵاتی ڕۆڵ دەکەوێت.
الصلاحيات مطبقة داخل النسخة المحلية. الحماية بين مستخدمين حقيقيين تتطلب مصادقة وفرض هذه القواعد على الخادم.|Permissions apply within this local version. Multi-user security requires authentication and server-side enforcement.|دەسەڵاتەکان لەم وەشانە ناوخۆییە جێبەجێ دەکرێن. پاراستنی فرەبەکارهێنەر پێویستی بە پشتڕاستکردنەوە و جێبەجێکردنی یاساکان لە سێرڤەر هەیە.
منح الإجراء لا يمنح الوصول إلى وكلاء خارج النطاق المحدد.|Granting an action does not grant access to agents outside the assigned scope.|ڕێگەدان بە کردارێک دەستگەیشتن بە بریکارانی دەرەوەی مەودای دیاریکراو نادات.
القالب يستبدل تخصيصات المسودة. لا تحفظ التغييرات إلا بعد مراجعتها وتأكيدها.|The template replaces draft overrides. Changes are saved only after review and confirmation.|قاڵبەکە جێی تایبەتمەندییەکانی ڕەشنووس دەگرێتەوە. گۆڕانکاری تەنها دوای پێداچوونەوە و پەسەندکردن پاشەکەوت دەکرێت.
عرض فقط: لا يمكن تعديل حسابك الحالي أو حساب مدير النظام.|Read only: your current account and system owners cannot be edited here.|تەنها بینین: هەژماری ئێستات یان بەڕێوەبەری سیستەم لێرە ناگۆڕدرێت.
رؤية تفصيلية للمبيعات والمخزون والمحافظ والشبكة والتشغيل ضمن صلاحيات حسابك.|Detailed sales, inventory, wallets, network and operations within your access scope.|بینینی وردی فرۆشتن، کۆگا، جزدان، تۆڕ و کارکردن لە مەودای دەسەڵاتەکانت.
التاريخ يرشح الحركات. الحالة تخص المبيعات فقط. المنتج والمزود يرشحان المبيعات والمخزون والأسعار. المخزون والشبكة والإعدادات لقطات حالية.|Dates filter activity; status filters sales only. Product and provider filter sales, inventory and prices. Inventory, network and settings are current snapshots.|بەروار مامەڵەکان دەپالێوێت؛ دۆخ تەنها بۆ فرۆشتنە. بەرهەم و دابینکەر بۆ فرۆشتن و کۆگا و نرخەکانن. کۆگا و تۆڕ و ڕێکخستنەکان دۆخی ئێستان.
الفلاتر الزمنية تخص الحركات؛ المخزون والشبكة والإعدادات تعرض الوضع الحالي. لا يتضمن التقرير رموز البطاقات.|Date filters apply to activity. Inventory, network and settings show current state. Card codes are excluded.|پاڵاوتنی کات بۆ مامەڵەکانە. کۆگا و تۆڕ و ڕێکخستنەکان دۆخی ئێستا نیشان دەدەن. کۆدی کارت لە ڕاپۆرتدا نییە.
الفترة والحالة تخصان عمليات البيع؛ الربح إجمالي قبل المصاريف التشغيلية.|Date and status filters apply to sales. Gross profit is before operating expenses.|بەروار و دۆخ بۆ فرۆشتنن. قازانج پێش خەرجییەکانی کارکردنە.
لقطة المخزون الحالية عند إنشاء التقرير؛ لا تمثل رصيدًا تاريخيًا عند نهاية الفترة.|Current inventory at generation time; not a historical period-end balance.|دۆخی کۆگای ئێستا لە کاتی ڕاپۆرت؛ باڵانسی مێژوویی کۆتایی ماوە نییە.
المحافظ مستقلة عن المخزون. التحويلات الداخلية لا تمثل إيرادًا؛ لا تجمع الوارد كصافي مبيعات.|Wallets are separate from inventory. Internal transfers are not revenue; credits are not net sales.|جزدانەکان لە کۆگا جیان. گواستنەوەی ناوخۆ داهات نییە؛ هاتوو فرۆشتنی خالص نییە.
السجلات الحالية ضمن النطاق.|Current records within scope.|تۆمارەکانی ئێستا لە مەودای دیاریکراو.
الأسعار الحالية؛ لا تطبق عليها فترة البيع.|Current prices; sales date range does not apply.|نرخەکانی ئێستا؛ ماوەی فرۆشتن کاریگەری نییە.
لا يتضمن التقرير رموز PIN أو كلمات التشفير.|The report excludes PINs and encryption passwords.|ڕاپۆرت کۆدی PIN و وشەی نهێنی کۆدکردن ناگرێتەوە.
يعرض سجل النشاط دون تضمين قيم الحقول الحساسة.|Activity log without sensitive field values.|تۆماری چالاکی بەبێ بەهای خانە هەستیارەکان.
إعدادات حالية وليست سجلًا تاريخيًا.|Current settings, not historical configuration.|ڕێکخستنی ئێستا، نەک مێژوویی.
`;
  for (const row of rows.trim().split("\n")) {
    const [ar, en, ckb] = row.split("|");
    MasalLocale.dictionaries.en[ar] = en;
    MasalLocale.dictionaries.ckb[ar] = ckb;
  }
})();
