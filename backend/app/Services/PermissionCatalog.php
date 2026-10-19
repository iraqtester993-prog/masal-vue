<?php

namespace App\Services;

use App\Enums\AccountType;

class PermissionCatalog
{
    public const LABELS = [
        'governorates.view' => 'عرض المحافظات', 'governorates.toggle' => 'تفعيل وتعطيل المحافظات',
        'sources.view' => 'عرض مصادر الطلبيات', 'sources.create' => 'إضافة مصدر طلبية', 'sources.edit' => 'تعديل مصدر الطلبية', 'sources.toggle' => 'تفعيل وتعطيل المصدر',
        'posTypes.view' => 'عرض أنواع نقاط البيع', 'posTypes.create' => 'إضافة نوع نقطة بيع', 'posTypes.edit' => 'تعديل نوع نقطة البيع', 'posTypes.toggle' => 'تفعيل وتعطيل نوع النقطة', 'posTypes.export' => 'تصدير أنواع النقاط',
        'representatives.view' => 'عرض المندوبين', 'representatives.create' => 'إضافة مندوب', 'representatives.edit' => 'تعديل المندوب', 'representatives.toggle' => 'تفعيل وتعطيل المندوب', 'representatives.export' => 'تصدير المندوبين', 'representatives.images' => 'إدارة صور المندوب',
        'pos.representatives' => 'تعيين مندوب نقطة البيع', 'pos.type' => 'تعيين نوع نقطة البيع',
        'wallets.view' => 'عرض المحافظ', 'wallets.deposit' => 'تسجيل إيداع خارجي', 'wallets.transfer' => 'تمويل تابع', 'wallets.request' => 'طلب تمويل', 'wallets.approve' => 'مراجعة طلبات التمويل',
        'ledger.view' => 'عرض قيود المحفظة', 'invoices.view' => 'عرض الفواتير', 'invoices.create' => 'تسجيل فاتورة', 'invoices.settle' => 'تسديد فاتورة',
        'prices.template' => 'تنزيل قالب الأسعار', 'prices.import' => 'استيراد قالب الأسعار',
        'prices.view' => 'عرض الأسعار', 'prices.propose' => 'اقتراح تعديل الأسعار', 'prices.approve' => 'مراجعة تعديل الأسعار', 'prices.reverse' => 'إلغاء تعديل الأسعار',
        'security.fundingRequests' => 'إعداد قواعد طلبات التمويل',
        'wallets.reverse' => 'استرجاع تمويل تابع', 'wallets.bulk' => 'تمويل مجموعة تابعين', 'security.fundingRecovery' => 'إعداد مدة استرجاع التمويل',
        'import.view' => 'عرض الطلبيات والاستيراد', 'import.preview' => 'معاينة وإنشاء طلبية', 'import.approve' => 'مراجعة واعتماد الطلبيات',
        'inventory.view' => 'عرض المخزون', 'inventory.edit' => 'تعديل بطاقة متاحة', 'inventory.quarantine' => 'عزل البطاقات', 'inventory.cancel' => 'إلغاء بطاقات متاحة', 'inventory.restore' => 'إعادة بطاقة للمخزون', 'inventory.export' => 'تصدير المخزون',
        'data.pin' => 'عرض رموز البطاقات', 'claims.view' => 'عرض التالف والمطالبات', 'claims.create' => 'إنشاء مطالبة', 'claims.settle' => 'تسوية مطالبة',
        'inventory.details' => 'عرض تفاصيل دفعة المخزون', 'claims.loss' => 'اعتماد خسارة المطالبة',
        'exports.view' => 'عرض طلبات سحب المخزون', 'exports.request' => 'طلب سحب دفعة المخزون', 'exports.approve' => 'مراجعة سحب المخزون', 'exports.encrypt' => 'تنزيل ملف البطاقات المصرح به',
        'sales.view' => 'عرض سجل المبيعات', 'sales.export' => 'تصدير سجل المبيعات', 'sales.limits' => 'إدارة حدود البيع والطباعة',
        'sell.create' => 'البيع من الحساب', 'sell.receipt' => 'عرض وصل الحساب', 'sell.print' => 'بدء طباعة الوصل', 'sell.result' => 'تسجيل نتيجة الطباعة', 'sell.reprint' => 'طلب إعادة الطباعة', 'sell.deliver' => 'تسجيل تسليم الوصل',
        'exceptions.view' => 'عرض استثناءات الطباعة', 'exceptions.approve' => 'مراجعة استثناء الطباعة', 'security.view' => 'عرض إعدادات الحماية والأمان', 'security.policies' => 'إدارة ضوابط الطباعة',
        'branding.view' => 'عرض تصميم البطاقة', 'branding.receipt' => 'عرض إعدادات الوصل', 'branding.edit' => 'تعديل تصميم الوصل',
        'support.view' => 'عرض الدعم الفني', 'support.create' => 'فتح محادثة دعم', 'support.reply' => 'الرد على محادثة الدعم', 'support.close' => 'إغلاق محادثة الدعم', 'support.escalate' => 'تصعيد محادثة الدعم', 'support.attach' => 'إرفاق ملف للدعم', 'support.broadcast' => 'إرسال رسالة دعم جماعية',
        'notifications.view' => 'عرض الإشعارات', 'notifications.send' => 'إرسال إشعار', 'notifications.attach' => 'إرفاق ملف بالإشعار', 'notifications.translations' => 'إدارة ترجمات الإشعار', 'notifications.export' => 'تصدير الإشعارات',
        'dashboard.view' => 'عرض لوحة التحكم', 'reports.view' => 'عرض التقارير', 'reports.export' => 'تصدير التقارير', 'reports.print' => 'طباعة التقارير',
        'reports.sales' => 'تقارير المبيعات والأرباح', 'reports.inventory' => 'تقارير المخزون', 'reports.wallets' => 'تقارير المحافظ والتمويل', 'reports.network' => 'تقارير شبكة التوزيع', 'reports.prices' => 'تقارير الأسعار', 'reports.claims' => 'تقارير المطالبات', 'reports.support' => 'تقارير الدعم', 'reports.users' => 'تقارير المستخدمين', 'reports.audit' => 'تقارير التدقيق', 'reports.operations' => 'تقارير العمليات', 'data.profit' => 'عرض الأرباح المصرح بها',
        'products.view' => 'عرض المنتجات والفئات', 'products.create' => 'إضافة فئة', 'products.edit' => 'تعديل الفئة',
        'products.toggle' => 'تفعيل وتعطيل الفئة', 'products.order' => 'ترتيب الفئات', 'products.export' => 'تصدير الفئات',
        'products.fields' => 'تخصيص حقول البطاقة', 'products.images' => 'صور الفئة', 'products.availability' => 'المحافظات المسموحة للفئة',
        'providers.view' => 'عرض الشركات والمزودين', 'providers.create' => 'إضافة شركة', 'providers.edit' => 'تعديل الشركة',
        'providers.toggle' => 'تفعيل وتعطيل الشركة', 'providers.export' => 'تصدير الشركات', 'providers.images' => 'شعار الشركة',
        'agents.categories' => 'تحديد فئات التابعين', 'data.cost' => 'عرض وتصدير التكلفة المالية',
        'account.view' => 'عرض الحسابات', 'account.create' => 'إنشاء حساب تابع',
        'account.update' => 'تعديل بيانات التابع', 'account.toggle' => 'تفعيل وإيقاف التابع',
        'account.permissions' => 'صلاحيات التابع', 'account.login' => 'تعديل معرف دخول التابع', 'account.password_reset' => 'إعادة تعيين كلمة مرور التابع',
        'account.attachments.view' => 'عرض صور ومستمسكات الحساب', 'account.attachments.manage' => 'إدارة صور ومستمسكات التابع',
        'pos.device' => 'تعديل بيانات جهاز النقطة', 'pos.location' => 'تعديل عنوان النقطة',
        'staff.view' => 'عرض الموظفين', 'staff.create' => 'إضافة موظف',
        'staff.update' => 'تعديل الموظف', 'staff.toggle' => 'تفعيل وإيقاف الموظف', 'staff.role' => 'تعيين نوع صلاحية الموظف', 'staff.scope' => 'تعيين نطاق الموظف',
        'permission_profile.view' => 'عرض أنواع الصلاحيات', 'permission_profile.create' => 'إضافة نوع صلاحية',
        'permission_profile.update' => 'تعديل نوع صلاحية', 'permission_profile.toggle' => 'تفعيل وإيقاف نوع صلاحية',
        'permission_profile.delete' => 'حذف نوع صلاحية غير مرتبط',
        'agents.archive' => 'أرشفة الحسابات', 'agents.archiveView' => 'عرض أرشيف الحسابات',
        'security.app' => 'إيقاف استخدام التطبيق', 'security.login' => 'إيقاف تسجيل الدخول', 'security.sales' => 'إيقاف البيع', 'security.printing' => 'إيقاف الطباعة', 'security.import' => 'إيقاف الاستيراد',
        'digital.view' => 'عرض الخدمات الرقمية', 'digital.create' => 'تنفيذ خدمة رقمية', 'digital.assign' => 'تخصيص الخدمات الرقمية للتابعين', 'digital.receipt' => 'عرض وصل الخدمة الرقمية', 'digital.export' => 'تصدير الخدمات الرقمية', 'digital.refund' => 'التحقق من استرداد الخدمة الرقمية',
        'integrations.view' => 'عرض الربط مع الشركات', 'integrations.edit' => 'إعداد الربط مع الشركات',
        'company.view' => 'عرض موقع الشركة', 'company.edit' => 'إدارة محتوى موقع الشركة',
        'map.view' => 'عرض خريطة المستخدمين',
        'app.settings' => 'تعديل إعدادات التطبيق', 'printers.manage' => 'إدارة الطابعات المعتمدة',
        'staff.password_reset' => 'إعادة تعيين كلمة مرور الموظف', 'digital.print' => 'طباعة وصل الخدمة الرقمية',
        'integrations.credentials' => 'تغيير مفتاح ربط المزود', 'integrations.sync' => 'مزامنة فئات المزود', 'integrations.balance' => 'تحديث رصيد المزود',
        'topup.view' => 'عرض توزيع Topup', 'topup.assign' => 'توزيع فئات Topup', 'topup.prices' => 'تعديل أسعار Topup',
        'topup.allocate' => 'تخصيص رصيد Topup', 'topup.recover' => 'استرجاع رصيد Topup', 'topup.categories' => 'إدارة فئات Topup المركزية',
        'security.time' => 'إدارة أوقات عمل الموظفين',
        'backup.view' => 'عرض النسخ الاحتياطية', 'backup.create' => 'إنشاء نسخة احتياطية', 'backup.restore' => 'استرجاع نسخة احتياطية',
    ];

