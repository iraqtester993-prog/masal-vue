(function (root) {
  "use strict";
  const labels = {
    "المخزون والدفعات": "المخزون",
    "الطلبيات والاستيراد": "الطلبيات",
    "المنتجات والفئات": "الفئات",
    "الشركات والمزودون": "الشركات",
    "الأسعار والاعتمادات": "الأسعار",
    "التالف والمطالبات": "البطاقات التالفة",
    "التصدير الآمن": "تصدير البطاقات",
    "هوية الوكيل والوصل": "تصميم البطاقة",
    "الأمان والتشغيل": "الحماية والأمان",
    المزود: "الشركة",
    "اسم العلامة / المزود": "اسم الشركة",
    "العلامة التجارية": "الشركة",
    "إضافة مزود": "إضافة شركة",
    "سعر التحميل المتفق عليه": "سعر البيع • د.ع",
    "سعر التحميل": "سعر البيع",
    "تحميل دفعة": "رفع طلبية",
    "فاتورة تحميل دفعة": "فاتورة طلبية",
    "الفئات والمنتجات": "الفئات",
  };
  const blockNames = {
    company: "الشركة",
    category: "الفئة",
    image: "الصورة",
    codes: "بيانات البطاقة",
    amount: "المبلغ",
    footer: "التذييل",
  };
  const receipt = {
    props: ["tx", "design", "productId"],
    computed: {
      vm() {
        return this.$root;
      },
      product() {
        return (
          this.vm.s.products.find(
            (p) => p.id === (this.productId || this.tx?.product),
          ) || {}
        );
      },
      provider() {
        return (
          this.vm.s.providers.find((p) => p.id === this.product.provider) || {}
        );
      },
      agent() {
        return (
          this.vm.s.agents.find(
            (a) =>
              a.id ===
              this.vm.engine.main(this.tx?.agent || this.vm.brandAgent),
          ) || {}
        );
      },
      layout() {
        return (
          this.design ||
          this.product.cardDesign ||
          this.provider.cardDesign || {
            color: "#172b4d",
            width: this.product.receiptWidth || this.agent.width || 80,
            header: this.product.receiptHeader || this.agent.header,
            footer: this.product.receiptFooter || this.agent.footer,
            order: MasalMeetingRules.blocks,
          }
        );
      },
      cards() {
        return this.tx
          ? this.vm.s.cards.filter((c) => this.tx.cards.includes(c.id))
          : [
              {
                id: "preview",
                pin: "•••• •••• ••••",
                serial: "—",
                expiry: "—",
              },
            ];
      },
      image() {
        return (
          this.layout.image ||
          this.product.receiptImage ||
          this.product.image ||
          this.provider.receiptImage ||
          ""
        );
      },
    },
  };
  const company = {
    data() {
      return { editing: false, draft: null, slide: 0, busy: false };
    },
    computed: {
      vm() {
        return this.$root;
      },
      profile() {
        return (
          this.vm.s.companyProfile || { name: "ماسال", about: "", slides: [] }
        );
      },
      editable() {
        return (
          this.vm.can("company.edit") &&
          (this.vm.actor.role === "owner" ||
            this.vm.actor.staffAccount === "@system")
        );
      },
    },
    watch: {
      "vm.currentUser"() {
        this.editing = false;
        this.draft = null;
      },
    },
    methods: {
      edit() {
        this.draft = Masal.clone({
          ...this.profile,
          slides: this.profile.slides || [],
        });
        this.editing = true;
      },
      save() {
        this.vm.run(() => {
          this.vm.engine.saveCompany(this.draft);
          this.editing = false;
          this.slide = 0;
        });
      },
      move(n) {
        this.slide =
          (this.slide + n + this.profile.slides.length) %
          this.profile.slides.length;
      },
    },
  };
  const designer = {
    data() {
      return { type: "product", selected: "", draft: null, previewProduct: "" };
    },
    computed: {
      vm() {
        return this.$root;
      },
      items() {
        return this.type === "product"
          ? this.vm.s.products
          : this.vm.s.providers;
      },
    },
    watch: {
      type() {
        this.selected = this.items[0]?.id || "";
        this.load();
      },
      selected() {
        this.load();
      },
      "vm.currentUser"() {
        this.load();
      },
    },
    mounted() {
      this.selected = this.items[0]?.id || "";
      this.load();
    },
    methods: {
      load() {
        const item = this.items.find((x) => x.id === this.selected);
        this.draft = Masal.clone(
          item?.cardDesign || {
            color: "#172b4d",
            width: 80,
            header: "",
            footer: "",
            image: "",
            order: MasalMeetingRules.blocks,
          },
        );
        this.previewProduct =
          this.type === "product"
            ? this.selected
            : this.vm.s.products.find((p) => p.provider === this.selected)
                ?.id || "";
      },
      move(i, n) {
        const j = i + n;
        if (j < 0 || j >= this.draft.order.length) return;
        [this.draft.order[i], this.draft.order[j]] = [
          this.draft.order[j],
          this.draft.order[i],
        ];
      },
      save() {
        this.vm.run(() =>
          this.vm.engine.saveCardDesign(this.type, this.selected, this.draft),
        );
      },
      name(k) {
        return blockNames[k];
      },
    },
  };
  const controls = {
    data() {
      return { target: "", draft: {}, reason: "" };
    },
    computed: {
      vm() {
        return this.$root;
      },
      items() {
        return [
          ...this.vm.visibleAgents.map((x) => ({ ...x, kind: "agent" })),
          ...this.vm.visiblePOS.map((x) => ({ ...x, kind: "pos" })),
        ];
      },
      fields() {
        return [
          { key: "login", label: "إيقاف الدخول" },
          { key: "sales", label: "إيقاف البيع" },
          { key: "printing", label: "إيقاف الطباعة" },
          { key: "import", label: "إيقاف رفع الطلبيات" },
        ];
      },
    },
    watch: {
      target() {
        this.load();
      },
      "vm.currentUser"() {
        this.target = "";
        this.draft = {};
      },
    },
    methods: {
      load() {
        this.draft = Masal.clone(
          this.items.find((x) => x.id === this.target)?.operationStops || {},
        );
      },
      save() {
        const item = this.items.find((x) => x.id === this.target);
        if (!item) {
          this.vm.notify("اختر الحساب أولًا", true);
          return;
        }
        this.vm.run(() => {
          this.vm.engine.setOperationStops(item.kind, item.id, this.draft);
        }, "تم حفظ إعدادات الإيقاف للحساب: " + item.name);
      },
    },
  };
  function install(o) {
    const data = o.data;
    o.data = function () {
      const d = data.call(this),
        e = new Masal.Engine(d.s, d.currentUser);
      for (const a of d.s.agents.filter((a) => !a.parent)) {
        try {
          MasalMeetingRules.atomic(e, () => e.alignStock(a.id));
        } catch (error) {
          console.warn("Stock valuation pending:", a.id, error.message);
        }
      }
      return { ...d, companyReturn: "dashboard" };
    };
    const tr = o.methods.tr;
    o.methods.tr = function (text, ...args) {
      return tr.call(this, labels[text] || text, ...args);
    };
    o.computed.availableSaleProducts = function () {
      const pos = this.selectedPOS;
      return pos
        ? this.s.products
            .filter((p) =>
              this.engine.productAvailable(pos.agent, p.id, pos.city),
            )
            .sort((a, b) => (a.order || 0) - (b.order || 0))
        : [];
    };
    o.watch.availableSaleProducts = function (rows) {
      if (!rows.some((p) => p.id === this.saleForm.product))
        this.saleForm.product = rows[0]?.id || "";
    };
    const nav = o.computed.navGroups;
    o.computed.navGroups = function () {
      return nav.call(this).map((g) => ({
        ...g,
        items: g.items.map((i) => ({
          ...i,
          label: labels[i.label] || i.label,
        })),
      }));
    };
    const go = o.methods.go;
    o.methods.go = function (page) {
      if (page === "company" && this.page !== "company")
        this.companyReturn = this.page;
      return go.call(this, page);
    };
    o.components["meeting-receipt"] = receipt;
    o.components["company-page"] = company;
    o.components["card-designer"] = designer;
    o.components["operation-control"] = controls;
    company.components = {
      "image-attachment": o.components["image-attachment"],
    };
    designer.components = {
      "image-attachment": o.components["image-attachment"],
      "meeting-receipt": receipt,
    };
    const brand = o.computed.receiptAgent;
    o.computed.receiptAgent = function () {
      const base = brand.call(this),
        product = this.s.products.find((p) => p.id === this.modal?.tx?.product),
        provider = this.s.providers.find((p) => p.id === product?.provider),
        design = product?.cardDesign || provider?.cardDesign;
      return { ...base, width: design?.width || base.width };
    };
    const mounted = o.mounted;
    o.mounted = function () {
      mounted?.call(this);
      const scan = () => {
        if (!this.loginScreen) MasalMeetingRules.alerts(this.s);
      };
      scan();
      this._expiryTimer = setInterval(scan, 60000);
      this._expiryFocus = scan;
      window.addEventListener("focus", scan);
    };
    const unmount = o.beforeUnmount;
    o.beforeUnmount = function () {
      unmount?.call(this);
      clearInterval(this._expiryTimer);
      window.removeEventListener("focus", this._expiryFocus);
    };
    const switched = o.methods.switchUser;
    o.methods.switchUser = function (...args) {
      const result = switched.apply(this, args);
      MasalMeetingRules.alerts(this.s);
      const u = this.actor;
      if (u.role !== "owner" && u.staffAccount !== "@system") {
        try {
          this.engine.checkOperation(u.pos || u.agent, "login");
        } catch (e) {
          this.logoutToLogin();
          this.loginError = e.message;
        }
      }
      return result;
    };
    const login = o.methods.finishLogin;
    o.methods.finishLogin = function (id) {
      const u = this.s.users.find((u) => u.id === id);
      if (u?.role !== "owner" && u?.staffAccount !== "@system")
        new Masal.Engine(this.s, id).checkOperation(
          u?.pos || u?.agent,
          "login",
        );
      return login.call(this, id);
    };
    const op = o.components["operations-panel"],
      reset = op.methods.reset;
    op.methods.reset = function () {
      reset.call(this);
      this.loadPrice = this.e.policyPrice(this.importAgent, this.product) || 0;
    };
    op.methods.importPriceNotice = function (file) {
      const product = this.s.products.find((p) => p.id === file.product),
        agent = this.s.agents.find((a) => a.id === this.importAgent);
      return (
        "سعر البيع لفئة «" +
        (product?.name || "الفئة المختارة") +
        "» لدى «" +
        (agent?.name || "الوكيل المختار") +
        "» يُحدد من قسم «الأسعار». هذا الحقل للعرض فقط؛ احفظ السعر هناك ثم أعد فحص الطلبية."
      );
    };
    const preview = op.methods.previewFiles;
    op.methods.previewFiles = function () {
      this.preview = [];
      const fail = (message) => this.vm.notify(message, true);
      if (!this.files.length)
        return fail(
          "لم تختر ملف بطاقات. اختر الملف من «ملفات المزود» ثم اضغط «فحص ومعاينة».",
        );
      if (!this.s.agents.some((a) => a.id === this.importAgent && !a.parent))
        return fail(
          "لم تحدد الوكيل الرئيسي المستلم. اختر الوكيل الذي تريد إضافة البطاقات إلى مخزنه.",
        );
      for (const file of this.files) {
        const product = this.s.products.find((p) => p.id === file.product),
          prefix = "الملف «" + file.name + "»: ";
        if (!product)
          return fail(
            prefix +
              "لم تحدد الفئة. اختر فئة البطاقات أسفل اسم الملف ثم أعد الفحص.",
          );
        if (file.contentConflict)
          return fail(
            prefix +
              "توجد فئات متعارضة داخل الملف. صحح عمود الفئة أو افصل كل فئة في ملف مستقل ثم ارفع الملف مجددًا.",
          );
        if (!Number.isFinite(Number(file.cost)) || Number(file.cost) <= 0)
          return fail(
            prefix +
              "تكلفة البطاقة فارغة أو غير صحيحة. أدخل تكلفة شراء البطاقة الواحدة أكبر من صفر.",
          );
        if (
          !Number.isFinite(Number(file.expenses)) ||
          Number(file.expenses) < 0
        )
          return fail(
            prefix +
              "مصاريف الدفعة غير صحيحة. أدخل صفرًا إذا لم توجد مصاريف، أو مبلغًا موجبًا.",
          );
        file.loadPrice =
          this.e.policyPrice(this.importAgent, file.product) || 0;
        if (!(file.loadPrice > 0))
          return fail("لا يوجد سعر بيع صالح. " + this.importPriceNotice(file));
        if (file.rows[0].join("|") !== this.headers.join("|"))
          return fail(
            prefix +
              "أسماء الأعمدة أو ترتيبها تختلف عن الملف الأول. ارفع الملفات ذات التنسيق المختلف في طلبية منفصلة.",
          );
      }
      if (!Number.isInteger(this.mapping.pin) || this.mapping.pin < 0)
        return fail(
          "لم تربط عمود رمز البطاقة. اختر عمود pin من قائمة «رمز البطاقة» ثم أعد الفحص.",
        );
      return preview.call(this);
    };
    const w = o.components["workflow-panel"];
    w.computed.mainAgents = function () {
      return this.vm.visibleAgents.filter((a) => !a.parent);
    };
  }
  root.MasalMeetingUI = { install };
})(globalThis);
