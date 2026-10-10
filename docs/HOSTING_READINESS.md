# جاهزية الاستضافة — dananir-iq.com

تحديث 2026-10-06: العمل الفعّال انتقل إلى admin/agents/pos.dananir-iq.com، بالباك `20261006-final-domains-01` وقاعدة `dananiriq_masalprod`. نجحت مقارنة 30 جدولًا و138 فحص HTTP و9 فحوص تعطيل الكاتب القديم ومطابقة 174 ملفًا؛ [دليل القطع وحدوده](FINAL_DOMAIN_CUTOVER.md). سجلات الأساس والتجربة أدناه تاريخية، وبيئة التجربة محفوظة ومجمدة. جذر النطاق الرئيسي لم يُستبدل، وبقية وحدات الأعمال والموارد والمهام والتكاملات والأحمال تحتاج استكمالًا.

تاريخ التحديث: 2026-10-06.

## المعلوم

- المستخدم حدد dananir-iq.com وذكر أن الاستضافة cPanel، وقدم بيانات حساب عبر المحادثة.
- لا تحفظ كلمات مرور أو مفاتيح خاصة أو أسرار DB/API في هذه الوثيقة أو Git.
- تم الوصول إلى جلسة cPanel التي فتحها المستخدم في 2026-10-06، واعتماد المفتاح، واختبار SSH الخارجي بنجاح مع StrictHostKeyChecking=yes وBatchMode=yes. فُعّل لاحقًا إصدار staging واجتازت بيئة PHP واعتماديات Laravel فحص المنصة؛ حالة التحقق التفصيلية أدناه.
- عنوان الدخول الدائم: `https://dananir-iq.com:2083/`. لا تحفظ روابط cpsess أو رموز الجلسة؛ تنتهي صلاحيتها وتخص جلسة المصادقة.
- المستخدم المؤكد في اللوحة: `dananiriq`، ومجلد الحساب `/home/dananiriq`، وShared IP المعروض `135.125.109.190`. اتصال SSH المجرب: `dananiriq@dananir-iq.com` على المنفذ `22`.
- نسخة المشروع المحلية محفوظة؛ ليست نسخة من ملفات السيرفر أو قاعدة بياناته.

## الوصول والمتطلبات

| البند | الحالة | دليل القبول |
|---|---|---|
| رابط cPanel الفعلي | مؤكد: https://dananir-iq.com:2083/ | جلسة المستخدم المفتوحة وصلت إلى Tools |
| SSH hostname/IP والمنفذ | مؤكد بالتجربة: dananir-iq.com:22 | ssh-keyscan ثم اتصال مفتاح مع فحص صارم للبصمة |
| Shell access | مؤكد | Terminal وSSH نفذا أوامر قراءة PHP |
| مفتاح نشر محلي واعتماد public key | مكتمل | رسالة has been authorized واتصال خارجي ناجح دون كلمة مرور |
| PHP وإضافات Laravel | CLI وweb staging PHP 8.5.11؛ المشروع المقفل يحتاج PHP >= 8.4.1؛ فحص المنصة نجح | probe HTTPS للويب و`composer check-platform-reqs` على إصدار staging |
| Composer/طريقة تثبيت vendor | ثبت Composer 2.10.3 محليًا للحساب خارج الويب في 2026-10-06 | `php /home/dananiriq/.local/bin/composer --version`؛ تحقق SHA-384 للـinstaller الرسمي قبل تشغيله |
| DB الإصدار والاتصال | قاعدة staging مستقلة أنشئت، وPDO تحقق من MariaDB Server 10.11.19 | اتصال القاعدة المخصصة؛ كلمة المرور خاصة ولم تحفظ في المصدر أو الوثائق |
| document roots والباك الخاص | cPanel يشترط public_html للبوابات؛ Laravel الخاص وجسر البوابات فعلا | csrf أعاد 204 وme أعاد JSON 401 للبوابات الثلاث بعد تفعيل الإصدار |
| SSL والنطاقات الفرعية | نطاقات staging الثلاثة أنشئت، وDNS وTLS تحققا؛ نطاقات الإنتاج لم تنشأ | تحقق Python TLS صارم للمضيف والسلسلة لكل نطاق، دون تعطيل التحقق |
| Cron والـqueues | غير متحقق | مهمة مجربة دون تداخل أو تراكم |
| موارد الذاكرة والمعالج والرفع | PHP web: ذاكرة 128M ورفع 2M وPOST 8M؛ أحمال CPU/RAM الفعلية غير متحققة | probe HTTPS للحدود؛ بقي قياس الأحمال وحدود الخطة |
| SMTP والاتصال بالمزودين | غير متحقق | بريد اختبار وطلبات sandbox |
| النسخ والاستعادة | نسخة public_html أنشئت ونزلت وتطابق SHA-256؛ لا DB في حساب cPanel وقت الحصر | tar قائمة محتوى ناجحة؛ بقي اختبار الاستعادة الكامل عند وجود تطبيق وDB |

