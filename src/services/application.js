"use strict";
const { createApp } = Vue;
const NAV = [
  {
    title: "نظرة عامة",
    items: [
      [
        "dashboard",
        "لوحة التحكم",
        "▦",
        "متابعة المبيعات والمخزون ونشاط شبكة التوزيع",
      ],
      [
        "reports",
        "مركز التقارير والعمليات",
        "↗",
        "تقارير تفصيلية للمبيعات والمخزون والمحافظ والشبكة والتشغيل",
      ],
      ["company", "موقع الشركة", "◈", ""],
    ],
  },
  {
    title: "البطاقات والعمليات",
    items: [
      [
        "sell",
        "البيع والطباعة",
        "▣",
        "إصدار البطاقات من مخزن الوكيل ومتابعة الطباعة",
      ],
      ["sales", "سجل العمليات", "⇄", "تتبع كل بطاقة من الإصدار إلى التسليم"],
      [
        "inventory",
        "المخزون والدفعات",
        "▤",
        "مخزون مستقل لكل وكيل رئيسي • FIFO",
      ],
      [
        "import",
        "الطلبيات والاستيراد",
        "↥",
        "رفع الملف وفحص البطاقات ثم اعتماد الدفعة",
      ],
      [
        "products",
        "المنتجات والفئات",
        "◇",
        "البطاقات المحلية والعالمية وحدود البيع",
      ],
      [
        "providers",
        "الشركات والمزودون",
        "◈",
        "العلامة التجارية والمجهز والجهة المنظمة",
      ],
      [
        "prices",
        "الأسعار والاعتمادات",
        "≋",
        "قوائم أسعار الوكلاء ومراجعة التغييرات",
      ],
      [
        "exceptions",
        "استثناءات الطباعة",
        "!",
        "العمليات المعلقة والفاشلة وإعادة الطباعة",
      ],
      [
        "claims",
        "التالف والمطالبات",
        "◷",
        "حجر البطاقات وتوثيق جواب المزود والتسوية",
      ],
      ["exports", "التصدير الآمن", "↗", "تصدير الدفعات المتبقية بملف مشفر"],
    ],
  },
  {
    title: "شبكة التوزيع",
    items: [
      [
        "agents",
        "الوكلاء والفروع",
        "♧",
        "الوكلاء الرئيسيون والفرعيون ونطاق الشجرة",
      ],
      ["pos", "نقاط البيع والأجهزة", "▣", "الحسابات والأجهزة ومواقع الانتشار"],
      [
        "representatives",
        "المندوبون",
        "♙",
        "مندوبو الوكلاء وربطهم بنقاط البيع",
      ],
      ["posTypes", "أنواع نقاط البيع", "◇", "إدارة أنواع نقاط البيع"],
      [
        "wallets",
        "المحافظ والتحويلات",
        "▱",
        "محافظ الخدمات والرصيد التشغيلي والتمويل",
      ],
      ["map", "خريطة المستخدمين", "⌖", ""],
      [
        "support",
        "الدعم الفني",
        "☏",
        "التذاكر والردود والتصعيد إلى إدارة النظام",
      ],
      [
        "notifications",
        "الإشعارات والتنبيهات",
        "♧",
        "تنبيهات المخزون والعمليات وإشعارات الشبكة",
      ],
    ],
  },
  {
    title: "إدارة النظام",
    items: [
      ["governorates", "المحافظات", "◈", ""],
      [
        "users",
        "الهوية والمستخدمون",
        "♙",
        "مستخدمو النظام وأدوارهم ونطاق عملهم",
      ],
      [
        "permissions",
        "أنواع الصلاحيات",
        "⊞",
        "أنواع صلاحيات مسماة تُسند إلى الموظفين",
      ],
      [
        "integrations",
        "تكاملات API",
        "⌁",
        "إعدادات المزود وبيئة الربط لكل وكيل",
      ],
      [
        "audit",
        "سجل التدقيق",
        "≡",
        "تاريخ التغييرات والعمليات وقيمها السابقة والجديدة",
      ],
      [
        "monitoring",
        "المراقبة",
        "◉",
        "المؤشرات التشغيلية والاستثناءات المحلية",
      ],
      [
        "security",
        "الأمان والتشغيل",
        "⊙",
        "مفاتيح الإيقاف وحدود التشغيل والأجهزة",
      ],
      [
        "branding",
        "هوية الوكيل والوصل",
        "◐",
        "الشعار والألوان ومعلومات الدعم وتصميم الوصل",
      ],
      [
        "backup",
        "النسخ الاحتياطي",
        "↧",
        "نسخ بيانات النظام احتياطيًا على السيرفر واستعادتها",
      ],
    ],
  },
].map((g) => ({
  ...g,
  items: g.items.map(([id, label, icon, subtitle]) => ({
    id,
    label,
    icon,
    subtitle,
  })),
}));
const F = (key, label, type, options, extra = {}) => ({
  key,
  label,
  type,
  options,
  ...extra,
});
const schemas = {
  agents: {
    key: "agents",
    add: "وكيل جديد",
    columns: [
      F("name", "الوكيل"),
      F("type", "المستوى"),
      F("parent", "الوكيل الأعلى", null, "agents"),
      F("city", "المحافظة"),
      F("active", "الحالة"),
    ],
    fields: [
      F("name", "اسم الوكيل"),
      F("type", "النوع", null, ["رئيسي", "فرعي"]),
      F("parent", "الوكيل الأعلى", null, "agents", { required: false }),
      F("city", "المحافظة", null, MasalCategoriesUI.governorates),
      F("phone", "رقم الهاتف", "tel"),
      F("support", "معلومات الدعم", null, null, { required: false }),
      F("color", "لون الوكيل", "color"),
    ],
  },
  providers: {
    key: "providers",
    add: "إضافة شركة",
    columns: [
      F("name", "اسم الشركة"),
      F("supplier", "المجهز"),
      F("connection", "نوع الربط"),
      F("active", "الحالة"),
    ],
    fields: [
      F("name", "اسم الشركة"),
      F("supplier", "الجهة المجهزة"),
      F("connection", "نوع الربط", null, ["ملفات", "API"]),
    ],
  },
  products: {
    key: "products",
    add: "فئة جديدة",
    columns: [
      F("name", "الفئة"),
      F("provider", "المزود", null, "providers"),
      F("kind", "النوع"),
      F("face", "القيمة الاسمية"),
      F("currency", "عملة القيمة الاسمية"),
      F("active", "الحالة"),
    ],
    fields: [
      F("name", "اسم الفئة"),
      F("provider", "المزود", null, "providers"),
      F("kind", "النوع", null, ["محلية", "عالمية"]),
      F("face", "القيمة الاسمية", "number", null, { min: 1 }),
      F("currency", "عملة القيمة الاسمية", null, ["IQD", "USD"]),
      F("min", "أقل سعر بيع مسموح • د.ع", "number", null, { min: 1 }),
      F("dailyQty", "حد الكمية اليومي", "number", null, { min: 1 }),
      F("dailyAmount", "الحد المالي اليومي • د.ع", "number", null, { min: 1 }),
      F("fieldPolicy", "بيانات البطاقة المطلوبة عند الاستيراد", "cardFields"),
      F("order", "ترتيب الظهور", "number", null, { min: 0 }),
    ],
  },
  pos: {
    key: "pos",
    add: "نقطة بيع",
    columns: [
      F("name", "اسم النقطة"),
      F("representative", "المندوب"),
      F("agent", "الوكيل", null, "agents"),
      F("city", "المحافظة"),
      F("model", "الجهاز"),
      F("serial", "سيريال الجهاز"),
      F("active", "الحالة"),
    ],
    fields: [
      F("name", "الاسم التجاري"),
      F("owner", "اسم صاحب المكتب"),
      F("agent", "الوكيل التابع", null, "agents"),
      F("city", "المحافظة", null, MasalCategoriesUI.governorates),
      F("address", "المدينة والعنوان"),
      F("phone", "الهاتف", "tel"),
      F("serial", "الرقم التسلسلي للجهاز"),
      F("model", "موديل الجهاز"),
      F("version", "إصدار التطبيق"),
      F("posTypeId", "نوع نقطة البيع", null, "posTypes", { required: false }),
    ],
  },
  representatives: {
    key: "representatives",
    add: "مندوب جديد",
    columns: [
      F("name", "اسم المندوب"),
      F("phone", "رقم الهاتف"),
      F("address", "العنوان"),
      F("agent", "الوكيل", null, "agents"),
      F("active", "الحالة"),
    ],
    fields: [
      F("name", "اسم المندوب", null, null, { required: false }),
      F("phone", "رقم الهاتف", "tel", null, { required: false }),
      F("address", "العنوان", null, null, { required: false }),
      F("agent", "الوكيل", null, "agents"),
    ],
  },
  posTypes: {
    key: "posTypes",
    add: "نوع نقطة بيع",
    columns: [F("name", "النوع"), F("active", "الحالة")],
    fields: [
      F("name", "اسم نوع نقطة البيع"),
      F("active", "الحالة", null, [
        { value: true, label: "مفعل" },
        { value: false, label: "موقوف" },
      ]),
    ],
  },
  users: {
    key: "users",
    add: "مستخدم",
    columns: [
      F("name", "الاسم"),
      F("role", "الدور"),
      F("agent", "الوكيل", null, "agents"),
      F("active", "الحالة"),
    ],
    fields: [
      F("name", "اسم المستخدم"),
      F("role", "الدور", null, [
        { value: "employee", label: "موظف النظام" },
        { value: "owner", label: "مدير النظام" },
        { value: "supervisor", label: "مشرف" },
        { value: "main", label: "وكيل رئيسي" },
        { value: "sub", label: "وكيل فرعي" },
        { value: "pos", label: "موظف نقطة بيع" },
      ]),
      F("agent", "الوكيل", null, "agents", { required: false }),
      F("pos", "نقطة البيع", null, "pos", { required: false }),
      F(
        "assignedText",
        "معرفات الوكلاء المكلف بهم المشرف، مفصولة بفاصلة",
        null,
        null,
        { required: false },
      ),
    ],
  },
};
const statusNames = {
  "Partially Used": "صادرة جزئيًا",
  Completed: "مستنفدة",
  Available: "متاحة",
  Loaded: "محمّلة",
  Reserved: "محجوزة",
  Issued: "صادرة",
  "Print Requested": "بانتظار الطباعة",
  "Print Failed": "فشل الطباعة",
  Printed: "مطبوعة",
  Reprinted: "أعيدت طباعتها",
  "Reprint Requested": "طلب إعادة طباعة",
  Quarantined: "محجورة",
  Exported: "مصدّرة",
  "Cancelled by Reversal": "ملغاة",
  "Written Off": "مشطوبة",
  "Awaiting Replacement": "بانتظار تسوية / بديل",
  Delivered: "مسلّمة",
};
const pageRoles = {
  providers: ["owner", "supervisor"],
  representatives: ["owner", "supervisor", "main", "sub"],
  posTypes: ["owner", "supervisor", "main"],
  products: ["owner", "supervisor", "main"],
  users: ["owner", "main"],
  security: ["owner", "supervisor"],
  backup: ["owner"],
  integrations: ["owner", "supervisor", "main"],
  branding: ["owner", "main"],
  import: ["owner", "supervisor", "main"],
  exports: ["owner", "supervisor", "main"],
  claims: ["owner", "supervisor", "main"],
  prices: ["owner", "supervisor", "main"],
  permissions: ["owner", "supervisor", "main", "sub"],
  agents: ["owner", "supervisor", "main", "sub"],
  pos: ["owner", "supervisor", "main", "sub"],
  wallets: ["owner", "main", "sub"],
  monitoring: ["owner", "supervisor"],
  audit: ["owner", "supervisor", "main"],
  inventory: ["owner", "supervisor", "main"],
  notifications: ["owner", "supervisor", "main", "sub", "pos"],
};
function parseCSV(text) {
  const rows = [];
  let row = [],
    cell = "",
    quote = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (c === '"') {
      if (quote && text[i + 1] === '"') {
        cell += '"';
        i++;
      } else quote = !quote;
    } else if (c === "," && !quote) {
      row.push(cell.trim());
      cell = "";
    } else if ((c === "\n" || c === "\r") && !quote) {
      if (c === "\r" && text[i + 1] === "\n") i++;
      row.push(cell.trim());
      if (row.some(Boolean)) rows.push(row);
      row = [];
      cell = "";
    } else cell += c;
  }
  if (quote) throw Error("علامات الاقتباس في CSV غير مكتملة");
  row.push(cell.trim());
  if (row.some(Boolean)) rows.push(row);
  if (rows.length < 2)
    throw Error("الملف يجب أن يحتوي عنوان الأعمدة وسطر بيانات على الأقل");
  const headers = rows
    .shift()
    .map((x) => x.replace(/^\uFEFF/, "").toLowerCase());
  if (new Set(headers).size !== headers.length)
    throw Error("عناوين أعمدة مكررة");
  return rows.map((r) =>
    Object.fromEntries(headers.map((h, i) => [h, r[i] || ""])),
  );
}
function csv(rows) {
  if (!rows.length) return "";
  const headers = Object.keys(rows[0]);
  const escape = (x) =>
    '"' +
    String(x ?? "")
      .replace(/^[=+@\-]/, "'$&")
      .replaceAll('"', '""') +
    '"';
  return (
    "\uFEFF" +
    [
      headers.map(escape).join(","),
      ...rows.map((r) => headers.map((h) => escape(r[h])).join(",")),
    ].join("\r\n")
  );
}
function download(name, data, type = "application/json") {
  const a = document.createElement("a"),
    url = URL.createObjectURL(new Blob([data], { type }));
  a.href = url;
  a.download = name;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
function updateAccountNames(s) {
  for (const [id, old, name] of [
    ["U2", "مشرف بغداد", "مشرف — بغداد"],
    ["U3", "مدير وكالة بغداد", "وكيل رئيسي — وكالة بغداد"],
    ["U4", "مدير توزيع الكرادة", "وكيل فرعي — توزيع الكرادة"],
    ["U5", "موظف مركز النور", "نقطة بيع — مركز النور"],
  ]) {
    const user = s.users.find((u) => u.id === id && u.name === old);
    if (user) user.name = name;
  }
  return s;
}
function loadState() {
  try {
    const raw = MasalStateStorage.read();
    if (raw) {
      const s = JSON.parse(raw);
      s.representatives ??= [];
      s.posTypes ??= [
        { id: "POSTYPE-STORE", name: "متجر", active: true },
        { id: "POSTYPE-SUPER", name: "سوبر ماركت", active: true },
        { id: "POSTYPE-RESTAURANT", name: "مطعم", active: true },
      ];
      s.pos.forEach((p) => {
        p.representativeIds ??= [];
        p.documents ??= [];
      });
      validateBackup(s);
      return updateAccountNames(s);
    }
  } catch (e) {
    console.warn("Could not load saved state", e);
  }
  return Masal.seed();
}
function validateBackup(s) {
  if (!s || s.version !== 1 || !s.settings || typeof s.settings !== "object")
    throw Error("نسخة احتياطية غير متوافقة");
  const base = Masal.seed();
  for (const [k, v] of Object.entries(base))
    if (Array.isArray(v) && !Array.isArray(s[k]))
      throw Error("قائمة ناقصة: " + k);
  for (const k of [
    "agents",
    "providers",
    "products",
    "pos",
    "users",
    "cards",
    "batches",
  ]) {
    const ids = s[k].map((r) => r.id);
    if (
      ids.some((x) => typeof x !== "string") ||
      new Set(ids).size !== ids.length
    )
      throw Error("معرفات غير صالحة: " + k);
  }
  if (!s.users.some((u) => u.role === "owner" && u.active))
    throw Error("لا يوجد مدير نظام فعال");
  const agentIDs = new Set(s.agents.map((a) => a.id)),
    productIDs = new Set(s.products.map((p) => p.id)),
    batchIDs = new Set(s.batches.map((b) => b.id));
  for (const a of s.agents) {
    if (a.parent && !agentIDs.has(a.parent)) throw Error("مرجع وكيل غير صالح");
    let p = a,
      seen = new Set();
    while (p) {
      if (seen.has(p.id)) throw Error("حلقة في الشجرة");
      seen.add(p.id);
      p = s.agents.find((x) => x.id === p.parent);
    }
  }
  for (const p of s.pos)
    if (!agentIDs.has(p.agent)) throw Error("نقطة بيع غير صالحة");
  for (const c of s.cards)
    if (
      !agentIDs.has(c.agent) ||
      !productIDs.has(c.product) ||
      !batchIDs.has(c.batch) ||
      !Number.isFinite(c.cost)
    )
      throw Error("بطاقة غير صالحة");
  for (const l of s.ledger)
    if (!Number.isFinite(l.amount)) throw Error("قيد مالي غير صالح");
  return true;
}
Object.assign(globalThis, {
  NAV,
  F,
  schemas,
  statusNames,
  pageRoles,
  parseCSV,
  csv,
  download,
  validateBackup,
});
const initial = loadState();
initial.representatives ??= [];
initial.posTypes ??= [
  { id: "POSTYPE-STORE", name: "متجر", active: true },
  { id: "POSTYPE-SUPER", name: "سوبر ماركت", active: true },
  { id: "POSTYPE-RESTAURANT", name: "مطعم", active: true },
];
initial.pos.forEach((p) => {
  p.representativeIds ??= [];
  p.documents ??= [];
});
const appOptions = {
  data() {
    return {
      serverBackupBusy: false,
      s: initial,
      currentUser: initial.users.find((u) => u.active && u.role === "owner").id,
      page: "dashboard",
      theme: document.documentElement.dataset.theme || "light",
      lang: (() => {
        try {
          return localStorage.getItem("masal-language") || "ar";
        } catch (e) {
          return "ar";
        }
      })(),
      accountTab: "all",
      navSearch: "",
      search: "",
      statusFilter: "",
      menuOpen: false,
      viewportMobile: window.innerWidth <= 1100,
      sidebarHoverOpen: false,
      sidebarCollapsed: (() => {
        try {
          return localStorage.getItem("masal-sidebar-collapsed") === "true";
        } catch {
          return false;
        }
      })(),
      collapsedGroups: {
        "البطاقات والعمليات": true,
        "شبكة التوزيع": true,
        "إدارة النظام": true,
      },
      treeView: false,
      modal: null,
      toast: null,
      toastTimer: null,
      saveState: "محفوظ محليًا",
      editForm: {},
      reason: "",
      reply: "",
      importStep: 0,
      importTab: "new",
      imp: {
        agent: "A1",
        product: "C1",
        city: "بغداد",
        supplier: "مجهز تجريبي",
        expiry: "2027-12-31",
        cost: 4400,
        loadPrice: 4500,
        expenses: 0,
      },
      importText: "",
      importPreview: [],
      transferForm: { from: "A1", to: "A3", amount: 25000 },
      depositForm: { account: "A1", amount: 100000, reference: "" },
      priceAgent: "A1",
      priceDraft: {},
      saleForm: { pos: "POS1", product: "C1", quantity: 1 },
      saleKey: Masal.id("SALE"),
      claimForm: { batch: "B-A1-C1", reason: "" },
      exportPassword: "",
      settlements: {},
      integrationForm: {
        agent: "A1",
        provider: "P5",
        environment: "Test",
        expiry: "2027-12-31",
      },
      notificationForm: { title: "", body: "", target: "all", image: "" },
      ticketForm: {
        agent: "A1",
        title: "",
        description: "",
        priority: "متوسطة",
      },
      mapAgent: "",
      selectedMapPOS: null,
      reportFrom: "",
      reportTo: "",
      settingsDraft: Masal.clone(initial.settings),
      brandAgent: "A1",
      brandDraft: {},
      contact: { name: "", phone: "", city: "", message: "" },
      permissionMatrix: [
        ["رؤية البيانات", "الجميع", "المكلف بها", "شجرته", "شجرته", "نقطته"],
        ["مخزن البطاقات", "كل المخازن", "ضمن النطاق", "مخزنه", "—", "—"],
        ["تحميل وتصدير", "نعم", "ضمن النطاق", "مخزنه", "—", "—"],
        ["تحويل رصيد", "نعم", "—", "للأبناء", "لنقاطه", "—"],
        ["اعتماد الأسعار", "شخص ثانٍ", "شخص ثانٍ", "—", "—", "—"],
        [
          "البيع والطباعة",
          "نعم",
          "ضمن النطاق",
          "ضمن النطاق",
          "ضمن النطاق",
          "نقطته",
        ],
        ["الأمان العام", "نعم", "ضمن النطاق", "—", "—", "—"],
        ["النسخ والاستعادة", "نعم", "—", "—", "—", "—"],
      ],
      securityToggles: [
        {
          key: "app",
          label: "تشغيل التطبيق",
          help: "إيقاف عمليات النسخة المحلية",
        },
        {
          key: "sales",
          label: "السماح بالبيع",
          help: "منع إصدار بطاقات جديدة عند الإيقاف",
        },
        {
          key: "printing",
          label: "السماح بالطباعة",
          help: "منع البيع وإعادة الطباعة عند الإيقاف",
        },
        {
          key: "registration",
          label: "تسجيل الوكلاء",
          help: "التحكم بإضافة وكلاء جدد",
        },
        {
          key: "login",
          label: "تسجيل الدخول",
          help: "إعداد للخادم عند ربط المصادقة",
        },
      ],
    };
  },
  computed: {
    engine() {
      return new Masal.Engine(this.s, this.currentUser);
    },
    actor() {
      return this.engine.actor() || this.s.users[0];
    },
    navGroups() {
      return NAV.map((g) => ({
        ...g,
        items: g.items.filter(
          (n) =>
            this.can(n.id + ".view") &&
            (!this.navSearch ||
              this.t(n.label)
                .toLowerCase()
                .includes(this.navSearch.toLowerCase())),
        ),
      })).filter((g) => g.items.length);
    },
    currentNav() {
      return NAV.flatMap((g) => g.items).find((n) => n.id === this.page);
    },
    title() {
      return this.currentNav?.label || "ماسال";
    },
    subtitle() {
      return this.currentNav?.subtitle || "";
    },
    schema() {
      return schemas[this.page];
    },
    todayLabel() {
      return MasalLocale.date(new Date(), this.lang, "long");
    },
    visibleAgents() {
      return this.s.agents.filter((a) => this.engine.allowed(a.id));
    },
    visiblePOS() {
      return this.s.pos.filter(
        (p) =>
          this.engine.allowed(p.agent) &&
          (this.actor.role !== "pos" || p.id === this.actor.pos),
      );
    },
    visibleCards() {
      return this.s.cards.filter((c) => this.engine.allowed(c.agent));
    },
    availableCards() {
      return this.visibleCards.filter(
        (c) => c.status === "Available" && c.expiry > Masal.day(),
      );
    },
    visibleBatches() {
      return this.s.batches.filter((b) => this.engine.allowed(b.agent));
    },
    visibleSales() {
      return this.s.sales.filter(
        (t) =>
          this.engine.allowed(t.agent) &&
          (this.actor.role !== "pos" || t.pos === this.actor.pos),
      );
    },
    visibleLedger() {
      return this.s.ledger.filter((l) =>
        this.accounts.some((a) => a.id === l.account),
      );
    },
    accounts() {
      return [
        ...(this.actor.role === "pos" ? [] : this.visibleAgents),
        ...this.visiblePOS,
      ];
    },
    totalWallets() {
      return this.accounts.reduce((n, a) => n + this.engine.balance(a.id), 0);
    },
    totalSales() {
      return this.visibleSales.reduce((n, t) => n + t.total, 0);
    },
    exceptions() {
      return this.visibleSales.filter((t) =>
        ["Print Failed", "Print Requested", "Reprint Requested"].includes(
          t.status,
        ),
      );
    },
    visibleClaims() {
      return this.s.claims.filter((c) => this.engine.allowed(c.agent));
    },
    visibleExports() {
      return this.s.exports.filter((c) => this.engine.allowed(c.agent));
    },
    visibleTickets() {
      return this.s.tickets.filter((c) => this.engine.allowed(c.agent));
    },
    visiblePriceRequests() {
      return this.s.priceRequests.filter((r) =>
        r.changes.every((c) => this.engine.allowed(c.agent)),
      );
    },
    visibleNotifications() {
      return this.s.notifications.filter(
        (n) =>
          this.actor.role === "owner" ||
          n.target === "all" ||
          this.accounts.some((a) => a.id === n.target),
      );
    },
    filteredRows() {
      let rows = this.s[this.schema?.key] || [];
      if (this.page === "agents") rows = this.visibleAgents;
      if (this.page === "pos") rows = this.visiblePOS;
      if (this.page === "users" && this.actor.role !== "owner")
        rows = rows.filter((u) => this.engine.allowed(u.agent));
      return rows.filter(
        (r) =>
          !this.search ||
          Object.values(r).some((v) =>
            String(v).toLowerCase().includes(this.search.toLowerCase()),
          ),
      );
    },
    batchRows() {
      return this.visibleBatches.filter(
        (b) =>
          (!this.statusFilter || b.status === this.statusFilter) &&
          (!this.search ||
            (
              b.id +
              " " +
              this.nameOf("products", b.product) +
              " " +
              this.nameOf("agents", b.agent)
            ).includes(this.search)),
      );
    },
    transactionRows() {
      return (this.page === "exceptions" ? this.exceptions : this.visibleSales)
        .filter(
          (t) =>
            (!this.statusFilter || t.status === this.statusFilter) &&
            (!this.search ||
              (t.id + this.nameOf("pos", t.pos)).includes(this.search)),
        )
        .slice()
        .reverse();
    },
    auditRows() {
      return this.s.audit.filter(
        (r) =>
          (this.actor.role === "owner" ||
            r.user === this.currentUser ||
            this.s.users.some(
              (u) => u.id === r.user && this.engine.allowed(u.agent),
            )) &&
          (!this.search ||
            [r.action, r.name, r.entity].join(" ").includes(this.search)),
      );
    },
    selectedPOS() {
      return this.s.pos.find((p) => p.id === this.saleForm.pos);
    },
    selectedProduct() {
      return this.s.products.find((p) => p.id === this.saleForm.product);
    },
    receiptCards() {
      return this.modal?.tx
        ? this.s.cards.filter((c) => this.modal.tx.cards.includes(c.id))
        : [];
    },
    receiptAgent() {
      return (
        this.s.agents.find(
          (a) => a.id === this.engine.main(this.modal?.tx?.agent),
        ) || {}
      );
    },
    mapPOS() {
      return this.visiblePOS.filter(
        (p) =>
          !this.mapAgent ||
          this.engine.descendants(this.mapAgent).includes(p.agent),
      );
    },
    chart() {
      return Array.from({ length: 7 }, (_, i) => {
        const d = new Date();
        d.setDate(d.getDate() - (6 - i));
        const key = Masal.businessDay(d);
        return {
          label: MasalLocale.date(d, this.lang, "weekday"),
          today: i === 6,
          value: this.visibleSales
            .filter((t) => Masal.businessDay(t.time) === key)
            .reduce((n, t) => n + t.total, 0),
        };
      });
    },
    chartMax() {
      return Math.max(1, ...this.chart.map((b) => b.value));
    },
    weekTotal() {
      return this.chart.reduce((n, b) => n + b.value, 0);
    },
    reportSales() {
      return this.visibleSales.filter(
        (t) =>
          (!this.reportFrom || Masal.businessDay(t.time) >= this.reportFrom) &&
          (!this.reportTo || Masal.businessDay(t.time) <= this.reportTo),
      );
    },
    reportRows() {
      return this.s.products.map((p) => {
        const tx = this.reportSales.filter((t) => t.product === p.id);
        return {
          name: p.name,
          quantity: tx.reduce((n, t) => n + t.quantity, 0),
          sales: tx.reduce((n, t) => n + t.total, 0),
          cost: tx.reduce((n, t) => n + t.cost, 0),
        };
      });
    },
    reportMetrics() {
      const sales = this.reportSales.reduce((n, t) => n + t.total, 0),
        cost = this.reportSales.reduce((n, t) => n + t.cost, 0);
      return [
        { label: "إجمالي المبيعات", value: sales, unit: "د.ع" },
        { label: "تكلفة البطاقات المباعة", value: cost, unit: "د.ع" },
        { label: "الربح الإجمالي", value: sales - cost, unit: "د.ع" },
        {
          label: "عدد البطاقات الصادرة",
          value: this.reportSales.reduce((n, t) => n + t.quantity, 0),
          unit: "بطاقة",
        },
      ];
    },
    monitorMetrics() {
      const done = this.visibleSales.filter((t) =>
        ["Printed", "Reprinted"].includes(t.status),
      ).length;
      return [
        {
          label: "نجاح الطباعة",
          value: this.visibleSales.length
            ? Math.round((done / this.visibleSales.length) * 100) + "%"
            : "—",
          note: "بحسب تأكيد الطباعة المحلي",
        },
        {
          label: "العمليات المعلقة / الفاشلة",
          value: this.exceptions.length,
          note: "تحتاج متابعة",
        },
        {
          label: "الأجهزة المتصلة",
          value: this.visiblePOS.filter((p) => p.online).length,
          note: "محاكاة حالة الاتصال",
        },
        {
          label: "إجمالي أحداث التدقيق",
          value: this.auditRows.length,
          note: "في نطاق الحساب",
        },
      ];
    },
    alerts() {
      const out = [];
      for (const b of this.visibleBatches) {
        const cards = this.s.cards.filter(
          (c) => c.batch === b.id && c.status === "Available",
        );
        const days = Math.ceil((Date.parse(b.expiry) - Date.now()) / 86400000);
        if (cards.length && days <= this.s.settings.expiryDays)
          out.push({
            title: days < 0 ? "دفعة منتهية" : "دفعة تقترب من الانتهاء",
            body: `${this.nameOf("products", b.product)} · ${cards.length} بطاقة · ${b.id}`,
            page: "inventory",
          });
        if (cards.length < 5 && b.status === "Loaded")
          out.push({
            title: "مخزون منخفض",
            body:
              this.nameOf("products", b.product) +
              " · " +
              cards.length +
              " بطاقة",
            page: "inventory",
          });
      }
      for (const t of this.exceptions)
        out.push({
          title: statusNames[t.status],
          body: t.id + " · " + this.nameOf("pos", t.pos),
          page: "exceptions",
        });
      for (const p of this.visiblePOS.filter((p) => !p.online))
        out.push({ title: "جهاز غير متصل", body: p.name, page: "pos" });
      return out;
    },
  },
  watch: {
    modal(value) {
      if (value) {
        this._lastFocus = document.activeElement;
        this.$nextTick(() =>
          document
            .querySelector(
              ".modal input:not([type=file]),.modal select,.modal textarea,.modal button",
            )
            ?.focus(),
        );
      } else this.$nextTick(() => this._lastFocus?.focus?.());
    },
    s: {
      deep: true,
      handler() {
        this.persist();
      },
    },
    priceAgent() {
      this.priceDraft = {};
    },
  },
  methods: {
    tr(value) {
      return this.t(value);
    },
    t(value) {
      if (typeof value !== "string")
        return MasalLocale.translate(value, this.lang);
      const saved = [];
      let source = value;
      const names = [
        ...this.s.agents,
        ...this.s.pos,
        ...this.s.products,
        ...this.s.providers,
      ]
        .map((r) => r.name)
        .filter(Boolean)
        .sort((a, b) => b.length - a.length);
      for (const name of names) {
        if (source.includes(name)) {
          const key = "__RECORD_" + saved.length + "__";
          saved.push([key, name]);
          source = source.split(name).join(key);
        }
      }
      source = MasalLocale.translate(source, this.lang);
      for (const [key, name] of saved)
        source = source.split(key).join(MasalLocale.latin(name));
      return source;
    },
    setLanguage() {
      if (!["ar", "en", "ckb"].includes(this.lang)) this.lang = "ar";
      document.documentElement.lang = this.lang;
      document.documentElement.dir = this.lang === "en" ? "ltr" : "rtl";
      try {
        localStorage.setItem("masal-language", this.lang);
      } catch (e) {}
    },
    toggleSidebar() {
      if (this.viewportMobile) {
        this.menuOpen = !this.menuOpen;
        if (this.menuOpen)
          this.$nextTick(() =>
            document.querySelector(".sidebar-navigation button")?.focus(),
          );
        return;
      }
      this.sidebarCollapsed = !this.sidebarCollapsed;
      this.sidebarHoverOpen = false;
      try {
        localStorage.setItem(
          "masal-sidebar-collapsed",
          String(this.sidebarCollapsed),
        );
      } catch {}
    },
    toggleNavGroup(title) {
      const opening =
        (!this.viewportMobile && this.sidebarCollapsed) ||
        this.collapsedGroups[title];
      if (!this.viewportMobile && this.sidebarCollapsed) {
        this.sidebarCollapsed = false;
        try {
          localStorage.setItem("masal-sidebar-collapsed", "false");
        } catch {}
      }
      for (const key of Object.keys(this.collapsedGroups))
        this.collapsedGroups[key] = true;
      this.collapsedGroups[title] = !opening;
    },
    openQuickActions() {
      this.modal = { kind: "quick", title: "إجراء سريع" };
    },
    quickAction(page, edit = false) {
      this.closeModal();
      this.go(page);
      if (edit && this.page === page) this.openEdit();
    },
    reviewTransfer() {
      this.run(() => {
        const x = Masal.clone(this.transferForm);
        const check = new Masal.Engine(Masal.clone(this.s), this.currentUser);
        check.transfer(x.from, x.to, x.amount);
        this.modal = {
          kind: "transferReview",
          title: "مراجعة التحويل",
          transfer: x,
        };
      });
    },
    confirmTransfer() {
      this.run(() => {
        const x = this.modal.transfer;
        this.engine.transfer(x.from, x.to, x.amount);
        this.closeModal();
      }, "تم التحويل وتسجيل قيدين متقابلين");
    },
    toggleTheme() {
      this.theme = this.theme === "light" ? "dark" : "light";
      document.documentElement.dataset.theme = this.theme;
      try {
        localStorage.setItem("masal-appearance", this.theme);
      } catch (e) {
        this.notify("تعذر حفظ تفضيل المظهر", true);
      }
    },
    money(n) {
      return new Intl.NumberFormat("en-US", {
        maximumFractionDigits: 2,
      }).format(Number(n) || 0);
    },
    formatTime(t) {
      return MasalLocale.date(t, this.lang, "short");
    },
    status(v) {
      return statusNames[v] || v;
    },
    statusClass(v) {
      return /Failed|Written/.test(v)
        ? "bad"
        : /Requested|Quarantined|Exported|Awaiting/.test(v)
          ? "warn"
          : /Cancel/.test(v)
            ? "neutral"
            : "";
    },
    nameOf(key, id) {
      return (
        this.s[key]?.find((x) => x.id === id)?.name ||
        this.s[key]?.find((x) => x.id === id)?.title ||
        id ||
        "—"
      );
    },
    accountName(id) {
      return (
        this.s.agents.find((a) => a.id === id)?.name ||
        this.s.pos.find((p) => p.id === id)?.name ||
        id
      );
    },
    priceFor(agent, product) {
      return (
        this.s.prices.find(
          (p) =>
            p.agent === agent &&
            p.product === product &&
            p.effective <= Masal.day(),
        )?.price || 0
      );
    },
    notify(text, error = false) {
      clearTimeout(this.toastTimer);
      this.toast = { text, error };
      this.toastTimer = setTimeout(() => (this.toast = null), 5000);
    },
    run(fn, message) {
      try {
        const r = fn();
        if (message) this.notify(message);
        return r;
      } catch (e) {
        this.notify(e.message, true);
        return undefined;
      }
    },
    persist() {
      try {
        MasalStateStorage.write(this.s);
        this.saveState = "محفوظ محليًا";
      } catch (e) {
        this.saveState = "تعذر الحفظ محليًا";
        this.notify(e.message, true);
      }
    },
    go(page) {
      if (!this.can(page + ".view")) {
        this.notify("هذه الوحدة خارج صلاحيات الحساب", true);
        return;
      }
      this.page = page;
      const navGroup = NAV.find((g) => g.items.some((n) => n.id === page));
      for (const key of Object.keys(this.collapsedGroups))
        this.collapsedGroups[key] = true;
      if (navGroup) this.collapsedGroups[navGroup.title] = false;
      this.search = "";
      this.statusFilter = "";
      this.menuOpen = false;
      this.navSearch = "";
      location.hash = page;
      if (page === "security")
        this.settingsDraft = Masal.clone(this.s.settings);
      if (page === "branding") this.loadBrand();
      window.scrollTo(0, 0);
    },
    switchUser() {
      this.modal = null;
      this.page = this.navGroups.flatMap((g) => g.items)[0]?.id || "denied";
      this.priceAgent = this.visibleAgents[0]?.id || "";
      this.brandAgent =
        this.visibleAgents.find((a) => a.type === "رئيسي")?.id || "";
      this.imp.agent = this.brandAgent;
      this.saleForm.pos = this.visiblePOS[0]?.id || "";
      this.transferForm.from =
        this.actor.agent || this.visibleAgents[0]?.id || "";
      this.transferForm.to = this.visiblePOS[0]?.id || "";
      this.notificationForm.target =
        this.actor.role === "owner"
          ? "all"
          : this.actor.agent || this.visibleAgents[0]?.id;
      this.integrationForm.agent = this.brandAgent;
      this.claimForm.batch = this.visibleBatches[0]?.id || "";
      this.engine.log("تبديل حساب محلي", this.currentUser, null, {
        role: this.actor.role,
      });
      this.notify("تم تغيير نطاق العرض إلى " + this.actor.name);
    },
    closeModal() {
      this.modal = null;
      this.reason = "";
      this.reply = "";
    },
    inspect(data) {
      const sale = this.s.sales.find((t) => t.id === data?.id);
      if (sale) {
        this.engine.require(sale.agent);
        if (this.actor.role === "pos" && sale.pos !== this.actor.pos)
          throw Error("العملية خارج نطاق نقطة البيع");
        this.modal = { kind: "saleDetails", title: "تفاصيل عملية البيع", data };
        return;
      }
      this.modal = { kind: "inspect", title: "تفاصيل السجل", data };
    },
    optionsFor(f) {
      if (Array.isArray(f.options))
        return f.options.map((x) =>
          typeof x === "string" ? { value: x, label: x } : x,
        );
      let items = this.s[f.options] || [];
      if (f.options === "agents")
        items = this.visibleAgents.filter((a) => a.id !== this.editForm.id);
      if (f.options === "pos") items = this.visiblePOS;
      return items.map((x) => ({ value: x.id, label: x.name }));
    },
    displayField(row, col) {
      if (col.options && typeof col.options === "string")
        return this.nameOf(col.options, row[col.key]);
      if (col.key === "role")
        return {
          owner: "مدير النظام",
          supervisor: "مشرف",
          main: "رئيسي",
          sub: "فرعي",
          pos: "نقطة بيع",
          employee: "موظف النظام",
        }[row.role];
      const v = row[col.key];
      return typeof v === "string" && v.length > 90
        ? v.slice(0, 90) + "…"
        : (v ?? "—");
    },
    openEdit(row) {
      this.editForm = row
        ? Masal.clone(row)
        : {
            active: true,
            color: "#0284c7",
            connection: "ملفات",
            type: "رئيسي",
            parent: "",
            kind: "محلية",
            currency: "IQD",
            limit: 10,
            dailyQty: 100,
            dailyAmount: 500000,
            fields: "serial,pin,expiry",
            order: 1,
            reprint: 5,
            version: "1.0.0",
            role: "sub",
            agent: this.visibleAgents[0]?.id || "",
            assignedText: "",
            online: false,
          };
      if (row?.assigned) this.editForm.assignedText = row.assigned.join(",");
      this.modal = {
        kind: "edit",
        title: row ? "تعديل " + (row.name || row.title) : this.schema.add,
      };
    },
    saveEntity() {
      this.run(() => {
        const key = this.schema.key,
          v = Masal.clone(this.editForm),
          old = this.s[key].find((x) => x.id === v.id);
        if (this.page === "users") this.engine.require(null);
        else this.engine.require(null);
        for (const f of this.schema.fields) {
          if (
            f.required !== false &&
            (v[f.key] === undefined ||
              v[f.key] === null ||
              String(v[f.key]).trim() === "")
          )
            throw Error("أكمل حقل " + f.label);
          if (f.type === "number") {
            v[f.key] = Number(v[f.key]);
            if (
              !Number.isFinite(v[f.key]) ||
              (f.min !== undefined && v[f.key] < f.min) ||
              (f.max !== undefined && v[f.key] > f.max)
            )
              throw Error("قيمة غير صحيحة: " + f.label);
          }
        }
        if (key === "agents") {
          if (!old && !this.s.settings.registration)
            throw Error("تسجيل الوكلاء موقوف");
          if (old) {
            this.engine.require(old.id);
            if (v.parent !== old.parent || v.type !== old.type)
              throw Error(
                "نقل الوكيل أو تغيير مستواه يحتاج ترحيلًا ماليًا؛ غير متاح من التعديل",
              );
          }
          if (v.type === "فرعي" && !v.parent)
            throw Error("الوكيل الفرعي يحتاج وكيلاً أعلى");
          if (v.type === "رئيسي" && v.parent)
            throw Error("الوكيل الرئيسي لا يتبع وكيلاً آخر");
          if (!this.can("agents.createMain") && v.type === "رئيسي" && !old)
            throw Error("إضافة رئيسي من صلاحية الإدارة");
          if (v.parent) {
            this.engine.require(v.parent);
            if (v.id && this.engine.descendants(v.id).includes(v.parent))
              throw Error("لا يمكن إنشاء حلقة في الشجرة");
          }
        }
        if (key === "pos") {
          this.engine.require(v.agent);
          if (old) this.engine.require(old.agent);
          if (old && old.agent !== v.agent)
            throw Error("نقل نقطة بين الوكلاء يحتاج تسوية مستقلة");
          if (this.s.pos.some((p) => p.id !== v.id && p.serial === v.serial))
            throw Error("الجهاز مرتبط بنقطة أخرى");
        }
        if (key === "products") {
          if (!v.fields.split(",").includes("pin"))
            throw Error("قالب المنتج يجب أن يحتوي pin");
          if (
            v.fields
              .split(",")
              .some(
                (f) =>
                  !["serial", "pin", "expiry", "cvc", "reference"].includes(
                    f.trim(),
                  ),
              )
          )
            throw Error("حقل بطاقة غير مدعوم");
        }
        if (key === "users") {
          if (v.id === this.currentUser && v.role !== this.actor.role)
            throw Error("لا تغير دور حسابك الحالي");
          if (
            this.actor.role !== "owner" &&
            ["owner", "supervisor", "main"].includes(v.role)
          )
            throw Error("لا يمكنك منح هذا الدور");
          if (!["owner", "supervisor", "employee"].includes(v.role)) {
            this.engine.require(v.agent);
            const a = this.s.agents.find((a) => a.id === v.agent);
            if (
              (v.role === "main" && a?.type !== "رئيسي") ||
              (v.role === "sub" && a?.type !== "فرعي")
            )
              throw Error("الدور لا يطابق مستوى الوكيل");
          }
          if (
            v.role === "pos" &&
            !this.s.pos.some((p) => p.id === v.pos && p.agent === v.agent)
          )
            throw Error("اختر نقطة تتبع الوكيل المحدد");
          v.assigned = (v.assignedText || "")
            .split(",")
            .map((x) => x.trim())
            .filter(Boolean);
          if (v.assigned.some((a) => !this.engine.allowed(a)))
            throw Error("نطاق مشرف غير صالح");
          delete v.assignedText;
        }
        if (!v.id) v.id = Masal.id(key.toUpperCase());
        this.engine.log(old ? "تعديل سجل" : "إنشاء سجل", v.id, old || null, v);
        if (old) Object.assign(old, v);
        else this.s[key].push(v);
        this.closeModal();
      }, "تم حفظ البيانات");
    },
    toggleEntity(row) {
      this.run(() => {
        if (row.id === this.currentUser)
          throw Error("لا يمكنك إيقاف حسابك الحالي");
        if (["agents", "pos"].includes(this.page))
          this.engine.require(this.page === "agents" ? row.id : row.agent);
        else this.engine.require(null);
        const before = row.active;
        row.active = !before;
        this.engine.log("تغيير التفعيل", row.id, before, row.active);
      }, "تم تحديث الحالة");
    },
    logoutPOS(p) {
      this.run(() => {
        this.engine.require(p.agent);
        p.online = false;
        p.lastSeen = new Date().toISOString();
        this.engine.log("إنهاء جلسة جهاز محلي", p.id, true, false);
      }, "تم إنهاء جلسة الجهاز في المحاكاة");
    },
    logoutAll() {
      this.run(() => {
        this.engine.require(null);
        for (const p of this.visiblePOS) {
          p.online = false;
          this.engine.log("إنهاء جلسة جهاز محلي", p.id, true, false);
        }
      }, "تم إنهاء جلسات النطاق الحالي");
    },
    downloadImportTemplate() {
      download(
        "masal-cards-template.csv",
        csv([
          {
            serial: "DEMO-SERIAL-NEW",
            pin: "DEMO-NOT-VALID-NEW",
            expiry: "2027-12-31",
            cvc: "DEMO",
            reference: "SAMPLE",
          },
        ]),
        "text/csv;charset=utf-8",
      );
    },
    async readImport(e) {
      const f = e.target.files[0];
      if (!f) return;
      if (f.size > 5000000) {
        this.notify("حد الملف التجريبي 5 ميغابايت", true);
        return;
      }
      this.importText = await f.text();
    },
    nextImport() {
      this.run(() => {
        if (this.importStep === 0) {
          if (!this.imp.city.trim() || !this.imp.supplier.trim())
            throw Error("أكمل المدينة والمجهز");
          this.engine.validateImport(this.imp, []);
          this.importStep = 1;
        } else if (this.importStep === 1) {
          const rows = parseCSV(this.importText);
          this.importPreview = this.engine.validateImport(this.imp, rows);
          this.importStep = 2;
        } else {
          const b = this.engine.importBatch(
            this.imp,
            parseCSV(this.importText),
          );
          this.importStep = 0;
          this.importText = "";
          this.importPreview = [];
          this.go("inventory");
          this.notify(
            "تم تحميل " +
              b.quantity +
              " بطاقة مع تسجيل رصيدها التشغيلي وفاتورة الدفعة",
          );
        }
      });
    },
    inspectBatch(b) {
      this.modal = {
        kind: "batch",
        title: "بطاقات الدفعة " + b.id,
        cards: this.s.cards.filter((c) => c.batch === b.id),
      };
    },
    doBatch(b, action) {
      this.run(
        () => this.engine.batchAction(b.id, action),
        "تم تحديث حالة الدفعة",
      );
    },
    askCancelBatch(b) {
      this.modal = {
        kind: "confirm",
        title: "إلغاء الدفعة",
        message:
          "ستُمنع بطاقات الدفعة من البيع. لا يمكن إلغاء دفعة خرجت منها أي بطاقة.",
        action: () => this.engine.batchAction(b.id, "cancel"),
      };
    },
    confirmAction() {
      this.run(() => {
        this.modal.action();
        this.closeModal();
      }, "تم تنفيذ الإجراء");
    },
    transfer() {
      this.run(() => {
        this.engine.transfer(
          this.transferForm.from,
          this.transferForm.to,
          this.transferForm.amount,
        );
      }, "تم التحويل وتسجيل قيدين متقابلين");
    },
    openDeposit() {
      this.depositForm.account = this.accounts[0]?.id;
      this.modal = { kind: "deposit", title: "إيداع في المحفظة" };
    },
    deposit() {
      this.run(() => {
        this.engine.deposit(
          this.depositForm.account,
          this.depositForm.amount,
          this.depositForm.reference,
        );
        this.closeModal();
      }, "تم تسجيل الإيداع");
    },
    downloadPrices() {
      download(
        "masal-prices-v1.csv",
        csv(
          this.s.products.map((p) => ({
            version: 1,
            agent: this.priceAgent,
            product: p.id,
            price: this.priceFor(this.priceAgent, p.id),
          })),
        ),
        "text/csv;charset=utf-8",
      );
    },
    async importPrices(e) {
      const f = e.target.files[0];
      if (!f) return;
      try {
        const rows = parseCSV(await f.text());
        if (
          rows.some(
            (r) =>
              r.version !== "1" ||
              r.agent !== this.priceAgent ||
              !this.s.products.some((p) => p.id === r.product),
          )
        )
          throw Error("إصدار القالب أو الوكيل أو معرف المنتج غير صالح");
        const draft = {};
        for (const r of rows) {
          if (r.product in draft) throw Error("منتج مكرر");
          draft[r.product] = Number(r.price);
        }
        this.priceDraft = draft;
        this.notify("تم تحميل الأسعار؛ راجع المعاينة ثم أكد التعديل");
      } catch (e) {
        this.notify(e.message, true);
      }
      e.target.value = "";
    },
    submitPrices() {
      this.run(
        () => {
          const changes = Object.entries(this.priceDraft)
            .filter(
              ([p, v]) =>
                v !== "" &&
                v !== undefined &&
                +v !== this.priceFor(this.priceAgent, p),
            )
            .map(([product, price]) => ({
              agent: this.priceAgent,
              product,
              price,
            }));
          this.engine.proposePrices(changes);
          this.priceDraft = {};
        },
        ["owner", "main"].includes(this.actor.role)
          ? "تم تطبيق الأسعار مباشرة للوكيل المحدد"
          : "أرسل الطلب للمراجعة؛ يلزم اعتماد مستخدم آخر",
      );
    },
    approvePrices(r) {
      this.run(() => this.engine.approvePrices(r.id), "تم اعتماد الأسعار");
    },
    reversePrices(r) {
      this.run(
        () => this.engine.reversePrices(r.id),
        "تم إرجاع الأسعار السابقة",
      );
    },
    sell() {
      this.run(() => {
        const tx = this.engine.sell(
          this.saleForm.pos,
          this.saleForm.product,
          this.saleForm.quantity,
          this.saleKey,
        );
        this.saleKey = Masal.id("SALE");
        this.viewReceipt(tx);
        this.notify("تم الإصدار والخصم مرة واحدة؛ أكد نتيجة الطباعة");
      });
    },
    viewReceipt(t) {
      this.run(() => {
        this.engine.require(t.agent);
        if (this.actor.role === "pos" && this.actor.pos !== t.pos)
          throw Error("غير مسموح");
        this.modal = { kind: "receipt", title: "وصل العملية", tx: t };
      });
    },
    printReceipt() {
      try {
        this.engine.requirePermission("sell.print");
        this.engine.assertPrintReady(this.modal.tx.id);
      } catch (e) {
        this.notify(e.message, true);
        return;
      }
      const receipt = document.querySelector(".receipt");
      if (receipt) {
        const width = Math.max(
          58,
          Math.min(100, Number(this.receiptAgent.width) || 80),
        );
        const height = Math.max(
          100,
          Math.ceil(((receipt.scrollHeight * 25.4) / 96) * 1.15) + 12,
        );
        let style = document.getElementById("receipt-paper-size");
        if (!style) {
          style = document.createElement("style");
          style.id = "receipt-paper-size";
          document.head.appendChild(style);
        }
        style.textContent =
          "@page masal-receipt { size: " +
          width +
          "mm " +
          height +
          "mm; margin: 4mm; }";
      }
      window.print();
    },
    printResult(ok) {
      this.run(
        () => {
          this.engine.printResult(this.modal.tx.id, ok);
        },
        ok
          ? "تم تسجيل نجاح الطباعة"
          : "تم تسجيل الفشل؛ البطاقة نفسها محفوظة لإعادة الطباعة",
      );
    },
    openReprint(t) {
      if (["Print Requested", "Reprint Requested"].includes(t.status)) {
        this.viewReceipt(t);
        return;
      }
      this.reason = "";
      this.modal = {
        kind: "reprint",
        title: "إعادة طباعة البطاقة نفسها",
        tx: t,
      };
    },
    submitReprint() {
      this.run(() => {
        const t = this.engine.reprint(this.modal.tx.id, this.reason);
        this.reason = "";
        this.closeModal();
      }, "أرسل الطلب؛ بانتظار موافقة الأعلى دون خصم جديد");
    },
    createClaim() {
      this.run(
        () => this.engine.claim(this.claimForm.batch, this.claimForm.reason),
        "تم حجر المتبقي وفتح المطالبة",
      );
    },
    settle(c) {
      this.run(
        () => this.engine.settle(c.id, this.settlements[c.id]),
        "تم تسجيل نتيجة المزود؛ لا تنشئ التسوية إيداعًا نقديًا تلقائيًا",
      );
    },
    async exportEncrypted() {
      const exportActor = this.currentUser;
      try {
        if (!crypto.subtle)
          throw Error("التشفير غير متاح في هذا المتصفح؛ استخدم Edge أو Chrome");
        const b = this.s.batches.find((b) => b.id === this.claimForm.batch);
        if (!b) throw Error("اختر دفعة");
        this.engine.require(b.agent);
        if (this.exportPassword.length < 12)
          throw Error("كلمة التشفير يجب ألا تقل عن 12 حرفًا");
        if (!this.claimForm.reason.trim()) throw Error("سبب التصدير مطلوب");
        const cards = this.s.cards.filter(
          (c) =>
            c.batch === b.id && ["Available", "Quarantined"].includes(c.status),
        );
        if (!cards.length) throw Error("لا توجد بطاقات قابلة للتصدير");
        const salt = crypto.getRandomValues(new Uint8Array(16)),
          iv = crypto.getRandomValues(new Uint8Array(12)),
          enc = new TextEncoder();
        const material = await crypto.subtle.importKey(
          "raw",
          enc.encode(this.exportPassword),
          "PBKDF2",
          false,
          ["deriveKey"],
        );
        const key = await crypto.subtle.deriveKey(
          { name: "PBKDF2", salt, iterations: 210000, hash: "SHA-256" },
          material,
          { name: "AES-GCM", length: 256 },
          false,
          ["encrypt"],
        );
        const plain = csv(
          cards.map((c) => ({
            serial: c.serial,
            internal: c.internal,
            pin: c.pin,
            expiry: c.expiry,
            cvc: c.cvc,
            reference: c.reference,
          })),
        );
        const encrypted = await crypto.subtle.encrypt(
          { name: "AES-GCM", iv },
          key,
          enc.encode(plain),
        );
        const b64 = (x) => {
          let r = "";
          for (const v of new Uint8Array(x)) r += String.fromCharCode(v);
          return btoa(r);
        };
        const output = {
          format: "masal-encrypted-v1",
          algorithm: "AES-256-GCM",
          kdf: "PBKDF2-SHA256",
          iterations: 210000,
          salt: b64(salt),
          iv: b64(iv),
          data: b64(encrypted),
        };
        if (exportActor !== this.currentUser)
          throw Error("تغير المستخدم أثناء التصدير");
        this.engine.requirePermission("exports.encrypt", b.agent);
        this.engine.requirePermission("data.pin");
        cards.forEach((c) => (c.status = "Exported"));
        b.status = "Exported";
        const r = {
          id: Masal.id("EXP"),
          batch: b.id,
          agent: b.agent,
          quantity: cards.length,
          value: cards.reduce((n, c) => n + c.cost, 0),
          reason: this.claimForm.reason,
          time: new Date().toISOString(),
        };
        this.s.exports.unshift(r);
        this.engine.log("تصدير دفعة مشفرة", r.id, null, r);
        download("masal-" + b.id + ".encrypted.json", JSON.stringify(output));
        this.exportPassword = "";
        this.claimForm.reason = "";
        this.notify("تم تنزيل الملف المشفر ومنع البطاقات المصدرة من البيع");
      } catch (e) {
        this.notify(e.message, true);
      }
    },
    saveIntegration() {
      this.run(() => {
        this.engine.require(this.integrationForm.agent);
        const key =
          this.integrationForm.agent + "-" + this.integrationForm.provider;
        const old = this.s.integrations.find((i) => i.id === key),
          r = {
            ...this.integrationForm,
            id: key,
            time: new Date().toISOString(),
          };
        this.engine.log("إعداد تكامل", key, old, r);
        if (old) Object.assign(old, r);
        else this.s.integrations.push(r);
      }, "حُفظت الإعدادات؛ الربط الفعلي غير متصل");
    },
    async readImage(e, target, key) {
      const file = e.target.files[0];
      if (!file) return;
      if (
        !["image/png", "image/jpeg"].includes(file.type) ||
        file.size > 700000
      ) {
        this.notify("اختر PNG أو JPG بحجم أقل من 700 كيلوبايت", true);
        return;
      }
      const reader = new FileReader();
      reader.onload = () => (target[key] = reader.result);
      reader.readAsDataURL(file);
    },
    sendNotification() {
      this.run(() => {
        this.engine.require(null);
        if (
          this.notificationForm.target === "all" &&
          !this.can("notifications.broadcast")
        )
          throw Error("الإشعار العام من صلاحية مدير النظام");
        if (
          this.notificationForm.target !== "all" &&
          !this.accounts.some((a) => a.id === this.notificationForm.target)
        )
          throw Error("الجمهور خارج النطاق");
        const n = {
          ...this.notificationForm,
          id: Masal.id("NT"),
          time: new Date().toISOString(),
          user: this.currentUser,
        };
        this.s.notifications.unshift(n);
        this.engine.log("إضافة إشعار محلي", n.id, null, {
          target: n.target,
          title: n.title,
        });
        this.notificationForm.title = "";
        this.notificationForm.body = "";
        this.notificationForm.image = "";
      }, "أضيف الإشعار محليًا؛ لم يرسل إلى أجهزة خارجية");
    },
    openTicket() {
      this.ticketForm = {
        agent: this.actor.agent || this.visibleAgents[0]?.id,
        title: "",
        description: "",
        priority: "متوسطة",
      };
      this.modal = { kind: "newTicket", title: "فتح تذكرة دعم" };
    },
    saveTicket() {
      this.run(() => {
        this.engine.require(this.ticketForm.agent);
        const t = {
          ...this.ticketForm,
          id: Masal.id("T"),
          status: "مفتوحة",
          replies: [],
          time: new Date().toISOString(),
        };
        this.s.tickets.unshift(t);
        this.engine.log("فتح تذكرة", t.id, null, {
          title: t.title,
          agent: t.agent,
        });
        this.closeModal();
      }, "تم فتح التذكرة محليًا");
    },
    showTicket(t) {
      this.reply = "";
      this.modal = { kind: "ticket", title: t.title, ticket: t };
    },
    replyTicket() {
      this.run(() => {
        const t = this.modal.ticket;
        this.engine.require(t.agent);
        if (t.status === "مغلقة") throw Error("التذكرة مغلقة");
        t.replies.push({
          body: this.reply,
          user: this.currentUser,
          time: new Date().toISOString(),
        });
        this.engine.log("رد على تذكرة", t.id, null, this.reply);
        this.reply = "";
      }, "تم حفظ الرد");
    },
    ticketStatus(status) {
      this.run(() => {
        const t = this.modal.ticket;
        this.engine.require(t.agent);
        this.engine.log("حالة التذكرة", t.id, t.status, status);
        t.status = status;
      }, "تم تحديث حالة التذكرة");
    },
    mapX(lng) {
      return 55 + (Number(lng) - 39) * 43;
    },
    mapY(lat) {
      return 355 - (Number(lat) - 29) * 42;
    },
    setToggle(k, value) {
      try {
        this.engine.requirePermission("security." + k);
        const old = this.s.settings[k];
        this.s.settings[k] = value;
        this.engine.log("تغيير مفتاح التشغيل", k, old, value);
        this.notify("تم تحديث إعداد التشغيل");
      } catch (e) {
        this.notify(e.message, true);
        this.$forceUpdate();
      }
    },
    saveSettings() {
      this.run(() => {
        this.engine.require(null);
        for (const key of [
          "velocity",
          "expiryDays",
          "providerDaily",
          "reprint",
          "idle",
        ])
          if (
            !Number.isFinite(+this.settingsDraft[key]) ||
            +this.settingsDraft[key] < 0
          )
            throw Error("قيمة غير صحيحة");
        const old = Masal.clone(this.s.settings);
        for (const k of [
          "velocity",
          "expiryDays",
          "providerDaily",
          "reprint",
          "minVersion",
          "idle",
          "blockedIPs",
        ])
          this.s.settings[k] = this.settingsDraft[k];
        this.engine.log("تغيير السياسات", "settings", old, this.s.settings);
      }, "تم حفظ السياسات");
    },
    loadBrand() {
      const a = this.s.agents.find((a) => a.id === this.brandAgent);
      this.brandDraft = a
        ? {
            color: a.color || "#0f766e",
            logo: a.logo || "",
            support: a.support || "",
            header: a.header || "",
            footer: a.footer || "",
            width: a.width || 80,
          }
        : {};
    },
    saveBrand() {
      this.run(() => {
        this.engine.require(this.brandAgent);
        const a = this.s.agents.find((a) => a.id === this.brandAgent);
        if (!a) throw Error("اختر الوكيل");
        const old = Masal.clone(a);
        const { reprint, ...design } = this.brandDraft;
        Object.assign(a, design);
        this.engine.log("تغيير هوية الوكيل", a.id, old, a);
      }, "تم حفظ الهوية وقالب الوصل");
    },
    submitContact() {
      this.run(() => {
        const a = this.s.agents.find(
          (a) => a.type === "رئيسي" && a.city === this.contact.city,
        );
        const t = {
          id: Masal.id("CONTACT"),
          agent: a?.id || this.visibleAgents[0]?.id,
          title: "طلب تواصل: " + this.contact.name,
          description:
            this.contact.message +
            "\n" +
            this.contact.phone +
            " • " +
            this.contact.city,
          status: "مفتوحة",
          priority: "متوسطة",
          replies: [],
          time: new Date().toISOString(),
        };
        this.s.tickets.unshift(t);
        this.engine.log("طلب تواصل محلي", t.id, null, {
          city: this.contact.city,
        });
        this.contact = { name: "", phone: "", city: "", message: "" };
      }, "حُفظ الطلب محليًا في الدعم الفني؛ لا يوجد إرسال خارجي");
    },
    exportReport() {
      download(
        "masal-report.csv",
        csv(this.reportRows.map((r) => ({ ...r, profit: r.sales - r.cost }))),
        "text/csv;charset=utf-8",
      );
    },
    exportCurrent() {
      let rows;
      if (this.schema)
        rows = this.filteredRows.map((r) =>
          Object.fromEntries(
            ["id", ...this.schema.columns.map((c) => c.key)].map((k) => [
              k,
              r[k],
            ]),
          ),
        );
      else if (this.page === "reports") {
        this.exportReport();
        return;
      } else if (this.page === "audit")
        rows = this.auditRows.map((r) => ({
          time: r.time,
          user: r.name,
          action: r.action,
          entity: r.entity,
        }));
      else if (this.page === "wallets") rows = this.visibleLedger;
      else if (this.page === "map")
        rows = this.mapPOS.map((p) => ({
          name: p.name,
          agent: p.agent,
          lat: p.lat,
          lng: p.lng,
          city: p.city,
        }));
      else if (["inventory", "batches", "import"].includes(this.page))
        rows = this.visibleBatches;
      else if (this.page === "claims") rows = this.visibleClaims;
      else if (this.page === "exports") rows = this.visibleExports;
      else
        rows = this.visibleSales.map((t) => ({
          id: t.id,
          time: t.time,
          pos: this.nameOf("pos", t.pos),
          product: this.nameOf("products", t.product),
          quantity: t.quantity,
          total: t.total,
          status: this.status(t.status),
        }));
      if (!rows.length) {
        this.notify("لا توجد بيانات للتصدير");
        return;
      }
      download(
        "masal-" + this.page + ".csv",
        csv(rows),
        "text/csv;charset=utf-8",
      );
      this.engine.log("تصدير تقرير", this.page, null, { count: rows.length });
      this.notify("تم تنزيل التقرير");
    },
    async backup() {
      if (this.serverBackupBusy) return;
      this.serverBackupBusy = true;
      const actor = this.currentUser;
      try {
        this.engine.requirePermission("backup.download");
        this.engine.require(null);
        if (this.actor.role !== "owner")
          throw Error("النسخ الاحتياطي متاح لمدير النظام فقط");
        if (typeof globalThis.MasalBackupServer?.create !== "function")
          throw Error("النسخ الاحتياطي على السيرفر غير مربوط بعد");
        const result = await globalThis.MasalBackupServer.create();
        if (actor !== this.currentUser)
          throw Error("تغير المستخدم؛ راجع سجل النسخ على السيرفر");
        this.engine.requirePermission("backup.download");
        if (
          !result ||
          result.status !== "completed" ||
          typeof result.id !== "string" ||
          !result.id.trim()
        )
          throw Error("لم يؤكد السيرفر اكتمال النسخ الاحتياطي");
        this.engine.log("نسخ احتياطي على السيرفر", result.id, null, {
          time: new Date().toISOString(),
        });
        this.notify("تم النسخ الاحتياطي على السيرفر");
      } catch (error) {
        this.notify(error.message, true);
      } finally {
        this.serverBackupBusy = false;
      }
    },
    async prepareRestore(e) {
      const f = e.target.files[0];
      if (!f) return;
      try {
        this.engine.require(null);
        if (f.size > 15000000)
          throw Error("الملف أكبر من الحد المحلي 15 ميغابايت");
        const state = JSON.parse(await f.text());
        validateBackup(state);
        this.modal = {
          kind: "confirm",
          title: "استعادة البيانات",
          message: `الملف يحتوي ${state.agents.length} وكلاء و${state.cards.length} بطاقة. ستستبدل البيانات الحالية. يُفضل تنزيل نسخة احتياطية أولًا.`,
          action: () => {
            this.s = state;
            this.currentUser = state.users.find(
              (u) => u.role === "owner" && u.active,
            ).id;
            this.engine.log("استعادة نسخة", "restore", null, {
              filename: f.name,
            });
            this.go("dashboard");
          },
        };
      } catch (e) {
        this.notify(e.message, true);
      }
      e.target.value = "";
    },
  },
  mounted() {
    this.sidebarMedia = window.matchMedia("(max-width:1100px)");
    this.sidebarMedia.addEventListener("change", (e) => {
      this.viewportMobile = e.matches;
      this.menuOpen = false;
    });
    this.setLanguage();
    this.loadBrand();
    const page = location.hash.slice(1);
    if (NAV.some((g) => g.items.some((n) => n.id === page))) this.go(page);
    window.addEventListener("hashchange", () => {
      const p = location.hash.slice(1);
      if (p !== this.page && NAV.some((g) => g.items.some((n) => n.id === p)))
        this.go(p);
    });
    window.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        this.closeModal();
        this.menuOpen = false;
      }
      if (e.key === "Tab" && this.modal) {
        const nodes = [
          ...document.querySelectorAll(
            ".modal button,.modal input,.modal select,.modal textarea,.modal a",
          ),
        ].filter((x) => !x.disabled && x.getClientRects().length);
        const first = nodes[0],
          last = nodes[nodes.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last?.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first?.focus();
        }
      }
    });
  },
};
MasalEnhancements.install(appOptions);
MasalStaffUI.install(appOptions);
MasalNetworkTree.install(appOptions);
MasalCardFields.install(appOptions);
MasalAuditDetails.install(appOptions);
MasalOperationsUI.install(appOptions);
MasalWorkflowUI.install(appOptions);
MasalCompletionUI.install(appOptions);
MasalNetworkAccounts.install(appOptions);
MasalSupportRouting.install(appOptions);
MasalSimpleNotifications.install(appOptions);
MasalFeatureUpdates.install(appOptions);
MasalFormLifecycle.install(appOptions);
MasalLoginScreen.install(appOptions);
MasalVisualCleanup.install(appOptions);
MasalDashboard.install(appOptions);
MasalMeetingUI.install(appOptions);
MasalCategoriesUI.install(appOptions);
MasalRegions.install(appOptions);
MasalCompanySite.install(appOptions);
MasalCardLayout.install(appOptions);
MasalProvidersUI.install(appOptions);
MasalInventoryUI.install(appOptions);
MasalCatalogFilters.install(appOptions);
MasalWalletFilters.install(appOptions);
const beforeNetworkFilter = appOptions.computed.filteredRows;
appOptions.computed.filteredRows = function () {
  const rows = beforeNetworkFilter.call(this);
  if (
    this.page !== "agents" ||
    !["main", "sub", "subsub"].includes(this.networkKind)
  )
    return rows;
  return rows.filter((a) =>
    this.networkKind === "main"
      ? a.type === "رئيسي"
      : this.networkKind === "subsub"
        ? a.type === "فرعي" &&
          this.s.agents.some((p) => p.id === a.parent && p.type === "فرعي")
        : a.type !== "رئيسي",
  );
};
appOptions.components["network-points-table"] = {
  computed: {
    vm() {
      return this.$root;
    },
    rows() {
      const q = this.vm.search.trim().toLowerCase();
      return this.vm.visiblePOS.filter(
        (p) =>
          !q ||
          [p.name, p.city, this.vm.nameOf("agents", p.agent)].some((v) =>
            String(v || "")
              .toLowerCase()
              .includes(q),
          ),
      );
    },
  },
};