    public static function defaultRoleAllows(string $role, string $permission): bool
    {
        if ($role === 'admin') {
            return true;
        }
        if ($role === 'employee') {
            return false;
        }
        if (in_array($permission, self::GRANULAR_PERMISSIONS, true)) {
            return match ($permission) {
                'digital.print' => $role === 'pos',
                'integrations.balance', 'topup.view', 'topup.assign', 'topup.prices' => $role === 'agent',
                default => false,
            };
        }
        if (str_starts_with($permission, 'backup.')) {
            return false;
        }
        if ($permission === 'map.view' || $permission === 'company.edit' || str_starts_with($permission, 'integrations.') || in_array($permission, ['agents.archive', 'agents.archiveView', 'digital.refund'], true)) {
            return false;
        }
        if (str_starts_with($permission, 'digital.')) {
            return in_array($permission, $role === 'pos' ? ['digital.view', 'digital.create', 'digital.receipt', 'digital.export'] : ['digital.view', 'digital.assign', 'digital.export'], true);
        }
        if (str_starts_with($permission, 'governorates.') || str_starts_with($permission, 'posTypes.') || str_starts_with($permission, 'security.') || $permission === 'wallets.deposit') {
            return false;
        }
        if (in_array($permission, ['import.approve', 'claims.settle', 'claims.loss', 'exports.approve'], true)) {
            return false;
        }
        if ($permission === 'support.broadcast' || ($role === 'pos' && in_array($permission, ['notifications.send', 'notifications.attach', 'notifications.translations'], true))) {
            return false;
        }
        if ($role === 'pos' && str_starts_with($permission, 'reports.') && ! in_array($permission, ['reports.view', 'reports.sales', 'reports.wallets', 'reports.export', 'reports.print'], true)) {
            return false;
        }
        if ($role === 'pos' && (str_starts_with($permission, 'sources.') || str_starts_with($permission, 'representatives.') || str_starts_with($permission, 'invoices.') || str_starts_with($permission, 'prices.') || str_starts_with($permission, 'inventory.') || str_starts_with($permission, 'import.') || str_starts_with($permission, 'claims.') || str_starts_with($permission, 'exports.') || str_starts_with($permission, 'branding.') || in_array($permission, ['exceptions.approve', 'sales.limits', 'wallets.approve', 'wallets.transfer', 'wallets.bulk', 'wallets.reverse'], true))) {
            return false;
        }
        if (str_starts_with($permission, 'providers.') || (str_starts_with($permission, 'products.') && ! in_array($permission, ['products.view', 'products.export'], true)) || ($role === 'pos' && in_array($permission, ['agents.categories', 'products.view', 'products.export', 'data.cost'], true))) {
            return false;
        }

        return $permission !== 'account.login'
            && ! ($role === 'pos' && str_starts_with($permission, 'account.') && ! in_array($permission, ['account.view', 'account.attachments.view'], true))
            && ! ($role === 'pos' && str_starts_with($permission, 'pos.'));
    }

