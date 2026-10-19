export const profileFields = [
  "city",
  "phone",
  "support",
  "color",
  "owner_name",
  "address",
  "serial",
  "device_model",
  "app_version",
  "notes",
];
export const accountPhonePattern = '07[78][0-9]{8}';
export function normalizeAccountPhoneDigits(value) {
  return String(value ?? '').replace(/[٠-٩۰-۹]/g, digit => String('٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹'.indexOf(digit) % 10));
}
export function unchangedAccountPhone(value, original) {
  if (original == null || String(value ?? '') !== String(original)) return false;
  const local = normalizeAccountPhoneDigits(original).replace(/[\s()+-]/gu, '').replace(/^(?:00964|964|0)/, '');
  return /^7[78][0-9]{8}$/.test(local);
}
export function accountPhoneError(value, original) {
  return unchangedAccountPhone(value, original) || new RegExp(`^${accountPhonePattern}$`).test(normalizeAccountPhoneDigits(value))
    ? '' : 'أدخل رقم هاتف من 11 رقمًا يبدأ بـ 077 أو 078 فقط.';
}
export function accountDraft(account = {}) {
  return {...Object.fromEntries(
    ["name", ...profileFields].map((key) => [
      key,
      account[key] || (key === "color" ? "#168baf" : ""),
    ]),
  ),device_lock_enabled:account.device_lock_enabled??true};
}
export function accountPayload(
  draft,
  {
    account,
    parent,
    type,
    email,
    password,
    confirmation,
    login, loginReason, canLogin = false,
    canDevice = true,
    canLocation = true,
    reference = null, canType = true, canRepresentatives = true,
  } = {},
) {
  const fields = [
    "name",
    "city",
    "phone",
    "notes",
    ...(type === "pos"
      ? ["owner_name", "address", "serial", "device_model", "app_version"]
      : ["support", "color"]),
  ];
  const payload = Object.fromEntries(
    fields.map((key) => [key, String(draft[key] ?? "").trim()]),
  );
  payload.phone = unchangedAccountPhone(draft.phone, account?.phone) ? String(account.phone) : normalizeAccountPhoneDigits(payload.phone);
  if(type==='pos')payload.device_lock_enabled=draft.device_lock_enabled??true;
  if (type === 'pos' && reference) {
    if (canType) payload.pos_type_id = reference.pos_type_id ? Number(reference.pos_type_id) : null;
    if (canRepresentatives) payload.representative_ids = [...reference.representative_ids];
  }
  if (account) {
    if (type === "pos" && !canDevice)
      for (const key of ["serial", "device_lock_enabled", "device_model", "app_version"])
        delete payload[key];
    if (type === "pos" && !canLocation)
      for (const key of ["city", "address"]) delete payload[key];
    if (canLogin && login !== undefined && String(login).trim().toLowerCase() !== String(account.owner_user?.login || account.owner_user?.email || '').trim().toLowerCase()) {
      payload.login = String(login).trim().toLowerCase();
      payload.reason = String(loginReason || '').trim();
    }
    return { version: account.version, ...payload };
  }
  return {
    ...payload,
    type,
    parent_id: parent.id,
    user: {
      email: email.trim().toLowerCase(),
      password,
      password_confirmation: confirmation,
    },
  };
}
export function displayLogin(user) {
  return user?.login || user?.email || "—";
}
export async function initialAccountContext(identity, api, signal) {
  const managed=identity.capabilities?.management_account;
  if(Number.isSafeInteger(managed?.id)&&managed.id>0)return managed;
  if(Object.hasOwn(identity.capabilities||{},'management_account_id'))throw new Error('لا يوجد حساب متاح للإدارة حاليًا.');
  if (identity.membership?.kind !== "employee") return identity.account;
  if(Array.isArray(identity.permissions)&&!identity.permissions.includes('account.view'))throw new Error('لا يوجد حساب متاح للإدارة حاليًا.');
  const page = await api.accounts({ page: 1, per_page: 1 }, signal);
  if (!page.data?.length)
    throw new Error("لا توجد حسابات متاحة ضمن نطاقك الحالي.");
  return page.data[0];
}
export function canManageAttachments(identity, can, account) {
  return (
    !!identity &&
    account.type !== "system" &&
    account.id !== identity.account.id &&
    can("account.attachments.manage") &&
    can("account.update")
  );
}
export function selectedPermissions(catalog, selected) {
  const keys = new Set(selected);
  return catalog.filter((item) => keys.has(item.key)).map((item) => item.key);
}
export function grantablePermissions(catalog, authority, can, profile = false) {
  const limits = new Set(authority || []);
  return catalog.filter((item) => (!profile || item.employee_allowed !== false) && limits.has(item.key) && (can(item.key) || (can('digital.assign') && ['digital.create', 'digital.receipt', 'digital.print'].includes(item.key))));
}
export function permissionDependencies(key, profile = false, catalog = []) {
  const row = catalog.find(item => item.key === key);
  if (Array.isArray(row?.dependencies)) return [...new Set(row.dependencies)];
  const module = key.split(".")[0];
  if (key === 'digital.print') return [...(profile ? ['account.view'] : []), 'digital.view','digital.receipt','data.pin'];
  if (key === 'integrations.balance') return [...(profile ? ['account.view'] : []), 'digital.view'];
  return [
    ...(module === 'sell' ? ['sales.view'] : []),
    ...(key === 'agents.archive' ? ['agents.archiveView', 'account.view'] : []),
    ...(profile && key !== "account.view" ? ["account.view"] : []),
    ...(["staff", "permission_profile", "products", "providers", "governorates", "sources", "posTypes", "representatives", "wallets", "ledger", "invoices", "prices", "import", "inventory", "claims", "exports", "sales", "exceptions", "branding", "support", "notifications", "reports", "digital", "integrations", "company", "backup", "topup", ...(key.startsWith('security.') && !['security.fundingRequests','security.fundingRecovery'].includes(key) ? ['security'] : [])].includes(
      module,
    ) && key !== `${module}.view`
      ? [`${module}.view`]
      : []),
  ];
}
export function changePermission(
  selected,
  key,
  checked,
  available,
  profile = false,
  catalog = [],
) {
  const allowed = new Set(available);
  if (!allowed.has(key)) return [...selected];
  const dependencies = permissionDependencies(key, profile, catalog);
  if (checked) {
    if (
      dependencies.some(
        (dependency) =>
          !selected.includes(dependency) && !allowed.has(dependency),
      )
    )
      return [...selected];
    return [...new Set([...selected, ...dependencies, key])];
  }
  const dependent = selected.filter((entry) =>
    permissionDependencies(entry, profile, catalog).includes(key),
  );
  if (dependent.some((entry) => !allowed.has(entry))) return [...selected];
  return selected.filter(
    (entry) => entry !== key && !dependent.includes(entry),
  );
}
export function permissionGroups(catalog, search = "") {
  const query = search.trim().toLowerCase();
  const groups = new Map();
  for (const item of catalog) {
    if (
      query &&
      !`${item.label} ${item.key} ${item.group}`.toLowerCase().includes(query)
    )
      continue;
    const group =
      {
        account: "الحسابات والشبكة",
        pos: "نقاط البيع",
        staff: "الموظفون",
        permission_profile: "أنواع الصلاحيات",
        products: "الفئات",
        providers: "الشركات",
        agents: "الوكلاء",
        data: "البيانات",
        sources: "المصادر", representatives: "المندوبون", posTypes: "أنواع نقاط البيع", governorates: "المحافظات",
        wallets: "المحافظ", ledger: "القيود", invoices: "الفواتير", prices: "الأسعار", security: "الأمان والتشغيل",
        import: "الطلبيات والاستيراد", inventory: "المخزون", claims: "التالف والمطالبات",
        exports: "سحب المخزون",
        sales: "المبيعات", sell: "البيع والطباعة", exceptions: "استثناءات الطباعة", branding: "تصميم البطاقة",
        support: "الدعم الفني", notifications: "الإشعارات", reports: "التقارير", dashboard: "لوحة التحكم",
        digital: "الخدمات الرقمية", integrations: "الربط مع الشركات", company: "موقع الشركة", map: "خريطة المستخدمين",
        topup: "Topup والتخصيص", app: "إعدادات التطبيق", printers: "الطابعات المعتمدة", backup: "النسخ الاحتياطية",
      }[item.group] ||
      item.group ||
      "الصلاحيات";
    if (!groups.has(group)) groups.set(group, []);
    groups.get(group).push(item);
  }
  return [...groups.entries()].map(([title, items]) => ({ title, items }));
}
export function staffPayload(
  form,
  {
    staff,
    canRole = true,
    canScope = true,
    password,
    confirmation,
    reason,
  } = {},
) {
  const payload = {
    name: form.name.trim(),
    email: form.email.trim().toLowerCase(),
    notes: form.notes.trim(),
  };
  if (!staff)
    return {
      ...payload,
      permission_profile_id: Number(form.permission_profile_id),
      scope_roots: [...form.scope_roots],
      include_descendants: form.include_descendants,
      password,
      password_confirmation: confirmation,
    };
  const update = { ...payload, version: staff.version, reason: reason.trim() };
  if (
    canRole &&
    Number(form.permission_profile_id) !== staff.permission_profile_id
  )
    update.permission_profile_id = Number(form.permission_profile_id);
  if (
    canScope &&
    (JSON.stringify([...form.scope_roots].sort()) !==
      JSON.stringify([...(staff.scope_roots || [])].sort()) ||
      form.include_descendants !== staff.include_descendants)
  ) {
    update.scope_roots = [...form.scope_roots];
    update.include_descendants = form.include_descendants;
  }
  return update;
}