## ترتيب فحص الاستضافة

1. الحصول على رابط cPanel وعنوان SSH والمنفذ؛ تأكيد تفعيل Shell.
2. توليد مفتاح مستقل خارج المشروع، اعتماد العام فقط، والتحقق من بصمة السيرفر.
3. فحص قراءة فقط للموارد والمسارات والأدوات والموقع الحالي.
4. أخذ نسخة ملفات وقاعدة بيانات الاستضافة قبل أي تعديل عليها.
5. تجهيز staging ونطاق API وقاعدة اختبار بعد التأكد من إمكانات الحساب.
6. اختيار إصدار Laravel وطريقة queues والنشر وفق النتائج الفعلية.

في الفحص الأول كانت روابط staging/API مجرد اقتراح ولم يكن DNS قد عُدل. أضيفت لاحقًا نطاقات التجربة الثلاثة الموضحة أدناه. أسماء نطاقات الإنتاج ومقترح API المشترك لم تنشأ؛ التصميم الحالي يستخدم API على مضيف كل بوابة نفسه.

## مفتاح النشر المحلي

- أنشئ في 2026-10-06 بناءً على طلب المستخدم إنشاء مفتاح جديد للاستضافة الحالية.
- المفتاح العام: `C:\Users\PRO\.ssh\masal-dananir-deploy-20261006.pub`.
- المفتاح الخاص خارج المشروع تحت .ssh؛ لم يحفظ محتواه في الوثائق أو Git. لا passphrase لتمكين النشر المحلي الآلي، وصلاحيات ملفه مقيدة بحساب Windows الحالي.
- النوع RSA، الحجم 4096، البصمة `SHA256:bFbPWoFXBdHERBG4/7pBdv+WMo4QKbR+GH3WKVFpFps`.
- تم Import Key ثم Authorize للمفتاح العام في cPanel، وظهرت رسالة نجاح باسم المفتاح في 2026-10-06. لم يرفع المفتاح الخاص إلى السيرفر.
- إثبات مرئي خارج المشروع: `C:\Users\PRO\Desktop\masal-backups\ssh-authorized-20261006.png`.
- تمت قراءة مفتاح المضيف العام وبصمته من جلسة cPanel الموثوقة ومطابقته مع مفتاح اتصال SSH الخارجي. بصمة ED25519: `SHA256:eP0kcb2Bo4mhpyZpLfrnjHDIhgPZYkLWS1xDy2PSPpw`.
- known-hosts مخصص خارج المشروع: `C:\Users\PRO\.ssh\masal-dananir-known-hosts`.
- اختبار الاتصال بالمفتاح وStrictHostKeyChecking=yes وBatchMode=yes أعاد php -v وخروجًا 0.
- قيم PHP CLI المقروءة: memory_limit=128M، max_execution_time=0، upload_max_filesize=2M. لا يفترض أنها تطابق إعدادات PHP للويب.
- الخطوة التالية: نسخ ملفات وبيانات السيرفر، فحص web PHP/DB/الموارد، تحديد Composer وطريقة النشر، ثم staging.

## فحص واستعادة الملفات قبل الانتقال — 2026-10-06

