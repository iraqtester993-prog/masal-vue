(function () {
  const entries = {
    "فصل أرباح مراحل البيع": {
      en: "Profit by sales stage",
      ckb: "قازانج بەپێی قۆناغی فرۆشتن",
    },
    "ربح / إيراد ماسال عند تحميل الدفعات": {
      en: "Masal profit / revenue at batch posting",
      ckb: "قازانج / داهاتی ماساڵ لە کاتی تۆمارکردنی کۆمەڵە",
    },
    "أرباح الوكلاء على البطاقات الصادرة": {
      en: "Agent margin on issued cards",
      ckb: "قازانجی بریکار لە کارتە دەرکراوەکان",
    },
    "أرباح نقاط البيع": { en: "POS margin", ckb: "قازانجی خاڵی فرۆشتن" },
    "هذه مجاميع السجلات الحالية في نطاقك. هامش النقطة يتطلب إدخال سعر البيع النهائي للزبون عند الإصدار؛ الدفعات القديمة لم تُفوتر بأثر رجعي.":
      {
        en: "Current totals within your scope. POS margin uses the final customer price entered at issuance. Historical batches were not invoiced retroactively.",
        ckb: "کۆی تۆمارەکانی ئێستا لە مەودای تۆ. قازانجی خاڵ بەپێی نرخی کۆتایی کڕیارە. بۆ کۆمەڵە کۆنەکان پسوولەی پاشەکشە دروست نەکراوە.",
      },
    "أرصدة الخدمات والتمويل": {
      en: "Service balances and funding",
      ckb: "باڵانس و دابینکردنی خزمەتگوزارییەکان",
    },
    "رصيد البطاقات مصدره تحميل المخزون. لكل خدمة محفظتها؛ لا يجري تعويض رصيد خدمة من أخرى.":
      {
        en: "Card credit comes from stock intake. Each service has its own wallet; balances are not offset across services.",
        ckb: "باڵانسی کارت لە بارکردنی کۆگا دروست دەبێت. هەر خزمەتگوزارییەک جزدانێکی جیاوازی هەیە.",
      },
    "إجمالي رصيد الخدمة في نطاقك": {
      en: "Total service balance in your scope",
      ckb: "کۆی باڵانسی خزمەتگوزاری لە مەودای تۆ",
    },
    "طلبات بانتظار التمويل": {
      en: "Funding requests awaiting action",
      ckb: "داواکارییە چاوەڕوانەکانی دابینکردن",
    },
    "تحويلات منفذة": {
      en: "Completed transfers",
      ckb: "گواستنەوە تەواوکراوەکان",
    },
    "المتاح:": { en: "Available:", ckb: "بەردەست:" },
    "حركات الحساب": { en: "Account activity", ckb: "چالاکی هەژمار" },
    "كل الحسابات": { en: "All accounts", ckb: "هەموو هەژمارەکان" },
    الوقت: { en: "Time", ckb: "کات" },
    "لا توجد حركات لهذه الخدمة.": {
      en: "No activity for this service.",
      ckb: "هیچ چالاکییەک بۆ ئەم خزمەتگوزارییە نییە.",
    },
    "الأرصدة النقدية السابقة": {
      en: "Previous cash balances",
      ckb: "باڵانسە نەختە کۆنەکان",
    },
    "هذه القيود محفوظة كما كانت قبل فصل الخدمات، ولم تتحول إلى رصيد بطاقات جديد.":
      {
        en: "These entries are preserved from before service separation and were not converted into new card credit.",
        ckb: "ئەم تۆمارانە لە پێش جیاکردنەوەی خزمەتگوزارییەکان پارێزراون و نەگۆڕدراون بۆ باڵانسی کارتی نوێ.",
      },
    "طلب أو تنفيذ تمويل": {
      en: "Request or execute funding",
      ckb: "داواکردن یان جێبەجێکردنی دابینکردن",
    },
    "الوكيل الممول": { en: "Funding agent", ckb: "بریکاری دابینکەر" },
    المستفيد: { en: "Beneficiary", ckb: "سوودمەند" },
    "الغرض / سبب الاستثناء": {
      en: "Purpose / exception reason",
      ckb: "مەبەست / هۆکاری ئیستثنا",
    },
    "مرجع الإيداع / التحصيل": {
      en: "Deposit / collection reference",
      ckb: "سەرچاوەی دانان / وەرگرتن",
    },
    "المدير والمشرف المركزي يحتاجان استثناءً موثقًا قبل التمويل نيابة عن الوكيل.":
      {
        en: "Central administrators need a documented exception to fund on behalf of an agent.",
        ckb: "بەڕێوەبەرانی ناوەندی بۆ دابینکردن لەبری بریکار پێویستیان بە ئیستثنای تۆمارکراو هەیە.",
      },
    "تقديم طلب تمويل": {
      en: "Submit funding request",
      ckb: "ناردنی داواکاری دابینکردن",
    },
    "توثيق استثناء تمويل": {
      en: "Document funding exception",
      ckb: "تۆمارکردنی ئیستثنای دابینکردن",
    },
    "تنفيذ التمويل": { en: "Execute funding", ckb: "جێبەجێکردنی دابینکردن" },
    "إيداع لهذه الخدمة": {
      en: "Deposit to this service",
      ckb: "دانان بۆ ئەم خزمەتگوزارییە",
    },
    "الاستثناء جاهز للاستخدام مرة واحدة.": {
      en: "The exception is ready for one use.",
      ckb: "ئیستثناکە بۆ یەک بەکارهێنان ئامادەیە.",
    },
    "طلبات التمويل": {
      en: "Funding requests",
      ckb: "داواکارییەکانی دابینکردن",
    },
    "تنفيذ الطلبات المحددة": {
      en: "Process selected requests",
      ckb: "جێبەجێکردنی داواکارییە دیاریکراوەکان",
    },
    تحديد: { en: "Select", ckb: "دیاریکردن" },
    "د.ع ·": { en: "IQD ·", ckb: "دینار ·" },
    "تسليم الرصيد للمستفيد": {
      en: "Deliver balance to beneficiary",
      ckb: "گەیاندنی باڵانس بە سوودمەند",
    },
    "إلغاء الحجز": { en: "Cancel reservation", ckb: "هەڵوەشاندنەوەی حجز" },
    "اعتماد وحجز المبلغ": {
      en: "Approve and reserve amount",
      ckb: "پەسەندکردن و حجزکردنی بڕ",
    },
    "لا توجد طلبات بعد.": {
      en: "No requests yet.",
      ckb: "هێشتا داواکاری نییە.",
    },
    "تمويل عدة مستفيدين": {
      en: "Fund multiple beneficiaries",
      ckb: "دابینکردن بۆ چەند سوودمەند",
    },
    "اختر الخدمة أعلاه والوكيل الممول، ثم الصق معرف المستفيد والمبلغ في كل سطر. النتيجة مستقلة لكل مستفيد؛ إعادة التنفيذ بنفس المجموعة لا تكرر التحويلات الناجحة.":
      {
        en: "Select the service and funding agent, then paste one beneficiary ID and amount per line. Results are independent. Retrying the same group does not repeat successful transfers.",
        ckb: "خزمەتگوزاری و بریکار هەڵبژێرە، پاشان لە هەر ڕیزێک ناسنامە و بڕ دابنێ. دووبارەکردنەوەی گرووپ گواستنەوە سەرکەوتووەکان دووبارە ناکات.",
      },
    الممول: { en: "Funding source", ckb: "سەرچاوەی دابینکردن" },
    "استيراد المستفيد والمبلغ من Excel أو CSV": {
      en: "Import beneficiary and amount from Excel or CSV",
      ckb: "هاوردەکردنی سوودمەند و بڕ لە Excel یان CSV",
    },
    "تنفيذ المجموعة / إعادة المحاولة": {
      en: "Process group / retry",
      ckb: "جێبەجێکردنی گرووپ / دووبارەکردنەوە",
    },
    "بدء مجموعة جديدة": {
      en: "Start a new group",
      ckb: "دەستپێکردنی گرووپی نوێ",
    },
    "التمويل الجماعي يتبع صلاحية موظف الوكيل. لا يمنح استثناء الإدارة تلقائيًا.":
      {
        en: "Bulk funding requires agent-employee authority. It does not automatically grant an administrative exception.",
        ckb: "دابینکردنی کۆمەڵە پێویستی بە دەسەڵاتی کارمەندی بریکار هەیە. ئیستثنای بەڕێوەبردن خۆکار نییە.",
      },
    "فواتير تحميل الدفعات والذمم": {
      en: "Batch invoices and receivables",
      ckb: "پسوولەی کۆمەڵە و قەرزەکان",
    },
    "حساب الوكيل": { en: "Agent account", ckb: "هەژماری بریکار" },
    "المتبقي بعد التحصيل:": {
      en: "Outstanding after collection:",
      ckb: "ماوە دوای وەرگرتن:",
    },
    الفاتورة: { en: "Invoice", ckb: "پسوولە" },
    "سعر التحميل": { en: "Load price", ckb: "نرخی بارکردن" },
    القيمة: { en: "Value", ckb: "بەها" },
    "إيراد / ربح ماسال": {
      en: "Masal revenue / profit",
      ckb: "داهات / قازانجی ماساڵ",
    },
    "الدفعات السابقة لم تُنشأ لها فواتير بأثر رجعي. تثبت الأسعار والتكلفة عند اعتماد الدفعات الجديدة.":
      {
        en: "Historical batches were not invoiced retroactively. Prices and costs are fixed when new batches are approved.",
        ckb: "بۆ کۆمەڵە کۆنەکان پسوولە دروست نەکراوە. نرخ و تێچوو لە پەسەندکردنی کۆمەڵەی نوێ جێگیر دەکرێن.",
      },
    المدين: { en: "Debtor", ckb: "قەرزدار" },
    "مبلغ التحصيل": { en: "Collection amount", ckb: "بڕی وەرگرتن" },
    الطريقة: { en: "Method", ckb: "ڕێگا" },
    مندوب: { en: "Representative", ckb: "نوێنەر" },
    "تحويل مصرفي": { en: "Bank transfer", ckb: "گواستنەوەی بانکی" },
    "رقم السند": { en: "Receipt reference", ckb: "ژمارەی بەڵگە" },
    "تسجيل تحصيل": { en: "Record collection", ckb: "تۆمارکردنی وەرگرتن" },
    "سجل التمويل والعكس": {
      en: "Funding and reversals log",
      ckb: "تۆماری دابینکردن و پێچەوانەکردنەوە",
    },
    "سبب عكس التحويل": { en: "Reversal reason", ckb: "هۆکاری پێچەوانەکردنەوە" },
    "عكس التحويل": {
      en: "Reverse transfer",
      ckb: "پێچەوانەکردنەوەی گواستنەوە",
    },
    "استيراد متعدد الملفات • Excel وCSV وTXT": {
      en: "Multiple-file import • Excel, CSV and TXT",
      ckb: "هاوردەکردنی چەند فایل • Excel و CSV و TXT",
    },
    "ملفات XLSX أو نص مفصول بفواصل أو تبويبات. اختر الأعمدة والفئة لكل ملف قبل التحميل. يستخدم الربط نفسه لكل الملفات المحددة؛ اجمع الملفات ذات الأعمدة المتطابقة.":
      {
        en: "Use XLSX or comma/tab-delimited text. Select the columns and category for every file. One mapping applies to all selected files, so group files with matching headers.",
        ckb: "XLSX یان دەقی جیاکراو بە کۆما یان تاب بەکاربهێنە. ستوون و پۆل بۆ هەر فایل دیاری بکە. فایلەکان دەبێت ناونیشانی ستوونی یەکسانیان هەبێت.",
      },
    "الفئة الافتراضية": { en: "Default category", ckb: "پۆلی بنەڕەتی" },
    "تكلفة البطاقة": { en: "Card cost", ckb: "تێچووی کارت" },
    "سعر التحميل المتفق عليه": {
      en: "Agreed load price",
      ckb: "نرخی ڕێککەوتووی بارکردن",
    },
    "مصاريف الدفعة": { en: "Batch expenses", ckb: "خەرجی کۆمەڵە" },
    "نموذج العقد": { en: "Contract model", ckb: "جۆری گرێبەست" },
    "إعادة بيع": { en: "Resale", ckb: "دووبارەفرۆشتن" },
    عمولة: { en: "Commission", ckb: "کۆمسیۆن" },
    "عمولة الوحدة": { en: "Commission per unit", ckb: "کۆمسیۆنی هەر دانە" },
    "ملفات المزود": { en: "Provider files", ckb: "فایلەکانی دابینکەر" },
    "اسم قالب المزود": {
      en: "Provider template name",
      ckb: "ناوی قاڵبی دابینکەر",
    },
    "قالب محفوظ": { en: "Saved template", ckb: "قاڵبی پاشەکەوتکراو" },
    اختر: { en: "Choose", ckb: "هەڵبژێرە" },
    "حفظ ربط الأعمدة": {
      en: "Save column mapping",
      ckb: "پاشەکەوتکردنی پەیوەندی ستوونەکان",
    },
    "غير موجود / الافتراضي": { en: "Missing / default", ckb: "نییە / بنەڕەتی" },
    المصاريف: { en: "Expenses", ckb: "خەرجییەکان" },
    "فحص ومعاينة": { en: "Validate and preview", ckb: "پشکنین و پێشبینین" },
    "اعتماد الدفعات المعروضة": {
      en: "Approve displayed batches",
      ckb: "پەسەندکردنی کۆمەڵە پیشاندراوەکان",
    },
    "صالحة ·": { en: "valid ·", ckb: "دروست ·" },
    "مرفوضة ·": { en: "rejected ·", ckb: "ڕەتکراو ·" },
    "فواتير الدفعات": { en: "Batch invoices", ckb: "پسوولەکانی کۆمەڵە" },
    "الكمية في المخزون، وتكلفة الشراء، والرصيد التشغيلي، وفاتورة الوكيل قيم مستقلة.":
      {
        en: "Stock quantity, purchase cost, operational credit and the agent invoice are separate values.",
        ckb: "ژمارەی کۆگا و تێچووی کڕین و باڵانسی کارکردن و پسوولەی بریکار بەهای جیاوازن.",
      },
    "تظهر أول فاتورة عند اعتماد دفعة جديدة.": {
      en: "The first invoice appears when a new batch is approved.",
      ckb: "یەکەم پسوولە لە پەسەندکردنی کۆمەڵەی نوێ دەردەکەوێت.",
    },
    "عرض الفئة ونطاق توفرها": {
      en: "Category display and availability",
      ckb: "پیشاندان و بەردەستی پۆل",
    },
    "صورة الفئة": { en: "Category image", ckb: "وێنەی پۆل" },
    "لغة الوصل": { en: "Receipt language", ckb: "زمانی پسوولە" },
    "لغة الوكيل": { en: "Agent language", ckb: "زمانی بریکار" },
    "صورة الوصل": { en: "Receipt image", ckb: "وێنەی پسوولە" },
    "الوكلاء المسموح لهم": {
      en: "Allowed agents",
      ckb: "بریکارە ڕێگەپێدراوەکان",
    },
    "ترك الاختيارات فارغة يعني جميع الوكلاء.": {
      en: "No selections means all agents.",
      ckb: "بەبێ هەڵبژاردن واتە هەموو بریکارەکان.",
    },
    المحافظات: { en: "Provinces", ckb: "پارێزگاکان" },
    "حفظ عرض الفئة": {
      en: "Save category display",
      ckb: "پاشەکەوتکردنی پیشاندانی پۆل",
    },
    "حجز البطاقات قبل الإصدار": {
      en: "Reserve cards before issuance",
      ckb: "حجزکردنی کارت پێش دەرکردن",
    },
    "استخدم الفئة والكمية والنقطة المختارة في شاشة البيع. الحجز يحفظ البطاقات ويمنع استخدامها، ويمكن إلغاؤه قبل كشف الرموز.":
      {
        en: "Uses the category, quantity and POS selected on the sales screen. Reserved cards cannot be used elsewhere and can be released before revealing codes.",
        ckb: "پۆل و ژمارە و خاڵی هەڵبژێردراوی شاشەی فرۆشتن بەکاردەهێنێت. کارتی حجزکراو تا ئاشکراکردنی کۆد دەتوانرێت ئازاد بکرێت.",
      },
    "حجز الاختيار الحالي": {
      en: "Reserve current selection",
      ckb: "حجزکردنی هەڵبژاردنی ئێستا",
    },
    "إصدار وعرض الوصل": {
      en: "Issue and show receipt",
      ckb: "دەرکردن و پیشاندانی پسوولە",
    },
    "استثناء إعادة الطباعة لعملية واحدة": {
      en: "Reprint exception for one transaction",
      ckb: "ئیستثنای چاپکردنەوە بۆ یەک مامەڵە",
    },
    "يُطبّق الحد الأقل بين الوكيل والجهاز. الاستثناء يسمح بمحاولة إضافية واحدة دون تغيير حدود بقية العمليات.":
      {
        en: "The lower agent/device limit applies. An exception permits one extra attempt without changing other transactions.",
        ckb: "سنووری کەمتر لە نێوان بریکار و ئامێر جێبەجێ دەکرێت. ئیستثنا تەنها یەک هەوڵی زیادە ڕێگە دەدات.",
      },
    "سبب الطلب": { en: "Request reason", ckb: "هۆکاری داواکاری" },
    "طلب استثناء": { en: "Request exception", ckb: "داواکردنی ئیستثنا" },
    "اعتماد محاولة واحدة": {
      en: "Approve one attempt",
      ckb: "پەسەندکردنی یەک هەوڵ",
    },
    "مطابقة المطالبات": {
      en: "Claims reconciliation",
      ckb: "بەراوردکردنی داواکارییەکان",
    },
    المطالبة: { en: "Claim", ckb: "داواکاری" },
    المسحوب: { en: "Withdrawn", ckb: "کشێنراوە" },
    "الصادر من الدفعة": { en: "Issued from batch", ckb: "دەرکراو لە کۆمەڵە" },
    البديل: { en: "Replacement", ckb: "جێگرەوە" },
    التعويض: { en: "Compensation", ckb: "قەرەبوو" },
    النتيجة: { en: "Result", ckb: "ئەنجام" },
    "تسوية المطالبة وأثرها المالي": {
      en: "Claim settlement and financial effect",
      ckb: "یەکلایی داواکاری و کاریگەری دارایی",
    },
    "فتح المطالبة يسحب الرصيد التشغيلي للمتبقي. إعادة التفعيل تعيده؛ البديل يحصل على رصيده عند تحميل دفعته؛ التعويض يسجل في محفظة النقد.":
      {
        en: "Opening a claim withdraws credit for remaining cards. Reactivation restores it; replacement credit is posted with its batch; compensation goes to the cash wallet.",
        ckb: "کردنەوەی داواکاری باڵانسی کارتە ماوەکان دەکشێنێت. چالاککردنەوە دەیگەڕێنێتەوە؛ جێگرەوە لەگەڵ کۆمەڵەکەی تۆمار دەکرێت؛ قەرەبوو دەچێتە جزدانی نەخت.",
      },
    "الدفعة البديلة": { en: "Replacement batch", ckb: "کۆمەڵەی جێگرەوە" },
    "قيمة التعويض": { en: "Compensation amount", ckb: "بڕی قەرەبوو" },
    "اعتماد التسوية": { en: "Approve settlement", ckb: "پەسەندکردنی یەکلایی" },
    "ربط الجهاز والمنطقة": {
      en: "Device and region binding",
      ckb: "بەستنەوەی ئامێر و ناوچە",
    },
    "اشتراط ربط الجهاز": {
      en: "Require device binding",
      ckb: "پێویستکردنی بەستنەوەی ئامێر",
    },
    "السيريال المعتمد": { en: "Approved serial", ckb: "سریاڵی پەسەندکراو" },
    "معرف تثبيت التطبيق": {
      en: "App installation ID",
      ckb: "ناسنامەی دامەزراندنی ئەپ",
    },
    "مرجع شهادة الجهاز": {
      en: "Device certificate reference",
      ckb: "سەرچاوەی بڕوانامەی ئامێر",
    },
    "إصدار نظام التشغيل": {
      en: "Operating system version",
      ckb: "وەشانی سیستەمی کارپێکردن",
    },
    "سياسة المنطقة": { en: "Region policy", ckb: "سیاسەتی ناوچە" },
    "بدون قيد": { en: "Unrestricted", ckb: "بێ سنوور" },
    حظر: { en: "Block", ckb: "بلۆککردن" },
    "المحافظة المسموحة": { en: "Allowed province", ckb: "پارێزگای ڕێگەپێدراو" },
    "استثناء مؤقت حتى": {
      en: "Temporary exception until",
      ckb: "ئیستثنای کاتی تا",
    },
    "يُفحص السيريال والإصدار والمحافظة المسجلة عند البيع محليًا. التحقق من الجهاز الحقيقي وGPS يحتاج الربط.":
      {
        en: "Registered serial, version and province are checked locally at sale. Real device identity and GPS require integration.",
        ckb: "سریاڵ و وەشان و پارێزگای تۆمارکراو لە فرۆشتنی ناوخۆدا دەپشکنرێن. ناسنامەی ڕاستەقینە و GPS پێویستیان بە پەیوەستکردن هەیە.",
      },
    "اعتماد إعداد الجهاز": {
      en: "Approve device settings",
      ckb: "پەسەندکردنی ڕێکخستنی ئامێر",
    },
    "دورة طلب Top-up وAPI": {
      en: "Top-up and API request lifecycle",
      ckb: "قۆناغەکانی داواکاری Top-up و API",
    },
    "محاكاة محلية لطلبات المزود. لا يجري شحن فعلي أو إصدار رمز اتصال حقيقي.": {
      en: "Local provider simulation. No real recharge or access token is issued.",
      ckb: "هاوشێوەسازی ناوخۆی دابینکەر. هیچ شەحن یان تۆکنی ڕاستەقینە دروست ناکرێت.",
    },
    "كتالوج منتجات المزود المحلي": {
      en: "Local provider product catalog",
      ckb: "کەتەلۆگی ناوخۆی بەرهەمی دابینکەر",
    },
    "الرمز، الاسم، تكلفة الجملة، سعر البيع — سطر لكل منتج": {
      en: "Code, name, wholesale cost, sale price — one product per line",
      ckb: "کۆد، ناو، تێچووی کۆ، نرخی فرۆشتن — هەر بەرهەم لە ڕیزێک",
    },
    "فحص وحفظ الكتالوج": {
      en: "Validate and save catalog",
      ckb: "پشکنین و پاشەکەوتکردنی کەتەلۆگ",
    },
    "إعداد التكامل": {
      en: "Integration settings",
      ckb: "ڕێکخستنی پەیوەستکردن",
    },
    "اختبار الإعداد وتفعيله محليًا": {
      en: "Test and activate locally",
      ckb: "تاقیکردنەوە و چالاککردنی ناوخۆ",
    },
    "تدوير تعريف الرمز": {
      en: "Rotate token identifier",
      ckb: "نوێکردنەوەی ناسنامەی تۆکن",
    },
    "محفظة الخدمة": { en: "Service wallet", ckb: "جزدانی خزمەتگوزاری" },
    "منتج الخدمة": { en: "Service product", ckb: "بەرهەمی خزمەتگوزاری" },
    "قيمة مخصصة للاختبار": {
      en: "Custom test value",
      ckb: "بەهای تایبەت بۆ تاقیکردنەوە",
    },
    "رقم المستفيد": { en: "Beneficiary number", ckb: "ژمارەی سوودمەند" },
    "تكلفة الجملة": { en: "Wholesale cost", ckb: "تێچووی کۆ" },
    "سعر البيع": { en: "Sale price", ckb: "نرخی فرۆشتن" },
    "إنشاء / إعادة إرسال نفس الطلب": {
      en: "Create / resend the same request",
      ckb: "دروستکردن / ناردنەوەی هەمان داواکاری",
    },
    "طلب جديد": { en: "New request", ckb: "داواکاری نوێ" },
    "محاكاة تأكيد النجاح": {
      en: "Simulate success confirmation",
      ckb: "هاوشێوەسازی پشتڕاستکردنەوەی سەرکەوتن",
    },
    "محاكاة الفشل واسترجاع الحجز": {
      en: "Simulate failure and release credit",
      ckb: "هاوشێوەسازی شکست و ئازادکردنی باڵانس",
    },
    "نتيجة غير معروفة": { en: "Unknown result", ckb: "ئەنجامی نەزانراو" },
    "إرسال لمجموعة محددة": {
      en: "Send to selected recipients",
      ckb: "ناردن بۆ وەرگرە دیاریکراوەکان",
    },
    "اكتب العنوان والنص في نموذج الإشعار أدناه، ثم اختر الجمهور هنا.": {
      en: "Enter the title and body in the notification form below, then select recipients here.",
      ckb: "ناونیشان و دەق لە فۆڕمی خوارەوە بنووسە، پاشان وەرگر لێرە هەڵبژێرە.",
    },
    "تضمين فروع ونقاط الوكلاء المختارين": {
      en: "Include branches and POS of selected agents",
      ckb: "لق و خاڵەکانی بریکاری هەڵبژێردراو بگرەوە",
    },
    "إضافة الإشعار للمجموعة": {
      en: "Add notification for the group",
      ckb: "زیادکردنی ئاگاداری بۆ گرووپ",
    },
    "محتوى المنصة والتطبيقات": {
      en: "Platform and app content",
      ckb: "ناوەڕۆکی پلاتفۆرم و ئەپ",
    },
    "عن المنصة": { en: "About the platform", ckb: "دەربارەی پلاتفۆرم" },
    الخدمات: { en: "Services", ckb: "خزمەتگوزارییەکان" },
    التواصل: { en: "Contact", ckb: "پەیوەندی" },
    "رابط Android": { en: "Android link", ckb: "بەستەری Android" },
    "رابط iOS": { en: "iOS link", ckb: "بەستەری iOS" },
    "سياسة الخصوصية": { en: "Privacy policy", ckb: "سیاسەتی تایبەتمەندی" },
    "شروط الاستخدام": { en: "Terms of use", ckb: "مەرجەکانی بەکارهێنان" },
    "حفظ محتوى المنصة": {
      en: "Save platform content",
      ckb: "پاشەکەوتکردنی ناوەڕۆکی پلاتفۆرم",
    },
    "الخبر التالي": { en: "Next news item", ckb: "هەواڵی دواتر" },
    خدماتنا: { en: "Our services", ckb: "خزمەتگوزارییەکانمان" },
    "تطبيق Android": { en: "Android app", ckb: "ئەپی Android" },
    "تطبيق iOS": { en: "iOS app", ckb: "ئەپی iOS" },
    "نسخة احتياطية مشفرة": { en: "Encrypted backup", ckb: "پاشەکەوتی کۆدکراو" },
    "تشفير AES-256-GCM بكلمة مرور. احتفظ بالكلمة؛ لا يمكن استعادة النسخة دونها.":
      {
        en: "Password-based AES-256-GCM encryption. Keep the password; the backup cannot be restored without it.",
        ckb: "کۆدکردنی AES-256-GCM بە وشەی نهێنی. وشەکە بپارێزە؛ بەبێ ئەو پاشەکەوت ناگەڕێتەوە.",
      },
    "كلمة التشفير": { en: "Encryption password", ckb: "وشەی نهێنی کۆدکردن" },
    "تنزيل نسخة مشفرة": {
      en: "Download encrypted backup",
      ckb: "داگرتنی پاشەکەوتی کۆدکراو",
    },
    "استعادة نسخة مشفرة": {
      en: "Restore encrypted backup",
      ckb: "گەڕاندنەوەی پاشەکەوتی کۆدکراو",
    },
    "متابعة الخدمات والطلبات المحلية": {
      en: "Local service and request monitoring",
      ckb: "چاودێری خزمەتگوزاری و داواکاریی ناوخۆ",
    },
    "طلبات مزود غير محسومة": {
      en: "Unresolved provider requests",
      ckb: "داواکارییە یەکلانەکراوەکانی دابینکەر",
    },
    "طلبات تمويل معلقة": {
      en: "Pending funding requests",
      ckb: "داواکارییە چاوەڕوانەکانی دابینکردن",
    },
    "حجوزات لم تصدر": {
      en: "Unissued reservations",
      ckb: "حجزە دەرنەکراوەکان",
    },
    "المؤشرات مبنية على سجلات هذه النسخة. قياس الخادم والمزودين والأجهزة المتصلة يظهر بعد الربط.":
      {
        en: "Metrics use this local version's records. Server, provider and device measurements require integration.",
        ckb: "پێوانەکان لە تۆمارە ناوخۆییەکانن. پێوانەی ڕاژەکار و دابینکەر و ئامێر پێویستی بە پەیوەستکردن هەیە.",
      },
    "سياسة الأسعار حسب المحافظة وتاريخ السريان": {
      en: "Prices by province and effective date",
      ckb: "نرخ بەپێی پارێزگا و بەرواری کارپێکردن",
    },
    "المحافظة أو الكل": { en: "Province or all", ckb: "پارێزگا یان هەموو" },
    "السعر بالعملة الأصلية": {
      en: "Price in original currency",
      ckb: "نرخ بە دراوی بنەڕەتی",
    },
    "سعر الصرف إلى الدينار": {
      en: "Exchange rate to IQD",
      ckb: "نرخی گۆڕین بۆ دینار",
    },
    "تاريخ النفاذ": { en: "Effective date", ckb: "بەرواری کارپێکردن" },
    "السعر النهائي:": { en: "Final price:", ckb: "نرخی کۆتایی:" },
    "اعتماد السياسة": { en: "Approve policy", ckb: "پەسەندکردنی سیاسەت" },
    "حدود مخصصة وحقول إضافية": {
      en: "Custom limits and additional fields",
      ckb: "سنووری تایبەت و خانەی زیادە",
    },
    "حساب الوكيل أو الجهاز": {
      en: "Agent or device account",
      ckb: "هەژماری بریکار یان ئامێر",
    },
    "حد المبلغ اليومي": { en: "Daily amount limit", ckb: "سنووری بڕی ڕۆژانە" },
    "يطبق الحد الأقل بين الفئة والوكيل والجهاز.": {
      en: "The lowest category, agent or device limit applies.",
      ckb: "کەمترین سنوور لە نێوان پۆل و بریکار و ئامێر جێبەجێ دەکرێت.",
    },
    "حفظ الحدود": { en: "Save limits", ckb: "پاشەکەوتکردنی سنوورەکان" },
    "حقول البطاقة العالمية": {
      en: "International card fields",
      ckb: "خانەکانی کارتی نێودەوڵەتی",
    },
    "سطر لكل حقل: مفتاح إنكليزي | الاسم المعروض | مطلوب أو اختياري. تظهر القيم على الوصل بعد استيرادها.":
      {
        en: "One field per line: English key / display label / required or optional. Imported values appear on the receipt.",
        ckb: "هەر خانە لە ڕیزێک: کلیلی ئینگلیزی / ناوی پیشاندان / پێویست یان ئارەزوومەندانە. بەهاکان لە پسوولە دەردەکەون.",
      },
    "حفظ الحقول الإضافية": {
      en: "Save additional fields",
      ckb: "پاشەکەوتکردنی خانە زیادەکان",
    },
    "صور المزود": { en: "Provider images", ckb: "وێنەکانی دابینکەر" },
    "حفظ الصور": { en: "Save images", ckb: "پاشەکەوتکردنی وێنەکان" },
    "تبعية الموظف": { en: "Employee affiliation", ckb: "سەربەخۆیی کارمەند" },
    "الموظف المركزي يرى نطاقه بحسب الصلاحية. موظف الوكيل يمول من محفظة الوكيل المرتبط به فقط عند منحه صلاحية التمويل.":
      {
        en: "Central staff see their permitted scope. Agent staff may fund only from their linked agent wallet when granted funding permission.",
        ckb: "کارمەندی ناوەندی مەودای ڕێگەپێدراوی خۆی دەبینێت. کارمەندی بریکار بە مۆڵەتی دابینکردن تەنها لە جزدانی بریکاری پەیوەست دابین دەکات.",
      },
    "جهة العمل": { en: "Employer", ckb: "شوێنی کار" },
    "إدارة مركزية": {
      en: "Central administration",
      ckb: "بەڕێوەبەرایەتی ناوەندی",
    },
    "موظف داخل حساب وكيل": {
      en: "Employee within an agent account",
      ckb: "کارمەندی ناو هەژماری بریکار",
    },
    "حفظ التبعية": { en: "Save affiliation", ckb: "پاشەکەوتکردنی پەیوەندی" },
    "مطابقة المخزون والرصيد التشغيلي": {
      en: "Stock and operational credit reconciliation",
      ckb: "بەراوردی کۆگا و باڵانسی کارکردن",
    },
    "قيمة البطاقات المتاحة والمحجوزة": {
      en: "Value of available and reserved cards",
      ckb: "بەهای کارتە بەردەست و حجزکراوەکان",
    },
    "رصيد الرئيسي": { en: "Main agent balance", ckb: "باڵانسی بریکاری سەرەکی" },
    "الرصيد الموزع على الشجرة": {
      en: "Credit distributed across the hierarchy",
      ckb: "باڵانسی دابەشکراو لە پێکهاتە",
    },
    "فرق المطابقة:": {
      en: "Reconciliation difference:",
      ckb: "جیاوازی بەراورد:",
    },
    "الحجوزات جزء من الرصيد الموزع:": {
      en: "Reservations included in distributed credit:",
      ckb: "حجزەکان لەناو باڵانسی دابەشکراودان:",
    },
    "سقف مديونية الوكيل": {
      en: "Agent credit ceiling",
      ckb: "سنووری قەرزی بریکار",
    },
    "السقف • د.ع": { en: "Ceiling • IQD", ckb: "سنوور • دینار" },
    "عند تعيين سقف، يمنع اعتماد دفعة تتجاوز الذمم المتبقية بعد التحصيل.": {
      en: "A configured ceiling blocks batch posting when the resulting outstanding debt would exceed it.",
      ckb: "سنووری دیاریکراو ڕێگری لە بارکردنی کۆمەڵە دەکات کاتێک قەرزی ماوە لە سنوور تێدەپەڕێت.",
    },
    "حفظ سقف المديونية": {
      en: "Save credit ceiling",
      ckb: "پاشەکەوتکردنی سنووری قەرز",
    },
    "طلب سحب واعتماد التصدير": {
      en: "Withdrawal request and export approval",
      ckb: "داواکاری کشاندنەوە و پەسەندکردنی هەناردە",
    },
    "سبب السحب": { en: "Withdrawal reason", ckb: "هۆکاری کشاندنەوە" },
    "مهلة تنزيل الملف • ساعات": {
      en: "Download window • hours",
      ckb: "ماوەی داگرتن • کاتژمێر",
    },
    "حجر وطلب تصدير": {
      en: "Quarantine and request export",
      ckb: "ڕاگرتن و داواکردنی هەناردە",
    },
    "اعتماد التنزيل": { en: "Approve download", ckb: "پەسەندکردنی داگرتن" },
    "اختيار للتنزيل المشفر": {
      en: "Select for encrypted download",
      ckb: "هەڵبژاردن بۆ داگرتنی کۆدکراو",
    },
    "المهلة تمنع إنشاء تنزيل جديد داخل النظام. الملف الذي نُزّل لا يمكن إلغاؤه عن بُعد؛ الرابط الآمن يحتاج الخادم.":
      {
        en: "Expiry prevents new downloads within the system. A downloaded file cannot be revoked remotely; secure links require a server.",
        ckb: "بەسەرچوون ڕێگری لە داگرتنی نوێ دەکات. فایلی داگیراو لە دوورەوە هەڵناوەشێتەوە؛ بەستەری پارێزراو ڕاژەکاری دەوێت.",
      },
    "تسليم بديل عن الطباعة": {
      en: "Delivery instead of printing",
      ckb: "گەیاندن لەبری چاپکردن",
    },
    "تنزيل البطاقات نفسها في ملف مشفر وتسجيل قناة التسليم دون إصدار جديد أو خصم إضافي.":
      {
        en: "Download the same cards encrypted and record the delivery channel without new issuance or another debit.",
        ckb: "هەمان کارت بە کۆدکراوی دابگرە و کەناڵی گەیاندن تۆمار بکە بەبێ دەرکردن یان کەمکردنەوەی نوێ.",
      },
    "فتح أداة فك تشفير الملفات": {
      en: "Open file decryption tool",
      ckb: "کردنەوەی ئامرازی کۆدکردنەوەی فایل",
    },
    "مرجع التسليم": { en: "Delivery reference", ckb: "سەرچاوەی گەیاندن" },
    "كلمة تشفير الملف": {
      en: "File encryption password",
      ckb: "وشەی نهێنی کۆدکردنی فایل",
    },
    "تنزيل وتسجيل التسليم": {
      en: "Download and record delivery",
      ckb: "داگرتن و تۆمارکردنی گەیاندن",
    },
    "كاشيرة الجملة": { en: "Wholesale checkout", ckb: "کاشێری فرۆشتنی کۆ" },
    "تقسم الكمية إلى عمليات ضمن حد الفئة. تبقى كل عملية بسجل ووصل مستقلين. عند بلوغ الفاصل الزمني انتظر ثم أعد المحاولة بنفس المجموعة.":
      {
        en: "Quantity is split into transactions within the category limit. Each has its own receipt. If throttled, wait and retry the same group.",
        ckb: "ژمارە بە سنووری پۆل دابەش دەکرێت بۆ مامەڵەکان. هەر یەک پسوولەی خۆی هەیە. ئەگەر چاوەڕوانی داوا کرا، هەمان گرووپ دووبارە بکەرەوە.",
      },
    "الكمية الإجمالية للفئة والنقطة المختارتين": {
      en: "Total quantity for selected category and POS",
      ckb: "کۆی ژمارە بۆ پۆل و خاڵی هەڵبژێردراو",
    },
    "إصدار / إكمال المجموعة": {
      en: "Issue / resume group",
      ckb: "دەرکردن / بەردەوامبوونی گرووپ",
    },
    "مجموعة جديدة": { en: "New group", ckb: "گرووپی نوێ" },
    "قواعد التنبيهات المحلية": {
      en: "Local alert rules",
      ckb: "یاساکانی ئاگاداری ناوخۆ",
    },
    "حد انخفاض عدد البطاقات": {
      en: "Low-stock card threshold",
      ckb: "سنووری کەمی کارت",
    },
    "يفحص المخزون، واقتراب الانتهاء، وفشل الطباعة؛ لا يكرر التنبيه نفسه في اليوم. تظهر الخطورة والجهة المستهدفة في السجل.":
      {
        en: "Checks stock, expiry and print failure without repeating the same daily alert. Severity and recipient appear in the log.",
        ckb: "کۆگا و بەسەرچوون و شکستی چاپ دەپشکنێت بەبێ دووبارەکردنەوەی ئاگاداریی هەمان ڕۆژ. گرنگی و وەرگر لە تۆمار دەردەکەون.",
      },
    "حفظ الحد وفحص التنبيهات": {
      en: "Save threshold and check alerts",
      ckb: "پاشەکەوتکردنی سنوور و پشکنینی ئاگاداری",
    },
    "إرسال Push والبريد إلى أجهزة خارجية ينتظر ربط قنوات الإرسال.": {
      en: "External Push and email require delivery-channel integration.",
      ckb: "Push و ئیمەیڵی دەرەکی پێویستیان بە پەیوەستکردنی کەناڵەکان هەیە.",
    },
    "جهة الدعم المباشرة": {
      en: "Direct support contact",
      ckb: "پەیوەندی پشتگیری ڕاستەوخۆ",
    },
    "التذكرة تُربط بالوكيل المباشر المحدد فيها، ويمكن تصعيدها من داخل تفاصيل التذكرة.":
      {
        en: "The ticket belongs to its selected direct agent and can be escalated from its details.",
        ckb: "تیکەت سەر بە بریکاری ڕاستەوخۆی هەڵبژێردراوە و لە وردەکارییەکانیدا بەرز دەکرێتەوە.",
      },
    "سياسات الجهاز واختبار الدخول": {
      en: "Device policies and login test",
      ckb: "سیاسەتی ئامێر و تاقیکردنەوەی چوونەژوورەوە",
    },
    "أقل إصدار لنظام التشغيل": {
      en: "Minimum operating system version",
      ckb: "کەمترین وەشانی سیستەمی کارپێکردن",
    },
    "التحقق بخطوتين في اختبار الدخول": {
      en: "Two-step verification in login test",
      ckb: "پشتڕاستکردنەوەی دوو هەنگاو لە تاقیکردنەوەی چوونەژوورەوە",
    },
    "حفظ السياسة": { en: "Save policy", ckb: "پاشەکەوتکردنی سیاسەت" },
    "هذه واجهة اختبار محلية لرحلة الدخول والاستعادة؛ رموز OTP لا تُرسل خارجيًا. مبدّل الحسابات يبقى مخصصًا لفحص الأدوار.":
      {
        en: "Local login and recovery test. OTPs are not sent externally. The account switcher remains a role-testing tool.",
        ckb: "تاقیکردنەوەی ناوخۆی چوونەژوورەوە و گەڕاندنەوە. OTP بۆ دەرەوە نانێردرێت. گۆڕەری هەژمار بۆ تاقیکردنەوەی ڕۆڵەکانە.",
      },
    "كلمة المرور الحالية": { en: "Current password", ckb: "وشەی نهێنی ئێستا" },
    "كلمة مؤقتة / كلمة أول دخول الجديدة": {
      en: "Temporary / new first-login password",
      ckb: "وشەی نهێنی کاتی / نوێی یەکەم چوونەژوورەوە",
    },
    "تعيين كلمة مؤقتة": {
      en: "Set temporary password",
      ckb: "دانانی وشەی نهێنی کاتی",
    },
    "اختبار الدخول": { en: "Test login", ckb: "تاقیکردنەوەی چوونەژوورەوە" },
    "رمز اختبار محلي:": {
      en: "Local test code:",
      ckb: "کۆدی تاقیکردنەوەی ناوخۆ:",
    },
    "· ينتهي بعد دقيقتين": {
      en: "· expires in two minutes",
      ckb: "· دوای دوو خولەک بەسەردەچێت",
    },
    "رمز التحقق": { en: "Verification code", ckb: "کۆدی پشتڕاستکردنەوە" },
    "تأكيد الرمز": { en: "Confirm code", ckb: "پشتڕاستکردنەوەی کۆد" },
  };
  for (const [ar, v] of Object.entries(entries))
    for (const lang of ["en", "ckb"])
      MasalLocale.dictionaries[lang][ar] = v[lang];
})();
