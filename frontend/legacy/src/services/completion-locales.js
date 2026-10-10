(function () {
  const rows = `
أمان حسابي|My account security|ئاسایشی هەژمارەکەم
الجلسة مطلوبة للعمليات|An active session is required|دانیشتنێکی چالاک پێویستە
إنفاذ سياسات الجلسة والعمليات الحساسة|Enforce session and sensitive-action policies|جێبەجێکردنی سیاسەتی دانیشتن و کردارە هەستیارەکان
تطبق هذه السياسات داخل النسخة المحلية. التحقق من IP وVPN يعتمد بيانات جلسة الاختبار؛ مصدر الشبكة الحقيقي يحدده الخادم عند الربط.|These policies apply locally. IP and VPN checks use test-session data; the server must establish the actual network source.|ئەم سیاسەتانە ناوخۆیین. پشکنینی IP و VPN بە داتای تاقیکردنەوەیە؛ سەرچاوەی ڕاستەقینەی تۆڕ ڕاژەکار دیاری دەکات.
اشتراط جلسة فعالة|Require an active session|پێویستکردنی دانیشتنی چالاک
تحقق إضافي للعمليات الحساسة|Extra verification for sensitive actions|پشتڕاستکردنەوەی زیادە بۆ کردارە هەستیارەکان
حظر جلسات VPN|Block VPN sessions|بلۆککردنی دانیشتنی VPN
فعّل التحقق بخطوتين وسجل الدخول من أمان حسابي قبل إلزام هذه السياسات. مهلة الخمول وقائمة IP تؤخذان من إعدادات الأمان.|Enroll in two-step verification and sign in through My account security first. Idle timeout and blocked IPs come from security settings.|سەرەتا پشتڕاستکردنەوەی دوو هەنگاو چالاک بکە و لە ئاسایشی هەژمارەکەم بچۆ ژوورەوە. ماوەی بێچالاکی و IP لە ڕێکخستنی ئاسایشەوە وەردەگیرێن.
توجيه وتصعيد التنبيهات|Alert routing and escalation|ئاڕاستەکردن و بەرزکردنەوەی ئاگاداری
الخطورة|Severity|ئاستی مەترسی
القناة|Channel|کەناڵ
المستلم|Recipient|وەرگر
مهلة التصعيد بالدقائق|Escalation delay in minutes|ماوەی بەرزکردنەوە بە خولەک
إضافة قاعدة|Add rule|زیادکردنی یاسا
معالجة التنبيهات المستحقة|Process due alerts|جێبەجێکردنی ئاگادارییە کاتگەیشتووەکان
دقيقة|minute|خولەک
طابور الإرسال|Delivery queue|ڕیزی ناردن
البريد وPush يبقيان في حالة جاهز للربط؛ لا يسجل النظام إرسالًا لم يحدث.|Email and Push remain ready for integration; the system never records an unsent message as sent.|ئیمەیڵ و Push لە دۆخی ئامادەی پەیوەستکردن دەمێنن؛ نامەی نەنێردراو بە نێردراو تۆمار ناکرێت.
رموز الاختبار تظهر هنا محليًا؛ لا يجري إرسال بريد أو رسالة هاتف.|Test codes are shown locally here; no email or SMS is sent.|کۆدەکانی تاقیکردنەوە لێرە دەردەکەون؛ ئیمەیڵ یان SMS نانێردرێت.
الإجراء|Action|کردار
تسجيل الدخول|Sign in|چوونەژوورەوە
تفعيل التحقق بخطوتين|Enroll in two-step verification|چالاککردنی پشتڕاستکردنەوەی دوو هەنگاو
تأكيد إجراء حساس|Verify a sensitive action|پشتڕاستکردنەوەی کردارێکی هەستیار
نسيت كلمة المرور|Forgot password|وشەی نهێنیم لەبیرکردووە
تغيير كلمة المرور المؤقتة|Change temporary password|گۆڕینی وشەی نهێنی کاتی
استخدام كود استعادة|Use a recovery code|بەکارهێنانی کۆدی گەڕاندنەوە
البريد أو معرف الحساب|Email or account ID|ئیمەیڵ یان ناسنامەی هەژمار
كلمة المرور الجديدة|New password|وشەی نهێنی نوێ
كود استعادة لمرة واحدة|Single-use recovery code|کۆدی گەڕاندنەوەی یەکجارە
IP جلسة الاختبار|Test session IP|IPی دانیشتنی تاقیکردنەوە
محاكاة اتصال VPN|Simulate VPN connection|هاوشێوەسازی پەیوەندی VPN
متابعة|Continue|بەردەوامبوون
صندوق رسائل الاختبار|Test message inbox|سندوقی نامەکانی تاقیکردنەوە
الرمز صالح لدقيقتين وخمس محاولات فقط|Code valid for two minutes and five attempts|کۆدەکە بۆ دوو خولەک و پێنج هەوڵ دروستە
أكواد الاستعادة — احفظها الآن|Recovery codes — save them now|کۆدەکانی گەڕاندنەوە — ئێستا بیانپارێزە
الجلسات ومفتاح المرور|Sessions and passkey|دانیشتنەکان و کلیلی تێپەڕبوون
فعال|Active|چالاک
منتهية|Expired|بەسەرچوو
إنهاء جلساتي|End my sessions|کۆتاییهێنان بە دانیشتنەکانم
فحص دعم مفتاح المرور|Check passkey support|پشکنینی پشتگیری کلیلی تێپەڕبوون
داخل النظام|In-app|لەناو سیستەم
بريد إلكتروني|Email|ئیمەیڵ
حرجة|Critical|زۆر مەترسیدار
جاهز للربط — لم يرسل|Ready for integration — not sent|ئامادەی پەیوەستکردن — نەنێردراوە
تم التسليم محليًا|Delivered locally|لە ناوخۆ گەیەنرا
بانتظار الموعد|Scheduled|چاوەڕوانی کات
بانتظار التمويل|Awaiting funding|چاوەڕوانی دابینکردن
معتمد ومحجوز|Approved and reserved|پەسەندکراو و حجزکراو
محجوز|Reserved|حجزکراو
منفذ|Completed|جێبەجێکراو
معكوس|Reversed|پێچەوانەکراوە
قيد المعالجة|Processing|لە جێبەجێکردندایە
غير معروف|Unknown|نەزانراو
صادر|Issued|دەرکراو
فاشل|Failed|شکستخواردوو
ناجح|Successful|سەرکەوتوو
تم التفعيل؛ احفظ أكواد الاستعادة الآن، لن تظهر مرة أخرى|Enabled. Save the recovery codes now; they will not appear again.|چالاک کرا. کۆدەکانی گەڕاندنەوە ئێستا بپارێزە؛ دووبارە نیشان نادرێن.
تم تسجيل الدخول المحلي|Local sign-in successful|چوونەژوورەوەی ناوخۆ سەرکەوتوو بوو
تم تأكيد الإجراء الحساس لمدة خمس دقائق؛ أعد تنفيذ الإجراء|Verified for five minutes; retry the action|بۆ پێنج خولەک پشتڕاستکرایەوە؛ کردارەکە دووبارە بکەرەوە
تم تغيير كلمة المرور وإبطال الجلسات القديمة|Password changed and old sessions revoked|وشەی نهێنی گۆڕدرا و دانیشتنە کۆنەکان ناچالاک کران
تمت استعادة الحساب وإبطال الجلسات القديمة|Account recovered and old sessions revoked|هەژمار گەڕێندرایەوە و دانیشتنە کۆنەکان ناچالاک کران
تم تغيير كلمة المرور؛ سجل الدخول بالكلمة الجديدة|Password changed; sign in with the new password|وشەی نهێنی گۆڕدرا؛ بە وشەی نوێ بچۆ ژوورەوە
أدخل رمز قناة الاختبار المحلية|Enter the local test-channel code|کۆدی کەناڵی تاقیکردنەوەی ناوخۆ بنووسە
تم إنهاء جميع جلسات هذا الحساب|All sessions for this account ended|هەموو دانیشتنەکانی ئەم هەژمارە کۆتاییان هات
الجهاز يدعم مفتاح المرور؛ تسجيله واعتماده ينتظر خادم المصادقة|Device supports passkeys; registration requires an authentication server|ئامێر پشتگیری کلیلی تێپەڕبوون دەکات؛ تۆمارکردن ڕاژەکاری ناساندنی دەوێت
لا يوجد موثق بيومتري متاح على هذا الجهاز|No platform biometric authenticator available|هیچ ناسێنەری بایۆمەتری لەسەر ئەم ئامێرە بەردەست نییە
رمز التحقق غير صحيح|Incorrect verification code|کۆدی پشتڕاستکردنەوە نادروستە
انتهت صلاحية الرمز أو عدد المحاولات|Code expired or attempt limit reached|کۆد بەسەرچوو یان ژمارەی هەوڵەکان تەواو بوو
بيانات الدخول غير صحيحة|Invalid sign-in details|زانیاریی چوونەژوورەوە نادروستە
عنوان الشبكة محظور|Network address blocked|ناونیشانی تۆڕ بلۆککراوە
شبكة VPN محظورة حسب السياسة|VPN blocked by policy|VPN بەپێی سیاسەت بلۆککراوە
الجلسة منتهية؛ افتح أمان حسابي وسجل الدخول|Session expired; open My account security and sign in|دانیشتن بەسەرچوو؛ ئاسایشی هەژمارەکەم بکەرەوە و بچۆ ژوورەوە
يلزم تأكيد إضافي؛ افتح أمان حسابي ثم تأكيد إجراء حساس|Extra verification required; open My account security and verify a sensitive action|پشتڕاستکردنەوەی زیادە پێویستە؛ ئاسایشی هەژمارەکەم بکەرەوە و کردارێکی هەستیار پشتڕاست بکەرەوە
هذه النسخة محلية|This is a local version|ئەم وەشانە ناوخۆییە
رمز استعادة غير صالح أو مستخدم|Invalid or used recovery code|کۆدی گەڕاندنەوە نادروستە یان بەکارهاتووە
السيريال الداخلي|Internal serial|سریاڵی ناوخۆ
سيريال داخلي:|Internal serial:|سریاڵی ناوخۆ:
سيريال المزود:|Provider serial:|سریاڵی دابینکەر:
الانتهاء:|Expiry:|بەسەرچوون:
المرجع:|Reference:|سەرچاوە:
إعادة طباعة • المحاولة|Reprint • attempt|چاپکردنەوە • هەوڵ
بطاقات تجريبية غير صالحة للشحن|Test cards — not valid for recharge|کارتی تاقیکردنەوە — بۆ شەحن دروست نییە
`
    .trim()
    .split("\n");
  for (const row of rows) {
    const [ar, en, ckb] = row.split("|");
    MasalLocale.dictionaries.en[ar] = en;
    MasalLocale.dictionaries.ckb[ar] = ckb;
  }
})();