- public_html يحتوي .htaccess وphp.ini و.user.ini ومجلد .well-known/acme-challenge؛ لم يظهر تطبيق منشور في الحصر.
- النسخة أنشئت من ملفات السيرفر الموجودة، ولم ترفع نسخة المشروع القديمة إلى الموقع.
- مسار السيرفر: `/home/dananiriq/masal-backups/public-html-before-migration-20261006.tar.gz` خارج public_html، ومجلد النسخ بصلاحية 700.
- النسخة المحلية: `C:\Users\PRO\Desktop\masal-backups\public-html-before-migration-20261006.tar.gz`.
- الحجم 766 بايت، SHA-256: `3e1644204a010d70d3d33bb6a30c8a4761038f724084f4a333fcf2db02a2db6b`؛ تطابقت نسخة السيرفر والنسخة المنزلة، ونجح tar -tzf.
- النطاق: ملفات public_html فقط. ليست full cPanel account backup ولا تشمل البريد أو إعدادات DNS أو ملفات الحساب الأخرى.
- Mysql list_databases أعاد status=1 وdata=[]؛ لذلك لم ينشأ database dump. لا يدل هذا على غياب قواعد خارج حساب cPanel.
- LangPHP php_get_vhost_versions يؤكد أن الدومين يستخدم ea-php85 وPHP-FPM، وdocumentroot الحالي `/home/dananiriq/public_html`. لا يوجد probe HTTP لتأكيد إصدار runtime الدقيق أو الإضافات والحدود للويب حتى الآن.
- mysql --version يثبت إصدار العميل MariaDB 10.11.19، لا إصدار محرك السيرفر المتصل.
- Composer غير موجود في PATH ولا المسارات `/opt/cpanel/composer/bin/composer` و`/usr/local/bin/composer` و`/usr/bin/composer`؛ لم يثبت غيابه من جميع مسارات السيرفر ولم يثبت أي برنامج جديد.
- التالي: حسم قواعد الحسابات، إتمام جرد الإجراءات، فحص بيئة الويب وحدود الخطة، واختيار وسيلة Composer وتجهيز staging. لا يزال إنشاء Laravel أو حذف المصدر المحلي غير منفذ.

## تجهيز نطاقات التجربة وComposer — 2026-10-06

هذه نتائج تجهيز الاستضافة بعد بناء أساس المشروع محليًا. بدأت بالنطاقات وComposer، ثم أضيفت قاعدة staging وفحوص PHP للويب الموضحة في القسم اللاحق. الجزء السابق سجل الفحص الأول؛ لا يعني أن حالات «باقي/غير منفذ» التاريخية ما زالت كلها قائمة.

### نطاقات التجربة الفعلية

| البوابة | النطاق المنشأ | document root المؤكد من UAPI |
|---|---|---|
| مدير النظام | `admin-test.dananir-iq.com` | `/home/dananiriq/public_html/masal-staging/portals/admin` |
| الوكلاء | `agents-test.dananir-iq.com` | `/home/dananiriq/public_html/masal-staging/portals/agents` |
| نقاط البيع | `pos-test.dananir-iq.com` | `/home/dananiriq/public_html/masal-staging/portals/pos` |

