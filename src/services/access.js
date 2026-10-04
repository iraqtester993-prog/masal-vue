(function (root) {
  "use strict";
  const groups = [
    ["dashboard", "لوحة التحكم", "عرض،تصدير"],
    [
      "reports",
      "مركز التقارير",
      "عرض،مبيعات،مخزون،محافظ،شبكة،أسعار،مطالبات،دعم،مستخدمون،تدقيق،تشغيل،تصدير،طباعة",
    ],
    [
      "sell",
      "البيع والطباعة",
      "عرض،بيع،عرض الوصل،طباعة الوصل،نتيجة الطباعة،إعادة الطباعة",
    ],
    ["sales", "سجل العمليات", "عرض،تصدير"],
    ["inventory", "المخزون والدفعات", "عرض،تفاصيل،تعديل،حجر،إلغاء،تصدير"],
    ["import", "الطلبيات والاستيراد", "عرض،قالب،معاينة،اعتماد،تصدير"],
    ["products", "المنتجات والفئات", "عرض،إضافة،تعديل،تفعيل،تصدير"],
    ["providers", "الشركات والمزودون", "عرض،إضافة،تعديل،تفعيل،تصدير"],
    [
      "prices",
      "الأسعار والاعتمادات",
      "عرض،قالب،استيراد،اقتراح،اعتماد،تراجع،تصدير",
    ],
    ["exceptions", "استثناءات الطباعة", "عرض،تصدير"],
    ["claims", "التالف والمطالبات", "عرض،فتح مطالبة،تسوية،تصدير"],
    ["exports", "التصدير الآمن", "عرض،تشفير،تصدير"],
    ["agents", "الوكلاء والشجرة", "عرض،إضافة،تعديل،تفعيل،إضافة رئيسي،تصدير"],
    ["pos", "نقاط البيع والأجهزة", "عرض،إضافة،تعديل،تفعيل،إنهاء جلسة،تصدير"],
    ["wallets", "المحافظ والتحويلات", "عرض،تحويل،إيداع،تصدير"],
    ["map", "خريطة الانتشار", "عرض،تصدير"],
    ["support", "الدعم الفني", "عرض،إضافة،رد،إغلاق،تصعيد،تصدير"],
    ["notifications", "الإشعارات والتنبيهات", "عرض،إرسال،إرسال عام،تصدير"],
    [
      "users",
      "الهوية والمستخدمون",
      "عرض،إضافة،تعديل،تفعيل،تغيير الدور،تغيير النطاق،تصدير",
    ],
    ["representatives", "المندوبون", "عرض،إضافة،تعديل،تفعيل،تصدير"],
    ["posTypes", "أنواع نقاط البيع", "عرض،إضافة،تعديل،تفعيل،تصدير"],
    ["permissions", "الصلاحيات والنطاق", "عرض،منح ومنع،تغيير النطاق،تصدير"],
    ["integrations", "تكاملات API", "عرض،تعديل،تصدير"],
    ["audit", "سجل التدقيق", "عرض،تفاصيل،تصدير"],
    ["monitoring", "المراقبة", "عرض،تصدير"],
    [
      "security",
      "الأمان والتشغيل",
      "عرض،تشغيل التطبيق،تشغيل البيع،تشغيل الطباعة،تشغيل التسجيل،تشغيل الدخول،سياسات،إنهاء الكل،تصدير",
    ],
    ["branding", "هوية الوكيل والوصل", "عرض،تعديل،تصدير"],
    ["company", "موقع الشركة", "عرض،تعديل"],
    ["governorates", "المحافظات", "عرض،تفعيل"],
    ["backup", "النسخ الاحتياطي", "عرض،تنزيل،استعادة"],
    ["data", "البيانات الحساسة", "التكلفة،الربح،رموز البطاقات"],
  ];
  const verbs = {
    عرض: "view",
    إضافة: "create",
    تعديل: "edit",
    تفعيل: "toggle",
    تصدير: "export",
    طباعة: "print",
    مبيعات: "sales",
    مخزون: "inventory",
    محافظ: "wallets",
    شبكة: "network",
    أسعار: "prices",
    مطالبات: "claims",
    دعم: "support",
    مستخدمون: "users",
    تدقيق: "audit",
    تشغيل: "operations",
    بيع: "create",
    "عرض الوصل": "receipt",
    "طباعة الوصل": "print",
    "نتيجة الطباعة": "result",
    "إعادة الطباعة": "reprint",
    تفاصيل: "details",
    حجر: "quarantine",
    إلغاء: "cancel",
    قالب: "template",
    معاينة: "preview",
    اعتماد: "approve",
    استيراد: "import",
    اقتراح: "propose",
    تراجع: "reverse",
    "فتح مطالبة": "create",
    تسوية: "settle",
    تشفير: "encrypt",
    "إضافة رئيسي": "createMain",
    "إنهاء جلسة": "logout",
    تحويل: "transfer",
    إيداع: "deposit",
    رد: "reply",
    إغلاق: "close",
    تصعيد: "escalate",
    إرسال: "send",
    "إرسال عام": "broadcast",
    "تغيير الدور": "role",
    "تغيير النطاق": "scope",
    "منح ومنع": "manage",
    "تشغيل التطبيق": "app",
    "تشغيل البيع": "sales",
    "تشغيل الطباعة": "printing",
    "تشغيل التسجيل": "registration",
    "تشغيل الدخول": "login",
    سياسات: "policies",
    "إنهاء الكل": "logoutAll",
    تواصل: "contact",
    تنزيل: "download",
    استعادة: "restore",
    التكلفة: "cost",
    الربح: "profit",
    "رموز البطاقات": "pin",
  };
  const catalog = groups.flatMap(([module, group, labels]) =>
    labels.split("،").map((label) => ({
      key: module + "." + verbs[label],
      module,
      group,
      label,
      sensitive:
        ["data", "permissions", "backup", "security"].includes(module) ||
        [
          "approve",
          "reverse",
          "deposit",
          "cancel",
          "encrypt",
          "role",
          "scope",
          "settle",
          "createMain",
        ].includes(verbs[label]),
    })),
  );
  catalog.find((p) => p.key === "company.edit").label =
    "إدارة وتعديل موقع الشركة";
  catalog.find((p) => p.key === "company.edit").sensitive = true;
  for (const [key, label] of [
    ["security.velocity", "تعديل الفاصل بين العمليات"],
    ["security.expiryDays", "تعديل مهلة تنبيه الانتهاء"],
    ["security.providerDaily", "تعديل حد المزود اليومي"],
    ["security.reprint", "تعديل حد إعادة الطباعة"],
    ["security.minVersion", "تعديل أقل إصدار للتطبيق"],
    ["security.idle", "تعديل مهلة الخمول"],
    ["security.blockedIPs", "تعديل عناوين الشبكة المحظورة"],
    ["pos.device", "تعديل بيانات الجهاز"],
    ["pos.location", "تعديل الموقع الجغرافي"],
    ["pos.reprintLimit", "تعديل حد طباعة الجهاز"],
    ["products.priceFloor", "تعديل أقل سعر بيع مسموح"],
    ["products.limits", "تعديل حدود بيع الفئة"],
    ["branding.receipt", "تعديل قالب الوصل"],
    ["branding.identity", "تعديل الشعار واللون"],
  ]) {
    const module = key.split(".")[0];
    catalog.push({
      key,
      label,
      module,
      group: groups.find((g) => g[0] === module)[1],
      sensitive: true,
    });
  }
  for (const [key, label] of [
    ["wallets.request", "تقديم طلب تمويل"],
    ["wallets.exception", "استثناء تمويل من الإدارة"],
    ["wallets.reverse", "عكس تحويل خدمة"],
    ["wallets.collect", "تسجيل تحصيل ذمم"],
    ["exceptions.approve", "اعتماد استثناء طباعة"],
    ["claims.loss", "اعتماد خسارة أو رفض مطالبة"],
    ["integrations.transact", "تنفيذ طلب خدمة"],
    ["integrations.rotate", "تدوير رمز التكامل"],
  ]) {
    const module = key.split(".")[0];
    catalog.push({
      key,
      label,
      module,
      group: groups.find((g) => g[0] === module)[1],
      sensitive: true,
    });
  }
  for (const [key, label] of [
    ["prices.policy", "اعتماد سياسة أسعار منطقة وتاريخ"],
    ["wallets.creditLimit", "تعديل سقف المديونية"],
    ["exports.request", "طلب سحب وتصدير"],
    ["exports.approve", "اعتماد تنزيل مؤقت"],
    ["sell.deliver", "تسليم البطاقات دون طباعة"],
    ["sell.bulk", "بيع الجملة"],
    ["notifications.rules", "ضبط وفحص التنبيهات"],
    ["users.reset", "إعادة تعيين كلمة المرور"],
  ]) {
    const module = key.split(".")[0];
    catalog.push({
      key,
      label,
      module,
      group: groups.find((g) => g[0] === module)[1],
      sensitive: true,
    });
  }
  catalog.push({
    key: "agents.permissions",
    module: "agents",
    group: "الوكلاء والشجرة",
    label: "إدارة صلاحيات التابعين",
    sensitive: true,
  });
  const detailParents = {
    "permissions.delete": "permissions.manage",
    "permissions.create": "permissions.manage",
    "permissions.edit": "permissions.manage",
    "permissions.toggle": "permissions.manage",
    "products.order": "products.edit",
    "products.images": "products.edit",
    "products.availability": "products.edit",
    "products.fields": "products.edit",
    "providers.images": "providers.edit",
    "import.rules": "import.template",
    "wallets.approve": "wallets.transfer",
    "wallets.bulk": "wallets.transfer",
    "wallets.import": "wallets.transfer",
    "support.attach": "support.create",
    "notifications.attach": "notifications.send",
  };
  for (const [key, label, parent] of [
    ["users.resetDirect", "تغيير كلمة المرور مباشرة دون رمز", ""],
    ["security.fundingRequests", "إدارة مبالغ وعدد طلبات التمويل", ""],
    ["security.fundingRecovery", "تعديل مهلة استرجاع الرصيد", ""],
    ["pos.documents", "إدارة مستمسكات وصورة نقطة البيع", "pos.edit"],
    ["pos.representatives", "تعيين مندوبي نقطة البيع", "pos.edit"],
    ["pos.type", "تعيين نوع نقطة البيع", "pos.edit"],
    [
      "notifications.translations",
      "إرسال إشعار متعدد اللغات",
      "notifications.send",
    ],
  ]) {
    const module = key.split(".")[0];
    catalog.push({
      key,
      label,
      module,
      group: groups.find((g) => g[0] === module)[1],
      sensitive: true,
    });
    if (parent) detailParents[key] = parent;
  }
  const detailLabels = {
    "permissions.delete": "حذف نوع صلاحية",
    "permissions.create": "إضافة نوع صلاحية",
    "permissions.edit": "تعديل نوع صلاحية",
    "permissions.toggle": "تفعيل وتعطيل نوع صلاحية",
    "products.order": "ترتيب الفئات",
    "products.images": "صور الفئة والوصل",
    "products.availability": "إتاحة الفئة للوكلاء والمحافظات",
    "products.fields": "تخصيص حقول البطاقة",
    "providers.images": "صور وشعار المزود",
    "import.rules": "حفظ قواعد وقوالب المورد",
    "wallets.approve": "اعتماد وتسليم طلبات التمويل",
    "wallets.bulk": "تمويل مجموعة مستفيدين",
    "wallets.import": "استيراد ملف تمويل",
    "support.attach": "إرفاق صورة برسالة الدعم",
    "notifications.attach": "إرفاق صورة بالإشعار",
  };
  for (const [key, label] of Object.entries(detailLabels)) {
    const module = key.split(".")[0];
    catalog.push({
      key,
      label,
      module,
      group: groups.find((g) => g[0] === module)[1],
      sensitive: !key.endsWith(".attach"),
    });
  }
  function staffAccount(user) {
    return (
      user?.staffAccount ||
      (["main", "sub"].includes(user?.role) ? user.agent : "@system")
    );
  }
  function managementRole(state, user) {
    if (user?.role !== "employee" || !user.staffAccount) return user?.role;
    return user.staffAccount === "@system"
      ? "owner"
      : state.agents.find((a) => a.id === user.staffAccount)?.type === "رئيسي"
        ? "main"
        : "sub";
  }
  const rolePages = {
    owner: groups.map((g) => g[0]),
    supervisor: [
      "dashboard",
      "reports",
      "sell",
      "sales",
      "inventory",
      "import",
      "products",
      "providers",
      "prices",
      "exceptions",
      "claims",
      "exports",
      "agents",
      "pos",
      "representatives",
      "posTypes",
      "map",
      "support",
      "notifications",
      "permissions",
      "integrations",
      "audit",
      "monitoring",
      "security",
    ],
    main: [
      "dashboard",
      "reports",
      "sell",
      "sales",
      "inventory",
      "import",
      "products",
      "prices",
      "exceptions",
      "claims",
      "exports",
      "agents",
      "pos",
      "representatives",
      "posTypes",
      "wallets",
      "map",
      "support",
      "notifications",
      "users",
      "permissions",
      "integrations",
      "audit",
      "branding",
    ],
    sub: [
      "users",
      "dashboard",
      "reports",
      "sell",
      "sales",
      "exceptions",
      "agents",
      "pos",
      "representatives",
      "wallets",
      "map",
      "support",
      "notifications",
      "permissions",
    ],
    pos: [
      "wallets",
      "dashboard",
      "reports",
      "sell",
      "sales",
      "exceptions",
      "map",
      "support",
      "notifications",
    ],
    employee: ["dashboard"],
  };
  function defaults(role, key) {
    if (
      ["agents.archive", "agents.archiveView"].includes(key) &&
      role !== "owner"
    )
      return false;
    if (role === "sub" && key === "agents.createMain") return false;
    const p = catalog.find((x) => x.key === key);
    if (!p) return false;
    if (role === "owner") return true;
    if (
      [
        "users.resetDirect",
        "security.fundingRequests",
        "security.fundingRecovery",
      ].includes(key)
    )
      return false;
    if (p.module === "map") return false;
    if (p.module === "company") return key === "company.view";
    if (key === "prices.policy" && role === "main") return true;
    if (detailParents[key]) return defaults(role, detailParents[key]);
    if (
      ["main", "sub"].includes(role) &&
      [
        "permissions.manage",
        "users.view",
        "users.create",
        "users.edit",
        "users.toggle",
        "users.role",
        "users.export",
        "users.reset",
      ].includes(key)
    )
      return true;
    if (role === "employee") return ["dashboard.view"].includes(key);
    if (p.module === "data")
      return p.key === "data.pin" || ["supervisor", "main"].includes(role);
    if (!rolePages[role]?.includes(p.module)) return false;
    if (role === "pos" && p.module === "wallets")
      return ["wallets.view", "wallets.request"].includes(key);
    if (
      [
        "prices.policy",
        "wallets.creditLimit",
        "users.reset",
        "support.broadcast",
      ].includes(key)
    )
      return false;
    if (
      ["wallets.exception", "claims.loss", "integrations.rotate"].includes(key)
    )
      return false;
    if (key === "exceptions.approve")
      return ["main", "sub", "supervisor"].includes(role);
    if (key === "permissions.view") return true;
    if (p.module === "permissions") return false;
    if (p.module === "security")
      return key === "security.view" || key === "security.logoutAll";
    if (
      [
        "users.role",
        "users.scope",
        "users.toggle",
        "agents.createMain",
        "wallets.deposit",
        "notifications.broadcast",
      ].includes(key)
    )
      return false;
    if (p.module === "users")
      return (
        ["main"].includes(role) &&
        ["view", "create", "edit", "export"].includes(verbs[p.label])
      );
    if (["prices.approve", "prices.reverse", "claims.settle"].includes(key))
      return role === "supervisor";
    if (p.module === "products" && role === "main")
      return ["view", "export"].includes(verbs[p.label]);
    if (p.module === "reports") {
      const map = {
        sales: "sales",
        inventory: "inventory",
        wallets: "wallets",
        network: "agents",
        prices: "prices",
        claims: "claims",
        support: "support",
        users: "users",
        audit: "audit",
        operations: "monitoring",
      };
      return (
        !map[verbs[p.label]] || rolePages[role].includes(map[verbs[p.label]])
      );
    }
    return true;
  }
  function branchCreationBlocked(state, user) {
    if ((state ? managementRole(state, user) : user?.role) !== "sub")
      return false;
    const id = user.role === "employee" ? user.staffAccount : user.agent,
      agent = state?.agents.find((a) => a.id === id),
      parent = state?.agents.find((a) => a.id === agent?.parent);
    return !agent || agent.type !== "فرعي" || parent?.type !== "رئيسي";
  }
  function localCan(user, key, state) {
    if (key === "agents.create" && branchCreationBlocked(state, user))
      return false;
    if (
      key === "agents.createMain" &&
      (user?.role === "sub" || (state && managementRole(state, user) === "sub"))
    )
      return false;
    if (!user?.active || user.archivedAt || !catalog.some((p) => p.key === key))
      return false;
    if (user.role === "owner") return true;
    const [module, action] = key.split(".");
    if (module === "backup") return false;
    if (
      module !== "data" &&
      action !== "view" &&
      !localCan(user, module + ".view", state)
    )
      return false;
    if (user.permissionProfileId) {
      const profile = state?.permissionProfiles?.find(
        (p) => p.id === user.permissionProfileId,
      );
      return (
        !!profile?.active &&
        (profile.permissions.includes(key) ||
          (!profile.detailVersion &&
            detailParents[key] &&
            profile.permissions.includes(detailParents[key])))
      );
    }
    const value = user.access?.overrides?.[key];
    return value === "deny"
      ? false
      : value === "allow"
        ? true
        : defaults(user.role, key);
  }
  function networkPath(state, user) {
    if (!state) return [];
    if (
      user.role === "employee" &&
      user.staffAccount &&
      user.staffAccount !== "@system"
    )
      user = { ...user, role: "main", agent: user.staffAccount };
    if (!["main", "sub", "pos"].includes(user.role)) return [];
    const out = [],
      seen = new Set();
    if (user.role === "pos") {
      const pos = state.pos.find(
        (p) => p.id === user.pos && p.agent === user.agent,
      );
      if (!pos) return null;
      out.push(pos);
    }
    let id = user.agent;
    while (id) {
      if (seen.has(id)) return null;
      seen.add(id);
      const agent = state.agents.find((a) => a.id === id);
      if (!agent) return null;
      out.push(agent);
      id = agent.parent;
    }
    return out.length ? out : null;
  }
  function can(user, key, state) {
    if (
      key.startsWith("map.") &&
      !["owner", "employee", "supervisor"].includes(user?.role)
    )
      return false;
    if (
      [
        "wallets.bulk",
        "wallets.transfer",
        "wallets.approve",
        "wallets.import",
      ].includes(key) &&
      managementRole(state, user) === "pos"
    )
      return false;
    if (
      [
        "sell.view",
        "sell.create",
        "sell.bulk",
        "sell.deliver",
        "sell.print",
        "sell.result",
        "sell.reprint",
      ].includes(key) &&
      !["sub", "pos"].includes(managementRole(state, user))
    )
      return false;
    if (key.startsWith("governorates.") && user?.role !== "owner") return false;
    if (!localCan(user, key, state)) return false;
    if (user.role === "owner") return true;
    const [module, action] = key.split(".");
    if (
      module !== "data" &&
      action !== "view" &&
      key !== "sell.receipt" &&
      !can(user, module + ".view", state)
    )
      return false;
    const path = networkPath(state, user);
    if (path === null) return false;
    for (const node of path) {
      if (node.archivedAt) return false;
      if (user.role === "employee" && !node.active) return false;
      if (Object.values(node.networkRules || {}).some((r) => r[key] === "deny"))
        return false;
      if (node.id !== user.agent || ["pos", "employee"].includes(user.role)) {
        const parent = state.users.find(
          (u) => ["main", "sub"].includes(u.role) && u.agent === node.id,
        );
        if (parent && !localCan({ ...parent, active: true }, key, state))
          return false;
      }
    }
    return true;
  }
  function scope(state, user) {
    if (!user?.active) return [];
    if (
      user.role === "owner" ||
      (user.role === "employee" &&
        user.staffAccount === "@system" &&
        !(user.access?.scope?.roots || user.assigned || []).length)
    )
      return state.agents.map((a) => a.id);
    if (
      user.role === "employee" &&
      user.staffAccount &&
      user.staffAccount !== "@system"
    ) {
      const roots = [user.staffAccount],
        seen = new Set();
      for (let i = 0; i < roots.length; i++) {
        if (seen.has(roots[i])) continue;
        seen.add(roots[i]);
        for (const a of state.agents)
          if (a.parent === roots[i] && !roots.includes(a.id)) roots.push(a.id);
      }
      return roots.filter((id) => state.agents.some((a) => a.id === id));
    }
    let roots = ["supervisor", "employee"].includes(user.role)
      ? user.assigned || []
      : [user.agent];
    if (user.access?.scope) roots = user.access.scope.roots || [];
    const out = roots.filter((a) => state.agents.some((x) => x.id === a));
    if (user.access?.scope?.descendants !== false)
      for (let i = 0; i < out.length; i++)
        for (const a of state.agents)
          if (a.parent === out[i] && !out.includes(a.id)) out.push(a.id);
    return [...new Set(out)];
  }
  function save(engine, id, draft, reason) {
    engine.requirePermission("permissions.manage");
    const target = engine.s.users.find((u) => u.id === id);
    if (!target) throw Error("الموظف غير موجود");
    if (target.id === engine.user || target.role === "owner")
      throw Error("لا يمكن تعديل صلاحيات الحساب الحالي أو مدير النظام");
    if (!reason?.trim()) throw Error("سبب تغيير الصلاحيات مطلوب");
    const actor = engine.actor(),
      old = target.access || { overrides: {} };
    if (
      actor.role !== "owner" &&
      scope(engine.s, target).some((a) => !engine.allowed(a))
    )
      throw Error("الموظف خارج نطاق صلاحياتك");
    const next = JSON.parse(JSON.stringify(draft));
    next.overrides = next.overrides || {};
    for (const [key, value] of Object.entries(next.overrides)) {
      if (
        !catalog.some((p) => p.key === key) ||
        !["allow", "deny"].includes(value)
      )
        throw Error("تعريف صلاحية غير صالح");
    }
    for (const p of catalog) {
      const candidate = { ...target, active: true, access: next };
      if (
        can(candidate, p.key, engine.s) &&
        !can({ ...target, active: true }, p.key, engine.s) &&
        !can(actor, p.key, engine.s)
      )
        throw Error("لا يمكنك منح صلاحية لا تملكها: " + p.key);
    }
    if (
      JSON.stringify(next.scope || null) !== JSON.stringify(old.scope || null)
    ) {
      engine.requirePermission("permissions.scope");
      if (next.scope) {
        if (
          !Array.isArray(next.scope.roots) ||
          typeof next.scope.descendants !== "boolean" ||
          next.scope.roots.some((a) => !engine.s.agents.some((x) => x.id === a))
        )
          throw Error("نطاق غير صالح");
        if (
          actor.role !== "owner" &&
          scope(engine.s, { ...target, access: next }).some(
            (a) => !engine.allowed(a),
          )
        )
          throw Error("لا يمكن منح نطاق خارج نطاقك");
      }
    }
    const before = JSON.parse(JSON.stringify(old));
    target.access = next;
    engine.log("تعديل صلاحيات موظف", target.id, before, {
      ...next,
      reason: reason.trim(),
    });
    return target;
  }
  root.MasalAccess = {
    branchCreationBlocked,
    catalog,
    groups,
    can,
    localCan,
    networkPath,
    defaults,
    scope,
    save,
    detailParents,
    staffAccount,
    managementRole,
  };
})(globalThis);