appOptions.methods.navIconPath = function (id) {
  return (
    {
      company: "M4 21V4h16v17M8 8h3m3 0h2M8 12h3m3 0h2M9 21v-5h6v5",
      dashboard: "M3 10 12 3l9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z",
      reports: "M4 3v18h17M8 16v-5m5 5V6m5 10V9",
      sell: "M3 5h18v14H3ZM3 10h18M7 15h3",
      sales: "M8 3H5v18h14V3h-3M9 3h6v4H9ZM8 11h8m-8 4h8",
      inventory: "m3 7 9-4 9 4-9 4ZM3 7v10l9 4 9-4V7M12 11v10",
      import: "M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5",
      products: "M3 3h7v7H3Zm11 0h7v7h-7ZM3 14h7v7H3Zm11 0h7v7h-7Z",
      providers: "M4 21V4h11v17M15 10h5v11M2 21h20M8 8h3m-3 4h3m-3 4h3",
      prices: "M3 7h18M3 17h18M8 3v8m8 2v8",
      exceptions: "m12 3 10 18H2ZM12 9v5m0 3h.01",
      claims: "M6 3h9l4 4v14H6ZM14 3v5h5M9 12h7m-7 4h5",
      exports: "M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6",
      agents: "M9 3h6v5H9ZM3 16h6v5H3Zm12 0h6v5h-6ZM12 8v4M6 16v-4h12v4",
      pos: "M5 3h14v18H5ZM8 6h8v6H8Zm0 10h1m3 0h1m3 0h.01",
      wallets: "M3 6h17v15H3ZM3 6V3h14v3m-2 7h6v5h-6ZM17 15h.01",
      map: "m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2ZM9 3v16m6-14v16",
      support:
        "M4 13v-2a8 8 0 0 1 16 0v2M4 12H2v6h4v-6Zm16 0h2v6h-4v-6ZM20 18v3h-7",
      notifications: "M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M9 21h6",
      users:
        "M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-3a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v3",
      permissions: "M12 2 3 6v6c0 5 9 10 9 10s9-5 9-10V6ZM8 12l3 3 5-6",
      audit: "M5 3h14v18H5ZM8 7h8m-8 5h8m-8 5h5",
      monitoring: "M3 3h18v14H3ZM8 21h8m-4-4v4M5 11h3l2-5 4 8 2-4h3",
      security: "M5 10h14v11H5ZM8 10V6a4 4 0 0 1 8 0v4M12 14v3",
      branding:
        "M12 3a9 9 0 1 0 0 18h2a2 2 0 0 0 1-4 2 2 0 0 1 1-4h2a3 3 0 0 0 3-3 9 9 0 0 0-9-7ZM7 9h.01M11 6h.01M16 7h.01M6 14h.01",
      backup:
        "M7 18H5a4 4 0 0 1-1-8 8 8 0 0 1 15-1 4.5 4.5 0 0 1 0 9h-2M12 12v9m-3-3 3 3 3-3",
    }[id] || "M4 4h16v16H4Z"
  );
};
const priorMenuWatch = appOptions.watch.menuOpen;
appOptions.watch.menuOpen = function (open, old) {
  if (typeof priorMenuWatch === "function")
    priorMenuWatch.call(this, open, old);
  if (!open && this.viewportMobile)
    this.$nextTick(() => document.querySelector(".menuToggle")?.focus());
};
const priorSidebarMounted = appOptions.mounted;
appOptions.mounted = function () {
  priorSidebarMounted.call(this);
  document.addEventListener("keydown", (event) => {
    if (
      event.key !== "Tab" ||
      !this.viewportMobile ||
      !this.menuOpen ||
      this.modal
    )
      return;
    const nodes = [
      ...document.querySelectorAll(".sidebar button,.sidebar a"),
    ].filter(
      (e) => !e.disabled && !e.closest("[inert]") && e.getClientRects().length,
    );
    const first = nodes[0],
      last = nodes[nodes.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  });
};
MasalSellerAccounts.install(appOptions);
MasalPrintEscalation.install(appOptions);
MasalFundingRules.install(appOptions);
MasalCashOrders.install(appOptions);
MasalAuditFixes.install(appOptions);
MasalTablePagination.install(appOptions);
MasalSearchSelects.install(appOptions);
// User-requested one-time fresh start, with the previous browser data retained as a backup.
const freshStartData = appOptions.data;
appOptions.data = function () {
  const d = freshStartData.call(this),
    revision = "20260924-owner-only";
  if (d.s.freshStartRevision === revision) return d;
  const previous = MasalStateStorage.read();
  if (previous)
    localStorage.setItem("masal-backup-before-" + revision, previous);
  const owner = Masal.clone(
    d.s.users.find((u) => u.active && u.role === "owner"),
  );
  if (!owner) throw Error("تعذر العثور على حساب مدير النظام؛ لم يتم التفريغ");
  for (const [key, value] of Object.entries(d.s))
    if (Array.isArray(value)) d.s[key] = [];
  for (const [key, value] of Object.entries(d.s))
    if (
      value &&
      typeof value === "object" &&
      !Array.isArray(value) &&
      key !== "settings"
    )
      d.s[key] = {};
  d.s.users = [owner];
  owner.assigned = [];
  owner.agent = "";
  owner.pos = "";
  delete owner.permissionProfileId;
  d.s.companyProfile = {
    name: "ماسال",
    about: "",
    slides: [],
    activities: [],
    offers: [],
    projects: [],
    social: [],
  };
  d.s.governorates = MasalCategoriesUI.governorates.map((name) => ({
    name,
    active: false,
  }));
  d.s.settings = { ...Masal.seed().settings, support: "", email: "" };
  d.s.freshStartRevision = revision;
  d.currentUser = owner.id;
  d.brandAgent = "";
  d.priceAgent = "";
  d.permissionUser = "";
  MasalStateStorage.write(d.s);
  sessionStorage.removeItem("masal-login-user");
  return d;
};
// Provision the requested handoff login once; later password changes are preserved.
const handoffData = appOptions.data;
appOptions.data = function () {
  const d = handoffData.call(this);
  if (d.s.adminLoginRevision !== "20260924") {
    const owner = d.s.users.find((u) => u.active && u.role === "owner");
    if (!owner) throw Error("حساب مدير النظام غير موجود");
    owner.username = "admin";
    owner.credentials = {
      algorithm: "PBKDF2-SHA256",
      iterations: 210000,
      salt: "d1d134cb85c17da3495e2efe16d480ff",
      hash: "7aa7dba1e72c5e7e810d0362b3076d22164a53f83ab9bb13b62800446301a954",
    };
    owner.mustChangePassword = false;
    owner.twoFactor = false;
    d.s.adminLoginRevision = "20260924";
    MasalStateStorage.write(d.s);
    sessionStorage.removeItem("masal-login-user");
    d.loginScreen = true;
  }
  return d;
};
// Persistent, additive demonstration network. Never reseed on ordinary reloads.
(function () {
  const previousData = appOptions.data;
  appOptions.data = function () {
    const d = previousData.call(this),
      revision = "20260924-demo-network-v1";
    if (d.s.demoNetworkRevision === revision) return d;
    const s = Masal.clone(d.s),
      owner = s.users.find((u) => u.active && u.role === "owner");
    const ids = ["DEMO-MAIN", "DEMO-BRANCH", "DEMO-SUBBRANCH"],
      names = ["وكيل رئيسي", "فرع", "فرع فرعي"];
    if (s.agents.some((a) => ids.includes(a.id)))
      throw Error("تعارض معرفات شبكة العرض؛ البيانات الحالية محفوظة");
    MasalOperations.initialize(s);
    ids.forEach((id, i) =>
      s.agents.push({
        id,
        name: names[i],
        type: i ? "فرعي" : "رئيسي",
        parent: i ? ids[i - 1] : "",
        city: "بغداد",
        phone: "0770000000" + i,
        active: true,
        color: ["#0284c7", "#7c3aed", "#059669"][i],
        support: "",
        header: names[i],
        footer: "بطاقات تجريبية غير صالحة للشحن",
        reprint: 5,
        demo: true,
      }),
    );
    const points = ["DEMO-POS1", "DEMO-POS2"];
    points.forEach((id, i) =>
      s.pos.push({
        id,
        name: "نقطة " + (i + 1),
        owner: "صاحب نقطة " + (i + 1),
        agent: ids[i ? 2 : 0],
        city: "بغداد",
        address: "بغداد",
        phone: "0770000001" + i,
        email: "",
        lat: 33.3 + i * 0.02,
        lng: 44.43,
        serial: "DEMO-DEVICE-" + (i + 1),
        model: "جهاز تجريبي",
        version: "1.0.0",
        active: true,
        online: true,
        lastSeen: new Date().toISOString(),
        reprint: 5,
        demo: true,
      }),
    );
    const credentials = [
      {
        algorithm: "PBKDF2-SHA256",
        iterations: 210000,
        salt: "3eee129712b1b0eb58bacae4202f0986",
        hash: "9bcad437e3c6761940567bdea2cf6c5b0af42b04d5def75bf0f5b1222df13a13",
      },
      {
        algorithm: "PBKDF2-SHA256",
        iterations: 210000,
        salt: "2825ce0e30d58279eef7e89272c5bf02",
        hash: "2053babf014cf95ec4a20b9042eb02f811d5aef4777897890daeb8ccad2fbabf",
      },
      {
        algorithm: "PBKDF2-SHA256",
        iterations: 210000,
        salt: "013bcbdeba047acba7c8f61702612802",
        hash: "43eee6871b9d5ba0fb8f83b0773906b8d004f81616ef691c0b0652df44a109d0",
      },
      {
        algorithm: "PBKDF2-SHA256",
        iterations: 210000,
        salt: "edd9fb2a26bdf8cecf71dadcd3bb9123",
        hash: "8fb39f07354a8d502d858b10dd4fafabf3dabc5dc161f5394debdc4f39c0a788",
      },
      {
        algorithm: "PBKDF2-SHA256",
        iterations: 210000,
        salt: "e349f6ba00cc540556475592561447da",
        hash: "fc4f451d2c3e58c373a7988f1ba78fc2f90ba00e4f0f9cf3f5e3832921242a18",
      },
    ];
    const usernames = [
      "demo.main",
      "demo.branch",
      "demo.subbranch",
      "demo.pos1",
      "demo.pos2",
    ];
    usernames.forEach((username, i) => {
      if (s.users.some((u) => u.username === username))
        throw Error("اسم حساب العرض مستخدم: " + username);
      s.users.push({
        id: "DEMO-U" + i,
        name: i < 3 ? names[i] : "نقطة " + (i - 2),
        role: i === 0 ? "main" : i < 3 ? "sub" : "pos",
        agent: i < 3 ? ids[i] : ids[i === 3 ? 0 : 2],
        pos: i < 3 ? "" : points[i - 3],
        active: true,
        username,
        credentials: credentials[i],
        mustChangePassword: false,
        twoFactor: false,
        demo: true,
      });
    });
    const region = s.governorates.find((g) => g.name === "بغداد");
    if (region) region.active = true;
    else s.governorates.push({ name: "بغداد", active: true });
    s.providers.push({
      id: "DEMO-PROVIDER",
      name: "شركة العرض التجريبي",
      supplier: "مجهز تجريبي",
      organizer: "غير محدد",
      connection: "ملفات",
      active: true,
      demo: true,
    });
    s.products.push({
      id: "DEMO-PRODUCT",
      name: "بطاقة تجريبية • 5,000 دينار",
      provider: "DEMO-PROVIDER",
      face: 5000,
      currency: "IQD",
      kind: "محلية",
      active: true,
      min: 4500,
      limit: 10,
      dailyQty: 100,
      dailyAmount: 500000,
      fields: "serial,pin,expiry",
      order: 1,
      demo: true,
    });
    ids.forEach((agent) =>
      s.prices.push({
        id: "DEMO-PRICE-" + agent,
        agent,
        product: "DEMO-PRODUCT",
        price: 4800,
        effective: Masal.day(),
        region: "الكل",
      }),
    );
    const engine = new Masal.Engine(s, owner.id);
    const rows = Array.from({ length: 1000 }, (_, i) => ({
      serial: "MASAL-DEMO-" + String(i + 1).padStart(4, "0"),
      pin: "DEMO-NOT-VALID-" + String(i + 1).padStart(4, "0"),
      expiry: "2029-12-31",
    }));
    engine.approveCashOrder(
      {
        agent: ids[0],
        product: "DEMO-PRODUCT",
        city: "بغداد",
        supplier: "مجهز تجريبي",
        cost: 4400,
        expenses: 0,
        loadPrice: 4800,
        postingKey: revision,
        cashConfirmed: true,
      },
      rows,
    );
    [
      [0, ids[0], points[0], 480000],
      [0, ids[0], ids[1], 960000],
      [1, ids[1], ids[2], 480000],
      [2, ids[2], points[1], 240000],
    ].forEach(([user, from, to, amount], i) =>
      new Masal.Engine(s, "DEMO-U" + user).fund(
        from,
        to,
        amount,
        "voucher",
        revision + "-fund-" + i,
      ),
    );
    s.demoNetworkRevision = revision;
    const previous = MasalStateStorage.read();
    if (previous)
      localStorage.setItem("masal-backup-before-demo-network", previous);
    MasalStateStorage.write(s);
    d.s = s;
    return d;
  };
})();
appOptions.methods.batchLabel = function (b) {
  const product =
    this.s?.products?.find((p) => p.id === b?.product)?.name ||
    b?.product ||
    "غير محددة";
  const agent =
    this.s?.agents?.find((a) => a.id === b?.agent)?.name ||
    b?.agent ||
    "غير محدد";
  const created = String(b?.created || "").slice(0, 10);
  return (
    "دفعة " +
    product +
    " • " +
    agent +
    (created ? " • " + created : "") +
    " (" +
    (b?.id || "—") +
    ")"
  );
};
const originalTranslate = appOptions.methods.t;
appOptions.methods.t = function (value) {
  if (typeof value === "string") {
    const batch = this.s?.batches?.find((b) => b.id === value);
    if (batch) return this.batchLabel(batch);
  }
  return originalTranslate.call(this, value);
};
appOptions.methods.applyClaimsView = function () {
  if (this.page !== "claims") return;
  const shell = document.querySelector("#app .main .two");
  if (!shell) return;
  const hide = ["owner", "supervisor"].includes(this.actor.role);
  const form = shell.children[0];
  if (form) form.style.display = hide ? "none" : "";
};
const exportReasonData = appOptions.data;
appOptions.data = function () {
  const d = exportReasonData.call(this);
  d.exportRejectReasons ??= {};
  return d;
};
appOptions.methods.rejectExportRequest = function (r) {
  this.run(
    () => this.engine.rejectExport(r.id, this.exportRejectReasons[r.id]),
    "تم رفض طلب التصدير وتسجيل السبب",
  );
};
const originalGo = appOptions.methods.go;
appOptions.methods.go = function (page) {
  const result = originalGo.call(this, page);
  this.$nextTick(() => this.applyClaimsView());
  return result;
};
const originalMounted = appOptions.mounted;
appOptions.mounted = function () {
  originalMounted.call(this);
  this.$nextTick(() => this.applyClaimsView());
};

MasalScopeFilters.install(appOptions);
MasalReportGroups.install(appOptions);
MasalMoneyInputs.install(appOptions);
MasalSecurityControls.install(appOptions);
MasalSimpleWallets.install(appOptions);
MasalActivityNotifications.install(appOptions);
MasalPrintPolicies.install(appOptions);
MasalPrintPolicyScopes.install(appOptions);
MasalPrintScopePicker.install(appOptions);
MasalAccountTime.install(appOptions);
MasalSupportBroadcast.install(appOptions);
MasalUserMap.install(appOptions);
MasalPriceEditor.install(appOptions);
MasalAgentProducts.install(appOptions);
MasalDailyPrint.install(appOptions);
MasalSupportChat.install(appOptions);
MasalNetworkArchive.install(appOptions);
MasalPasswordAdmin.install(appOptions);
MasalPOSRegister.install(appOptions);
MasalOrderSources.install(appOptions, NAV);
MasalMultiOrders.install(appOptions);
// Regroup the already permission-filtered navigation; retain every visible destination.
(function (o) {
  const groups = [
    {
      title: "نظرة عامة",
      direct: true,
      icon: "dashboard",
      ids: ["dashboard", "reports", "company", "wallets"],
    },
    {
      title: "البطاقات والمخزون",
      icon: "inventory",
      ids: [
        "inventory",
        "import",
        "products",
        "prices",
        "providers",
        "sources",
      ],
    },
    {
      title: "عمليات البطاقات",
      icon: "sell",
      ids: ["sell", "exports", "claims", "exceptions"],
    },
    {
      title: "شبكة التوزيع",
      icon: "agents",
      ids: ["agents", "pos", "representatives", "posTypes", "map"],
    },
    {
      title: "التواصل والدعم",
      icon: "support",
      ids: ["support", "notifications"],
    },
    {
      title: "المستخدمون والصلاحيات",
      icon: "users",
      ids: ["users", "permissions", "accountTime", "deletedAccounts"],
    },
    {
      title: "إعدادات النظام",
      icon: "security",
      ids: ["security", "backup", "governorates", "integrations"],
    },
    {
      title: "إعدادات البطاقات والموقع",
      icon: "branding",
      ids: ["branding", "printPolicies", "company-settings"],
    },
  ];
  const navigation = o.computed.navGroups;
  o.computed.navGroups = function () {
    const source = navigation.call(this).flatMap((g) => g.items),
      byId = new Map(source.map((n) => [n.id, n])),
      used = new Set();
    const result = groups.map((g) => ({
      ...g,
      items: g.ids
        .filter((id) => byId.has(id))
        .map((id) => {
          used.add(id);
          return byId.get(id);
        }),
    }));
    const extra = source.filter((n) => !used.has(n.id));
    if (extra.length)
      result.push({ title: "أقسام إضافية", icon: "security", items: extra });
    return result.filter((g) => g.items.length);
  };
  const data = o.data;
  o.data = function () {
    const d = data.call(this);
    d.collapsedGroups = {
      ...d.collapsedGroups,
      ...Object.fromEntries(
        groups.filter((g) => !g.direct).map((g) => [g.title, true]),
      ),
      "أقسام إضافية": true,
    };
    return d;
  };
  const go = o.methods.go;
  o.methods.go = function (...args) {
    const result = go.apply(this, args);
    for (const group of this.navGroups) {
      if (!group.direct)
        this.collapsedGroups[group.title] = !group.items.some(
          (n) =>
            n.id === this.page ||
            (args[0] === "company-settings" && n.id === "company-settings"),
        );
    }
    return result;
  };
})(appOptions);
MasalInventoryManagement.install(appOptions);
// Keep demo identities consistent in account lists, maps and record references.
// Only replace untouched default names; preserve names already edited by the user.
const beforeDemoNames = appOptions.data;
appOptions.data = function () {
  const data = beforeDemoNames.call(this),
    s = data.s;
  const names = [
    ["DEMO-U0", "agents", "DEMO-MAIN", "وكيل رئيسي", "أحمد محمد — وكيل رئيسي"],
    ["DEMO-U1", "agents", "DEMO-BRANCH", "فرع", "علي حسن — فرع"],
    ["DEMO-U2", "agents", "DEMO-SUBBRANCH", "فرع فرعي", "مصطفى علي — فرع فرعي"],
    ["DEMO-U3", "pos", "DEMO-POS1", "نقطة 1", "حسين كاظم — نقطة بيع"],
    ["DEMO-U4", "pos", "DEMO-POS2", "نقطة 2", "عمر أحمد — نقطة بيع"],
  ];
  for (const [userId, key, id, oldName, newName] of names) {
    const user = s.users.find((u) => u.id === userId && u.demo),
      record = s[key].find((r) => r.id === id && r.demo);
    if (user?.name === oldName) user.name = newName;
    if (record?.name === oldName) record.name = newName;
    if (key === "pos" && record?.owner === "صاحب " + oldName)
      record.owner = newName.split(" — ")[0];
  }
  for (const user of s.users)
    if (user.role === "owner" && user.name === "مدير النظام")
      user.name = "محمد علي — مدير النظام";
  return data;
};
appOptions.methods.profileAccountLabel = function (user) {
  if (user.role === "owner" && user.name === "مدير النظام")
    return "محمد علي — مدير النظام";
  const aliases = {
    "DEMO-U0": ["وكيل رئيسي", "أحمد محمد — وكيل رئيسي"],
    "DEMO-U1": ["فرع", "علي حسن — فرع"],
    "DEMO-U2": ["فرع فرعي", "مصطفى علي — فرع فرعي"],
    "DEMO-U3": ["نقطة 1", "حسين كاظم — نقطة بيع"],
    "DEMO-U4": ["نقطة 2", "عمر أحمد — نقطة بيع"],
  };
  const alias = aliases[user.id];
  return alias && user.demo && user.name === alias[0] ? alias[1] : user.name;
};
MasalRepresentativesUI.install(appOptions);
MasalUILocalization.install(appOptions);
globalThis.MasalAppOptions = appOptions;