- أنشئت عبر `SubDomain addsubdomain` بعد التأكد من عدم وجود نطاقات فرعية للحساب. أعادت العمليات الثلاث `status=1`.
- جرى طلب root خارج `public_html` للنطاق الأول، لكن cPanel طبّعه تلقائيًا إلى المسار العام المذكور. محاولة `SubDomain changedocroot` إلى `masal-staging/portals/admin` أعادت `status=0` والرسالة: `The document root must begin with “public_html/”.`
- لذلك اعتمدت مجلدات بناء عامة منفصلة للبوابات. مصدر Laravel و`.env` و`vendor` يجب أن تبقى في `/home/dananiriq/masal-staging/backend` خارج document roots، ولا تُنسخ إلى المجلدات العامة.
- أنشئ مجلد `/home/dananiriq/masal-staging/portals/admin` فارغًا لمحاولة تغيير root، ولم يُنقل إليه محتوى. لا يوجه إليه نطاق فعلي.
- بقي document root للدومين الرئيسي `/home/dananiriq/public_html` كما هو. لم يستبدل ملف قائم فيه؛ أنشأت cPanel مجلدات staging الجديدة داخله.
- `Resolve-DnsName` أكد A للنطاقات الثلاثة إلى `135.125.109.190`؛ أسماء خوادم DNS المعادة `ns5.iraqtechno.com` و`ns6.iraqtechno.com`.
- `SSL start_autossl_check` أعاد `status=1`. بعدها تحقق Python عبر `ssl.create_default_context()` من سلسلة الشهادة واسم المضيف للنطاقات الثلاثة؛ لم يستخدم `-k` أو تعطيل التحقق.
- شهادات Let's Encrypt، جهة الإصدار `YR1`، وتاريخ انتهاء التحقق الحالي 2027-01-04 بتوقيت بغداد. قد تتغير الشهادات عند التجديد؛ لا تعتمد الوثيقة بدل التحقق الدوري.
- النطاقان الوكلاء ونقاط البيع يستخدمان شهادة تغطي `*.dananir-iq.com` مع aliases الخاصة بهما؛ نطاق المدير يستخدم شهادة باسمه. يظل عزل الجلسات والبيانات قائمًا على المضيف وصلاحيات Laravel، وليس اختلاف الشهادة.