    public static function rows(?array $permissions = null, ?AccountType $accountType = null): array
    {
        $permissions ??= array_keys(self::LABELS);

        return collect(self::LABELS)->only($permissions)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label, 'group' => explode('.', $key)[0], 'dependencies' => array_values(array_intersect(self::dependencies($key), $permissions)), 'employee_allowed' => self::employeeAllowed($key, $accountType)])->values()->all();
    }

    public const GRANULAR_PERMISSIONS = ['app.settings', 'printers.manage', 'staff.password_reset', 'digital.print', 'integrations.credentials', 'integrations.sync', 'integrations.balance', 'topup.view', 'topup.assign', 'topup.prices', 'topup.allocate', 'topup.recover', 'topup.categories', 'security.time'];

    public static function dependencies(string $permission): array
    {
        $module = explode('.', $permission)[0];
        if ($permission === 'integrations.balance') {
            return ['digital.view'];
        }
        if ($permission === 'digital.print') {
            return ['digital.view', 'digital.receipt', 'data.pin'];
        }
        if ($module === 'sell') {
            return ['sales.view'];
        }
        if ($permission === 'agents.archive') {
            return ['agents.archiveView', 'account.view'];
        }
        if ($module === 'security' && ! in_array($permission, ['security.view', 'security.fundingRequests', 'security.fundingRecovery'], true)) {
            return ['security.view'];
        }
        if (! str_ends_with($permission, '.view') && in_array($module, ['staff', 'permission_profile', 'products', 'providers', 'governorates', 'sources', 'posTypes', 'representatives', 'wallets', 'ledger', 'invoices', 'prices', 'import', 'inventory', 'claims', 'exports', 'sales', 'exceptions', 'branding', 'support', 'notifications', 'reports', 'digital', 'integrations', 'company', 'backup', 'topup'], true)) {
            return [$module.'.view'];
        }

        return [];
    }

    public static function employeeAllowed(string $permission, ?AccountType $accountType = null): bool
    {
        if ($accountType === AccountType::System) {
            return true;
        }

        return ! str_starts_with($permission, 'backup.') && ! str_starts_with($permission, 'governorates.') && ! in_array($permission, ['account.create', 'account.login', 'account.password_reset'], true);
    }
}
