(function (root) {
  "use strict";
  const missing = new Set();
  const translations = `
إجراءات إضافية|More actions|کرداری زیاتر
رفع صورة الشركة واسمها|Move company image and name up|بەرزکردنەوەی وێنە و ناوی کۆمپانیا
رفع النص العلوي|Move header text up|بەرزکردنەوەی دەقی سەرەوە
رفع اسم الفئة|Move category name up|بەرزکردنەوەی ناوی پۆل
رفع صورة الفئة|Move category image up|بەرزکردنەوەی وێنەی پۆل
رفع بيانات البطاقة|Move card data up|بەرزکردنەوەی داتای کارت
رفع المبلغ|Move amount up|بەرزکردنەوەی بڕ
رفع صورة الوكيل ونصه|Move agent image and text up|بەرزکردنەوەی وێنە و دەقی بریکار
رفع النص السفلي|Move footer text up|بەرزکردنەوەی دەقی خوارەوە
عدد محاولات إعادة الطباعة بعد الفشل|Reprint attempts after failure|ژمارەی هەوڵی دووبارەچاپ دوای شکست
الحد الأقصى لعدد البطاقات في الطلب الواحد|Maximum cards per request|زۆرترین ژمارەی کارت لە داواکارییەک
مدة الانتظار بين عمليتي طباعة — بالثواني|Wait between print attempts — seconds|ماوەی چاوەڕوانی نێوان دوو چاپ — بە چرکە
أدخل كلمة مرور جديدة|Enter a new password|وشەی نهێنییەکی نوێ بنووسە
إخفاء كلمة المرور الجديدة|Hide new password|شاردنەوەی وشەی نهێنیی نوێ
إظهار كلمة المرور الجديدة|Show new password|پیشاندانی وشەی نهێنیی نوێ
إخفاء تأكيد كلمة المرور|Hide password confirmation|شاردنەوەی پشتڕاستکردنەوەی وشەی نهێنی
إظهار تأكيد كلمة المرور|Show password confirmation|پیشاندانی پشتڕاستکردنەوەی وشەی نهێنی
حفظ كلمة المرور|Save password|پاشەکەوتکردنی وشەی نهێنی
كلمة المرور يجب أن تكون 8 أحرف على الأقل|Password must contain at least 8 characters|وشەی نهێنی دەبێت لانیکەم 8 پیت بێت
تصدير البطاقات المرفوضة (|Export rejected cards (|هەناردەکردنی کارتە ڕەتکراوەکان (
القائمة الرئيسية|Main menu|لیستی سەرەکی
مركز التقارير والعمليات|Reports and operations center|ناوەندی ڕاپۆرت و کردارەکان
موقع الشركة|Company website|ماڵپەڕی کۆمپانیا
البطاقات والمخزون|Cards and inventory|کارت و کۆگا
الطلبيات|Orders|داواکارییەکان
الأسعار|Prices|نرخەکان
الشركات|Companies|کۆمپانیاکان
المصادر|Suppliers|دابینکەرەکان
عمليات البطاقات|Card operations|کردارەکانی کارت
خريطة المستخدمين|User map|نەخشەی بەکارهێنەران
التواصل والدعم|Communication and support|پەیوەندی و پشتگیری
المستخدمون والصلاحيات|Users and permissions|بەکارهێنەر و دەسەڵاتەکان
إعدادات النظام|System settings|ڕێکخستنەکانی سیستەم
الحماية والأمان|Security and protection|ئاسایش و پاراستن
إعدادات البطاقات والموقع|Card and website settings|ڕێکخستنەکانی کارت و ماڵپەڕ
تعديل بروفايل الشركة|Edit company profile|دەستکاریی پڕۆفایلی کۆمپانیا
الحساب الحالي|Current account|هەژماری ئێستا
طي القائمة|Collapse menu|داخستنی لیست
وكيل|Agent|بریکار
إجمالي المسجلين|Total registered|کۆی تۆمارکراوان
المحافظات النشطة|Active provinces|پارێزگا چالاکەکان
محافظة|Province|پارێزگا
المفعلة في النظام|Enabled in the system|چالاککراو لە سیستەم
نقطة|POS|خاڵ
مبيعات اليوم|Today's sales|فرۆشتنی ئەمڕۆ
اليوم|Today|ئەمڕۆ
ربح ماسال من الطلبيات اليوم|Masal profit from today's orders|قازانجی ماساڵ لە داواکارییەکانی ئەمڕۆ
اليوم · قبل المصاريف|Today · before expenses|ئەمڕۆ · پێش خەرجی
البطاقات المتاحة|Available cards|کارتە بەردەستەکان
المخزون الحالي|Current inventory|کۆگای ئێستا
طلبات التمويل المعلقة|Pending funding requests|داواکارییە چاوەڕوانەکانی دابینکردنی دارایی
طلب|Request|داواکاری
طلبات إعادة الطباعة|Reprint requests|داواکارییەکانی دووبارەچاپ
بانتظار الإكمال|Awaiting completion|چاوەڕوانی تەواوکردن
رسائل الدعم المفتوحة|Open support messages|نامە کراوەکانی پشتگیری
رسالة|Message|نامە
التحقق من الحساب|Account verification|پشتڕاستکردنەوەی هەژمار
تحقق|Verify|پشتڕاستکردنەوە
التقارير حسب الفترة والجهة المختارة|Reports for the selected period and account|ڕاپۆرت بەپێی ماوە و هەژماری هەڵبژێردراو
المبيعات والأرباح|Sales and profit|فرۆشتن و قازانج
المبيعات والطباعة والأسعار|Sales, printing and pricing|فرۆشتن، چاپ و نرخ
تقارير|Reports|ڕاپۆرتەکان
المحافظ والتمويل|Wallets and funding|جزدان و دابینکردنی دارایی
الأرصدة والتمويل والتحصيل|Balances, funding and collections|باڵانس، دابینکردن و وەرگرتنی پارە
الدفعات والبطاقات والمطالبات|Batches, cards and claims|کۆمەڵە، کارت و داواکاریی قەرەبوو
الوكلاء ونقاط البيع|Agents and POS|بریکار و خاڵەکانی فرۆشتن
شبكة التوزيع وحالة الحسابات|Distribution network and account statuses|تۆڕی دابەشکردن و دۆخی هەژمارەکان
المتابعة الإدارية|Administrative follow-up|بەدواداچوونی بەڕێوەبەرایەتی
التغييرات والدعم والإشعارات|Changes, support and notifications|گۆڕانکاری، پشتگیری و ئاگادارکردنەوە
اختر مجموعة لعرض التقارير المرتبطة بها|Select a group to view its reports|گرووپێک هەڵبژێرە بۆ بینینی ڕاپۆرتەکانی
الأرصدة والحركات|Balances and activity|باڵانس و جوڵەکان
تمويل متعدد|Bulk funding|دابینکردنی دارایی کۆمەڵە
استرجاع الرصيد|Reclaim balance|گەڕاندنەوەی باڵانس
الفواتير والتحصيل|Invoices and collections|پسوولە و وەرگرتنی پارە
إجمالي كل الأرصدة|Total of all balances|کۆی هەموو باڵانسەکان
البطاقات • رصيد تشغيلي|Cards • operating credit|کارت • باڵانسی کارکردن
الرصيد الحالي للحسابات المعروضة|Current balance of displayed accounts|باڵانسی ئێستای هەژمارە پیشاندراوەکان
الرصيد المتاح للحسابات المعروضة|Available balance of displayed accounts|باڵانسی بەردەستی هەژمارە پیشاندراوەکان
الرصيد المحجوز للحسابات المعروضة|Reserved balance of displayed accounts|باڵانسی حجزکراوی هەژمارە پیشاندراوەکان
عدد بطاقات مخزون الوكيل الرئيسي|Main agent inventory card count|ژمارەی کارتی کۆگای بریکاری سەرەکی
تكلفة مخزون الوكيل الرئيسي|Main agent inventory cost|تێچووی کۆگای بریکاری سەرەکی
قيمة مخزون البطاقات|Card inventory value|بەهای کۆگای کارت
تمويل وارد|Incoming funding|دابینکردنی دارایی هاتوو
تمويل صادر|Outgoing funding|دابینکردنی دارایی دەرچوو
تحميل بطاقات|Card loading|بارکردنی کارت
طلبات تمويل الوكلاء|Agent funding requests|داواکاریی دابینکردنی دارایی بریکارەکان
طلبات واردة|Incoming requests|داواکارییە هاتووەکان
سجل التمويل|Funding history|تۆماری دابینکردنی دارایی
الحسابات والأرصدة|Accounts and balances|هەژمار و باڵانسەکان
بحث باسم الحساب أو الهاتف|Search account name or phone|گەڕان بە ناوی هەژمار یان تەلەفۆن
كل الأنواع ضمن نطاقي|All types within my scope|هەموو جۆرەکان لە مەوداکەم
عدد الحسابات المطابقة|Matching account count|ژمارەی هەژمارە گونجاوەکان
تابع إلى|Parent account|سەر بە
الرصيد المتاح|Available balance|باڵانسی بەردەست
فرعي تابع لفرعي|Sub-branch of a branch|لقی لاوەکیی لق
عدد الصفوف في الصفحة|Rows per page|ڕیز لە هەر لاپەڕە
مخزن الوكيل الرئيسي|Main agent inventory|کۆگای بریکاری سەرەکی
جميع المخازن المتاحة|All available inventories|هەموو کۆگا بەردەستەکان
النشطة|Active|چالاکەکان
الموقوفة|Suspended|ڕاگیراوەکان
الملغاة|Cancelled|هەڵوەشاوەکان
تصدير|Export|هەناردەکردن
المعاينة والإرسال|Preview and submit|پێشبینین و ناردن
بحث في الفئات|Search categories|گەڕان لە پۆلەکان
عملة القيمة الاسمية|Face value currency|دراوی بەهای ناونراو
رمز الشحن / التفعيل|Recharge / activation code|کۆدی شەحن / چالاککردن
تاريخ الانتهاء|Expiry date|بەرواری بەسەرچوون
الرقم التسلسلي|Serial number|ژمارەی سریاڵ
الرقم المرجعي|Reference number|ژمارەی سەرچاوە
المحافظات المسموحة|Allowed provinces|پارێزگا ڕێگەپێدراوەکان
النص أعلى البطاقة|Card header text|دەقی سەرەوەی کارت
النص أسفل البطاقة|Card footer text|دەقی خوارەوەی کارت
الترتيب|Order|ڕیزبەندی
مطلوب|Required|پێویست
غير مستخدم|Not used|بەکارنەهاتوو
مفعلة|Enabled|چالاک
استيراد Excel / CSV|Import Excel / CSV|هاوردەکردنی Excel / CSV
إضافة شركة|Add company|زیادکردنی کۆمپانیا
البطاقات التالفة|Damaged cards|کارتە تێکچووەکان
التوزيع التنظيمي|Organization hierarchy|پێکهاتەی ڕێکخراوەیی
عرض مختصر|Compact view|پیشاندانی کورت
بحث بالاسم أو المحافظة…|Search name or province…|گەڕان بە ناو یان پارێزگا…
اسم صاحب المكتب|Office owner name|ناوی خاوەنی نووسینگە
تاريخ إنشاء الحساب|Account creation date|بەرواری دروستکردنی هەژمار
المندوب|Representative|نوێنەر
الاتصال|Connection|پەیوەندی
بدون موقع|No location|بێ شوێن
موقع الحساب المسجل|Registered account location|شوێنی تۆمارکراوی هەژمار
إضافة صورة (اختياري)|Add image (optional)|زیادکردنی وێنە (ئارەزوومەندانە)
مستخدمو النظام|System users|بەکارهێنەرانی سیستەم
المستخدمون الموقوفون|Suspended users|بەکارهێنەرە ڕاگیراوەکان
بحث باسم الموظف أو البريد الإلكتروني…|Search employee name or email…|گەڕان بە ناوی کارمەند یان ئیمەیڵ…
اسم الموظف|Employee name|ناوی کارمەند
لا توجد صلاحيات مضافة|No permissions added|هیچ دەسەڵاتێک زیاد نەکراوە
الفروع الفرعية|Sub-branches|لقە لاوەکییەکان
إيقاف النظام بالكامل|Suspend the entire system|ڕاگرتنی هەموو سیستەم
إيقاف الدخول|Suspend sign-in|ڕاگرتنی چوونەژوورەوە
إيقاف الطباعة|Suspend printing|ڕاگرتنی چاپ
إيقاف رفع الطلبيات|Suspend order uploads|ڕاگرتنی بارکردنی داواکاری
نسخ احتياطي|Back up|پاشەکەوتکردن
جارٍ النسخ الاحتياطي…|Backing up…|پاشەکەوت دەکرێت…
النسخ الاحتياطي على السيرفر غير مربوط بعد|Server backup is not connected yet|پاشەکەوتی سێرڤەر هێشتا نەبەستراوەتەوە
تم النسخ الاحتياطي على السيرفر|Server backup completed|پاشەکەوتی سێرڤەر تەواو بوو
اختيار نسخة احتياطية من الحاسبة|Choose a backup from this computer|هەڵبژاردنی پاشەکەوت لە کۆمپیوتەر
بغداد|Baghdad|بەغدا
البصرة|Basra|بەسرە
نينوى|Nineveh|نەینەوا
أربيل|Erbil|هەولێر
السليمانية|Sulaymaniyah|سلێمانی
دهوك|Duhok|دهۆک
حلبجة|Halabja|هەڵەبجە
كركوك|Kirkuk|کەرکووک
ديالى|Diyala|دیالە
الأنبار|Anbar|ئەنبار
صلاح الدين|Saladin|سەلاحەدین
بابل|Babylon|بابل
كربلاء|Karbala|کەربەلا
النجف|Najaf|نەجەف
واسط|Wasit|واست
ميسان|Maysan|میسان
ذي قار|Dhi Qar|زیقار
المثنى|Muthanna|موسەننا
القادسية|Qadisiyah|قادسیە
صورة الشركة واسمها|Company image and name|وێنە و ناوی کۆمپانیا
بيانات البطاقة|Card data|داتای کارت
صورة الوكيل ونصه|Agent image and text|وێنە و دەقی بریکار
محاولات إعادة الطباعة|Reprint attempts|هەوڵەکانی دووبارەچاپ
بطاقات الطلب الواحد|Cards per request|کارتی هەر داواکاری
الفاصل بين الطباعات (ثانية)|Print interval (seconds)|ماوەی نێوان چاپەکان (چرکە)
البطاقات والخدمات الإلكترونية|Cards and electronic services|کارت و خزمەتگوزاریی ئەلیکترۆنی
☾ ليلي|☾ Dark|☾ تاریک
القائمة ☰|Menu ☰|لیست ☰
السلايدر|Slideshow|پیشاندانی سلاید
النشاطات|Activities|چالاکییەکان
العروض|Offers|ئۆفەرەکان
المشاريع|Projects|پڕۆژەکان
مواقع التواصل|Social links|بەستەرە کۆمەڵایەتییەکان
طلبات العملاء|Customer requests|داواکارییەکانی کڕیار
الفروع المباشرة ونقاط البيع بكامل شبكة الحساب · يظهر الوكيل المباشر بجانب كل نقطة|Direct branches and POS across the account network · Each POS shows its direct agent|لقە ڕاستەوخۆکان و خاڵەکانی تۆڕی هەژمار · بریکاری ڕاستەوخۆ لە تەنیشت هەر خاڵێکە
الوكلاء · الصفحة|Agents · Page|بریکارەکان · لاپەڕە
حدد المستفيدين وأدخل مبلغ كل واحد، ثم اضغط معاينة المجموعة. المحفظة المستخدمة:|Select beneficiaries and enter each amount, then preview the group. Wallet used:|سوودمەندەکان هەڵبژێرە و بڕی هەر یەک بنووسە، پاشان کۆمەڵەکە پێشبینین بکە. جزدانی بەکارهاتوو:
رصيد البطاقات يجهّز بطلبية مخزون معتمدة بنفس القيمة.|Card credit is supplied by an approved inventory order of equal value.|باڵانسی کارت بە داواکارییەکی پەسەندکراوی کۆگا بە هەمان بەها دابین دەکرێت.
الرصيد يُضاف عند اعتماد الطلبية. ربطها هنا لا يضيف رصيدًا ثانيًا.|Credit is added when the order is approved. Linking it here does not add credit again.|باڵانس لە کاتی پەسەندکردنی داواکاری زیاد دەکرێت. بەستنەوەی لێرە باڵانس دووبارە زیاد ناکات.
الصورة اختيارية؛ بدون صورة يظهر العنوان والتفاصيل بخلفية التصميم.|The image is optional; without it, the title and details appear on the design background.|وێنە ئارەزوومەندانەیە؛ بەبێ وێنە ناونیشان و وردەکاری لەسەر پاشبنەمای دیزاین دەردەکەون.
هذا القسم مخفي. فعّل «إظهار القسم» ليظهر محتواه في البروفايل.|This section is hidden. Enable “Show section” to display its content in the profile.|ئەم بەشە شاراوەیە. «پیشاندانی بەش» چالاک بکە بۆ دەرکەوتنی ناوەڕۆکەکە لە پڕۆفایل.
اختر حد الكمية أو الحد المالي ليظهر حقل القيمة. سيُطبّق حد واحد فقط.|Choose a quantity or amount limit to show its value field. Only one limit applies.|سنووری ژمارە یان دارایی هەڵبژێرە بۆ دەرکەوتنی خانەی بەها. تەنها یەک سنوور جێبەجێ دەکرێت.
التاريخ والمرجع للسجلات؛ الأرصدة المعروضة حالية.|Date and reference filter records; displayed balances are current.|بەروار و سەرچاوە بۆ پاڵاوتنی تۆمارەکانن؛ باڵانسە پیشاندراوەکان هی ئێستان.
البطاقات المرفوضة لا تدخل المخزون. راجع أسباب الرفض في الجدول أدناه.|Rejected cards do not enter inventory. Review rejection reasons in the table below.|کارتە ڕەتکراوەکان ناچنە کۆگا. هۆکاری ڕەتکردنەوە لە خشتەی خوارەوە ببینە.
يوجد ملف بلا بطاقات صالحة؛ راجع أسباب الرفض ثم صححه أو أزله.|A file has no valid cards; review the errors, then correct or remove it.|فایلێک هیچ کارتی دروستی نییە؛ هەڵەکان ببینە، پاشان چاکی بکەوە یان لای ببە.
بانتظار تسجيل نتيجة المحاولة الحالية. لا تبدأ طباعة ثانية قبل حسم النتيجة.|Waiting for the current attempt result. Do not print again before recording it.|چاوەڕوانی تۆمارکردنی ئەنجامی هەوڵی ئێستا. پێش تۆمارکردنی ئەنجام دووبارە چاپ مەکە.
تم بلوغ الحد؛ اطلب موافقة إعادة الطباعة.|Limit reached; request reprint approval.|سنوور پڕ بووە؛ داوای مۆڵەتی دووبارەچاپ بکە.
◆ وكيل · ■ فرع · ▲ نقطة · ● موظف|◆ Agent · ■ Branch · ▲ POS · ● Employee|◆ بریکار · ■ لق · ▲ خاڵ · ● کارمەند
مشاركة موقع جهازك مع الإدارة مطلوبة لاستخدام الحساب وإظهاره على الخريطة.|Sharing your device location with administration is required to use the account and show it on the map.|هاوبەشکردنی شوێنی ئامێر لەگەڵ بەڕێوەبەرایەتی بۆ بەکارهێنانی هەژمار و پیشاندانی لە نەخشە پێویستە.
اسمح للموقع من إعدادات المتصفح، وتأكد من تشغيل خدمة الموقع بالجهاز، ثم اضغط تفعيل الموقع.|Allow location in browser settings, enable device location services, then press Enable location.|مۆڵەتی شوێن لە ڕێکخستنی وێبگەڕ بدە، خزمەتگوزاری شوێنی ئامێر چالاک بکە، پاشان چالاککردنی شوێن هەڵبژێرە.
سيُخفى الحساب ويتوقف تسجيل دخوله، مع الاحتفاظ ببياناته وسجلاته.|The account will be hidden and sign-in disabled, while its data and records are retained.|هەژمار دەشاردرێتەوە و چوونەژوورەوەی ڕادەگیرێت، بە پاراستنی داتا و تۆمارەکانی.
وضع تجربة محلي — الرمز 123456. لم تُرسل رسالة واتساب.|Local test mode — code 123456. No WhatsApp message was sent.|دۆخی تاقیکردنەوەی ناوخۆ — کۆد 123456. هیچ نامەی واتساپ نەنێردراوە.
8 أحرف على الأقل، تتضمن حرفًا إنكليزيًا ورقمًا.|At least 8 characters, including an English letter and a digit.|لانیکەم 8 پیت، لەگەڵ پیتێکی ئینگلیزی و ژمارەیەک.
د.ع. لا يوجد إرجاع نقدي تلقائي.|IQD. No automatic cash refund.|دینار. هیچ گەڕاندنەوەیەکی خۆکاری نەخت نییە.
البطاقات المباعة والمستخدمة وسجلاتها تبقى دون تغيير.|Sold and used cards and their records remain unchanged.|کارتە فرۆشراو و بەکارهاتووەکان و تۆمارەکانیان بێ گۆڕانکاری دەمێننەوە.
نسخة فقط؛ لا يتغير رصيد المخزون أو حالة البطاقات.|Copy only; inventory balance and card statuses do not change.|تەنها کۆپی؛ باڵانسی کۆگا و دۆخی کارتەکان ناگۆڕێت.
حذف|Delete|سڕینەوە
الفئات|Categories|پۆلەکان
الفروع|Branches|لقەکان
نقاط الشبكة|Network POS|خاڵەکانی تۆڕ
رصيد البطاقات|Card balance|باڵانسی کارت
صلاحيات التابع|Subordinate permissions|دەسەڵاتەکانی ژێردەست
بحث بالاسم أو المحافظة أو الهاتف|Search by name, province or phone|گەڕان بە ناو، پارێزگا یان تەلەفۆن
بحث التابعين|Search subordinates|گەڕان بۆ ژێردەستەکان
عدد التابعين|Subordinate count|ژمارەی ژێردەستەکان
الفروع / النقاط|Branches / POS|لقەکان / خاڵەکان
الوكيل المباشر|Direct agent|بریکاری ڕاستەوخۆ
الصفحة|Page|لاپەڕە
حساب|Account|هەژمار
التالي|Next|دواتر
لا توجد حسابات مطابقة|No matching accounts|هیچ هەژمارێکی گونجاو نییە
فلترة وبحث|Filter and search|پاڵاوتن و گەڕان
مسح الفلاتر|Clear filters|پاککردنەوەی پاڵاوتنەکان
نوع المحفظة|Wallet type|جۆری جزدان
لا توجد حركات مطابقة|No matching activity|هیچ جوڵەیەکی گونجاو نییە
لا توجد طلبات مطابقة|No matching requests|هیچ داواکارییەکی گونجاو نییە
المبلغ المسترجع · د.ع|Refund amount · IQD|بڕی گەڕاوە · دینار
سبب الاسترجاع|Refund reason|هۆکاری گەڕاندنەوە
المتاح للاسترجاع|Available to reclaim|بەردەست بۆ گەڕاندنەوە
كامل المتاح|Full available amount|هەموو بڕی بەردەست
استرجاع إلى محفظتي|Reclaim to my wallet|گەڕاندنەوە بۆ جزدانەکەم
اختر محفظة محددة للاسترجاع|Select a wallet to reclaim funds|جزدانێک بۆ گەڕاندنەوە هەڵبژێرە
الحساب المسترجع منه|Source account|هەژماری سەرچاوە
السبب|Reason|هۆکار
رقم العملية|Transaction ID|ژمارەی مامەڵە
لا توجد عمليات استرجاع|No refunds|هیچ گەڕاندنەوەیەک نییە
الممول للتمويل المتعدد|Bulk funding source|دابینکەری دارایی کۆمەڵە
بحث باسم المستفيد|Search beneficiary name|گەڕان بە ناوی سوودمەند
اسم النقطة أو الوكيل أو المحافظة|POS, agent or province name|ناوی خاڵ، بریکار یان پارێزگا
اختيار|Select|هەڵبژاردن
أدخل المبلغ|Enter amount|بڕەکە بنووسە
لا توجد جهات مطابقة متاحة للتمويل.|No matching accounts available for funding.|هیچ هەژمارێکی گونجاو بۆ دابینکردنی دارایی نییە.
المستفيدون المحددون:|Selected beneficiaries:|سوودمەندە هەڵبژێردراوەکان:
استيراد قائمة من ملف — اختياري|Import a list from a file — optional|هاوردەکردنی لیست لە فایل — ئارەزوومەندانە
معاينة مجموعة التمويل|Funding group preview|پێشبینینی کۆمەڵەی دابینکردنی دارایی
إغلاق معاينة التمويل|Close funding preview|داخستنی پێشبینینی دابینکردن
المحفظة|Wallet|جزدان
مرجع الطلب|Request reference|سەرچاوەی داواکاری
الفحص|Validation|پشکنین
المتبقي:|Remaining:|ماوە:
الرصيد لا يكفي لكامل المجموعة|Insufficient balance for the entire group|باڵانس بۆ هەموو کۆمەڵەکە بەس نییە
تأكيد التمويل|Confirm funding|پشتڕاستکردنەوەی دابینکردن
تنزيل الفحص|Download validation results|داگرتنی ئەنجامی پشکنین
مستفيد|Beneficiary|سوودمەند
تنزيل النتائج|Download results|داگرتنی ئەنجامەکان
لا توجد فواتير مطابقة|No matching invoices|هیچ پسوولەیەکی گونجاو نییە
لدى:|Assigned to:|لای:
سبب فشل الطباعة:|Print failure reason:|هۆکاری شکستی چاپ:
سجل التصعيد|Escalation history|تۆماری بەرزکردنەوە
سبب التصعيد|Escalation reason|هۆکاری بەرزکردنەوە
رفع إلى الأعلى|Escalate|بەرزکردنەوە بۆ سەرەوە
رقم الطلب|Request ID|ژمارەی داواکاری
تاريخ الطلب|Request date|بەرواری داواکاری
لا توجد طلبات|No requests|هیچ داواکارییەک نییە
معاينة طلب التالف|Damaged-card request preview|پێشبینینی داواکاری کارتی تێکچوو
معاينة الطلب|Request preview|پێشبینینی داواکاری
إغلاق معاينة الطلب|Close request preview|داخستنی پێشبینینی داواکاری
رقم الدفعة|Batch ID|ژمارەی کۆمەڵە
المبلغ المعوض · محفظة البطاقات|Compensated amount · Card wallet|بڕی قەرەبوو · جزدانی کارت
استبدال بطاقات|Replace cards|گۆڕینەوەی کارت
تعويض مالي|Financial compensation|قەرەبووی دارایی
رفع ملف البطاقات البديلة|Upload replacement cards file|بارکردنی فایلی کارتە جێگرەوەکان
السيريال|Serial|سریاڵ
تأكيد الاستبدال|Confirm replacement|پشتڕاستکردنەوەی گۆڕینەوە
تأكيد التعويض|Confirm compensation|پشتڕاستکردنەوەی قەرەبوو
محفظة البطاقات|Card wallet|جزدانی کارت
طلبية جديدة|New order|داواکاریی نوێ
عملية بيع|Sale|مامەڵەی فرۆشتن
رسالة جديدة|New message|نامەی نوێ
تصدير المستخدمين|Export users|هەناردەکردنی بەکارهێنەران
تصدير PDF|Export PDF|هەناردەکردنی PDF
تصدير Excel|Export Excel|هەناردەکردنی Excel
تصدير البيانات|Export data|هەناردەکردنی داتا
بحث الحسابات|Search accounts|گەڕان بۆ هەژمارەکان
نوع الحساب في الجدول|Account type in table|جۆری هەژمار لە خشتە
عدد الحسابات في الصفحة|Accounts per page|هەژمار لە هەر لاپەڕە
محفظة التمويل:|Funding wallet:|جزدانی دابینکردنی دارایی:
إلى الجهة الأعلى|To parent account|بۆ هەژماری سەرەوە
المبلغ المطلوب|Requested amount|بڕی داواکراو
ملاحظة اختيارية|Optional note|تێبینیی ئارەزوومەندانە
إرسال طلب التمويل|Send funding request|ناردنی داواکاری دابینکردنی دارایی
اختر تابعًا|Select a subordinate|ژێردەستێک هەڵبژێرە
مبلغ التمويل|Funding amount|بڕی دابینکردنی دارایی
المتبقي بعد التحويل:|Remaining after transfer:|ماوە دوای گواستنەوە:
تحميل طلبية|Load order|بارکردنی داواکاری
رصيدك المتاح لهذه المحفظة:|Your available wallet balance:|باڵانسی بەردەستی ئەم جزدانەت:
مراجعة الطلب|Review request|پێداچوونەوەی داواکاری
الطلبية المعتمدة|Approved order|داواکاریی پەسەندکراو
اختر طلبية بنفس القيمة|Select an order of equal value|داواکارییەک بە هەمان بەها هەڵبژێرە
رقم الإيداع أو السند|Deposit or receipt number|ژمارەی دانانی پارە یان بەڵگە
سبب الرفض|Rejection reason|هۆکاری ڕەتکردنەوە
رفض الطلب|Reject request|ڕەتکردنەوەی داواکاری
لا توجد طلبات واردة بانتظار الإجراء|No incoming requests awaiting action|هیچ داواکارییەکی هاتوو چاوەڕوان نییە
حالة الطلب|Request status|دۆخی داواکاری
كل الحالات|All statuses|هەموو دۆخەکان
الجهة الأعلى / الممول|Parent account / funder|هەژماری سەرەوە / دابینکەری دارایی
إلغاء الطلب|Cancel request|هەڵوەشاندنەوەی داواکاری
لا توجد عمليات|No transactions|هیچ مامەڵەیەک نییە
دفعة المخزون|Inventory batch|کۆمەڵەی کۆگا
دفعة السحب|Withdrawal batch|کۆمەڵەی کشاندنەوە
صلاحية التنزيل (ساعة)|Download validity (hours)|ماوەی داگرتن (کاتژمێر)
طلب سحب|Request withdrawal|داواکاری کشاندنەوە
انتهاء التنزيل|Download expiry|کۆتایی ماوەی داگرتن
لا توجد طلبات سحب|No withdrawal requests|هیچ داواکارییەکی کشاندنەوە نییە
فك تشفير ملف|Decrypt file|کردنەوەی کۆدی فایل
سجل الملفات المنزّلة|Downloaded files history|تۆماری فایلە داگیراوەکان
معاينة الصورة|Image preview|پێشبینینی وێنە
إزالة|Remove|لابردن
حفظ|Save|پاشەکەوتکردن
الشركة|Company|کۆمپانیا
الصورة|Image|وێنە
الرابط|Link|بەستەر
اسم الشركة|Company name|ناوی کۆمپانیا
شعار الشركة|Company logo|لۆگۆی کۆمپانیا
نبذة عن الشركة|About the company|دەربارەی کۆمپانیا
الموقع الإلكتروني|Website|ماڵپەڕ
إدارة الموقع|Manage website|بەڕێوەبردنی ماڵپەڕ
إظهار القسم|Show section|پیشاندانی بەش
عبارة الواجهة|Hero tagline|دەستەواژەی سەرەکی
إظهار العنصر|Show item|پیشاندانی بڕگە
المنصة|Platform|پلاتفۆرم
اسم منصة التواصل|Social platform name|ناوی پلاتفۆرمی کۆمەڵایەتی
إضافة موقع تواصل|Add social link|زیادکردنی بەستەری کۆمەڵایەتی
واتساب مع رمز الدولة|WhatsApp with country code|واتساپ لەگەڵ کۆدی وڵات
أوقات خدمة العملاء|Customer service hours|کاتەکانی خزمەتگوزاری کڕیار
أقسام موقع الشركة|Company website sections|بەشەکانی ماڵپەڕی کۆمپانیا
مرحبًا بك في موقع الشركة|Welcome to our website|بەخێربێیت بۆ ماڵپەڕەکەمان
تخطي المقدمة|Skip intro|تێپەڕاندنی پێشەکی
لنتواصل|Get in touch|با پەیوەندی بکەین
اكتشف المزيد|Discover more|زیاتر بدۆزەوە
اكتشف|Discover|بدۆزەوە
تعرّف على|Meet|ئاشنا بە
الصورة السابقة|Previous image|وێنەی پێشوو
الصورة التالية|Next image|وێنەی دواتر
عن الشركة|About us|دەربارەمان
تابعنا|Follow us|بەدواماندا بێ
خدمة العملاء|Customer service|خزمەتگوزاری کڕیار
يسعدنا تواصلك|We would love to hear from you|خۆشحاڵ دەبین بە پەیوەندیت
اتصل بنا|Contact us|پەیوەندیمان پێوە بکە
واتساب|WhatsApp|واتساپ
الهاتف أو البريد الإلكتروني|Phone or email|تەلەفۆن یان ئیمەیڵ
تم تسجيل رسالتك لدى إدارة الشركة.|Your message has been recorded for company management.|نامەکەت بۆ بەڕێوەبەرایەتی کۆمپانیا تۆمار کرا.
جميع الحقوق محفوظة|All rights reserved|هەموو مافەکان پارێزراون
رجوع إلى الصفحة السابقة|Back to previous page|گەڕانەوە بۆ لاپەڕەی پێشوو
تصميم البطاقة|Card design|دیزاینی کارت
شركة البطاقة|Card company|کۆمپانیای کارت
فئة البطاقة|Card category|پۆلی کارت
النص العلوي|Header text|دەقی سەرەوە
النص السفلي|Footer text|دەقی خوارەوە
لون النص|Text color|ڕەنگی دەق
ترتيب البطاقة|Card layout order|ڕیزبەندی کارت
صورتي على البطاقة|My image on the card|وێنەکەم لەسەر کارت
نصي على البطاقة|My text on the card|دەقەکەم لەسەر کارت
لون الخط|Font color|ڕەنگی نووسین
معاينة البطاقة|Preview card|پێشبینینی کارت
حفظ التعديلات|Save changes|پاشەکەوتکردنی گۆڕانکارییەکان
لا توجد فئات متاحة|No available categories|هیچ پۆلێکی بەردەست نییە
التحكم بالتوقيف|Suspension controls|کۆنترۆڵی ڕاگرتن
حساب مقيّد|Restricted account|هەژماری سنووردار
نطاق التوقيف|Suspension scope|مەودای ڕاگرتن
بحث باسم الحساب|Search account name|گەڕان بە ناوی هەژمار
لا توجد نتائج|No results|هیچ ئەنجامێک نییە
خيارات التوقيف|Suspension options|هەڵبژاردنەکانی ڕاگرتن
سبب التوقيف|Suspension reason|هۆکاری ڕاگرتن
تطبيق التوقيف|Apply suspension|جێبەجێکردنی ڕاگرتن
عرض فقط|Read only|تەنها بینین
يوجد توقيف عام سابق|An existing global suspension is active|ڕاگرتنێکی گشتی پێشوو چالاکە
إلغاء التوقيف العام السابق|Remove previous global suspension|لابردنی ڕاگرتنی گشتی پێشوو
قرارات التوقيف الفعّالة|Active suspensions|بڕیارە چالاکەکانی ڕاگرتن
لا توجد قرارات توقيف جديدة|No new suspensions|هیچ بڕیارێکی نوێی ڕاگرتن نییە
إلغاء هذا التوقيف|Remove this suspension|لابردنی ئەم ڕاگرتنە
الحسابات المعطّلة أو المقيّدة|Disabled or restricted accounts|هەژمارە ناچالاک یان سنووردارەکان
بحث الحسابات المعطّلة|Search disabled accounts|گەڕان بۆ هەژمارە ناچالاکەکان
الإيقاف الفعلي|Effective suspension|ڕاگرتنی کاریگەر
إلغاء التوقيف المباشر السابق|Remove previous direct suspension|لابردنی ڕاگرتنی ڕاستەوخۆی پێشوو
لا توجد حسابات مقيّدة|No restricted accounts|هیچ هەژمارێکی سنووردار نییە
تحريك للأعلى|Move up|بەرزکردنەوە
تحريك للأسفل|Move down|دابەزاندن
رموز الفئة في ملفات الاستيراد (اختياري)|Category codes in import files (optional)|کۆدەکانی پۆل لە فایلی هاوردە (ئارەزوومەندانە)
الحد اليومي للفئة|Category daily limit|سنووری ڕۆژانەی پۆل
نوع الحد اليومي|Daily limit type|جۆری سنووری ڕۆژانە
اختر نوع الحد|Select limit type|جۆری سنوور هەڵبژێرە
الحد المالي اليومي|Daily amount limit|سنووری دارایی ڕۆژانە
بحث عن محافظة|Search province|گەڕان بۆ پارێزگا
محافظة مفعلة|Enabled province|پارێزگای چالاک
الوكلاء الرئيسيون|Main agents|بریکارە سەرەکییەکان
الوكلاء الفرعيون|Sub-agents|بریکارە لاوەکییەکان
بحث باسم الشركة|Search company name|گەڕان بە ناوی کۆمپانیا
لا توجد شركات مطابقة|No matching companies|هیچ کۆمپانیایەکی گونجاو نییە
شعار الشركة (اختياري)|Company logo (optional)|لۆگۆی کۆمپانیا (ئارەزوومەندانە)
نوع الحساب|Account type|جۆری هەژمار
شبكة الوكيل|Agent network|تۆڕی بریکار
حالة الحساب|Account status|دۆخی هەژمار
مفعّل|Enabled|چالاک
معطّل|Disabled|ناچالاک
بحث بالاسم أو الهاتف|Search name or phone|گەڕان بە ناو یان تەلەفۆن
رقم الحركة أو المرجع|Transaction ID or reference|ژمارەی جوڵە یان سەرچاوە
من تاريخ|From date|لە بەرواری
إلى تاريخ|To date|تا بەرواری
تطبيق|Apply|جێبەجێکردن
بحث بالاسم أو الهاتف أو المندوب|Search name, phone or representative|گەڕان بە ناو، تەلەفۆن یان نوێنەر
بحث نقاط البيع|Search POS|گەڕان بۆ خاڵەکانی فرۆشتن
الأعمدة الظاهرة|Visible columns|ستوونە دیارەکان
لا توجد نقاط بيع مطابقة|No matching POS|هیچ خاڵێکی فرۆشتنی گونجاو نییە
تم استبعاد|Excluded|دوورخرایەوە
بطاقة مرفوضة من المخزون.|rejected cards from inventory.|کارتی ڕەتکراو لە کۆگا.
تنزيل ملف البطاقات المرفوضة|Download rejected cards file|داگرتنی فایلی کارتە ڕەتکراوەکان
المصدر|Supplier|دابینکەر
سعر البيع للوكيل • د.ع|Agent purchase price • IQD|نرخی کڕینی بریکار • دینار
حدد السعر من قسم الأسعار|Set the price in Pricing|نرخ لە بەشی نرخەکان دیاری بکە
مصاريف الدفعة • د.ع (اختياري)|Batch expenses • IQD (optional)|خەرجی کۆمەڵە • دینار (ئارەزوومەندانە)
تاريخ الانتهاء عند عدم وجوده بالملف (اختياري)|Expiry if missing from file (optional)|بەسەرچوون ئەگەر لە فایل نەبوو (ئارەزوومەندانە)
ملف البطاقات|Cards file|فایلی کارت
أعمدة الملف|File columns|ستوونەکانی فایل
المصدر والشركة|Supplier and company|دابینکەر و کۆمپانیا
بطاقات صالحة|Valid cards|کارتە دروستەکان
بطاقات مرفوضة|Rejected cards|کارتە ڕەتکراوەکان
سعر البطاقة للوكيل|Agent card purchase price|نرخی کڕینی کارت بۆ بریکار
ملف|File|فایل
مستبعدة|Excluded|دوورخراوە
سجل الطلبيات|Order history|تۆماری داواکارییەکان
عدد الفئات|Category count|ژمارەی پۆلەکان
وكيل الطلبية|Order agent|بریکاری داواکاری
شركة الطلبية|Order company|کۆمپانیای داواکاری
اختر الشركة|Select company|کۆمپانیا هەڵبژێرە
مصدر الطلبية|Order supplier|دابینکەری داواکاری
اختر المصدر|Select supplier|دابینکەر هەڵبژێرە
محافظة الطلبية|Order province|پارێزگای داواکاری
رفع ملفات الفئات|Upload category files|بارکردنی فایلەکانی پۆل
فئة|Category|پۆل
ملف / ورقة|File / sheet|فایل / پەڕە
جارٍ قراءة الملفات…|Reading files…|خوێندنەوەی فایلەکان…
إدخال نص بدل ملف|Enter text instead of a file|نووسینی دەق لەبری فایل
إضافة البيانات|Add data|زیادکردنی داتا
الملف|File|فایل
الفئة حسب المرجع|Reference category|پۆل بەپێی سەرچاوە
فئة المخزون|Inventory category|پۆلی کۆگا
رمز غير معروف أو ربط غير مكتمل|Unknown code or incomplete mapping|کۆدی نەناسراو یان پەیوەندی ناتەواو
صالحة|Valid|دروست
مرفوضة|Rejected|ڕەتکراوە
تعديل بيانات المعاينة|Edit preview data|دەستکاریی داتای پێشبینین
استبعاد|Exclude|دوورخستنەوە
بطاقة مرفوضة واعتماد|rejected cards and approve|کارتی ڕەتکراو و پەسەندکردنی
بطاقة صالحة فقط|valid cards only|تەنها کارتی دروست
فحص الملفات|Validate files|پشکنینی فایلەکان
إرسال للإدارة|Send to administration|ناردن بۆ بەڕێوەبەرایەتی
اعتماد الطلبية|Approve order|پەسەندکردنی داواکاری
رمز الفئة|Category code|کۆدی پۆل
السعر|Price|نرخ
إجمالي قيمة البطاقات|Total card value|کۆی بەهای کارت
رقم دفعة المخزون|Inventory batch ID|ژمارەی کۆمەڵەی کۆگا
سطر الملف|File row|ڕیزی فایل
تصدير البطاقات المرفوضة|Export rejected cards|هەناردەکردنی کارتە ڕەتکراوەکان
الطلبيات السابقة|Previous orders|داواکارییە پێشووەکان
سجل الطلبيات متعددة الملفات|Multi-file order history|تۆماری داواکاریی چەند فایل
طلبية|Order|داواکاری
بحث بالطلبية أو الوكيل أو الفئة|Search order, agent or category|گەڕان بە داواکاری، بریکار یان پۆل
بانتظار الاعتماد|Awaiting approval|چاوەڕوانی پەسەندکردن
معتمدة|Approved|پەسەندکراوە
معادة للتصحيح|Returned for correction|گەڕێندراوە بۆ چاککردنەوە
الطلبية|Order|داواکاری
تاريخ الإرسال|Submission date|بەرواری ناردن
الملفات|Files|فایلەکان
إعادة للتصحيح|Return for correction|گەڕاندنەوە بۆ چاککردنەوە
تصحيح|Correct|چاککردنەوە
المخزون|Inventory|کۆگا
لا توجد طلبيات|No orders|هیچ داواکارییەک نییە
إغلاق المعاينة|Close preview|داخستنی پێشبینین
سجل المتابعة|Follow-up history|تۆماری بەدواداچوون
سبب الإعادة أو الرفض|Return or rejection reason|هۆکاری گەڕاندنەوە یان ڕەتکردنەوە
سبب مراجعة الطلبية|Order review reason|هۆکاری پێداچوونەوەی داواکاری
تصحيح وإعادة إرسال|Correct and resubmit|چاککردنەوە و ناردنەوە
بحث برقم الطلبية أو الوكيل أو الفئة|Search order ID, agent or category|گەڕان بە ژمارەی داواکاری، بریکار یان پۆل
بحث الطلبيات|Search orders|گەڕان بۆ داواکارییەکان
حالة الطلبية|Order status|دۆخی داواکاری
رقم الطلبية|Order ID|ژمارەی داواکاری
تاريخ الإدخال|Entry date|بەرواری تۆمارکردن
لا توجد طلبيات مطابقة|No matching orders|هیچ داواکارییەکی گونجاو نییە
بطاقات الطلبية|Order cards|کارتەکانی داواکاری
التسلسل|Sequence|ڕیزبەندی
عدد السجلات في الصفحة|Records per page|تۆمار لە هەر لاپەڕە
فتح التقرير الكامل|Open full report|کردنەوەی ڕاپۆرتی تەواو
رجوع إلى التقارير|Back to reports|گەڕانەوە بۆ ڕاپۆرتەکان
ضوابط الطباعة|Print controls|کۆنترۆڵەکانی چاپ
نطاق الإعدادات|Settings scope|مەودای ڕێکخستنەکان
عام|General|گشتی
مخصص|Custom|تایبەت
اسم القاعدة|Rule name|ناوی یاسا
مثال: طباعة فروع بغداد|Example: Baghdad branch printing|نموونە: چاپی لقەکانی بەغدا
اسم قاعدة الطباعة|Print rule name|ناوی یاسای چاپ
البطاقات اليومية (0 بلا حد)|Daily cards (0 = unlimited)|کارتی ڕۆژانە (0 = بێ سنوور)
الحد اليومي للبطاقات المطبوعة|Daily printed-card limit|سنووری ڕۆژانەی کارتی چاپکراو
احتساب الحد اليومي|Daily limit calculation|ژماردنی سنووری ڕۆژانە
لكل حساب مستقلاً|Per account independently|بۆ هەر هەژمارێک بە جیا
مجموع شبكة كل وكيل|Total for each agent network|کۆی تۆڕی هەر بریکارێک
حفظ ضوابط الطباعة|Save print controls|پاشەکەوتکردنی کۆنترۆڵی چاپ
قاعدة جديدة|New rule|یاسای نوێ
نطاق الفئات|Category scope|مەودای پۆلەکان
نطاق الفئات للحد اليومي|Daily limit category scope|مەودای پۆلەکانی سنووری ڕۆژانە
كل الفئات — مجموع مشترك|All categories — shared total|هەموو پۆلەکان — کۆی هاوبەش
فئات محددة — حد مستقل لكل فئة|Selected categories — separate limit per category|پۆلە هەڵبژێردراوەکان — سنووری جیا بۆ هەر پۆل
النطاقات المحفوظة|Saved scopes|مەودا پاشەکەوتکراوەکان
القاعدة / النطاق|Rule / scope|یاسا / مەودا
محاولات الفشل|Failed attempts|هەوڵە شکستەکان
بطاقات الطلب|Cards per request|کارتی هەر داواکاری
الفاصل بالثواني|Interval in seconds|ماوە بە چرکە
الحد اليومي|Daily limit|سنووری ڕۆژانە
الاحتساب|Calculation|ژماردن
تعطيل|Disable|ناچالاککردن
اختيار النطاق|Select scope|هەڵبژاردنی مەودا
محدد|Selected|هەڵبژێردراو
نوع النطاق|Scope type|جۆری مەودا
الوكلاء والفروع|Agents and branches|بریکار و لقەکان
بحث بالاسم أو المعرّف…|Search name or ID…|گەڕان بە ناو یان ناسنامە…
بحث بنطاق الطباعة|Search print scope|گەڕان لە مەودای چاپ
فلترة الوكيل الرئيسي|Filter main agent|پاڵاوتنی بریکاری سەرەکی
الفرع|Branch|لق
فلترة الفرع|Filter branch|پاڵاوتنی لق
كل الفروع|All branches|هەموو لقەکان
عرض المختارين فقط|Show selected only|تەنها هەڵبژێردراوەکان پیشان بدە
مع الفروع ونقاط البيع|Include branches and POS|لەگەڵ لق و خاڵەکانی فرۆشتن
هذا الحساب فقط|This account only|تەنها ئەم هەژمارە
تم الاختيار ✓|Selected ✓|هەڵبژێردرا ✓
صفحة النطاقات السابقة|Previous scopes page|لاپەڕەی پێشووی مەوداکان
صفحة النطاقات التالية|Next scopes page|لاپەڕەی دواتری مەوداکان
النطاقات المختارة|Selected scopes|مەودا هەڵبژێردراوەکان
مسح الاختيار|Clear selection|پاککردنەوەی هەڵبژاردن
المختارون|Selected accounts|هەژمارە هەڵبژێردراوەکان
المختارون السابقون|Previous selected accounts|هەژمارە هەڵبژێردراوە پێشووەکان
المختارون التاليون|Next selected accounts|هەژمارە هەڵبژێردراوە دواترەکان
نطاق|Scope|مەودا
عرض النطاق|View scope|بینینی مەودا
نطاق مختار|Selected scope|مەودای هەڵبژێردراو
إغلاق النطاقات|Close scopes|داخستنی مەوداکان
بحث بالاسم…|Search by name…|گەڕان بە ناو…
بحث في النطاقات المختارة|Search selected scopes|گەڕان لە مەودا هەڵبژێردراوەکان
نتيجة|Result|ئەنجام
الفئات المسموحة|Allowed categories|پۆلە ڕێگەپێدراوەکان
تم تحديد|Selected|هەڵبژێردرا
بحث بالفئة أو القيمة|Search category or value|گەڕان بە پۆل یان بەها
بحث الفئات المسموحة|Search allowed categories|گەڕان بۆ پۆلە ڕێگەپێدراوەکان
فلتر مزود الفئات|Filter category provider|پاڵاوتنی دابینکەری پۆلەکان
كل الشركات والمزودين|All companies and providers|هەموو کۆمپانیا و دابینکەرەکان
تحديد كل نتائج البحث|Select all search results|هەڵبژاردنی هەموو ئەنجامەکان
إلغاء تحديد النتائج|Deselect results|لابردنی هەڵبژاردنی ئەنجامەکان
عرض المحددة فقط|Show selected only|تەنها هەڵبژێردراوەکان پیشان بدە
معطلة|Disabled|ناچالاک
لا توجد فئات مطابقة|No matching categories|هیچ پۆلێکی گونجاو نییە
تأكيد الاختيار|Confirm selection|پشتڕاستکردنەوەی هەڵبژاردن
ضوابط الطباعة المطبقة|Applied print controls|کۆنترۆڵە جێبەجێکراوەکانی چاپ
الحد الأقصى للبطاقات في الطلب:|Maximum cards per request:|زۆرترین کارت لە داواکاری:
الفاصل:|Interval:|ماوە:
ثانية|Seconds|چرکە
البطاقات اليوم:|Cards today:|کارتی ئەمڕۆ:
قيد الطباعة:|Printing:|لە چاپدایە:
الطباعة التالية بعد|Next print in|چاپی دواتر دوای
أوقات صلاحية الحساب|Account access times|کاتەکانی بەکارهێنانی هەژمار
توقيت بغداد|Baghdad time|کاتی بەغدا
بحث المستخدمين|Search users|گەڕان بۆ بەکارهێنەران
ابحث بالاسم أو اسم الدخول|Search name or login name|گەڕان بە ناو یان ناوی چوونەژوورەوە
بحث مستخدمي الأوقات|Search time-controlled users|گەڕان بۆ بەکارهێنەرانی کۆنترۆڵی کات
مستخدم ضوابط الوقت|Time-controlled user|بەکارهێنەری کۆنترۆڵی کات
تفعيل ضوابط وقت الحساب|Enable account time controls|چالاککردنی کۆنترۆڵی کاتی هەژمار
تفعيل مدة الصلاحية|Enable validity period|چالاککردنی ماوەی بەکارهێنان
مدة صلاحية الحساب|Account validity period|ماوەی بەکارهێنانی هەژمار
فترة محددة بتاريخ بداية ونهاية|Period with a start and end date|ماوەی دیاریکراو بە بەرواری دەستپێک و کۆتایی
تاريخ ووقت البداية — بغداد|Start date and time — Baghdad|بەروار و کاتی دەستپێک — بەغدا
تاريخ ووقت النهاية — بغداد|End date and time — Baghdad|بەروار و کاتی کۆتایی — بەغدا
بدون تقييد بتاريخ|No date restriction|بێ سنووری بەروار
تفعيل ساعات الدوام|Enable working hours|چالاککردنی کاتەکانی کار
ساعات الدوام|Working hours|کاتەکانی کار
وقت دخول يومي مسموح|Allowed daily access time|کاتی ڕێگەپێدراوی ڕۆژانە
بدء الدوام — بغداد|Work starts — Baghdad|دەستپێکی کار — بەغدا
انتهاء الدوام — بغداد|Work ends — Baghdad|کۆتایی کار — بەغدا
بدون تقييد بساعات دوام|No working-hour restriction|بێ سنووری کاتەکانی کار
تفعيل مهلة الخمول|Enable inactivity timeout|چالاککردنی ماوەی بێکاری
الخروج عند الخمول|Sign out when inactive|چوونەدەرەوە لە کاتی بێکاری
يُحسب عند عدم استخدام الحساب|Applies when the account is not in use|لە کاتی بەکارنەهێنانی هەژماردا هەژمار دەکرێت
مهلة الخمول بالدقائق|Inactivity timeout in minutes|ماوەی بێکاری بە خولەک
اختصارات مهلة الخمول|Inactivity timeout presets|هەڵبژاردە خێراکان بۆ ماوەی بێکاری
الخروج بسبب الخمول غير مفعّل|Inactivity sign-out is disabled|چوونەدەرەوە بەهۆی بێکاری ناچالاکە
تفعيل مدة الجلسة|Enable session duration|چالاککردنی ماوەی دانیشتن
مدة الجلسة الإجبارية|Maximum session duration|زۆرترین ماوەی دانیشتن
خروج دوري حتى أثناء الاستخدام|Periodic sign-out even while active|چوونەدەرەوەی خولی تەنانەت لە کاتی بەکارهێنان
مدة الجلسة بالدقائق|Session duration in minutes|ماوەی دانیشتن بە خولەک
اختصارات مدة الجلسة|Session duration presets|هەڵبژاردە خێراکان بۆ ماوەی دانیشتن
الخروج الدوري غير مفعّل|Periodic sign-out is disabled|چوونەدەرەوەی خولی ناچالاکە
حفظ أوقات الحساب|Save account times|پاشەکەوتکردنی کاتەکانی هەژمار
اختر مستخدمًا لضبط أوقات حسابه|Select a user to configure access times|بەکارهێنەرێک بۆ ڕێکخستنی کات هەڵبژێرە
الإعدادات المحفوظة|Saved settings|ڕێکخستنە پاشەکەوتکراوەکان
مدة الصلاحية|Validity period|ماوەی بەکارهێنان
الدوام — بغداد|Working hours — Baghdad|کاتەکانی کار — بەغدا
الخمول / الجلسة بالدقائق|Inactivity / session in minutes|بێکاری / دانیشتن بە خولەک
خريطة الحسابات والمستخدمين|Account and user map|نەخشەی هەژمار و بەکارهێنەران
بحث|Search|گەڕان
اسم المستخدم أو الحساب|User or account name|ناوی بەکارهێنەر یان هەژمار
بحث مستخدمي الخريطة|Search map users|گەڕان بۆ بەکارهێنەرانی نەخشە
فرع|Branch|لق
الموظفون|Employees|کارمەندان
الحساب وتابعوه|Account and subordinates|هەژمار و ژێردەستەکانی
حالة الاتصال|Connection status|دۆخی پەیوەندی
فلترة حالة الاتصال|Filter connection status|پاڵاوتنی دۆخی پەیوەندی
اختيار مخصص|Custom selection|هەڵبژاردنی تایبەت
عرض نتائج البحث|Show search results|پیشاندانی ئەنجامەکانی گەڕان
اختر مستخدمًا|Select a user|بەکارهێنەرێک هەڵبژێرە
تعذر تحميل بعض أجزاء الخريطة|Some map tiles could not load|هەندێک بەشی نەخشە بار نەکرا
إعادة المحاولة|Retry|هەوڵدانەوە
خريطة مواقع المستخدمين|User location map|نەخشەی شوێنی بەکارهێنەران
آخر ظهور — بغداد|Last seen — Baghdad|دوا دەرکەوتن — بەغدا
الموقع|Location|شوێن
وقت آخر موقع — بغداد|Last location time — Baghdad|کاتی دوا شوێن — بەغدا
دقة الموقع|Location accuracy|وردی شوێن
حوالي|Approximately|نزیکەی
متر|Meters|مەتر
مستخدمون في المواقع المتقاربة|Users at nearby locations|بەکارهێنەران لە شوێنە نزیکەکان
المستخدمون|Users|بەکارهێنەران
ليس لديك صلاحية عرض المواقع|You cannot view locations|مۆڵەتی بینینی شوێنەکانت نییە
تفعيل الموقع|Enable location|چالاککردنی شوێن
تسجيل الخروج|Sign out|چوونەدەرەوە
طريقة تعديل الأسعار|Price editing mode|شێوازی دەستکاریی نرخ
تعديل سعر فئة|Edit one category price|دەستکاریی نرخی پۆلێک
تعديل أسعار الفئات|Edit category prices|دەستکاریی نرخی پۆلەکان
قائمة أسعار الوكيل|Agent price list|لیستی نرخەکانی بریکار
بحث عن فئة|Search category|گەڕان بۆ پۆل
اسم الفئة أو قيمتها|Category name or value|ناو یان بەهای پۆل
بحث فئات الأسعار|Search pricing categories|گەڕان بۆ پۆلەکانی نرخ
فلترة شركة الفئات|Filter category company|پاڵاوتنی کۆمپانیای پۆلەکان
كل الشركات|All companies|هەموو کۆمپانیاکان
نطاق التعديل|Edit scope|مەودای دەستکاری
نطاق تعديل الأسعار|Price edit scope|مەودای دەستکاریی نرخ
نتائج الفلترة|Filtered results|ئەنجامە پاڵاوتراوەکان
كل الفئات|All categories|هەموو پۆلەکان
الفئات المحددة يدويًا|Manually selected categories|پۆلە بە دەست هەڵبژێردراوەکان
نوع التعديل|Adjustment type|جۆری گۆڕانکاری
نوع تعديل الأسعار|Price adjustment type|جۆری گۆڕانکاریی نرخ
زيادة مبلغ|Increase by amount|زیادکردن بە بڕ
نقصان مبلغ|Decrease by amount|کەمکردنەوە بە بڕ
مبلغ تعديل الأسعار|Price adjustment amount|بڕی گۆڕانکاریی نرخ
معاينة التغييرات|Preview changes|پێشبینینی گۆڕانکارییەکان
خيارات إضافية|More options|هەڵبژاردەی زیاتر
أقل سعر مسموح|Minimum allowed price|کەمترین نرخی ڕێگەپێدراو
السعر الحالي|Current price|نرخی ئێستا
السعر الجديد|New price|نرخی نوێ
تعديلات فردية|Individual edits|دەستکارییە تاکەکان
مسح التعديلات الفردية|Clear individual edits|پاککردنەوەی دەستکارییە تاکەکان
معاينة تعديل الأسعار|Price change preview|پێشبینینی گۆڕانکاریی نرخ
إغلاق معاينة الأسعار|Close price preview|داخستنی پێشبینینی نرخ
فئات|Categories|پۆلەکان
السعر القديم|Old price|نرخی کۆن
التغيير|Change|گۆڕانکاری
رجوع للتعديل|Back to editing|گەڕانەوە بۆ دەستکاری
سجل تغييرات الأسعار|Price change history|تۆماری گۆڕانکاریی نرخ
بحث بالعملية أو الفئة أو المستخدم|Search transaction, category or user|گەڕان بە مامەڵە، پۆل یان بەکارهێنەر
بحث في سجل الأسعار|Search price history|گەڕان لە تۆماری نرخەکان
حالة تغيير السعر|Price change status|دۆخی گۆڕانکاریی نرخ
سجل الأسعار من تاريخ|Price history start date|بەرواری دەستپێکی تۆماری نرخ
سجل الأسعار إلى تاريخ|Price history end date|بەرواری کۆتایی تۆماری نرخ
التاريخ والوقت|Date and time|بەروار و کات
السعر السابق|Previous price|نرخی پێشوو
الفرق|Difference|جیاوازی
نفّذ التعديل|Apply changes|جێبەجێکردنی گۆڕانکاری
لا توجد تغييرات أسعار مطابقة|No matching price changes|هیچ گۆڕانکارییەکی گونجاوی نرخ نییە
أرقام الدعم|Support phone numbers|ژمارەکانی پشتگیری
رقم|Number|ژمارە
اتصال|Call|پەیوەندی
إدارة أرقام الدعم الخاصة بي|Manage my support numbers|بەڕێوەبردنی ژمارەکانی پشتگیریی خۆم
اسم الرقم|Phone label|ناوی ژمارە
077xxxxxxxx أو 078xxxxxxxx|077xxxxxxxx or 078xxxxxxxx|077xxxxxxxx یان 078xxxxxxxx
11 رقمًا تبدأ بـ077 أو 078|11 digits starting with 077 or 078|11 ژمارە کە بە 077 یان 078 دەست پێ بکات
+ إضافة رقم دعم|+ Add support number|+ زیادکردنی ژمارەی پشتگیری
حفظ الأرقام|Save numbers|پاشەکەوتکردنی ژمارەکان
المحادثات|Conversations|گفتوگۆکان
غير مقروءة|Unread|نەخوێندراوە
بحث بالعنوان أو الطرف|Search subject or participant|گەڕان بە بابەت یان بەشدار
بحث محادثات الدعم|Search support conversations|گەڕان لە گفتوگۆکانی پشتگیری
غير المقروءة|Unread|نەخوێندراوەکان
لا توجد محادثات مطابقة|No matching conversations|هیچ گفتوگۆیەکی گونجاو نییە
المحادثة المفتوحة|Open conversation|گفتوگۆی کراوە
إغلاق المحادثة|Close conversation|داخستنی گفتوگۆ
مرفق الرسالة|Message attachment|هاوپێچی نامە
اختر محادثة لعرض الرسائل والرد عليها|Select a conversation to view and reply|گفتوگۆیەک هەڵبژێرە بۆ بینین و وەڵامدانەوە
أرشيف المحذوفات|Deleted accounts archive|ئەرشیفی هەژمارە سڕاوەکان
بحث بالاسم أو إيميل الدخول|Search name or login email|گەڕان بە ناو یان ئیمەیڵی چوونەژوورەوە
بحث المحذوفات|Search archive|گەڕان لە ئەرشیف
تاريخ الأرشفة|Archived date|بەرواری ئەرشیفکردن
المنفّذ|Performed by|جێبەجێکەر
لا توجد حسابات مؤرشفة|No archived accounts|هیچ هەژمارێکی ئەرشیفکراو نییە
صورة الحساب المؤرشف|Archived account image|وێنەی هەژماری ئەرشیفکراو
معرّف الحساب|Account ID|ناسنامەی هەژمار
الجهة الأعلى|Parent account|هەژماری سەرەوە
سبب الأرشفة|Archive reason|هۆکاری ئەرشیفکردن
إيميل الدخول|Login email|ئیمەیڵی چوونەژوورەوە
العمليات السابقة:|Previous transactions:|مامەڵە پێشووەکان:
القيود المالية:|Financial entries:|تۆمارە داراییەکان:
سبب الحذف الأرشيفي|Reason for archiving|هۆکاری ئەرشیفکردن
كلمة مرور حسابك|Your account password|وشەی نهێنیی هەژمارەکەت
إعادة تعيين كلمة المرور|Reset password|ڕێکخستنەوەی وشەی نهێنی
إضافة مصدر|Add supplier|زیادکردنی دابینکەر
بحث باسم المصدر أو الشركة|Search supplier or company name|گەڕان بە ناوی دابینکەر یان کۆمپانیا
اسم المصدر|Supplier name|ناوی دابینکەر
حفظ المصدر|Save supplier|پاشەکەوتکردنی دابینکەر
لا توجد مصادر|No suppliers|هیچ دابینکەرێک نییە
متبقية|Remaining|ماوە
بحث بالسيريال|Search serial|گەڕان بە سریاڵ
بحث البطاقات|Search cards|گەڕان بۆ کارت
حالة البطاقة|Card status|دۆخی کارت
تحديد نتائج البحث|Select search results|هەڵبژاردنی ئەنجامەکانی گەڕان
تعليم المحدد كتالف|Mark selected as damaged|دیاریکردنی هەڵبژێردراوەکان وەک تێکچوو
تصدير نسخة من المحدد|Export selected copy|هەناردەکردنی کۆپی هەڵبژێردراوەکان
إلغاء المتبقي من الطلبية|Cancel order remainder|هەڵوەشاندنەوەی ماوەی داواکاری
إيقاف البيع|Stop selling|ڕاگرتنی فرۆشتن
استرجاع الملغي|Restore cancelled cards|گەڕاندنەوەی کارتە هەڵوەشاوەکان
سحب للمزوّد|Return to provider|گەڕاندنەوە بۆ دابینکەر
ملاحظات|Notes|تێبینییەکان
صيغة الملف|File format|فۆرماتی فایل
ملف مشفر يتضمن الرموز|Encrypted file including codes|فایلی کۆدکراو لەگەڵ کۆدەکان
CSV دون PIN أو CVC|CSV without PIN or CVC|CSV بەبێ PIN یان CVC
عملية الإلغاء|Cancellation|هەڵوەشاندنەوە
سبب الإجراء|Action reason|هۆکاری کردار
الرصيد التشغيلي المسحوب:|Withdrawn operating credit:|باڵانسی کارکردنی کشێنراوە:
بطاقات المخزون|Inventory cards|کارتەکانی کۆگا
بحث بالطلبية أو الفئة|Search order or category|گەڕان بە داواکاری یان پۆل
بحث المخزون|Search inventory|گەڕان لە کۆگا
جميع الفئات|All categories|هەموو پۆلەکان
المتاح|Available|بەردەست
المباع|Sold|فرۆشراو
التالف|Damaged|تێکچوو
الملغي|Cancelled|هەڵوەشاوە
إدارة|Manage|بەڕێوەبردن
رقم السجل|Record ID|ژمارەی تۆمار
العدد|Count|ژمارە
الصيغة|Format|فۆرمات
لا توجد نسخ مصدّرة|No exported copies|هیچ کۆپییەکی هەناردەکراو نییە
تبديل المظهر|Toggle theme|گۆڕینی ڕووکار
البريد الإلكتروني أو اسم المستخدم|Email or username|ئیمەیڵ یان ناوی بەکارهێنەر
تذكرني|Remember me|بیرم بهێنەوە
رمز المحاكاة المحلية:|Local test code:|کۆدی تاقیکردنەوەی ناوخۆ:
الدخول للنسخة التجريبية|Open demo|چوونەژوورەوەی وەشانی تاقیکردنەوە
لوحة إدارة شبكة التوزيع|Distribution network management|بەڕێوەبردنی تۆڕی دابەشکردن
بطاقات الاتصال|Telecom cards|کارتەکانی پەیوەندی
بطاقات الألعاب|Gaming cards|کارتەکانی یاری
باقات الإنترنت|Internet packages|پاکێجەکانی ئینتەرنێت
عالم البطاقات، بين يديك|The world of cards at your fingertips|جیهانی کارت لە بەردەستت
إدارة البطاقات الإلكترونية وشبكة التوزيع|E-card and distribution network management|بەڕێوەبردنی کارتی ئەلیکترۆنی و تۆڕی دابەشکردن
اتصالات|Telecom|پەیوەندییەکان
ألعاب|Games|یارییەکان
إنترنت|Internet|ئینتەرنێت
جميع الحقوق محفوظة لشركة عراق تكنو للحلول البرمجية|All rights reserved to Iraq Techno Software Solutions|هەموو مافەکان بۆ کۆمپانیای عێراق تەکنۆ بۆ چارەسەری نەرمەکاڵا پارێزراون
ملخص الحساب|Account summary|پوختەی هەژمار
ملخص جميع الحسابات|All accounts summary|پوختەی هەموو هەژمارەکان
بحث الوكلاء والفروع|Search agents and branches|گەڕان بۆ بریکار و لقەکان
عرض الوكلاء والنقاط|View agents and POS|بینینی بریکار و خاڵەکان
اسحب لترتيب الفئة|Drag to reorder category|ڕابکێشە بۆ ڕیزکردنی پۆل
تبويبات الطلبيات|Order tabs|تابەکانی داواکاری
سعر البيع للزبون • د.ع|Customer sale price • IQD|نرخی فرۆشتن بۆ کڕیار • دینار
أقسام الصلاحيات|Permission sections|بەشەکانی دەسەڵات
صلاحيات القسم|Section permissions|دەسەڵاتەکانی بەش
إغلاق صلاحيات القسم|Close section permissions|داخستنی دەسەڵاتەکانی بەش
رجوع للأقسام|Back to sections|گەڕانەوە بۆ بەشەکان
المستخدمين|Users|بەکارهێنەران
عام ضمن نطاقي|Everyone in my scope|هەمووان لە مەوداکەم
الوكلاء الرئيسيون فقط|Main agents only|تەنها بریکارە سەرەکییەکان
الأفرع فقط|Branches only|تەنها لقەکان
نقاط البيع فقط|POS only|تەنها خاڵەکانی فرۆشتن
بحث بالاسم أو البريد|Search name or email|گەڕان بە ناو یان ئیمەیڵ
صورة الإشعار|Notification image|وێنەی ئاگادارکردنەوە
تواصل مع المستلم وتابع الردود هنا|Contact the recipient and follow replies here|لێرە پەیوەندی بە وەرگر بکە و وەڵامەکان بەدوادا بچۆ
مستخدمو رسالة الدعم|Support message recipients|وەرگرانی نامەی پشتگیری
عام لكل المستخدمين ضمن نطاقي|All users in my scope|هەموو بەکارهێنەران لە مەوداکەم
مستلم مباشر|Direct recipient|وەرگری ڕاستەوخۆ
بحث مستلمي الدعم|Search support recipients|گەڕان بۆ وەرگرانی پشتگیری
المرسل إليه|Recipient|وەرگر
محتوى الرسالة|Message body|ناوەڕۆکی نامە
رجوع إلى المجموعات|Back to groups|گەڕانەوە بۆ گرووپەکان
حالة الأجهزة|Device status|دۆخی ئامێرەکان
آخر اتصال|Last connection|دوا پەیوەندی
لا توجد أجهزة ضمن النطاق|No devices in scope|هیچ ئامێرێک لە مەوداکە نییە
الفرع / الفرع الفرعي|Branch / sub-branch|لق / لقی لاوەکی
اسم مدير النظام|System administrator name|ناوی بەڕێوەبەری سیستەم
حفظ الاسم|Save name|پاشەکەوتکردنی ناو
ملاحظات (اختياري)|Notes (optional)|تێبینی (ئارەزوومەندانە)
طلب إعادة تعيين كلمة المرور|Request password reset|داواکاری ڕێکخستنەوەی وشەی نهێنی
إنشاء، تمويل، طباعة…|Create, fund, print…|دروستکردن، دابینکردنی دارایی، چاپ…
تعديل الفئات|Edit categories|دەستکاریی پۆلەکان
بريد تسجيل الدخول|Login email|ئیمەیڵی چوونەژوورەوە
ربح الوكيل على البطاقات الصادرة|Agent profit on issued cards|قازانجی بریکار لە کارتە دەرکراوەکان
ربح نقطة البيع|POS profit|قازانجی خاڵی فرۆشتن
من:|From:|لە:
الصورة المرفقة بالإشعار|Notification attachment image|وێنەی هاوپێچی ئاگادارکردنەوە
الشرح|Description|ڕوونکردنەوە
المستلم الحالي:|Current recipient:|وەرگری ئێستا:
كلمة تشفير التنزيل|Download encryption password|وشەی نهێنیی کۆدکردنی داگرتن
بانتظار المعالجة|Awaiting processing|چاوەڕوانی چارەسەرکردن
معوضة|Compensated|قەرەبووکراوە
مستبدلة|Replaced|گۆڕدراوەتەوە
مشطوبة|Written off|سڕاوەتەوە لە حساب
تالف — قيد المعالجة|Damaged — awaiting processing|تێکچوو — چاوەڕوانی چارەسەرکردن
سعر الشراء الأصلي غير موثق|Original purchase price is not recorded|نرخی کڕینی سەرەتایی تۆمار نەکراوە
تم اعتماد الطلبية|Order approved|داواکاری پەسەند کرا
أرسلت الطلبية للإدارة|Order sent to administration|داواکاری بۆ بەڕێوەبەرایەتی نێردرا
بدون تعديل|Unchanged|بێ گۆڕانکاری
عرض الكل|Show all|پیشاندانی هەموو
المرفوضة فقط|Rejected only|تەنها ڕەتکراوەکان
صالح|Valid|دروست
تأكيد الاعتماد|Confirm approval|پشتڕاستکردنەوەی پەسەندکردن
تأكيد الإعادة|Confirm return|پشتڕاستکردنەوەی گەڕاندنەوە
تأكيد الرفض|Confirm rejection|پشتڕاستکردنەوەی ڕەتکردنەوە
إغلاق الجدول|Collapse table|داخستنی خشتە
عرض الجدول|Show table|پیشاندانی خشتە
صلاحيات كاملة|Full permissions|دەسەڵاتی تەواو
غير مسجل|Not recorded|تۆمار نەکراوە
`
    .trim()
    .split("\n");
  for (const row of translations) {
    const [ar, en, ckb] = row.split("|");
    MasalLocale.dictionaries.en[ar] = en;
    MasalLocale.dictionaries.ckb[ar] = ckb;
  }

  function install(options) {
    options.computed.translationRecordNames = function () {
      return [
        ...new Set(
          ["agents", "pos", "users", "products", "providers", "orderSources"]
            .flatMap((key) =>
              (this.s[key] || []).flatMap((r) => [
                r.name,
                r.owner,
                r.email,
                r.username,
                r.notes,
                r.reason,
                r.content,
                r.title,
              ]),
            )
            .filter((v) => typeof v === "string" && v),
        ),
      ].sort((a, b) => b.length - a.length);
    };
    options.methods.t = function (value) {
      if (typeof value !== "string")
        return MasalLocale.translate(value, this.lang);
      if (this.lang === "ar") return value;
      let source = value;
      const saved = [];
      for (const name of this.translationRecordNames) {
        if (source.includes(name)) {
          const token = "\u0001" + saved.length + "\u0002";
          saved.push(name);
          source = source.split(name).join(token);
        }
      }
      source = MasalLocale.translate(source, this.lang);
      return source.replace(/\u0001(\d+)\u0002/g, (_, i) => saved[Number(i)]);
    };
  }
  root.MasalUILocalization = { install, missing };
})(globalThis);