المراجع الرسمية المستخدمة للتحقق من العمليات: [إنشاء subdomain](https://api.docs.cpanel.net/specifications/cpanel.openapi/subdomain/subdomain-addsubdomain)، [AutoSSL للحساب](https://api.docs.cpanel.net/specifications/cpanel.openapi/auto-generated-ssl-certificates/ssl-start_autossl_check). تحققت معاملات `addsubdomain/changedocroot` أيضًا من كود UAPI المثبت على السيرفر قبل التنفيذ.

### Composer الخاص بحساب الاستضافة

- كان غير موجود في PATH، ولم يوجد ملف الهدف قبل التثبيت.
- الموقع الحالي: `/home/dananiriq/.local/bin/composer`، خارج الويب، دون تغيير إعدادات Composer العامة للسيرفر أو PATH للحساب.
- الإصدار المؤكد: `2.10.3`؛ التشغيل: `php /home/dananiriq/.local/bin/composer`.
- تنزيل installer من `https://getcomposer.org/installer` والتوقيع من `https://composer.github.io/installer.sig` باستخدام curl مع تحقق HTTPS وTLS.
- SHA-384 للملف تطابق التوقيع الرسمي قبل تشغيله: `c8b085408188070d5f52bcfe4ecfbee5f727afa458b2573b8eaaf77b3419b0bf2768dc67c86944da1544f06fa544fd47`.
- استخدم `php -d allow_url_fopen=1` للـinstaller فقط؛ لم تتغير `php.ini` أو إعدادات PHP الدائمة.
- ملفات تدقيق التثبيت محفوظة خارج الويب في `/home/dananiriq/.local/composer-install.3R0AVEAE`؛ ليست أسرارًا أو بيانات التطبيق.
- لا يعني تثبيت Composer نجاح `composer install` أو فحص اعتماديات Laravel على المنصة؛ ينفذ ذلك عند تجهيز إصدار الباك.

طريقة التحقق قبل التنفيذ تتبع [التنزيل الرسمي لـComposer](https://getcomposer.org/download/). لا يعاد استخدام digest ثابت دون تنزيل توقيع إصدار installer الحالي والتحقق منه.

### موارد الفحص قبل رفع التطبيق

- `Quota get_quota_info`: الاستخدام 1.36 MB و182 inode وقت الفحص؛ قيم حدود MB/inodes صفر. `ResourceUsage get_usages` أعاد `maximum=null` للقرص وsubdomains وقواعد البيانات، و`maximum=0` للـaddon domains والـaliases. لذلك استُخدمت subdomains، ولا يفترض أن الأرقام تؤكد موارد CPU/RAM غير محدودة.
- `Features has_feature name=subdomains`: `status=1,data=1`.
- `LangPHP php_get_vhost_versions` للدومين الأساسي: `ea-php85`، PHP-FPM فعال، `pm_max_children=20` و`pm_max_requests=50` وidle timeout ثلاث ثوان. النطاقات الجديدة ترث `ea-php85` وفق domains_data؛ بقي اختبار إعدادات PHP الفعلية للويب.
- CLI: PHP 8.5.11، `memory_limit=128M` و`max_execution_time=0` و`upload_max_filesize=2M` و`post_max_size=8M`، و`open_basedir` فارغ و`allow_url_fopen` غير فعال. لا تُساوى هذه القيم بقيم الويب.
- الحد الفعلي لنسخة المشروع الحالية **PHP >= 8.4.1** بسبب اعتماديات Symfony 8.1 المقفلة، رغم أن الحد العام لـLaravel 13 هو 8.3. CLI 8.5.11 يحقق الحد؛ يلزم تأكيده للويب وفحص `check-platform-reqs` قبل إعلان التوافق.
- CLI extensions تشمل `pdo_mysql/pdo_sqlite/mbstring/bcmath/curl/fileinfo/openssl/intl/xml/dom/zip/Phar` وغيرها.
- `phpopenbasedirprotect=1` في معلومات vhosts؛ وصول الجسر إلى Laravel الخاص يجب اختباره عبر HTTPS بعد نشر ملف الجسر، لا استنتاجه من open_basedir الفارغ للـCLI.
- Mysql list_databases كان فارغًا في فحص ما قبل النشر. لم يُنشأ DB أو مستخدم DB أو `.env` أو حساب تطبيق ضمن تجهيز النطاقات وComposer.

### ما تبقى قبل إعلان نجاح النشر

- [x] قاعدة بيانات staging ومستخدمها المحدود، واتصال PDO وLaravel بمحرك السيرفر الفعلي.
- [x] backend خارج الويب واعتماديات الإنتاج وفحص المنصة ومفتاح البيئة.
- [x] PHP للويب والجسر الخاص، ومسارات الدخول وAPI الأساسية و404 للـassets على الاستضافة الفعلية؛ اختبارات عزل الجلسات موثقة بعد اكتمالها.
- [x] رفع حزم Vue الثلاث وربط المضيفين بإعدادات البوابات؛ `/sanctum/csrf-cookie` و`/auth/me` استجابا على النطاقات الثلاثة.
- [x] دخول المدير وعزل جلسته ومنع بياناته في بوابة الوكلاء وCSRF/logout على HTTPS، وفحوص الحسابات والمعاملات على MariaDB كما يلي.
- [ ] اختبار الاستعادة والرجوع ومراقبة الأخطاء.

تسجل نتائج هذه البنود بعد تنفيذها؛ إنشاء النطاقات ونجاح TLS ليس إعلانًا أن التطبيق يعمل عليها.

## قاعدة staging وPHP للويب — 2026-10-06

هذه نتائج فحص UAPI وprobe HTTPS قبل تفعيل التطبيق. نتائج تفعيل الإصدار وmigrations تأتي في القسم اللاحق.

- أنشئت قاعدة مستقلة `dananiriq_masalstage` ومستخدمها `dananiriq_masalstg`؛ كلمة المرور عشوائية وخاصة، ولم تُحفظ في مصدر المشروع أو مخرجات الأدوات أو هذه الوثيقة.
- اتصال PDO من الحساب بقاعدة staging نجح، و`SELECT VERSION()` أكد **MariaDB Server 10.11.19**؛ هذا تحقق من المحرك الفعلي وليس إصدار عميل CLI فقط.
- ملف البيئة الدائم موجود في `/home/dananiriq/masal-staging/backend/shared/.env` بصلاحية `600`، ومجلد `shared` بصلاحية `700`، خارج document roots.
- نموذج نشر Laravel الجاري: إصدارات خاصة خارج الويب، ورابط `current` للإصدار الفعال، وبيئة وتخزين مشتركان دائمان بين الإصدارات. تثبيت المسارات الفعلية واختبارها يوثقان بعد تفعيل الإصدار.
- probe مؤقت على بوابة المدير عبر HTTPS أكد PHP للويب **8.5.11**، أعلى من الحد المقفل للمشروع **8.4.1**.
- probe أكد توفر `pdo_mysql` و`mbstring` و`openssl` و`fileinfo` للويب، وإمكان قراءة ملف البيئة الخاص من عملية PHP-FPM خارج المجلد العام؛ لم يُرسل محتوى البيئة أو أسرارها إلى HTTP.
- حدود الويب المقروءة: `memory_limit=128M` و`upload_max_filesize=2M` و`post_max_size=8M`.
- ملف probe المؤقت أزيل بعد تفعيل إصدار staging، وكذلك ملفات bootstrap/repair المؤقتة؛ لا توجد واجهة تشخيص مقصودة ضمن الحزمة الدائمة.

بعد هذا الفحص جرى تثبيت الاعتماديات وتهيئة قاعدة البيانات وتفعيل البوابات كما يوضح القسم التالي؛ فحص PHP/DB وحده ليس دليل قبول لاختبارات المصادقة والعزل.

## تفعيل إصدار staging الأول — 2026-10-06

- فُعّل إصدار Laravel الأول عبر رابط `current`، مع المصدر والاعتماديات خارج المجلدات العامة وملف البيئة والتخزين الدائمين تحت `shared`.
- المسار الفعلي: `/home/dananiriq/masal-staging/backend/current` يشير إلى `/home/dananiriq/masal-staging/backend/releases/20261006-foundation-01`. البيئة في `backend/shared/.env` والتخزين في `backend/shared/storage` تحت جذر staging الخاص نفسه. كلمة «private» وصف للعزل خارج الويب، وليست اسم مجلد إضافي في المسار.
- ملفات الجسر العامة تحت `/home/dananiriq/public_html/masal-staging/portals/{admin,agents,pos}/masal-api.php`، وكلها تمرر إلى `/home/dananiriq/masal-staging/backend/current/public/index.php`.
- `composer install --no-dev` ثبت 77 حزمة للإنتاج، ونجح فحص المنصة على PHP 8.5.11.
- migrations وتهيئة الأدوار والصلاحيات و`optimize` نجحت، وأنشئ حساب مدير staging بطريقة CLI مخفية الإدخال. لا تحفظ بيانات الدخول في هذه الوثائق أو Git.
- محرك MariaDB الافتراضي لدى الاستضافة كان **MyISAM**. فشل DDL الأول برقم `1071` في المفتاح الأساسي لجدول `password_reset_tokens`. عدل إعداد mysql/mariadb في المصدر لاستخدام **InnoDB** صراحة، بما يناسب المعاملات والأقفال المطلوبة.
- قبل الإصلاح تحقق منفذ النشر أن جدولَي `users` و`migrations` داخل قاعدة staging الجديدة فارغان (`COUNT=0`). أزيل الجدولان الفارغان اللذان خلّفهما الفشل فقط، ثم أعيدت migrations بنجاح؛ لم تكن هناك بيانات مستخدمين عند الإصلاح. لا يمثل ذلك إجراءً مسموحًا على جداول تشغيل تحتوي بيانات.
- فحص HTTP للنطاقات الثلاثة بعد التفعيل: صفحة `/login` أعادت **200**، و`/sanctum/csrf-cookie` أعاد **204**، و`/api/v1/auth/me` دون جلسة أعاد **401 JSON**، وasset مفقود أعاد **404**.
- أزيل probe PHP وملفات bootstrap/repair المؤقتة بعد استخدامها.

هذه النتائج تثبت تفعيل الواجهة والـAPI الأولي. نتائج تحقق قاعدة البيانات والدخول الفعلي وعزل جلسة المدير على HTTPS موثقة في الأقسام اللاحقة.

## تحقق MariaDB والدخول الفعلي — 2026-10-06

- اختبار staging على المحرك الفعلي اجتاز **16 فحصًا**، وأعاد `rolled_back=true`؛ زالت جميع fixtures التي أنشأها الاختبار. ليس هذا ادعاءً باستعادة نسخة احتياطية كاملة.
- شملت الفحوص InnoDB، وصحة `parent_id` وعمق علاقات closure، وإنشاء POS تحت أي مستوى وكيل، ونطاق الوكيل، وPOS لنفسها فقط، و404 للشبكة الأجنبية، وسجل التدقيق، وإلغاء إنشاء مكرر داخل المعاملة المتداخلة دون بقايا حسابات.
- تسجيل الدخول الحقيقي لمدير staging على `https://admin-test.dananir-iq.com` نجح عبر HTTPS، ونشرت آخر حزمة الواجهة المعتمدة.
- سلوك Apache الفعلي: `/login` أعاد **200**، و`/sanctum/csrf-cookie` أعاد **204**، و`/api/v1/auth/me` دون جلسة أعاد **401**. الوصول المباشر إلى `masal-api.php` أعاد **404**، وكذلك asset مفقود ومسار API خارج المسارات الموجهة. طلب `.env` أعاد **403**.
- ملف runtime probe المؤقت أزيل. أسرار البيئة وبيانات دخول المدير لم تدخل حزمة Vue أو Git أو هذه الوثيقة.
- مساعد mysql smoke المؤقت خارج الويب أزيل بعد اكتمال الفحوص؛ لا تُبقي هذه المرحلة واجهة تشخيص أو fixtures للاختبار.

نجاح rollback لمعالجة بيانات اختبار يثبت ذرية المعاملة؛ لا يعوض اختبار الاستعادة من النسخة الاحتياطية. نتائج عزل HTTPS موضحة في القسم التالي.

## تحقق المتصفح والجلسات على HTTPS — 2026-10-06

- تسجيل دخول المدير الفعلي على `admin-test` وصل إلى `/accounts` وعرض حساب النظام وزر إنشاء التابع في المتصفح.
- أثناء بقاء المدير مسجلًا، فتح `/accounts` في `agents-test` و`pos-test` أعاد المتصفح إلى `/login`؛ جلسة المدير لم تنتقل إلى المضيفين الآخرين.
- استخدام بيانات مدير staging الفعلية في بوابة الوكلاء رُفض برسالة عامة، ثم مسحت بيانات النموذج. هذا فحص لرفض البوابة الخاطئة دون كشف سبب خاص بهوية الحساب.
- زر خروج المدير أعاد الواجهة إلى صفحة الدخول، ثم نجحت إعادة دخوله بالبيانات نفسها ووصل إلى لوحة الحساب الصحيحة.
- النطاقات الثلاثة أعادت **204** لتهيئة CSRF. أسماء الجلسات `masal_admin_session` و`masal_agents_session` و`masal_pos_session` مستقلة، ولكل منها `Secure` و`HttpOnly` و`SameSite=Lax`، ودون صفة `Domain`. كوكي `XSRF-TOKEN` يستخدم `Secure` و`SameSite=Lax` ويظل خاصًا بالمضيف.
- `/api/v1/auth/me` دون جلسة أعاد **401 JSON** لكل مضيف حتى عند إرسال `X-Masal-Portal: admin` من العميل؛ ترويسة العميل لا تمنحه مصادقة.
- طلب `POST /api/v1/auth/login` على بوابة المدير بجسم JSON فارغ ودون رمز CSRF، مع ترويستي `Accept: application/json` و`Origin: https://admin-test.dananir-iq.com`، أعاد **419 JSON**. هذا سجل الطلب المجرب ونتيجته، وليس وصفًا لجميع الحالات التي تقبلها حماية Laravel.

الفحص الحي أثبت دخول المدير ومنع انتقال جلسته ورفض بياناته في بوابة أخرى. لا يعني ذلك تنفيذ جميع العمليات التجارية أو اختبار دخولين صحيحين متزامنين لمستخدمَي مدير ووكيل. استعادة نسخة DB، والرجوع إلى إصدار سابق، وكامل تدفقات الأعمال ما زالت غير مجربة ضمن هذه المرحلة.
