# إزالة تكرار المحتوى الثابت

`deduplicate_immutable.py` يعمل على Python 3.9 فأحدث. يشارك الملفات المتطابقة بروابط صلبة مع إبقاء جميع روابطها، ولا يحذف أصولًا فريدة أو يتصل بقاعدة البيانات أو ينقل ملفات عبر الشبكة. لا تُكتب ملفات JS/CSS والصور وAPK المشتركة في موضعها لاحقًا؛ أي استبدال معتمد يكون بملف مؤقت ثم استبدال ذري.

أنشئ مجلد عمل خاصًا بصلاحية `0700`، اسمه يبدأ بـ`private-` ويقع مباشرة داخل مجلد حساب الاستضافة `BASE`. جميع المراحل على الخادم تتطلب `--base BASE --stage STAGE --expected-release RELEASE --config-sha256 CONFIG_SHA --env-sha256 ENV_SHA`. البصمات تُستلم من سجل إصدار موثوق، ولا تُطبع محتويات البيئة أو config.

1. `plan` يفحص الملفات ومراجع الواجهات وService Worker ويحفظ `plan.json` وبصمته. لا يغير ملفات الويب.
2. `archive --plan-sha256 PLAN_SHA` يحفظ كل البايتات المتأثرة في `before.tar.gz` وبيان `backup-manifest.json`، ويطبع بصمتيهما.
3. انقل الخطة والأرشيف والبيان إلى الأرشيف الخاص خارج الاستضافة، ثم شغّل محليًا، بما في ذلك Windows:

```text
python deduplicate_immutable.py verify-backup --plan plan.json --plan-sha256 PLAN_SHA --archive before.tar.gz --archive-sha256 ARCHIVE_SHA --backup-manifest backup-manifest.json --backup-manifest-sha256 MANIFEST_SHA --receipt-output desktop-receipt.json
```

4. انقل الإيصال إلى مجلد العمل باسم `desktop-receipt.json` وصلاحية `0600`، ثم استخدم `apply --plan-sha256 PLAN_SHA --receipt-sha256 RECEIPT_SHA` مع خيارات الخادم أعلاه. الأداة تعيد فحص البايتات والهوية والصلاحيات ووقت HTTP قبل الدمج وبعده.
5. `verify --plan-sha256 PLAN_SHA` يعيد فحص النتيجة. `restore --plan-sha256 PLAN_SHA --receipt-sha256 RECEIPT_SHA` يسترجع البايتات والملكية والصلاحيات والتوقيت الأصلي للملفات المتأثرة؛ يرفض الكتابة فوق تغيير أحدث غير مرتبط.

المراجعة والأرشفة والنقل والتطبيق خطوات صريحة. لا جدولة أو نشر تلقائي. تختلف بصمة manifest الحالي وحدها عن إثبات أن ملفًا قديمًا غير مستخدم؛ تبقى مسارات التوافق والأجهزة والتبويبات القديمة محفوظة.
