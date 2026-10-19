# خادم دنانير

Laravel **13.34.0** وSanctum **4.3.3** وفق `composer.lock`، ويتطلب PHP **8.4.1+**. يتضمن API وقواعد العمل والتفويض والتخزين والمخطط واختبارات النظام. مرجع النشر القائم هو **`20261018-permissions138`**.

## التجهيز المحلي

من جذر المشروع:

```powershell
.\scripts\setup.ps1 -Target backend
.\scripts\build.ps1 -Target backend
```

`setup` يثبت اعتماديات Composer 2.10+ بالإصدارات المقفلة، ولا يعدل قاعدة أو ينشئ حسابًا. `build` يشغّل PHPUnit بإعداد اختبار مستقل وSQLite في الذاكرة، لا بقاعدة بيئة التشغيل. متغير `MASAL_PHP` يحدد PHP إن لم يكن على PATH، و`MASAL_COMPOSER` يحدد `composer.phar` عند الحاجة.

لإنشاء **بيئة محلية جديدة بقاعدة فارغة فقط**:

```powershell
Copy-Item backend/.env.local.example backend/.env
New-Item -ItemType File -Path backend/database/database.sqlite
& .\scripts\backend.ps1 artisan key:generate
& .\scripts\backend.ps1 artisan migrate --seed
& .\scripts\backend.ps1 artisan masal:create-admin --login=admin.system --email=owner@example.com --name=Administrator
npm run backend:serve
```

الأمر الأخير لإنشاء المدير يطلب كلمة المرور وتأكيدها بصورة مخفية. البذر الافتراضي يجهز قاموس الصلاحيات؛ لا ينشئ حسابات أو أرصدة أو بطاقات تجريبية. لا تستعمل هذه الخطوات مع `.env` إنتاج أو قاعدة قائمة، ولا تستبدل `APP_KEY` لنسخة مستخدمة.

## بنية الخادم

- `app/Http/`: المسارات الواردة والتحقق والموارد والوسائط.
- `app/Services/` و`app/Models/`: قواعد العمل والتفويض والاستعلامات والتكاملات.
- `routes/`: مسارات المجالات والمهام المجدولة.
- `database/migrations/`: تاريخ مخطط القاعدة؛ ترتيب الملفات جزء من سلامة الإنشاء والاستعادة.
- `tests/`: اختبارات الوحدة والوظائف، وتجارب محرك قاعدة فعلي منفصلة عند تفعيلها صراحة.

المصادقة بجلسات Cookie وCSRF على أصل كل بوابة. الخادم يحدد البوابة من المضيف المعتمد، ويتحقق من العضوية والإجراء والنطاق وحالة الحساب والأسلاف. المبالغ تعتمد وحدات صحيحة وتمثيلًا عشريًا دقيقًا؛ لا تقرّبها بأرقام JavaScript العائمة. مفاتيح المزودين وعمليات الشراء تبقى في الخادم.

تفويض الموظف لا يحوله إلى مالك الحساب، ولا يسمح بتعديل نفسه أو صاحب النظام أو حساب أقوى أو نطاق أوسع منه. التفاصيل في [الصلاحيات](../docs/PERMISSIONS.md).

## بيئة الإنتاج والصيانة

Laravel و`vendor` خارج جذر الويب في `/home/dananiriq/masal-backend/releases/<release>/`، و`current` يشير إلى النسخة الفعالة. البيئة الخاصة والتخزين الدائم داخل `shared/`. البوابات العامة تحتوي ملفات Vue المبنية وجسرًا إلى `current/public/index.php`؛ لا تعرض ملفات المصدر أو البيئة أو النسخ الخاصة.

`routes/console.php` يسجل العامل `backups:work` ومهام الصيانة المجدولة؛ يلزم التحقق من cron ومن PHP الصحيح على الاستضافة. وجود المهمة في المصدر لا يثبت تشغيلها فعليًا.

النسخ داخل النظام لها تنسيق موقع ومشفر ومعاينة واستعادة محروسة إلى قاعدة منفصلة. الاستعادة لا تعيد شراء العمليات من المزودين، وتحتاج تحققًا من المنصة والمفاتيح والقيود ومنع الكتابات أثناء التحويل. فحوص الاستعادة الأصلية تستعمل قواعد ظل قابلة للإتلاف فقط؛ ليست أمرًا لتجربتها على الإنتاج.

## التحقق

```powershell
& .\scripts\backend.ps1 artisan route:list --path=api
& .\scripts\backend.ps1 vendor/bin/phpunit --filter=PermissionVisibilityTest
```

أداة التشغيل العامة تستخدم البيئة المحلية المختارة، بينما أمر `build -Target backend` يفرض إعداد الاختبار المعزول. اختبارات SQLite لا تغني عن قبول MariaDB/MySQL للمعاملات والمشغلات والتزامن والاستعادة. راجع [التسليم](../docs/HANDOVER.md) و[النشر](../deployment/README.md).
