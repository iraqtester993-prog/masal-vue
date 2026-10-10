export const statusLabels = {
  recorded:'مسجلة', unknown:'بانتظار التحقق', processing:'قيد التنفيذ',
  'Print Requested':'بانتظار الطباعة', 'Print Failed':'فشلت الطباعة', Printed:'مطبوع', Reprinted:'معاد طباعته',
  'Reprint Requested':'طلب إعادة طباعة', Reserved:'محجوز', Cancelled:'ملغى', Delivered:'تم التسليم',
  Available:'متاح', Issued:'مباع', Quarantined:'محجور', Exported:'مصدّر', Loaded:'محمّل',
  'Awaiting Replacement':'بانتظار الاستبدال', 'Cancelled by Reversal':'ملغى بالتراجع',
  active:'مفعل', inactive:'غير مفعل', disabled:'معطل', suspended:'موقوف',
  pending:'بانتظار المراجعة', approved:'معتمد', rejected:'مرفوض', cancelled:'ملغى', posted:'منفذ',
  open:'مفتوحة', closed:'مغلقة', unpaid:'غير مدفوعة', partial:'مدفوعة جزئيًا', paid:'مدفوعة',
  used:'مستخدم', reversed:'تم التراجع', review:'بانتظار التحقق', succeeded:'ناجحة', failed:'فاشلة', refunded:'مسترجعة',
  queued:'بانتظار التنفيذ', preparing:'جارٍ التجهيز', validated:'تم التحقق', switching:'جارٍ التفعيل',
  completed:'مكتملة', rollback_failed:'تعذر الرجوع', expired:'منتهية', online:'متصل', offline:'غير متصل',
};
const labels = {
  status:statusLabels,
  accountType:{system:'مدير النظام',main_agent:'وكيل رئيسي',sub_agent:'وكيل فرعي',sub_branch:'فرع فرعي',pos:'نقطة بيع',owner:'مالك الحساب',employee:'موظف'},
  portal:{admin:'بوابة مدير النظام',agents:'بوابة الوكلاء',pos:'بوابة نقاط البيع',public:'الموقع العام'},
  productKind:{card:'بطاقة',voucher:'بطاقة',topup:'تعبئة مباشرة',bundle:'باقة',bill:'تسديد فاتورة',rabiaa:'الرابعة'},
  provider:{topup:'التعبئة المباشرة — آسياسيل',rabiaa:'الرابعة'},
  currency:{IQD:'دينار عراقي'},
  field:{name:'الاسم',status:'الحالة',city:'المحافظة',phone:'رقم الهاتف',parent_id:'الوكيل الأعلى',permission_id:'الصلاحية',allowed:'السماح',product_id:'الفئة',quantity:'الكمية',version:'إصدار السجل'},
  dailyMode:{account:'الحساب فقط',network:'الحساب والشبكة التابعة',count:'عدد البطاقات',amount:'مبلغ المبيعات',cards:'عدد البطاقات',transactions:'عدد العمليات',quantity:'عدد البطاقات',all:'جميع الفئات',selected:'الفئات المحددة',per_product:'حسب الفئة',total:'الإجمالي'},
  environment:{production:'تشغيل فعلي',testing:'اختبار',staging:'تجربة',local:'محلي',configured:'متصل',ready:'جاهز',review:'بانتظار التحقق',unconfigured:'غير متصل'},
  page:{accounts:'الحسابات',agents:'الوكلاء',pos:'نقاط البيع',sales:'المبيعات',sell:'البيع والطباعة',wallets:'المحافظ',prices:'الأسعار',inventory:'المخزون',import:'الطلبيات',exports:'تصدير البطاقات',claims:'البطاقات التالفة',support:'الدعم الفني',notifications:'الإشعارات',exceptions:'استثناءات الطباعة',digital:'الخدمات الإلكترونية',security:'الحماية والأمان',reports:'التقارير',products:'الفئات',providers:'الشركات',backup:'النسخ الاحتياطي',company:'موقع الشركة',map:'الخريطة'},
  movement:{voucher:'البطاقات',cash:'النقد',transfer:'تحويل',funding:'تمويل',deposit:'إيداع',sale:'بيع',stock_load:'تحميل مخزون',stock_funding:'تمويل بطلبية',stock_import:'تحميل مخزون',invoice_settlement:'تحصيل فاتورة',invoice_payment:'تسديد فاتورة',recovery:'استرجاع الرصيد'},
  setting:{velocity_seconds:'الفاصل بين العمليات',min_app_version:'أدنى إصدار للتطبيق',min_os_version:'أدنى إصدار للنظام',reprint_limit:'حد إعادة الطباعة',sales_enabled:'تفعيل البيع',printing_enabled:'تفعيل الطباعة',failed_retries:'محاولات الطباعة بعد الفشل',max_cards:'بطاقات الطلب الواحد',interval_seconds:'الفاصل بين العمليات',daily_cards:'الحد اليومي للبطاقات',daily_mode:'طريقة احتساب الحد اليومي'},
};
export const auditActionLabels = {
  'auth.login':'تسجيل الدخول','auth.login_failed':'فشل تسجيل الدخول','auth.logout':'تسجيل الخروج',
  'auth.password_reset':'إعادة تعيين كلمة المرور','auth.profile':'تعديل الملف الشخصي','auth.time_expired':'انتهاء صلاحية الجلسة',
  'account.login':'تعديل بيانات دخول الحساب','account.permissions':'تعديل صلاحيات الحساب','account.location':'تعديل موقع الحساب',
  'account.attachment.upload':'رفع مرفق الحساب','account.attachment.delete':'حذف مرفق الحساب','agents.categories':'تحديد الفئات المسموحة',
  'catalog.reference.import':'استيراد الفئات المرجعية','pos.reference-profile':'تعديل بيانات نقطة البيع',
  'preferences.updated':'تعديل تفضيلات المستخدم','backup.create':'إنشاء نسخة احتياطية','backup.restore-reviewed':'اعتماد طلب استرجاع النسخة',
  'integrations.edit':'تعديل الربط مع الشركة','integrations.catalog':'استعلام فئات الشركة',
  'digital.balance':'استعلام رصيد الشركة','digital.create':'إنشاء عملية خدمة إلكترونية','digital.assign':'توزيع الخدمات الإلكترونية',
  'digital.acknowledge':'تأكيد نتيجة الخدمة الإلكترونية','digital.result':'تسجيل نتيجة الخدمة الإلكترونية','digital.refund':'استرجاع مبلغ الخدمة الإلكترونية',
  'digital.receipt':'عرض إيصال الخدمة الإلكترونية','digital.print_authorization':'اعتماد طباعة إيصال الخدمة الإلكترونية',
  'digital.export':'تصدير سجل الخدمات الإلكترونية','topup.catalog.synchronized':'مزامنة فئات شركة التعبئة',
  'topup.categories.assigned':'توزيع فئات التعبئة','topup.category.saved':'حفظ فئة تعبئة',
  'import.submit':'إرسال طلبية للاعتماد','import.resubmit':'إعادة إرسال الطلبية','import.approve':'مراجعة واعتماد الطلبية',
  'inventory.quarantine':'إيقاف بيع الطلبية','inventory.resume':'إعادة تفعيل الطلبية','inventory.cancel':'إلغاء الطلبية',
  'inventory.restore':'استرجاع الطلبية','inventory.export':'تصدير المخزون','inventory.revalue':'إعادة تقييم المخزون',
  'wallets.request':'إنشاء طلب تمويل','wallets.approve':'مراجعة طلب التمويل','wallets.cancel':'إلغاء طلب التمويل',
  'wallets.deposit':'إيداع رصيد','wallets.transfer':'تحويل رصيد','wallets.reverse':'التراجع عن التمويل','wallets.bulk':'تمويل متعدد',
  'invoices.create':'إنشاء فاتورة','invoices.settle':'تسديد فاتورة','prices.propose':'اقتراح تعديل الأسعار',
  'prices.approve':'مراجعة تعديل الأسعار','prices.reverse':'إلغاء تعديل الأسعار','claims.create':'إنشاء مطالبة','claims.settle':'تسوية مطالبة',
  'exports.request':'إنشاء طلب إرجاع للمجهّز','exports.approve':'مراجعة إرجاع المجهّز','exports.download':'تنزيل البطاقات المصدّرة',
  'sell.create':'إنشاء عملية بيع','sell.deliver':'تسجيل تسليم البطاقات','sell.print':'بدء طباعة البطاقات',
  'sell.receipt':'عرض إيصال البيع','sell.reprint':'طلب إعادة طباعة','sell.result':'تسجيل نتيجة الطباعة',
  'sales.issue':'إصدار البطاقات','sales.cancel-reservation':'إلغاء حجز البطاقات','sales.export':'تصدير المبيعات','sales.limits':'تعديل حدود البيع',
  'exceptions.approve':'مراجعة استثناء الطباعة','security.account_time':'تعديل أوقات صلاحية الحساب','security.direct':'تعديل الإيقاف المباشر',
  'security.fundingRequests':'تعديل ضوابط التمويل','security.policies':'تعديل السياسات الأمنية','security.resume':'رفع الإيقاف','security.stop':'إيقاف العمليات',
  'security.restore-global':'استعادة التشغيل العام','sessions.revoke':'إنهاء جلسة الدخول',
  'company.update':'تعديل موقع الشركة','company.inquiry.review':'مراجعة استفسار الشركة','support.phones':'تعديل أرقام الدعم',
  'support.reply':'إرسال رد للدعم','notifications.send':'إرسال إشعار','notifications.export':'تصدير الإشعارات',
  'products.order':'ترتيب الفئات','representatives.photo.upload':'رفع صورة المندوب','representatives.photo.delete':'حذف صورة المندوب',
};
const auditEntities={account:'الحساب',staff:'الموظف',permission_profile:'نوع الصلاحية',products:'الفئة',providers:'الشركة',sources:'المصدر',governorates:'المحافظة',posTypes:'نوع نقطة البيع',representatives:'المندوب',support:'طلب الدعم'};
const auditVerbs={create:'إنشاء',update:'تعديل',edit:'تعديل',status:'تعديل حالة',toggle:'تعديل حالة',delete:'حذف',archive:'أرشفة',export:'تصدير',open:'فتح',close:'إغلاق',reopen:'إعادة فتح'};
export function auditActionLabel(value) {
  const text=String(value??'');
  if (Object.hasOwn(auditActionLabels,text)) return auditActionLabels[text];
  const [entity,verb,...extra]=text.split('.');
  if (!extra.length && auditEntities[entity] && auditVerbs[verb]) return `${auditVerbs[verb]} ${auditEntities[entity]}`;
  return /[\u0600-\u06ff]/.test(text)?text:'إجراء آخر';
}
export function systemLabel(value,kind) {
  if (value===null||value===undefined||value==='') return '—';
  if (kind==='action') return auditActionLabel(value);
  const table=labels[kind];
  if (!table) return String(value);
  return Object.hasOwn(table,value)?table[value]:/[\u0600-\u06ff]/.test(String(value))?String(value):'غير محدد';
}
