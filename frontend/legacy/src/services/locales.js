/* UI dictionaries only: business records and stored enum values remain unchanged. */
(function (root) {
  const rows = `
ماسال كاردز|Masal Cards|ماساڵ کاردز
ماسال|Masal|ماساڵ
نظرة عامة|Overview|پوختە
نظرة عامة على المبيعات|Sales overview|پوختەی فرۆشتن
لوحة التحكم|Dashboard|داشبۆرد
التقارير والأرباح|Reports & profit|ڕاپۆرت و قازانج
البطاقات والعمليات|Cards & operations|کارت و کردارەکان
البيع والطباعة|Sales & printing|فرۆشتن و چاپکردن
سجل العمليات|Transactions|تۆماری مامەڵەکان
المخزون والدفعات|Inventory & batches|کۆگا و بەشەکان
الطلبيات والاستيراد|Orders & imports|داواکاری و هاوردە
المنتجات والفئات|Products & categories|بەرهەم و پۆلەکان
الشركات والمزودون|Companies & providers|کۆمپانیا و دابینکەرەکان
الأسعار والاعتمادات|Pricing & approvals|نرخ و پەسەندکردن
استثناءات الطباعة|Print exceptions|کێشەکانی چاپکردن
التالف والمطالبات|Damaged cards & claims|کارتی خراپ و داواکارییەکان
التصدير الآمن|Secure exports|هەناردەی پارێزراو
شبكة التوزيع|Distribution network|تۆڕی دابەشکردن
الوكلاء والشجرة|Agents & hierarchy|بریکار و پێکهاتە
نقاط البيع والأجهزة|POS & devices|خاڵی فرۆشتن و ئامێرەکان
المحافظ والتحويلات|Wallets & transfers|جزدان و گواستنەوەکان
خريطة الانتشار|Coverage map|نەخشەی بڵاوبوونەوە
الدعم الفني|Technical support|پشتگیری تەکنیکی
الإشعارات والتنبيهات|Notifications & alerts|ئاگادارکردنەوە و ئاگادارییەکان
إدارة النظام|System administration|بەڕێوەبردنی سیستەم
الهوية والمستخدمون|Identity & users|ناسنامە و بەکارهێنەران
الصلاحيات والنطاق|Permissions & scope|دەسەڵات و سنوور
تكاملات API|API integrations|پەیوەندییەکانی API
سجل التدقيق|Audit log|تۆماری پشکنین
المراقبة|Monitoring|چاودێری
الأمان والتشغيل|Security & operations|ئاسایش و کارپێکردن
هوية الوكيل والوصل|Agent branding & receipts|ناسنامەی بریکار و پسوڵە
محتوى صفحة الهبوط|Website content|ناوەڕۆکی ماڵپەڕ
صفحة المنصة|Platform website|ماڵپەڕی پلاتفۆرم
النسخ الاحتياطي|Backups|کۆپیی پاراستن
النسخ والاستعادة|Backup & restore|پاراستن و گەڕاندنەوە
مساحة عمل محلية|Local workspace|شوێنی کاری ناوخۆیی
بيانات تجريبية • تُحفظ في متصفحك|Demo data • saved in your browser|داتای تاقیکردنەوە • لە وێبگەڕەکەت پاشەکەوت دەکرێت
Vue 3 · الإصدار الأول|Vue 3 · Version 1|Vue 3 · وەشانی 1
نسخة محلية تفاعلية|Interactive local edition|وەشانی ناوخۆیی کارلێککار
بيانات تجريبية|Demo data|داتای تاقیکردنەوە
المخزون والمحافظ منفصلان|Inventory and wallets are separate|کۆگا و جزدانەکان جیاوازن
محفوظ محليًا|Saved locally|لە ناوخۆ پاشەکەوت کرا
تعذر الحفظ؛ نزّل نسخة احتياطية|Save failed; download a backup|پاشەکەوتکردن سەرکەوتوو نەبوو؛ کۆپیی پاراستن دابگرە
ابحث في وحدات النظام…|Search system modules…|گەڕان لە بەشەکانی سیستەم…
بحث الوحدات|Search modules|گەڕان بەدوای بەشەکان
محاكاة دور المستخدم|Preview user role|پێشبینینی ڕۆڵی بەکارهێنەر
فتح القائمة|Open navigation|کردنەوەی لیست
إغلاق القائمة الجانبية|Close navigation|داخستنی لیستی لاوەکی
تفعيل الوضع الليلي|Enable dark mode|چالاککردنی دۆخی تاریک
تفعيل الوضع النهاري|Enable light mode|چالاککردنی دۆخی ڕووناک
الوضع الليلي|Dark mode|دۆخی تاریک
الوضع النهاري|Light mode|دۆخی ڕووناک
الإشعارات|Notifications|ئاگادارکردنەوەکان
إجراء سريع|Quick action|کرداری خێرا
اختر الإجراء|Choose an action|کردارێک هەڵبژێرە
إضافة وكيل|Add agent|زیادکردنی بریکار
إضافة نقطة بيع|Add POS|زیادکردنی خاڵی فرۆشتن
مراجعة التحويل|Review transfer|پێداچوونەوەی گواستنەوە
تأكيد التحويل|Confirm transfer|پشتڕاستکردنەوەی گواستنەوە
المستلم|Recipient|وەرگر
المرسل|Sender|نێرەر
↥ تصدير البيانات|↥ Export data|↥ هەناردەی داتا
＋ طلبية جديدة|＋ New order|＋ داواکاریی نوێ
＋ عملية بيع|＋ New sale|＋ فرۆشتنی نوێ
＋ تذكرة جديدة|＋ New ticket|＋ تیکەتی نوێ
＋ إيداع|＋ Deposit|＋ دانانی پارە
متابعة المبيعات والمخزون ونشاط شبكة التوزيع|Track sales, stock and distribution activity|بەدواداچوونی فرۆشتن، کۆگا و چالاکیی تۆڕی دابەشکردن
سجل المبيعات والتكلفة والربح الإجمالي|Sales, cost and gross profit records|تۆماری فرۆشتن، تێچوو و قازانجی گشتی
إصدار البطاقات من مخزن الوكيل ومتابعة الطباعة|Issue cards from agent inventory and track printing|دەرکردنی کارت لە کۆگای بریکار و بەدواداچوونی چاپ
تتبع كل بطاقة من الإصدار إلى التسليم|Track every card from issue to delivery|بەدواداچوونی هەر کارتێک لە دەرکردنەوە تا گەیاندن
مخزون مستقل لكل وكيل رئيسي • FEFO ثم FIFO|Separate main-agent inventory • FEFO then FIFO|کۆگای جیاواز بۆ هەر بریکاری سەرەکی • FEFO پاشان FIFO
رفع الملف وفحص البطاقات ثم اعتماد الدفعة|Upload, validate cards and commit the batch|بارکردنی فایل، پشکنینی کارت و پەسەندکردنی بەش
البطاقات المحلية والعالمية وحدود البيع|Local and global cards with sales limits|کارتە ناوخۆیی و نێودەوڵەتییەکان و سنووری فرۆشتن
العلامة التجارية والمجهز والجهة المنظمة|Brand, supplier and organizing authority|نیشانی بازرگانی، دابینکەر و لایەنی ڕێکخەر
قوائم أسعار الوكلاء ومراجعة التغييرات|Agent price lists and change approvals|لیستی نرخی بریکارەکان و پێداچوونەوە بە گۆڕانکارییەکان
العمليات المعلقة والفاشلة وإعادة الطباعة|Pending, failed and reprint transactions|مامەڵە چاوەڕوان و شکستخواردووەکان و دووبارەچاپ
حجر البطاقات وتوثيق جواب المزود والتسوية|Quarantine cards and record provider resolutions|گۆشەگیرکردنی کارت و تۆمارکردنی وەڵامی دابینکەر و یەکلایی
تصدير الدفعات المتبقية بملف مشفر|Export remaining batches to an encrypted file|هەناردەی بەشە ماوەکان بە فایلی کۆدکراو
الوكلاء الرئيسيون والفرعيون ونطاق الشجرة|Main and sub-agents within the hierarchy|بریکارە سەرەکی و لاوەکییەکان لە پێکهاتەکەدا
الحسابات والأجهزة ومواقع الانتشار|Accounts, devices and coverage locations|هەژمارەکان، ئامێرەکان و شوێنەکانی بڵاوبوونەوە
الأرصدة المالية مستقلة عن قيمة المخزون|Wallet balances are separate from inventory value|باڵانسی دارایی لە بەهای کۆگا جیاوازە
مواقع نقاط البيع حسب الوكيل والمنطقة|POS locations by agent and region|شوێنی خاڵەکانی فرۆشتن بەپێی بریکار و ناوچە
التذاكر والردود والتصعيد إلى إدارة النظام|Tickets, replies and escalation to administration|تیکەت، وەڵام و بەرزکردنەوە بۆ بەڕێوەبەرایەتی
تنبيهات المخزون والعمليات وإشعارات الشبكة|Inventory, transaction and network alerts|ئاگاداریی کۆگا، مامەڵە و تۆڕ
مستخدمو النظام وأدوارهم ونطاق عملهم|System users, roles and access scope|بەکارهێنەران، ڕۆڵەکان و سنووری دەستڕاگەیشتن
مصفوفة الصلاحيات وعزل فروع شجرة الوكلاء|Permission matrix and agent-branch isolation|خشتەی دەسەڵات و جیاکردنەوەی لقەکانی بریکارەکان
إعدادات المزود وبيئة الربط لكل وكيل|Provider settings and environments per agent|ڕێکخستنی دابینکەر و ژینگەی پەیوەندی بۆ هەر بریکار
تاريخ التغييرات والعمليات وقيمها السابقة والجديدة|Change history with previous and new values|مێژووی گۆڕانکاری بە بەهاکانی پێشوو و نوێ
المؤشرات التشغيلية والاستثناءات المحلية|Local operational metrics and exceptions|پێوەرە کارگێڕییەکان و کێشە ناوخۆییەکان
مفاتيح الإيقاف وحدود التشغيل والأجهزة|Shutdown controls, limits and devices|کۆنترۆڵی وەستان، سنوور و ئامێرەکان
الشعار والألوان ومعلومات الدعم وتصميم الوصل|Logo, colors, support and receipt design|لۆگۆ، ڕەنگ، پشتگیری و دیزاینی پسوڵە
إدارة محتوى الأخبار والشرائح التعريفية|Manage news and introductory slides|بەڕێوەبردنی هەواڵ و سلایدە ناساندنەکان
واجهة تعريفية بالخدمات والتواصل|Services and contact website|ماڵپەڕی ناساندنی خزمەتگوزاری و پەیوەندی
تنزيل بيانات مساحة العمل واستعادتها|Download and restore workspace data|داگرتن و گەڕاندنەوەی داتای شوێنی کار
إجمالي مبيعات التجزئة والجملة • Total Sales|Total retail & wholesale sales|کۆی فرۆشتنی تاک و کۆ
إجمالي الشراء والتوريد • Total Purchase|Total purchases & procurement|کۆی کڕین و دابینکردن
إجمالي المبيعات|Total sales|کۆی فرۆشتن
↗ الربح الإجمالي|↗ Gross profit|↗ قازانجی گشتی
الربح الإجمالي|Gross profit|قازانجی گشتی
عملية بيع مسجلة|recorded sales|فرۆشتنی تۆمارکراو
تكلفة استلام الدفعات ومصاريف التوريد|Batch acquisition and procurement costs|تێچووی وەرگرتنی بەش و خەرجیی دابینکردن
الرصيد المتبقي بالمستودع الرقمي:|Remaining digital inventory:|ماوەی کۆگای دیجیتاڵی:
كود متاح|available codes|کۆدی بەردەست
الحسابات والوكلاء|Accounts & agents|هەژمار و بریکارەکان
الكل|All|هەموو
رئيسي|Main|سەرەکی
فرعي|Sub-agent|لاوەکی
نقاط البيع|Points of sale|خاڵەکانی فرۆشتن
حساب فعال|active accounts|هەژماری چالاک
حسابات الوكلاء والموزعين ونقاط البيع في نطاقك|Agent, distributor and POS accounts in your scope|هەژماری بریکار، دابەشکەر و خاڵی فرۆشتن لە سنوورەکەت
الحسابات المفعلة / إجمالي الشبكة|Active accounts / entire network|هەژماری چالاک / تەواوی تۆڕ
أرصدة المحافظ المستقلة|Separate wallet balances|باڵانسی جزدانە جیاوازەکان
نشاط نقاط البيع|POS activity|چالاکیی خاڵی فرۆشتن
نقطة متصلة / Online POS|Online POS|خاڵی فرۆشتنی پەیوەست
نقاط البيع النشطة (POS):|Active POS:|خاڵە چالاکەکانی فرۆشتن:
جهاز|devices|ئامێر
عمليات تحتاج متابعة|Transactions needing attention|مامەڵە پێویست بە بەدواداچوونەکان
عملية|transactions|مامەڵە
إحصائيات الإرسال والمبيعات|Distribution & sales statistics|ئاماری دابەشکردن و فرۆشتن
توزيع وتيرة المبيعات والأداء اليومي لنقاط السحب|Daily sales and POS performance|فرۆشتنی ڕۆژانە و کارایی خاڵەکانی فرۆشتن
أسبوعي (Weekly)|Weekly|هەفتانە
دينار عراقي • إجمالي المبيعات خلال آخر سبعة أيام|IQD • total sales over the last seven days|دیناری عێراقی • کۆی فرۆشتنی حەوت ڕۆژی ڕابردوو
بيانات المبيعات المسجلة في النظام|Sales recorded in the system|فرۆشتنە تۆمارکراوەکانی سیستەم
تحميل التقرير التفصيلي الكامل ←|Download detailed report →|داگرتنی ڕاپۆرتی ورد ←
توزيع الوكلاء والمحافظات|Agents & provinces|بریکار و پارێزگاکان
المخزون ونقاط البيع التابعة للوكلاء الرئيسيين|Inventory and POS under main agents|کۆگا و خاڵەکانی فرۆشتنی بریکارە سەرەکییەکان
نقاط بيع|points of sale|خاڵی فرۆشتن
بطاقة متاحة|available cards|کارتی بەردەست
نطاق شبكة التوزيع|Distribution network scope|سنووری تۆڕی دابەشکردن
عرض الخريطة التفاعلية ←|View coverage map →|بینینی نەخشە ←
آخر العمليات|Recent transactions|دوایین مامەڵەکان
الإصدار والطباعة في سجل واحد|Issuing and printing in one log|دەرکردن و چاپکردن لە یەک تۆماردا
جميع العمليات ←|All transactions →|هەموو مامەڵەکان ←
لا توجد مبيعات بعد. ابدأ أول عملية من شاشة البيع.|No sales yet. Start your first sale from the sales screen.|هێشتا فرۆشتن نییە. یەکەم فرۆشتنت لە شاشەی فرۆشتن دەست پێ بکە.
بدء عملية بيع|Start a sale|دەستپێکردنی فرۆشتن
يحتاج انتباهك|Needs your attention|پێویستی بە سەرنجی تۆیە
تنبيهات المخزون والعمليات|Inventory & transaction alerts|ئاگاداریی کۆگا و مامەڵەکان
تنبيه|alerts|ئاگاداری
لا توجد تنبيهات حاليًا|No current alerts|ئێستا هیچ ئاگادارییەک نییە
إدارة البطاقات وشبكة التوزيع|Card & distribution management|بەڕێوەبردنی کارت و تۆڕی دابەشکردن
الاسم|Name|ناو
الوكيل|Agent|بریکار
اسم الوكيل|Agent name|ناوی بریکار
النوع|Type|جۆر
المستوى|Level|ئاست
الوكيل الأعلى|Parent agent|بریکاری سەرەوە
المحافظة|Province|پارێزگا
الحالة|Status|دۆخ
رقم الهاتف|Phone number|ژمارەی تەلەفۆن
الهاتف|Phone|تەلەفۆن
معلومات الدعم|Support information|زانیاریی پشتگیری
لون الوكيل|Agent color|ڕەنگی بریکار
وكيل جديد|New agent|بریکاری نوێ
مزود جديد|New provider|دابینکەری نوێ
فئة جديدة|New category|پۆلی نوێ
نقطة بيع|Point of sale|خاڵی فرۆشتن
مستخدم|User|بەکارهێنەر
خبر / شريحة|News / slide|هەواڵ / سلاید
العلامة التجارية|Brand|نیشانی بازرگانی
المجهز|Supplier|دابینکەر
الجهة المنظمة|Organizing authority|لایەنی ڕێکخەر
نوع الربط|Connection type|جۆری پەیوەندی
اسم العلامة / المزود|Brand / provider name|ناوی نیشان / دابینکەر
الجهة المجهزة|Supplying company|کۆمپانیای دابینکەر
ملفات|Files|فایلەکان
الفئة|Category|پۆل
اسم الفئة|Category name|ناوی پۆل
المزود|Provider|دابینکەر
القيمة الاسمية|Face value|بەهای ناوی
العملة|Currency|دراو
حد العملية|Per-transaction limit|سنووری هەر مامەڵەیەک
أقل سعر بيع مسموح • د.ع|Contract minimum • IQD|کەمترین نرخی گرێبەست • د.ع
عدد البطاقات في العملية|Cards per transaction|ژمارەی کارت لە مامەڵەیەکدا
حد الكمية اليومي|Daily quantity limit|سنووری ژمارەی ڕۆژانە
الحد المالي اليومي • د.ع|Daily amount limit • IQD|سنووری پارەی ڕۆژانە • د.ع
الحقول المفصولة بفاصلة (serial,pin,expiry,cvc,reference)|Comma-separated fields (serial,pin,expiry,cvc,reference)|خانەکان بە کۆما جیا بکەوە (serial,pin,expiry,cvc,reference)
ترتيب الظهور|Display order|ڕیزبەندی پیشاندان
محلية|Local|ناوخۆیی
عالمية|Global|نێودەوڵەتی
اسم النقطة|POS name|ناوی خاڵ
الجهاز|Device|ئامێر
سيريال الجهاز|Device serial|ژمارەی زنجیرەیی ئامێر
الاسم التجاري|Business name|ناوی بازرگانی
اسم صاحب المحل|Shop owner name|ناوی خاوەنی دوکان
الوكيل التابع|Assigned agent|بریکاری پەیوەندیدار
المدينة والعنوان|City & address|شار و ناونیشان
البريد|Email|ئیمەیڵ
خط العرض|Latitude|هێڵی پانی
خط الطول|Longitude|هێڵی درێژی
الرقم التسلسلي للجهاز|Device serial number|ژمارەی زنجیرەیی ئامێر
موديل الجهاز|Device model|مۆدێلی ئامێر
إصدار التطبيق|App version|وەشانی ئەپ
حد إعادة الطباعة|Reprint limit|سنووری دووبارەچاپ
الدور|Role|ڕۆڵ
اسم المستخدم|User name|ناوی بەکارهێنەر
مدير النظام|System owner|بەڕێوەبەری سیستەم
مشرف|Supervisor|سەرپەرشتیار
موظف نقطة بيع|POS operator|کارمەندی خاڵی فرۆشتن
معرفات الوكلاء المكلف بهم المشرف، مفصولة بفاصلة|Assigned agent IDs, comma-separated|ناسنامەی بریکارە سپێردراوەکان بە کۆما جیا بکەوە
العنوان|Title|ناونیشان
المحتوى|Content|ناوەڕۆک
منشور|Published|بڵاوکراوە
عنوان الشريحة أو الخبر|Slide or news title|ناونیشانی سلاید یان هەواڵ
قائمة الوكلاء|Agent list|لیستی بریکارەکان
الشجرة التنظيمية|Organization tree|پێکهاتەی ڕێکخراو
إدارة ماسال|Masal administration|بەڕێوەبەرایەتی ماساڵ
نطاق الحساب الحالي|Current account scope|سنووری هەژماری ئێستا
يتبع|Under|سەر بە
متصل|Online|پەیوەست
غير متصل|Offline|ناپەیوەست
منتهي|Ended|کۆتایی هاتوو
بحث بالاسم أو المعرف أو المحافظة…|Search name, ID or province…|گەڕان بە ناو، ناسنامە یان پارێزگا…
سجل|records|تۆمار
المعرف|ID|ناسنامە
الإجراءات|Actions|کردارەکان
مفعل|Active|چالاک
موقوف|Disabled|ناچالاک
تعديل|Edit|دەستکاری
تفعيل|Activate|چالاککردن
إيقاف|Deactivate|ناچالاککردن
إنهاء الجلسة|End session|کۆتاییهێنان بە دانیشتن
لا توجد نتائج مطابقة|No matching results|هیچ ئەنجامێکی هاوتا نییە
بيانات الطلبية|Order details|وردەکاریی داواکاری
الملف والتحقق|File & validation|فایل و پشکنین
المراجعة والتحميل|Review & load|پێداچوونەوە و بارکردن
الوكيل الرئيسي|Main agent|بریکاری سەرەکی
تاريخ الانتهاء الافتراضي|Default expiry date|بەرواری بەسەرچوونی بنەڕەتی
تكلفة البطاقة • د.ع|Cost per card • IQD|تێچووی هەر کارت • د.ع
مصاريف توريد الدفعة • د.ع|Batch procurement expenses • IQD|خەرجیی دابینکردنی بەش • د.ع
تنسيق الملف|File format|جۆری فایل
تنزيل قالب CSV|Download CSV template|داگرتنی قاڵبی CSV
رفع الملف|Upload file|بارکردنی فایل
أو الصق محتوى CSV|Or paste CSV content|یان ناوەڕۆکی CSV دابنێ
بطاقة صالحة|valid cards|کارتی دروست
مرفوضة. لن تُحمّل الأسطر المرفوضة ولن يضاف أي رصيد للمحفظة.|rejected. Rejected rows will not be loaded and no wallet funds will be added.|ڕەتکراوە. ڕیزە ڕەتکراوەکان بار ناکرێن و هیچ پارەیەک زیاد ناکرێت بۆ جزدان.
السطر|Row|ڕیز
سيريال المزود|Provider serial|ژمارەی زنجیرەیی دابینکەر
الانتهاء|Expiry|بەسەرچوون
نتيجة الفحص|Validation result|ئەنجامی پشکنین
سيولد معرف داخلي|An internal ID will be generated|ناسنامەی ناوخۆیی دروست دەکرێت
صالح|Valid|دروست
السابق|Previous|پێشوو
اعتماد وتحميل البطاقات|Approve & load cards|پەسەندکردن و بارکردنی کارت
متابعة ←|Continue →|بەردەوامبە ←
بطاقات متاحة|Available cards|کارتە بەردەستەکان
تكلفة المخزون المتاح|Available inventory cost|تێچووی کۆگای بەردەست
بطاقات معلقة / محجورة|Pending / quarantined cards|کارتە چاوەڕوان / گۆشەگیرکراوەکان
سياسة السحب|Allocation policy|سیاسەتی دەرکردن
الأقرب انتهاءً، ثم الأقدم إدخالًا|Earliest expiry, then oldest entry|نزیکترین بەسەرچوون، پاشان کۆنترین تۆمار
بحث برقم الدفعة أو الفئة…|Search batch ID or category…|گەڕان بە ناسنامەی بەش یان پۆل…
جميع الحالات|All statuses|هەموو دۆخەکان
الدفعة|Batch|بەش
الوكيل / الفئة|Agent / category|بریکار / پۆل
المتاح / الإجمالي|Available / total|بەردەست / کۆ
التكلفة|Cost|تێچوو
البطاقات|Cards|کارتەکان
حجر|Quarantine|گۆشەگیرکردن
إلغاء|Cancel|هەڵوەشاندنەوە
تحويل رصيد|Transfer funds|گواستنەوەی پارە
بالدينار العراقي|Iraqi dinar|دیناری عێراقی
من محفظة|From wallet|لە جزدانی
إلى محفظة|To wallet|بۆ جزدانی
المبلغ|Amount|بڕ
تنفيذ التحويل|Transfer funds|گواستنەوەی پارە
أرصدة الحسابات|Account balances|باڵانسی هەژمارەکان
سجل حركة المحافظ|Wallet ledger|تۆماری جووڵەی جزدانەکان
المرجع|Reference|سەرچاوە
الحساب|Account|هەژمار
الحركة|Movement|جووڵە
التاريخ|Date|بەروار
تنزيل قالب الأسعار|Download pricing template|داگرتنی قاڵبی نرخەکان
استيراد CSV|Import CSV|هاوردەی CSV
أقل سعر بيع مسموح|Contract minimum|کەمترین نرخی گرێبەست
السعر الفعال|Active price|نرخی چالاک
سعر جديد • د.ع|New price • IQD|نرخی نوێ • د.ع
بدون تعديل|No change|بێ گۆڕانکاری
إرسال للمراجعة|Submit for review|ناردن بۆ پێداچوونەوە
طلبات اعتماد الأسعار|Price approval requests|داواکاریی پەسەندکردنی نرخ
تعديلات|changes|گۆڕانکاری
اعتماد|Approve|پەسەندکردن
تراجع|Roll back|گەڕاندنەوە
لا توجد طلبات اعتماد|No approval requests|هیچ داواکاریی پەسەندکردن نییە
اختيار المنتج|Choose a product|بەرهەمێک هەڵبژێرە
مفرد وجملة|Single & bulk|تاک و کۆ
نقطة البيع|Point of sale|خاڵی فرۆشتن
ملخص العملية|Transaction summary|پوختەی مامەڵە
سحب من مخزن الرئيسي|Allocated from main-agent inventory|دەرکردن لە کۆگای بریکاری سەرەکی
سعر البطاقة|Card price|نرخی کارت
عدد البطاقات|Number of cards|ژمارەی کارت
الإجمالي|Total|کۆ
بطاقات · الحد اليومي|cards • daily limit|کارت • سنووری ڕۆژانە
د.ع. الأقرب انتهاءً يُصدر أولًا.|IQD. Earliest-expiring cards are issued first.|د.ع. کارت بە نزیکترین بەسەرچوون یەکەم جار دەردەچێت.
إصدار البطاقات ومعاينة الوصل ←|Issue cards & preview receipt →|دەرکردنی کارت و پێشبینینی پسوڵە ←
بحث برقم العملية أو نقطة البيع|Search transaction ID or POS|گەڕان بە ژمارەی مامەڵە یان خاڵی فرۆشتن
العملية|Transaction|مامەڵە
المنتج|Product|بەرهەم
الكمية / المبلغ|Quantity / amount|ژمارە / بڕ
الوصل|Receipt|پسوڵە
إعادة طباعة|Reprint|دووبارەچاپ
السجل|Log|تۆمار
لا توجد عمليات مطابقة|No matching transactions|هیچ مامەڵەیەکی هاوتا نییە
فتح مطالبة ومعالجة دفعة|Open a claim & process batch|کردنەوەی داواکاری و چارەسەری بەش
تصدير بطاقات متبقية|Export remaining cards|هەناردەی کارتە ماوەکان
سبب المشكلة|Issue description|هۆکاری کێشە
سبب التصدير|Export reason|هۆکاری هەناردە
كلمة مرور تشفير الملف (12 حرفًا على الأقل)|File encryption password (at least 12 characters)|وشەی نهێنیی کۆدکردنی فایل (لانیکەم 12 پیت)
حجر وفتح مطالبة|Quarantine & open claim|گۆشەگیرکردن و کردنەوەی داواکاری
تشفير وتنزيل الدفعة|Encrypt & download batch|کۆدکردن و داگرتنی بەش
المطالبات والتسويات|Claims & settlements|داواکاری و یەکلاییەکان
سجل التصدير|Export log|تۆماری هەناردە
مشفّر|Encrypted|کۆدکراو
نتيجة المزود|Provider decision|بڕیاری دابینکەر
تسجيل التسوية|Record resolution|تۆمارکردنی یەکلایی
لا توجد سجلات بعد|No records yet|هێشتا تۆمار نییە
الصلاحية|Permission|دەسەڵات
المشرف|Supervisor|سەرپەرشتیار
الرئيسي|Main agent|بریکاری سەرەکی
الفرعي|Sub-agent|بریکاری لاوەکی
رؤية البيانات|Data access|بینینی داتا
الجميع|All|هەموو
المكلف بها|Assigned agents|بریکارە سپێردراوەکان
شجرته|Own hierarchy|پێکهاتەی خۆی
نقطته|Own POS|خاڵی خۆی
مخزن البطاقات|Card inventory|کۆگای کارت
كل المخازن|All inventories|هەموو کۆگاکان
ضمن النطاق|Within scope|لە سنووردا
مخزنه|Own inventory|کۆگای خۆی
تحميل وتصدير|Import & export|هاوردە و هەناردە
نعم|Yes|بەڵێ
للأبناء|To descendants|بۆ لقەکان
لنقاطه|To own POS|بۆ خاڵەکانی خۆی
اعتماد الأسعار|Approve pricing|پەسەندکردنی نرخ
شخص ثانٍ|Second person|کەسی دووەم
الأمان العام|Global security|ئاسایشی گشتی
النطاق الحالي:|Current scope:|سنووری ئێستا:
. غيّر الحساب من أعلى الشاشة لاختبار عزل فروع الشجرة.|. Switch accounts in the header to test branch isolation.|. هەژمارەکە لە سەرەوە بگۆڕە بۆ تاقیکردنەوەی جیاوازیی لقەکان.
بيئة الربط|Environment|ژینگەی پەیوەندی
تاريخ انتهاء الاعتماد|Credential expiry date|بەرواری بەسەرچوونی ڕێپێدان
حفظ إعداد الربط|Save integration settings|پاشەکەوتکردنی ڕێکخستنی پەیوەندی
لا توجد بيانات اعتماد محفوظة|No credentials stored|هیچ زانیاریی ڕێپێدان پاشەکەوت نەکراوە
بانتظار الخادم|Awaiting backend|چاوەڕوانی سێرڤەر
لا توجد تكاملات مهيأة|No integrations configured|هیچ پەیوەندییەک ڕێکنەخراوە
إنشاء إشعار محلي|Create local notification|دروستکردنی ئاگادارکردنەوەی ناوخۆیی
النص|Message|پەیام
الجمهور|Audience|وەرگرەکان
جميع الأجهزة|All devices|هەموو ئامێرەکان
نقاط|POS for|خاڵەکانی
صورة اختيارية|Optional image|وێنەی ئارەزوومەندانە
إضافة إلى مركز الإشعارات|Add to notification center|زیادکردن بۆ ناوەندی ئاگادارکردنەوە
التنبيهات الآلية|Automatic alerts|ئاگادارییە ئۆتۆماتیکییەکان
معالجة|Resolve|چارەسەرکردن
لا توجد تنبيهات|No alerts|هیچ ئاگادارییەک نییە
الإشعارات المسجلة|Notification history|مێژووی ئاگادارکردنەوەکان
لا توجد إشعارات|No notifications|هیچ ئاگادارکردنەوەیەک نییە
عرض المحادثة (|View conversation (|بینینی گفتوگۆ (
لا توجد تذاكر|No tickets|هیچ تیکەتێک نییە
انتشار نقاط البيع|POS coverage|بڵاوبوونەوەی خاڵەکانی فرۆشتن
جميع الوكلاء|All agents|هەموو بریکارەکان
العراق|Iraq|عێراق
مخطط انتشار تقريبي • يعتمد على الإحداثيات المسجلة|Approximate coverage • based on saved coordinates|نەخشەی نزیکەیی • بەپێی کۆئۆردیناتە تۆمارکراوەکان
نقاط الشبكة •|Network POS •|خاڵەکانی تۆڕ •
تفاصيل|Details|وردەکاری
من|From|لە
إلى|To|بۆ
تقارير البيع والتكلفة والربح؛ المحافظ في سجل مستقل.|Sales, cost and profit reports; wallets have a separate ledger.|ڕاپۆرتی فرۆشتن، تێچوو و قازانج؛ جزدانەکان تۆماری جیاوازیان هەیە.
المبيعات حسب الفئة|Sales by category|فرۆشتن بەپێی پۆل
تنزيل التقرير CSV|Download CSV report|داگرتنی ڕاپۆرتی CSV
الكمية|Quantity|ژمارە
المبيعات|Sales|فرۆشتن
تكلفة المباع|Cost of sales|تێچووی فرۆشتن
تكلفة البطاقات المباعة|Cost of cards sold|تێچووی کارتە فرۆشراوەکان
عدد البطاقات الصادرة|Cards issued|کارتە دەرکراوەکان
بحث بالإجراء أو المستخدم أو العملية|Search action, user or transaction|گەڕان بە کردار، بەکارهێنەر یان مامەڵە
حدث|events|ڕووداو
التوقيت|Time|کات
المستخدم|User|بەکارهێنەر
الإجراء|Action|کردار
قبل / بعد|Before / after|پێش / پاش
التفاصيل|Details|وردەکاری
ستظهر هنا جميع التغييرات والعمليات|All changes and transactions will appear here|هەموو گۆڕانکاری و مامەڵەکان لێرە دەردەکەون
مركز الاستثناءات والتنبيهات|Exception & alert center|ناوەندی کێشە و ئاگادارییەکان
فتح|Open|کردنەوە
الخدمات الخارجية|External services|خزمەتگوزارییە دەرەکییەکان
غير مربوط|Not connected|پەیوەست نەکراوە
خادم التطبيق|Application server|سێرڤەری ئەپ
قاعدة البيانات|Database|بنکەی داتا
بوابة API للمزودين|Provider API gateway|دەروازەی APIی دابینکەرەکان
خدمة OTP / TOTP|OTP / TOTP service|خزمەتگوزاری OTP / TOTP
بوابة طابعات POS|POS printer gateway|دەروازەی چاپکەری POS
مفاتيح التشغيل|Operation controls|کۆنترۆڵەکانی کارپێکردن
سياسات وحدود التشغيل|Operating policies & limits|سیاسەت و سنوورەکانی کارپێکردن
الفاصل بين العمليات • ثانية|Transaction interval • seconds|ماوەی نێوان مامەڵەکان • چرکە
مهلة تنبيه الانتهاء • يوم|Expiry alert window • days|ماوەی ئاگاداریی بەسەرچوون • ڕۆژ
حد المزود اليومي لكل نقطة • د.ع|Daily provider limit per POS • IQD|سنووری ڕۆژانەی دابینکەر بۆ هەر خاڵ • د.ع
عدد إعادة الطباعة الافتراضي|Default reprint limit|سنووری بنەڕەتیی دووبارەچاپ
أقل إصدار تطبيق (إعداد للربط)|Minimum app version (integration setting)|کەمترین وەشانی ئەپ (ڕێکخستنی پەیوەندی)
مهلة الخمول • دقيقة (للربط)|Idle timeout • minutes (integration)|ماوەی بێکاری • خولەک (پەیوەندی)
قائمة IP محظورة (إعداد للربط)|Blocked IPs (integration setting)|IPی قەدەغەکراو (ڕێکخستنی پەیوەندی)
حفظ السياسات|Save policies|پاشەکەوتکردنی سیاسەتەکان
الجلسات والأجهزة المحلية|Local sessions & devices|دانیشتن و ئامێرە ناوخۆییەکان
إنهاء جلسات النطاق الحالي|End sessions in current scope|کۆتاییهێنان بە دانیشتنەکانی سنووری ئێستا
تسجيل خروج|Sign out|چوونەدەرەوە
تشغيل التطبيق|Application enabled|چالاککردنی ئەپ
إيقاف عمليات النسخة المحلية|Disable local operations|وەستاندنی کردارە ناوخۆییەکان
السماح بالبيع|Allow sales|ڕێگەدان بە فرۆشتن
منع إصدار بطاقات جديدة عند الإيقاف|Block new card issuance when disabled|ڕێگریکردن لە دەرکردنی کارتی نوێ کاتی وەستان
السماح بالطباعة|Allow printing|ڕێگەدان بە چاپکردن
منع البيع وإعادة الطباعة عند الإيقاف|Block sales and reprinting when disabled|ڕێگریکردن لە فرۆشتن و دووبارەچاپ کاتی وەستان
تسجيل الوكلاء|Agent registration|تۆمارکردنی بریکار
التحكم بإضافة وكلاء جدد|Control new agent registration|کۆنترۆڵی تۆمارکردنی بریکاری نوێ
تسجيل الدخول|Sign-in enabled|چالاککردنی چوونەژوورەوە
إعداد للخادم عند ربط المصادقة|Backend setting for authentication integration|ڕێکخستنی سێرڤەر بۆ پەیوەندیی پشتڕاستکردنەوە
لون الهوية|Brand color|ڕەنگی ناسنامە
الشعار|Logo|لۆگۆ
ترويسة الوصل|Receipt header|سەردێڕی پسوڵە
تذييل الوصل|Receipt footer|کۆتایی پسوڵە
عرض الوصل|Receipt width|پانیی پسوڵە
عدد إعادة الطباعة|Reprint count|ژمارەی دووبارەچاپ
حفظ الهوية|Save branding|پاشەکەوتکردنی ناسنامە
معاينة الوصل|Receipt preview|پێشبینینی پسوڵە
بطاقة شحن تجريبية|Demo recharge card|کارتی پڕکردنەوەی تاقیکردنەوە
سيريال داخلي: MSL-DEMO-001|Internal serial: MSL-DEMO-001|ژمارەی ناوخۆیی: MSL-DEMO-001
الانتهاء:|Expiry:|بەسەرچوون:
شبكة واحدة. إمكانيات أوسع.|One network. More possibilities.|یەک تۆڕ. دەرفەتی زیاتر.
الدخول إلى مساحة العمل ←|Enter workspace →|چوونەژوورەوە بۆ شوێنی کار ←
من نحن|About us|دەربارەی ئێمە
تواصل معنا|Contact us|پەیوەندیمان پێوە بکە
طلب تواصل|Contact request|داواکاریی پەیوەندی
الرسالة|Message|پەیام
حفظ طلب التواصل محليًا|Save contact request locally|پاشەکەوتکردنی داواکاریی پەیوەندی لە ناوخۆ
نسخة احتياطية|Backup|کۆپیی پاراستن
تنزيل JSON يحفظ الوكلاء والدفعات والمحافظ والعمليات والإعدادات.|Download JSON with agents, batches, wallets, transactions and settings.|داگرتنی JSON بە بریکار، بەش، جزدان، مامەڵە و ڕێکخستنەکان.
تنزيل نسخة البيانات|Download backup|داگرتنی کۆپیی داتا
استعادة مساحة العمل|Restore workspace|گەڕاندنەوەی شوێنی کار
اختر ملف نسخة احتياطية متوافقًا مع الإصدار الأول.|Choose a backup file compatible with version 1.|فایلی کۆپیی پاراستنی گونجاو لەگەڵ وەشانی 1 هەڵبژێرە.
إغلاق|Close|داخستن
بدون|None|هیچ
حفظ البيانات|Save changes|پاشەکەوتکردنی گۆڕانکاری
معرف داخلي|Internal ID|ناسنامەی ناوخۆیی
غير متوفر|Not available|بەردەست نییە
بطاقات تجريبية غير صالحة للشحن|Demo cards — not valid for recharge|کارتە تاقیکردنەوەکان بۆ پڕکردنەوە بەکار نایەن
إعادة طباعة • المحاولة|Reprint • attempt|دووبارەچاپ • هەوڵ
المرجع:|Reference:|سەرچاوە:
الحالة:|Status:|دۆخ:
. نافذة الطباعة لا تؤكد خروج الورقة؛ سجل النتيجة أدناه.|. The print dialog does not confirm paper output; record the result below.|. پەنجەرەی چاپ دەرچوونی کاغەز پشتڕاست ناکاتەوە؛ ئەنجامەکە لە خوارەوە تۆمار بکە.
طباعة المتصفح|Browser print|چاپی وێبگەڕ
فشلت الطباعة|Print failed|چاپکردن شکستی هێنا
تأكيد نجاح الطباعة|Confirm successful print|پشتڕاستکردنەوەی چاپی سەرکەوتوو
نفس البطاقات دون خصم جديد. عدد المحاولات السابقة:|Same cards, no additional charge. Previous attempts:|هەمان کارت بێ کەمکردنەوەی پارە. ژمارەی هەوڵەکانی پێشوو:
سبب إعادة الطباعة|Reprint reason|هۆکاری دووبارەچاپ
طلب إعادة الطباعة|Request reprint|داواکاریی دووبارەچاپ
المبلغ • د.ع|Amount • IQD|بڕ • د.ع
مرجع الإيداع|Deposit reference|سەرچاوەی دانانی پارە
تسجيل الإيداع|Record deposit|تۆمارکردنی دانانی پارە
الوصف|Description|وەسف
الأولوية|Priority|پێشینە
منخفضة|Low|نزم
متوسطة|Medium|مامناوەند
عالية|High|بەرز
صورة مرفقة|Image attachment|وێنەی هاوپێچ
فتح التذكرة|Create ticket|دروستکردنی تیکەت
الرد|Reply|وەڵام
تصعيد للإدارة|Escalate to administration|بەرزکردنەوە بۆ بەڕێوەبەرایەتی
حفظ الرد|Save reply|پاشەکەوتکردنی وەڵام
رجوع|Back|گەڕانەوە
تأكيد|Confirm|پشتڕاستکردنەوە
د.ع|IQD|د.ع
بطاقة|cards|کارت
متاحة|Available|بەردەست
محمّلة|Loaded|بارکراو
محجوزة|Reserved|گیراو
صادرة|Issued|دەرکراو
بانتظار الطباعة|Awaiting print|چاوەڕوانی چاپ
فشل الطباعة|Print failed|شکستی چاپ
مطبوعة|Printed|چاپکراو
أعيدت طباعتها|Reprinted|دووبارە چاپکراو
طلب إعادة طباعة|Reprint requested|داوای دووبارەچاپ کرا
محجورة|Quarantined|گۆشەگیرکراو
مصدّرة|Exported|هەناردەکراو
ملغاة|Cancelled|هەڵوەشێنراوە
مشطوبة|Written off|سڕاوە لە هەژمار
بانتظار تسوية / بديل|Awaiting settlement / replacement|چاوەڕوانی یەکلایی / جێگرەوە
مسلّمة|Delivered|گەیەنراو
صادرة جزئيًا|Partially issued|بەشێکی دەرکراو
مستنفدة|Depleted|تەواوبوو
قيد المراجعة|Pending review|لە پێداچوونەوەدا
معتمد|Approved|پەسەندکراو
تم التراجع|Rolled back|گەڕێنراوەتەوە
معلقة|Pending|چاوەڕوان
مفتوحة|Open|کراوە
مغلقة|Closed|داخراو
مصعّدة|Escalated|بەرزکراوەتەوە
استبدال|Replacement|جێگۆڕکێ
إعادة تفعيل|Reactivation|دووبارە چالاککردنەوە
رفض|Rejected|ڕەتکراو
تعويض|Compensation|قەرەبوو
خسارة|Loss|زیان
تفاصيل السجل|Record details|وردەکاریی تۆمار
إيداع في المحفظة|Wallet deposit|دانانی پارە لە جزدان
إعادة طباعة البطاقة نفسها|Reprint the same card|دووبارەچاپی هەمان کارت
وصل العملية|Transaction receipt|پسوڵەی مامەڵە
فتح تذكرة دعم|New support ticket|تیکەتی نوێی پشتگیری
إلغاء الدفعة|Cancel batch|هەڵوەشاندنەوەی کۆمەڵە
استعادة البيانات|Restore data|گەڕاندنەوەی داتا
دفعة تقترب من الانتهاء|Batch nearing expiry|بەشێک نزیکە لە بەسەرچوون
دفعة منتهية|Expired batch|بەشی بەسەرچوو
مخزون منخفض|Low inventory|کۆگای کەم
جهاز غير متصل|Device offline|ئامێر ناپەیوەستە
نجاح الطباعة|Print success|سەرکەوتنی چاپ
بحسب تأكيد الطباعة المحلي|Based on local print confirmation|بەپێی پشتڕاستکردنەوەی چاپی ناوخۆیی
العمليات المعلقة / الفاشلة|Pending / failed transactions|مامەڵە چاوەڕوان / شکستخواردووەکان
تحتاج متابعة|Needs follow-up|پێویستی بە بەدواداچوونە
الأجهزة المتصلة|Online devices|ئامێرە پەیوەستەکان
محاكاة حالة الاتصال|Simulated connection status|هاوشێوەکردنی دۆخی پەیوەندی
إجمالي أحداث التدقيق|Total audit events|کۆی ڕووداوەکانی پشکنین
في نطاق الحساب|Within account scope|لە سنووری هەژماردا
عرض المبيعات|View sales|بینینی فرۆشتن
عرض المخزون|View inventory|بینینی کۆگا
عرض الوكلاء|View agents|بینینی بریکارەکان
عرض نقاط البيع|View POS|بینینی خاڵەکانی فرۆشتن
عرض الحسابات|Account view|بینینی هەژمارەکان
نسبة نقاط البيع المتصلة|Online POS percentage|ڕێژەی خاڵە پەیوەستەکانی فرۆشتن
مخطط إحداثيات نقاط البيع|POS coordinate map|نەخشەی کۆئۆردیناتی خاڵەکانی فرۆشتن
تم حفظ البيانات|Changes saved|گۆڕانکارییەکان پاشەکەوت کران
تم تحديث الحالة|Status updated|دۆخ نوێ کرایەوە
تم التحويل وتسجيل قيدين متقابلين|Transfer completed with balanced entries|گواستنەوە بە دوو تۆماری هاوسەنگ تەواو بوو
تم تسجيل الإيداع|Deposit recorded|دانانی پارە تۆمار کرا
أرسل الطلب للمراجعة؛ يلزم اعتماد مستخدم آخر|Submitted for review; another user must approve|نێردرا بۆ پێداچوونەوە؛ بەکارهێنەرێکی تر دەبێت پەسەندی بکات
تم اعتماد الأسعار|Prices approved|نرخەکان پەسەند کران
تم إرجاع الأسعار السابقة|Previous prices restored|نرخەکانی پێشوو گەڕێنرانەوە
تم الإصدار والخصم مرة واحدة؛ أكد نتيجة الطباعة|Issued and charged once; confirm the print result|دەرکرا و یەکجار پارە کەمکرایەوە؛ ئەنجامی چاپ پشتڕاست بکەوە
تم تسجيل نجاح الطباعة|Successful printing recorded|چاپی سەرکەوتوو تۆمار کرا
تم تسجيل الفشل؛ البطاقة نفسها محفوظة لإعادة الطباعة|Failure recorded; the same card is retained for reprint|شکست تۆمار کرا؛ هەمان کارت بۆ دووبارەچاپ پارێزراوە
تم طلب إعادة الطباعة دون خصم جديد|Reprint requested without additional charge|داوای دووبارەچاپ کرا بێ کەمکردنەوەی پارە
تم تحديث حالة الدفعة|Batch status updated|دۆخی بەش نوێ کرایەوە
تم حجر المتبقي وفتح المطالبة|Remaining cards quarantined and claim opened|کارتە ماوەکان گۆشەگیر کران و داواکاری کرایەوە
تم تنزيل التقرير|Report downloaded|ڕاپۆرت دابەزی
تم تنزيل النسخة الاحتياطية|Backup downloaded|کۆپیی پاراستن دابەزی
تم تنفيذ الإجراء|Action completed|کردار تەواو بوو
تم فتح التذكرة محليًا|Ticket created locally|تیکەت لە ناوخۆ دروست کرا
تم حفظ الرد|Reply saved|وەڵام پاشەکەوت کرا
تم تحديث حالة التذكرة|Ticket status updated|دۆخی تیکەت نوێ کرایەوە
تم تحديث إعداد التشغيل|Operation setting updated|ڕێکخستنی کارپێکردن نوێ کرایەوە
تم حفظ السياسات|Policies saved|سیاسەتەکان پاشەکەوت کران
تم حفظ الهوية وقالب الوصل|Branding and receipt template saved|ناسنامە و قاڵبی پسوڵە پاشەکەوت کران
تم إنهاء جلسة الجهاز في المحاكاة|Device session ended in the simulation|دانیشتنی ئامێر لە هاوشێوەکردندا کۆتایی هات
تم إنهاء جلسات النطاق الحالي|Sessions ended in current scope|دانیشتنەکانی سنووری ئێستا کۆتایی هاتن
غير مسموح|Not permitted|ڕێگەپێنەدراوە
الرصيد غير كافٍ|Insufficient balance|باڵانس بەس نییە
رصيد نقطة البيع غير كافٍ|Insufficient POS balance|باڵانسی خاڵی فرۆشتن بەس نییە
المخزون المتاح غير كافٍ|Insufficient available inventory|کۆگای بەردەست بەس نییە
لا يوجد سعر فعال صالح لهذه الفئة|No valid active price for this category|هیچ نرخێکی چالاک و دروست بۆ ئەم پۆلە نییە
يجب أن يعتمد التغيير شخص ثانٍ|A second person must approve this change|کەسێکی دووەم دەبێت ئەم گۆڕانکارییە پەسەند بکات
البيع أو الطباعة موقوفة، أو الجهاز غير متصل|Sales or printing disabled, or device offline|فرۆشتن یان چاپ وەستاوە، یان ئامێر ناپەیوەستە
سبب إعادة الطباعة مطلوب|A reprint reason is required|هۆکاری دووبارەچاپ پێویستە
لا توجد بيانات للتصدير|No data to export|هیچ داتایەک بۆ هەناردە نییە
هذه الوحدة خارج صلاحيات الحساب|This module is outside your permissions|ئەم بەشە لە دەسەڵاتی هەژمارەکەت نییە
تم تغيير نطاق العرض إلى|View scope changed to|سنووری پیشاندان گۆڕدرا بۆ
تم تحميل|Loaded|بارکرا
بطاقة دون تغيير المحفظة|cards without changing the wallet|کارت بێ گۆڕینی جزدان
PIN مكرر|Duplicate PIN|PINی دووبارە
سيريال مزود مكرر|Duplicate provider serial|ژمارەی زنجیرەیی دابینکەری دووبارە
رمز PIN مفقود|Missing PIN|PIN نییە
تاريخ انتهاء غير صالح|Invalid expiry date|بەرواری بەسەرچوون نادروستە
حقول البطاقة العالمية ناقصة|Missing global-card fields|خانەکانی کارتی نێودەوڵەتی ناتەواون
تجاوز حد عدد البطاقات في العملية|Per-transaction card limit exceeded|سنووری کارتی مامەڵە تێپەڕێنرا
تم بلوغ الحد اليومي للفئة|Category daily limit reached|سنووری ڕۆژانەی پۆل گەیشتە کۆتایی
تم بلوغ الحد اليومي للمزود|Provider daily limit reached|سنووری ڕۆژانەی دابینکەر گەیشتە کۆتایی
انتظر الفاصل المحدد بين العمليات|Wait for the transaction interval|چاوەڕێی ماوەی نێوان مامەڵەکان بکە
لا يمكن إلغاء دفعة خرجت منها بطاقات|A batch with released cards cannot be cancelled|کۆمەڵەیەک کە کارتی لێ دەرکراوە هەڵناوەشێنرێتەوە
الحساب أو التطبيق موقوف|Account or application disabled|هەژمار یان ئەپ وەستاوە
ليست لديك صلاحية تنفيذ هذا الإجراء|You do not have permission for this action|دەسەڵاتی ئەم کردارەت نییە
هذا السجل خارج نطاق صلاحياتك|This record is outside your access scope|ئەم تۆمارە لە سنووری دەسەڵاتت نییە
قيمة غير صحيحة|Invalid value|بەهای نادروست
أكمل حقل|Complete the field|خانەکە پڕ بکەوە
قائمة التغييرات فارغة|No changes to submit|هیچ گۆڕانکارییەک بۆ ناردن نییە
اختر دفعة|Select a batch|بەشێک هەڵبژێرە
اختر الوكيل|Select an agent|بریکارێک هەڵبژێرە
اختر الفئة|Select a category|پۆلێک هەڵبژێرە
لا توجد بطاقات متبقية|No remaining cards|هیچ کارتێک نەماوە
لا توجد بطاقات صالحة|No valid cards|هیچ کارتێکی دروست نییە
لا توجد بطاقات قابلة للتصدير|No cards available for export|هیچ کارتێک بۆ هەناردە بەردەست نییە
تسجيل الوكلاء موقوف|Agent registration disabled|تۆمارکردنی بریکار وەستاوە
الطباعة موقوفة|Printing disabled|چاپکردن وەستاوە
الطلب غير متاح|Request unavailable|داواکاری بەردەست نییە
العملية غير موجودة|Transaction not found|مامەڵە نەدۆزرایەوە
الدفعة غير موجودة|Batch not found|بەش نەدۆزرایەوە
المطالبة غير موجودة|Claim not found|داواکاری نەدۆزرایەوە
توجد مطالبة معلقة|A pending claim already exists|داواکارییەکی چاوەڕوان هەیە
إيداع|Deposit|دانانی پارە
إيداع افتتاحي تجريبي|Demo opening deposit|دانانی پارەی سەرەتایی تاقیکردنەوە
تحويل صادر|Outgoing transfer|گواستنەوەی دەرچوو
تحويل وارد|Incoming transfer|گواستنەوەی هاتوو
بيع بطاقة|Card sale|فرۆشتنی کارت
تعديل سجل|Record updated|تۆمار دەستکاری کرا
إنشاء سجل|Record created|تۆمار دروست کرا
جلسة محلية|Local session|دانیشتنی ناوخۆیی
تبديل حساب محلي|Local account switched|هەژماری ناوخۆیی گۆڕدرا
تحويل مالي|Funds transfer|گواستنەوەی پارە
إيداع مالي|Deposit recorded|دانانی پارە تۆمار کرا
تحميل دفعة|Batch loaded|بەش بارکرا
إصدار وطلب طباعة|Issued and print requested|دەرکرا و داوای چاپ کرا
نتيجة طباعة|Print result|ئەنجامی چاپ
إلغاء دفعة|Batch cancellation|هەڵوەشاندنەوەی کۆمەڵە
حجر دفعة|Batch quarantined|بەش گۆشەگیر کرا
اقتراح أسعار|Prices proposed|نرخ پێشنیار کرا
اعتماد أسعار|Prices approved|نرخ پەسەند کرا
تراجع أسعار|Prices rolled back|نرخ گەڕێنرایەوە
فتح مطالبة|Claim opened|داواکاری کرایەوە
تسوية مطالبة|Claim resolved|داواکاری یەکلایی کرایەوە
تغيير التفعيل|Activation changed|چالاککردن گۆڕدرا
إنهاء جلسة جهاز محلي|Local device session ended|دانیشتنی ئامێری ناوخۆیی کۆتایی هات
تصدير دفعة مشفرة|Encrypted batch exported|بەشی کۆدکراو هەناردە کرا
إعداد تكامل|Integration configured|پەیوەندی ڕێکخرا
إضافة إشعار محلي|Local notification added|ئاگادارکردنەوەی ناوخۆیی زیاد کرا
فتح تذكرة|Ticket opened|تیکەت کرایەوە
رد على تذكرة|Ticket reply|وەڵامی تیکەت
حالة التذكرة|Ticket status|دۆخی تیکەت
تغيير مفتاح التشغيل|Operation control changed|کۆنترۆڵی کارپێکردن گۆڕدرا
تغيير السياسات|Policies changed|سیاسەتەکان گۆڕدران
تغيير هوية الوكيل|Agent branding changed|ناسنامەی بریکار گۆڕدرا
طلب تواصل محلي|Local contact request|داواکاریی پەیوەندیی ناوخۆیی
تصدير تقرير|Report exported|ڕاپۆرت هەناردە کرا
استعادة نسخة|Backup restored|کۆپیی پاراستن گەڕێنرایەوە
تم تحميل الأسعار للمعاينة؛ أرسلها للمراجعة عند الانتهاء|Prices loaded for preview; submit for review when ready|نرخەکان بۆ پێشبینین بارکران؛ کە تەواو بوو بۆ پێداچوونەوە بینێرە
تم تنزيل الملف المشفر ومنع البطاقات المصدرة من البيع|Encrypted file downloaded; exported cards blocked from sale|فایلی کۆدکراو دابەزی؛ فرۆشتنی کارتە هەناردەکراوەکان قەدەغە کرا
تم تسجيل نتيجة المزود؛ لا تنشئ التسوية إيداعًا نقديًا تلقائيًا|Provider decision recorded; settlement does not automatically fund a wallet|بڕیاری دابینکەر تۆمار کرا؛ یەکلایی بە خۆکار پارە زیاد ناکات بۆ جزدان
حُفظت الإعدادات؛ الربط الفعلي غير متصل|Settings saved; live integration is not connected|ڕێکخستنەکان پاشەکەوت کران؛ پەیوەندیی ڕاستەقینە نەبەستراوە
أضيف الإشعار محليًا؛ لم يرسل إلى أجهزة خارجية|Notification added locally; not sent to external devices|ئاگادارکردنەوە لە ناوخۆ زیاد کرا؛ بۆ ئامێری دەرەکی نەنێردرا
حُفظ الطلب محليًا في الدعم الفني؛ لا يوجد إرسال خارجي|Request saved locally in support; nothing sent externally|داواکاری لە پشتگیریی ناوخۆ پاشەکەوت کرا؛ هیچ شتێک بۆ دەرەوە نەنێردرا
`
    .trim()
    .split("\n");
  const dictionaries = { en: {}, ckb: {} };
  for (const row of rows) {
    const [ar, en, ckb] = row.split("|");
    dictionaries.en[ar.trim()] = en;
    dictionaries.ckb[ar.trim()] = ckb;
  }
  const additional = `
لغة الواجهة|Interface language|زمانی ڕووکار
العربية|العربية|العربية
کوردی|کوردی|کوردی
وكيل رئيسي|Main agent|بریکاری سەرەکی
وكيل فرعي|Sub-agent|بریکاری لاوەکی
الدعم العام|General support|پشتگیریی گشتی
غير محدد|Not specified|دیاری نەکراوە
بطاقات تجريبية غير قابلة للشحن. نجاح الطباعة يُسجل يدويًا هنا إلى حين ربط أجهزة POS.|Demo cards cannot be redeemed. Print results are confirmed manually until POS devices are connected.|کارتە تاقیکردنەوەکان بەکار نایەن. تا بەستنی ئامێرەکانی POS، ئەنجامی چاپ بە دەست تۆمار دەکرێت.
استخدم بيانات تجريبية فقط؛ هذه النسخة تحفظ البيانات محليًا دون خزنة تشفير على خادم. يُقبل CSV، ويُفصل السيريال الداخلي عن سيريال المزود.|Use demo data only. This edition stores data locally without a server vault. CSV is supported; internal and provider serials remain separate.|تەنها داتای تاقیکردنەوە بەکاربهێنە. ئەم وەشانە داتا لە ناوخۆ پاشەکەوت دەکات بێ خەزێنەی کۆدکردنی سێرڤەر. CSV پشتگیری دەکرێت و ژمارەی ناوخۆیی لە هی دابینکەر جیاوازە.
الفئة التي لا تملك سعرًا فعالًا موجبًا لا يمكن بيعها. قيم الحدود المالية بالدينار العراقي.|A category cannot be sold without a positive active price. Financial limits are in Iraqi dinars.|پۆلێک بێ نرخی چالاکی ئەرێنی نافرۆشرێت. سنوورە داراییەکان بە دیناری عێراقین.
العلامة التجارية والمجهز والجهة المنظمة حقول مستقلة. تكاملات API تُضبط لكل وكيل على حدة.|Brand, supplier and organizing authority are separate fields. API integrations are configured per agent.|نیشانی بازرگانی، دابینکەر و لایەنی ڕێکخەر خانەی جیاوازن. پەیوەندیی API بۆ هەر بریکارێک جیا ڕێکدەخرێت.
التغييرات تمر بالمراجعة ثم اعتماد شخص ثانٍ. السعر صفر أو الأقل من أقل سعر بيع مسموح مرفوض. الاستيراد المحلي بصيغة CSV قابلة للفتح في Excel.|Changes require review and approval by a second person. Zero prices and prices below the contract minimum are rejected. Local imports use Excel-compatible CSV files.|گۆڕانکاری پێویستی بە پێداچوونەوە و پەسەندکردنی کەسێکی دووەم هەیە. نرخی سفر یان کەمتر لە سنووری گرێبەست ڕەت دەکرێتەوە. هاوردەی ناوخۆیی بە CSVی گونجاو لەگەڵ Excelە.
البطاقة التي كُشف رمزها لا تعاد للمخزون بعد فشل الطباعة. إعادة الطباعة تستخدم البطاقة نفسها دون خصم جديد.|A card with an exposed PIN never returns to available inventory after print failure. Reprinting uses the same card with no additional charge.|کارتێک کە PINەکەی پیشاندراوە، دوای شکستی چاپ ناگەڕێتەوە بۆ کۆگای بەردەست. دووبارەچاپ هەمان کارت بەکاردەهێنێت بێ کەمکردنەوەی پارە.
الحجر والتصدير يخرجان البطاقات من المتاح، ويحتفظان بتكلفتها معلقة دون خصم آلي من المحفظة. التصدير التجريبي مشفر بكلمة مرور، ولا يوفر رابطًا محدود الصلاحية.|Quarantine and export remove cards from available inventory, with their cost held pending and no automatic wallet debit. Demo exports are password-encrypted; expiring download links are not available.|گۆشەگیرکردن و هەناردە کارت لە کۆگای بەردەست دەردەهێنن و تێچووەکەی چاوەڕوان دەمێنێت بێ کەمکردنەوەی خۆکاری پارە. هەناردەی تاقیکردنەوە بە وشەی نهێنی کۆد دەکرێت؛ بەستەری کاتی بەردەست نییە.
إضافة المخزون لا تموّل المحافظ. التحويل ينشئ قيدين متقابلين، ولا يسمح بتجاوز الرصيد أو التحويل خارج الشجرة.|Adding inventory does not fund wallets. Transfers create balanced entries and cannot exceed the balance or leave the agent hierarchy.|زیادکردنی کۆگا پارە زیاد ناکات بۆ جزدان. گواستنەوە دوو تۆماری هاوسەنگ دروست دەکات و ناتوانێت باڵانس تێپەڕێنێت یان بچێتە دەرەوەی پێکهاتە.
الإحداثيات دقيقة حسب الإدخال؛ حدود العراق مرسومة للتوضيح وليست خريطة ملاحة.|Coordinates follow the entered data; Iraq's outline is illustrative and is not a navigation map.|کۆئۆردیناتەکان بەپێی داتای داخڵکراون؛ سنووری عێراق بۆ ڕوونکردنەوەیە، نەک بۆ ڕێنیشاندان.
· التذاكر والردود محفوظة محليًا ولا تُرسل إلى جهة خارجية.|• Tickets and replies are stored locally and are not sent externally.|• تیکەت و وەڵامەکان لە ناوخۆ پاشەکەوت دەکرێن و بۆ دەرەوە نانێردرێن.
مبدّل الحسابات في الأعلى لاختبار نطاق الرؤية محليًا؛ لا يمثل تسجيل دخول آمنًا. المصادقة الحقيقية وصلاحيات الخادم ضمن متطلبات الربط.|The account switcher previews access scope locally; it is not secure sign-in. Real authentication and backend authorization require server integration.|گۆڕەری هەژمار بۆ تاقیکردنەوەی سنووری بینینە لە ناوخۆ؛ چوونەژوورەوەی پارێزراو نییە. پشتڕاستکردنەوە و دەسەڵاتی ڕاستەقینە پێویستیان بە پەیوەندیی سێرڤەر هەیە.
هذا تمثيل لصلاحيات النموذج المحلي. منع الوصول الحقيقي يتطلب فرض الصلاحيات على الخادم في كل طلب API.|This represents permissions in the local model. Real access control must be enforced by the server on every API request.|ئەمە پیشاندانی دەسەڵاتەکانە لە مۆدێلی ناوخۆیی. کۆنترۆڵی ڕاستەقینەی دەستڕاگەیشتن دەبێت لە سێرڤەر بۆ هەر داواکاریی API جێبەجێ بکرێت.
الربط الفعلي ينتظر وثائق المزود والخادم. لا تدخل Token حقيقيًا في ملف HTML؛ تحفظ هذه الشاشة إعدادات البيئة فقط.|Live integration awaits provider documentation and a backend. Do not enter real tokens into HTML; this screen stores environment settings only.|پەیوەندیی ڕاستەقینە چاوەڕوانی بەڵگەنامەی دابینکەر و سێرڤەرە. Tokenی ڕاستەقینە لە HTML مەنووسە؛ ئەم شاشەیە تەنها ڕێکخستنی ژینگە پاشەکەوت دەکات.
لا تتوفر أزرار تعديل أو حذف للسجل. حفظه ضد العبث خارج الواجهة يحتاج خادمًا؛ ملف المتصفح ليس سجل تدقيق حصينًا.|Audit entries cannot be edited or deleted through the UI. Tamper resistance requires a backend; browser storage is not a protected audit ledger.|تۆمارەکانی پشکنین لە ڕووکار دەستکاری یان سڕینەوە ناکرێن. پاراستن لە دەستکاری پێویستی بە سێرڤەر هەیە؛ پاشەکەوتی وێبگەڕ تۆمارێکی پارێزراو نییە.
المؤشرات التالية مشتقة من بيانات النسخة المحلية. مراقبة الخوادم وقاعدة البيانات وزمن API غير متاحة دون خادم متصل.|These metrics are derived from local data. Server, database and API latency monitoring requires a connected backend.|ئەم پێوەرانە لە داتای ناوخۆیی وەرگیراون. چاودێریی سێرڤەر، بنکەی داتا و کاتی API پێویستی بە سێرڤەری پەیوەست هەیە.
المفاتيح أدناه تضبط محاكاة العمليات المحلية. TOTP وكلمات المرور والجلسات الموثوقة وحظر الشبكات تحتاج تنفيذًا على الخادم.|These controls configure the local simulation. TOTP, passwords, trusted sessions and network blocking must be implemented on the server.|ئەم کۆنترۆڵانە هاوشێوەکردنی ناوخۆیی ڕێکدەخەن. TOTP، وشەی نهێنی، دانیشتنی متمانەپێکراو و قەدەغەکردنی تۆڕ پێویستیان بە جێبەجێکردن لە سێرڤەر هەیە.
إدارة البطاقات|Card management|بەڕێوەبردنی کارت
مخزون منظم، دفعات واضحة، وسياسة صرف حسب تاريخ الانتهاء.|Organized inventory, clear batches and expiry-based allocation.|کۆگای ڕێکخراو، بەشی ڕوون و دەرکردن بەپێی بەرواری بەسەرچوون.
شبكة الوكلاء|Agent network|تۆڕی بریکارەکان
رؤية موحدة للوكلاء والمحافظ ونقاط البيع التابعة.|A unified view of agents, wallets and their POS network.|بینینێکی یەکگرتوو بۆ بریکار، جزدان و تۆڕی خاڵەکانی فرۆشتن.
بيع وطباعة|Sell & print|فرۆشتن و چاپ
إصدار البطاقات ومتابعة الطباعة والاستثناءات في مكان واحد.|Issue cards and track printing and exceptions in one place.|دەرکردنی کارت و بەدواداچوونی چاپ و کێشەکان لە یەک شوێن.
منصة تشغيل لتوزيع البطاقات الإلكترونية، تربط إدارة النظام بالوكلاء ونقاط البيع.|An electronic-card distribution platform connecting administration, agents and POS.|پلاتفۆرمێک بۆ دابەشکردنی کارتی ئەلیکترۆنی کە بەڕێوەبەرایەتی، بریکار و خاڵی فرۆشتن پێکەوە دەبەستێت.
تطبيقات الهاتف وروابط المتاجر تضاف عند توفير النسخ المنشورة.|Mobile apps and store links will be added when released.|ئەپی مۆبایل و بەستەری فرۆشگاکان کاتی بڵاوکردنەوە زیاد دەکرێن.
النسخة الاحتياطية تشمل البيانات التجريبية ورموز البطاقات المحلية. لا تستخدم بيانات حقيقية. الاستعادة تستبدل مساحة العمل الحالية بعد فحص الملف وتأكيدك.|Backups contain demo data and local card codes. Do not use real data. After validation and confirmation, restoring replaces the current workspace.|کۆپیی پاراستن داتای تاقیکردنەوە و کۆدی کارتە ناوخۆییەکان لەخۆدەگرێت. داتای ڕاستەقینە بەکار مەهێنە. دوای پشکنین و پشتڕاستکردنەوە، گەڕاندنەوە شوێنی کاری ئێستا دەگۆڕێت.
الفحص يمنع PIN المكرر والسيريال المكرر للفئة نفسها، ويرفض الحقول الناقصة والتواريخ المنتهية.|Validation rejects duplicate PINs and category serials, missing fields and expired dates.|پشکنین PIN و ژمارەی زنجیرەیی دووبارە، خانەی ناتەواو و بەرواری بەسەرچوو ڕەت دەکاتەوە.
ستُمنع بطاقات الدفعة من البيع. لا يمكن إلغاء دفعة استخدمت منها أي بطاقة.|These cards will be blocked from sale. A batch with any used card cannot be cancelled.|فرۆشتنی ئەم کارتانە قەدەغە دەکرێت. بەشێک کە کارتێکی بەکارهاتووە هەڵناوەشێنرێتەوە.
الحالة|Status|دۆخ
سبب المطالبة مطلوب|A claim reason is required|هۆکاری داواکاری پێویستە
سبب التصدير مطلوب|An export reason is required|هۆکاری هەناردە پێویستە
حالة العملية لا تسمح بإعادة الطباعة|This transaction status does not allow reprinting|دۆخی ئەم مامەڵەیە ڕێگە بە دووبارەچاپ نادات
تجاوز حد إعادة الطباعة؛ يلزم تعديل الحد بواسطة الإدارة بعد المراجعة|Reprint limit exceeded; administration must review and adjust the limit|سنووری دووبارەچاپ تێپەڕێنرا؛ بەڕێوەبەرایەتی دەبێت پێداچوونەوە بکات و سنوور بگۆڕێت
لا يوجد طلب طباعة معلق|No pending print request|هیچ داواکاریی چاپی چاوەڕوان نییە
منتج غير معروف أو سعر أقل من أقل سعر بيع مسموح|Unknown product or price below contract minimum|بەرهەمی نەناسراو یان نرخێک کەمتر لە سنووری گرێبەست
تغير السعر منذ المراجعة؛ أعد تقديم الطلب|Price changed since review; submit a new request|نرخ لە دوای پێداچوونەوە گۆڕاوە؛ داواکاریی نوێ بنێرە
يوجد تعديل أحدث؛ قدم طلبًا جديدًا|A newer change exists; submit a new request|گۆڕانکاریی نوێتر هەیە؛ داواکاریی نوێ بنێرە
حدد حسابين مختلفين ومبلغًا موجبًا|Choose two different accounts and a positive amount|دوو هەژماری جیاواز و بڕێکی ئەرێنی هەڵبژێرە
مصدر التحويل يجب أن يكون وكيلاً|Transfer source must be an agent|سەرچاوەی گواستنەوە دەبێت بریکار بێت
الحساب المستلم غير موجود|Recipient account not found|هەژماری وەرگر نەدۆزرایەوە
التحويل مسموح للأبناء ونقاط البيع التابعة فقط|Transfers are allowed only to descendants and their POS|گواستنەوە تەنها بۆ لقەکان و خاڵەکانیان ڕێگەپێدراوە
يمكنك التحويل من محفظتك فقط|You can transfer only from your own wallet|تەنها لە جزدانی خۆت دەتوانیت پارە بگوازیتەوە
المبلغ الموجب ومرجع الإيداع مطلوبان|A positive amount and deposit reference are required|بڕێکی ئەرێنی و سەرچاوەی دانانی پارە پێویستن
حساب غير موجود|Account not found|هەژمار نەدۆزرایەوە
المخزون يخص الوكيل الرئيسي فقط|Inventory belongs only to the main agent|کۆگا تەنها بۆ بریکاری سەرەکییە
تكلفة التوريد غير صحيحة|Invalid procurement cost|تێچووی دابینکردن نادروستە
اختر نقطة بيع وفئة|Select a POS and category|خاڵێکی فرۆشتن و پۆلێک هەڵبژێرە
نقطة البيع خارج الصلاحية|POS outside your permissions|خاڵی فرۆشتن لە دەسەڵاتت نییە
الفئة أو المزود غير مفعل|Category or provider disabled|پۆل یان دابینکەر ناچالاکە
أحد وكلاء الشجرة موقوف|An agent in this hierarchy is disabled|بریکارێک لەم پێکهاتەیەدا ناچالاکە
الفئة غير متاحة لهذا الوكيل|Category unavailable for this agent|پۆل بۆ ئەم بریکارە بەردەست نییە
الفئة غير متاحة في هذه المحافظة|Category unavailable in this province|پۆل لەم پارێزگایە بەردەست نییە
تسوية غير صالحة|Invalid settlement|یەکلایی نادروستە
البطاقات التي تم تصدير رموزها لا تعاد للبيع؛ يلزم بديل من المزود|Cards with exported codes cannot be resold; the provider must supply replacements|کارتێک کۆدەکەی هەناردە کراوە نافرۆشرێتەوە؛ دابینکەر دەبێت جێگرەوە دابین بکات
كلمة التشفير يجب ألا تقل عن 12 حرفًا|Encryption password must have at least 12 characters|وشەی نهێنیی کۆدکردن دەبێت لانیکەم 12 پیت بێت
اختر PNG أو JPG بحجم أقل من 700 كيلوبايت|Choose a PNG or JPG under 700 KB|وێنەی PNG یان JPGی کەمتر لە 700 KB هەڵبژێرە
التذكرة مغلقة|Ticket closed|تیکەت داخراوە
الإشعار العام من صلاحية مدير النظام|Only the system owner can send a global notification|تەنها بەڕێوەبەری سیستەم دەتوانێت ئاگادارکردنەوەی گشتی بنێرێت
الجمهور خارج النطاق|Audience outside your scope|وەرگرەکان لە سنوورەکەت نین
مفاتيح النظام العامة من صلاحية المدير|Global controls require the system owner|کۆنترۆڵی گشتی پێویستی بە بەڕێوەبەری سیستەم هەیە
لا يمكنك إيقاف حسابك الحالي|You cannot disable your current account|ناتوانیت هەژماری ئێستات ناچالاک بکەیت
لا تغير دور حسابك الحالي|You cannot change your current account role|ناتوانیت ڕۆڵی هەژماری ئێستات بگۆڕیت
لا يمكنك منح هذا الدور|You cannot grant this role|ناتوانیت ئەم ڕۆڵە بدەیت
الدور لا يطابق مستوى الوكيل|Role does not match agent level|ڕۆڵ لەگەڵ ئاستی بریکار ناگونجێت
اختر نقطة تتبع الوكيل المحدد|Choose a POS belonging to the selected agent|خاڵێک هەڵبژێرە کە سەر بە بریکارە دیاریکراوەکە بێت
نطاق مشرف غير صالح|Invalid supervisor scope|سنووری سەرپەرشتیار نادروستە
الجهاز مرتبط بنقطة أخرى|Device already assigned to another POS|ئامێرەکە پێشتر بە خاڵێکی ترەوە بەستراوە
نقل نقطة بين الوكلاء يحتاج تسوية مستقلة|Moving a POS between agents requires separate settlement|گواستنەوەی خاڵ لە نێوان بریکارەکان پێویستی بە یەکلایی جیاواز هەیە
أكمل المدينة والمجهز|Complete city and supplier details|زانیاریی شار و دابینکەر تەواو بکە
منتج مكرر|Duplicate product|بەرهەمی دووبارە
منتج مكرر في الملف|Duplicate product in file|بەرهەم لە فایلدا دووبارەیە
المنتج مكرر في الملف|Duplicate product in file|بەرهەم لە فایلدا دووبارەیە
الملف يجب أن يحتوي عنوان الأعمدة وسطر بيانات على الأقل|The file needs column headers and at least one data row|فایلەکە پێویستی بە ناونیشانی ستوونەکان و لانیکەم ڕیزێکی داتا هەیە
علامات الاقتباس في CSV غير مكتملة|Unclosed quotes in CSV|نیشانەی وتە لە CSVدا ناتەواوە
عناوين أعمدة مكررة|Duplicate column headers|ناونیشانی ستوونی دووبارە
حقل بطاقة غير مدعوم|Unsupported card field|خانەی کارت پشتگیری ناکرێت
قالب المنتج يجب أن يحتوي pin|Product template must include pin|قاڵبی بەرهەم دەبێت pinی تێدا بێت
الوكيل الفرعي يحتاج وكيلاً أعلى|A sub-agent needs a parent agent|بریکاری لاوەکی پێویستی بە بریکاری سەرەوە هەیە
الوكيل الرئيسي لا يتبع وكيلاً آخر|A main agent cannot have a parent agent|بریکاری سەرەکی نابێت سەر بە بریکارێکی تر بێت
إضافة رئيسي من صلاحية الإدارة|Only administration can add a main agent|تەنها بەڕێوەبەرایەتی دەتوانێت بریکاری سەرەکی زیاد بکات
لا يمكن إنشاء حلقة في الشجرة|A hierarchy cycle is not allowed|بازنە لە پێکهاتەدا ڕێگەپێنەدراوە
نقل الوكيل أو تغيير مستواه يحتاج ترحيلًا ماليًا؛ غير متاح من التعديل|Moving an agent or changing its level requires financial migration, not a normal edit|گواستنەوەی بریکار یان گۆڕینی ئاستەکەی پێویستی بە گواستنەوەی دارایی هەیە، نەک دەستکاریی ئاسایی
إدارة الفئات والمزودين العامة تحتاج الإدارة أو المشرف|Managing shared categories and providers requires an owner or supervisor|بەڕێوەبردنی پۆل و دابینکەری گشتی پێویستی بە بەڕێوەبەر یان سەرپەرشتیار هەیە
لإدارة النظام فقط|System administration only|تەنها بۆ بەڕێوەبەرایەتی سیستەم
`
    .trim()
    .split("\n");
  for (const row of additional) {
    const [ar, en, ckb] = row.split("|");
    dictionaries.en[ar.trim()] = en;
    dictionaries.ckb[ar.trim()] = ckb;
  }
  const latin = (value) =>
    String(value).replace(/[٠-٩۰-۹]/g, (c) =>
      String(c.charCodeAt(0) - (c >= "۰" ? 1776 : 1632)),
    );
  const translationPatterns = {},
    untranslated = new Set();
  function translate(value, lang) {
    if (
      value == null ||
      typeof value === "object" ||
      typeof value === "boolean"
    )
      return value;
    const source = latin(value);
    if (lang === "ar") return source;
    const trimmed = source.trim(),
      dict = dictionaries[lang] || dictionaries.en;
    if (dict[trimmed]) return source.replace(trimmed, () => dict[trimmed]);
    const count = Object.keys(dict).length;
    let cache = translationPatterns[lang];
    if (!cache || cache.count !== count) {
      const keys = Object.keys(dict)
        .filter((k) => k.trim() && typeof dict[k] === "string")
        .sort((a, b) => b.length - a.length)
        .map((k) => k.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"));
      cache = translationPatterns[lang] = {
        count,
        re: new RegExp(
          "(?<![\\p{L}\\p{N}_])(?:" + keys.join("|") + ")(?![\\p{L}\\p{N}_])",
          "gu",
        ),
      };
    }
    const text = source.replace(cache.re, (key) => dict[key] ?? key);
    if (lang === "en" && /[\u0600-\u06ff]/.test(text))
      untranslated.add(trimmed);
    return text;
  }
  function localDate(value, lang, mode) {
    const d = new Date(value);
    if (lang !== "ckb")
      return latin(
        new Intl.DateTimeFormat(
          lang === "en" ? "en-GB-u-nu-latn" : "ar-IQ-u-nu-latn",
          mode === "weekday"
            ? { weekday: "short", timeZone: "Asia/Baghdad" }
            : mode === "long"
              ? {
                  weekday: "long",
                  year: "numeric",
                  month: "long",
                  day: "numeric",
                  timeZone: "Asia/Baghdad",
                }
              : {
                  dateStyle: "short",
                  timeStyle: "short",
                  timeZone: "Asia/Baghdad",
                },
        ).format(d),
      );
    const p = Object.fromEntries(
      new Intl.DateTimeFormat("en-GB-u-nu-latn", {
        year: "numeric",
        month: "numeric",
        day: "numeric",
        weekday: "short",
        hour: "2-digit",
        minute: "2-digit",
        hour12: false,
        timeZone: "Asia/Baghdad",
      })
        .formatToParts(d)
        .map((x) => [x.type, x.value]),
    );
    const days = {
        Sun: "یەکشەممە",
        Mon: "دووشەممە",
        Tue: "سێشەممە",
        Wed: "چوارشەممە",
        Thu: "پێنجشەممە",
        Fri: "هەینی",
        Sat: "شەممە",
      },
      months = [
        "کانوونی دووەم",
        "شوبات",
        "ئازار",
        "نیسان",
        "ئایار",
        "حوزەیران",
        "تەمووز",
        "ئاب",
        "ئەیلوول",
        "تشرینی یەکەم",
        "تشرینی دووەم",
        "کانوونی یەکەم",
      ];
    return mode === "weekday"
      ? days[p.weekday]
      : mode === "long"
        ? days[p.weekday] +
          "، " +
          p.day +
          " " +
          months[Number(p.month) - 1] +
          " " +
          p.year
        : p.day + "/" + p.month + "/" + p.year + " " + p.hour + ":" + p.minute;
  }
  root.MasalLocale = {
    dictionaries,
    translate,
    latin,
    date: localDate,
    untranslated,
  };
})(globalThis);
