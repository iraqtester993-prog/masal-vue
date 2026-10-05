(function (root) {
  "use strict";
  const M = root.Masal,
    P = M.Engine.prototype,
    now = () => new Date().toISOString();
  const own = (e) => {
    const u = e.actor();
    return u.role === "owner" || u.staffAccount === "@system"
      ? "@owner"
      : u.role === "pos"
        ? u.pos
        : u.staffAccount || u.agent || "";
  };
  const parent = (s, id) =>
    s.pos.find((p) => p.id === id)?.agent ||
    s.agents.find((a) => a.id === id)?.parent ||
    (s.agents.some((a) => a.id === id) ? "@owner" : "");
  const defaultRequestAmounts = [50000, 100000, 150000, 200000, 250000, 300000];
  P.fundingRequestPolicy = function () {
    return (
      this.s.settings.posFundingRequests || {
        amounts: defaultRequestAmounts.slice(),
        dailyLimit: 1,
      }
    );
  };
  P.saveFundingRequestPolicy = function (draft) {
    this.operations();
    this.requirePermission("security.fundingRequests");
    const dailyLimit = Number(draft.dailyLimit),
      amounts = (draft.amounts || []).map(Number);
    if (!Number.isSafeInteger(dailyLimit) || dailyLimit < 1)
      throw Error("عدد الطلبات اليومي يجب أن يكون عددًا صحيحًا لا يقل عن 1");
    if (
      amounts.some(
        (n) =>
          !Number.isFinite(n) ||
          n <= 0 ||
          !Number.isSafeInteger(Math.round(n * 100)) ||
          Math.abs(n * 100 - Math.round(n * 100)) > 0.00001,
      )
    )
      throw Error("أدخل مبالغ موجبة وصحيحة بمنزلتين عشريتين كحد أقصى");
    if (new Set(amounts).size !== amounts.length) throw Error("يوجد مبلغ مكرر");
    const before = M.clone(this.fundingRequestPolicy()),
      policy = { amounts: amounts.sort((a, b) => a - b), dailyLimit };
    this.s.settings.posFundingRequests = policy;
    this.log(
      "تعديل إعدادات طلبات تمويل نقاط البيع",
      "posFundingRequests",
      before,
      policy,
    );
    return policy;
  };
  P.fundingRequestsToday = function (pos, at = new Date()) {
    const day = M.businessDay(at);
    return (this.s.fundingRequests || []).filter(
      (r) =>
        r.to === pos &&
        Number.isFinite(Date.parse(r.time)) &&
        M.businessDay(r.time) === day,
    ).length;
  };
  P.assertFundingRequestPolicy = function (to, value) {
    if (!this.s.pos.some((p) => p.id === to)) return;
    const policy = this.fundingRequestPolicy();
    if (!policy.amounts.includes(Number(value)))
      throw Error("اختر مبلغًا من مبالغ طلبات التمويل المعتمدة");
    if (this.fundingRequestsToday(to) >= policy.dailyLimit)
      throw Error(
        "وصلت إلى الحد اليومي لطلبات التمويل (" +
          policy.dailyLimit +
          ")؛ يمكنك إرسال طلب جديد غدًا بتوقيت بغداد",
      );
  };
  const legacyRequestFunding = P.requestFunding;
  P.requestFunding = function (to, from, value, service, purpose, key) {
    this.operations();
    const old = this.s.fundingRequests.find((r) => r.key === key);
    if (!old) this.assertFundingRequestPolicy(to, value);
    return legacyRequestFunding.call(
      this,
      to,
      from,
      value,
      service,
      purpose,
      key,
    );
  };
  function amount(value) {
    const n = Number(value);
    if (
      !Number.isFinite(n) ||
      n <= 0 ||
      Math.abs(n * 100 - Math.round(n * 100)) > 0.000001
    )
      throw Error("أدخل مبلغًا موجبًا بمنزلتين عشريتين كحد أقصى");
    return n;
  }
  function service(e, id) {
    if (
      ![
        "voucher",
        "topup",
        "cash",
        ...e.s.providers
          .filter((p) => p.connection === "API")
          .map((p) => "api:" + p.id),
      ].includes(id)
    )
      throw Error("اختر محفظة صحيحة");
  }
  function active(e, id) {
    const a =
      e.s.agents.find((a) => a.id === id) || e.s.pos.find((p) => p.id === id);
    if (!a?.active) throw Error("الحساب غير موجود أو موقوف");
    e.accountCheck(id);
    return a;
  }
  function atomic(e, fn) {
    const previous = e._walletFlow;
    e._walletFlow = true;
    try {
      return root.MasalMeetingRules.atomic(e, fn);
    } finally {
      e._walletFlow = previous;
    }
  }
  P.walletIdentity = function () {
    return own(this);
  };
  P.walletParent = function () {
    return parent(this.s, own(this));
  };
  P.walletChildren = function () {
    const id = own(this);
    if (id === "@owner")
      return this.s.agents.filter(
        (a) => !a.parent && a.active && this.allowed(a.id),
      );
    return [
      ...this.s.agents.filter((a) => a.parent === id),
      ...this.s.pos.filter((p) => p.agent === id),
    ].filter((a) => a.active);
  };
  P.walletRequestVisible = function (r) {
    const id = own(this);
    return (
      (r.from === id || r.to === id) && this.allowed(this.accountAgent(r.to))
    );
  };
  P.requestWalletFunding = function (value, kind, note, key) {
    this.operations();
    this.requirePermission("wallets.request");
    const to = own(this),
      from = parent(this.s, to);
    if (!from || to === "@owner") throw Error("لا توجد جهة أعلى لهذا الحساب");
    active(this, to);
    if (from !== "@owner" && !this.s.agents.find((a) => a.id === from)?.active)
      throw Error("حساب الجهة الأعلى موقوف");
    value = amount(value);
    service(this, kind);
    if (!key) throw Error("معرف الطلب مطلوب");
    note = String(note || "").trim();
    const old = this.s.fundingRequests.find((r) => r.key === key);
    if (old) {
      if (
        old.to !== to ||
        old.from !== from ||
        old.amount !== value ||
        old.service !== kind ||
        old.purpose !== note
      )
        throw Error("معرف الطلب مستخدم لبيانات مختلفة");
      return old;
    }
    this.assertFundingRequestPolicy(to, value);
    const r = {
      id: M.id("FR"),
      key,
      from,
      to,
      amount: value,
      service: kind,
      purpose: note,
      status: "بانتظار التمويل",
      time: now(),
      user: this.user,
      requesterName: this.actor().name,
      simpleWallet: 1,
    };
    this.s.fundingRequests.unshift(r);
    this.log("طلب تمويل من الجهة الأعلى", r.id, null, {
      from,
      to,
      amount: value,
      service: kind,
    });
    return r;
  };
  P.walletFundingBatches = function (r) {
    if (!r || r.from !== "@owner" || r.service !== "voucher") return [];
    return this.s.batches.filter(
      (b) =>
        b.agent === r.to &&
        ["Loaded", "Partially Used"].includes(b.status) &&
        b.created >= r.time &&
        !b.walletFundingRequest &&
        this.s.batchInvoices.some(
          (i) =>
            i.batch === b.id &&
            i.status !== "معكوسة" &&
            Math.round(i.amount * 100) === Math.round(r.amount * 100),
        ),
    );
  };
  P.reviewWalletFunding = function (id, decision, details = {}) {
    this.operations();
    this.requirePermission("wallets.approve");
    const r = this.s.fundingRequests.find((r) => r.id === id);
    if (!r || r.from !== own(this) || !this.walletRequestVisible(r))
      throw Error("الطلب ليس موجّهًا لحسابك");
    if (!["approve", "reject"].includes(decision)) throw Error("قرار غير صالح");
    if (r.status === "منفذ" && decision === "approve") return r;
    if (!["بانتظار التمويل", "معتمد ومحجوز"].includes(r.status))
      throw Error("تمت معالجة الطلب مسبقًا");
    active(this, r.to);
    if (parent(this.s, r.to) !== r.from)
      throw Error("تغيّر تسلسل الحساب؛ أعد تقديم الطلب");
    service(this, r.service);
    amount(r.amount);
    if (decision === "reject" && !String(details.reason || "").trim())
      throw Error("سبب الرفض مطلوب");
    if (r.status === "معتمد ومحجوز" && decision === "reject")
      throw Error("الطلب معتمد سابقًا؛ ألغِ حجزه من تفاصيل الأرصدة أولًا");
    return atomic(this, () => {
      if (decision === "reject") {
        r.status = "مرفوض";
        r.reason = details.reason.trim();
      } else if (r.from === "@owner") {
        if (r.service === "voucher") {
          this.requirePermission("import.approve");
          const b = this.walletFundingBatches(r).find(
            (b) => b.id === details.batch,
          );
          if (!b)
            throw Error(
              "اختر طلبية معتمدة بعد الطلب وبنفس القيمة ولم ترتبط بطلب تمويل آخر",
            );
          b.walletFundingRequest = r.id;
          r.batch = b.id;
          r.fulfillment = "stock";
          r.reference = b.id;
        } else {
          this.requirePermission("wallets.deposit");
          const reference = String(details.reference || "").trim();
          if (!reference) throw Error("مرجع الإيداع مطلوب");
          this.serviceCredit(
            r.to,
            r.service,
            r.amount,
            reference,
            "WALLET-REQUEST:" + r.id,
          );
          r.reference = reference;
          r.fulfillment = "deposit";
        }
        r.status = "منفذ";
        r.approvedAmount = r.amount;
      } else if (r.status === "معتمد ومحجوز") {
        const h = this.s.fundingHolds.find(
          (h) => h.request === id && h.status === "محجوز",
        );
        if (!h) throw Error("الحجز غير موجود");
        const t = this.completeFunding(h.id);
        r.transfer = t.id;
        r.approvedAmount = t.amount;
        r.status = "منفذ";
      } else {
        const t = this.fund(
          r.from,
          r.to,
          r.amount,
          r.service,
          "WALLET-REQUEST:" + r.id,
        );
        r.status = "منفذ";
        r.approvedAmount = t.amount;
        r.transfer = t.id;
      }
      r.reviewedAt = now();
      r.approverId = this.user;
      r.approverName = this.actor().name;
      this.log(
        decision === "approve" ? "موافقة وتمويل طلب" : "رفض طلب تمويل",
        r.id,
        null,
        {
          from: r.from,
          to: r.to,
          amount: r.amount,
          service: r.service,
          reason: r.reason || "",
          batch: r.batch || "",
          reference: r.reference || "",
        },
      );
      return r;
    });
  };
  P.cancelWalletFunding = function (id) {
    this.operations();
    this.requirePermission("wallets.request");
    const r = this.s.fundingRequests.find((r) => r.id === id);
    if (!r || r.to !== own(this)) throw Error("يمكن إلغاء طلبات حسابك فقط");
    if (r.status === "ملغي") return r;
    if (r.status !== "بانتظار التمويل")
      throw Error("يمكن إلغاء الطلب قبل الموافقة فقط");
    r.status = "ملغي";
    r.cancelledAt = now();
    r.cancelledBy = this.user;
    r.cancelledByName = this.actor().name;
    this.log("إلغاء طلب تمويل", id, null, { from: r.from, to: r.to });
    return r;
  };
  P.directWalletFunding = function (to, value, kind, key) {
    this.operations();
    this.requirePermission("wallets.transfer");
    const from = own(this);
    if (from === "@owner")
      throw Error(
        "تمويل الإدارة يتم عبر الطلبات الواردة وإيداع موثق أو طلبية مخزون",
      );
    if (!this.walletChildren().some((a) => a.id === to))
      throw Error("اختر أحد التابعين المباشرين لحسابك");
    active(this, to);
    service(this, kind);
    value = amount(value);
    return atomic(this, () => this.fund(from, to, value, kind, key));
  };
  // Old entry points must not bypass the new decision and cancellation rules.
  const process = P.processFunding;
  P.processFunding = function (id, value, ...args) {
    const r = this.s.fundingRequests.find((r) => r.id === id);
    if (r?.simpleWallet) {
      if (Number(value) !== r.amount)
        throw Error("اعتمد المبلغ المطلوب دون تغييره");
      return this.reviewWalletFunding(id, "approve");
    }
    if (r && !["بانتظار التمويل", "منفذ", "معتمد ومحجوز"].includes(r.status))
      throw Error("الطلب غير متاح");
    return process.call(this, id, value, ...args);
  };
  const reserve = P.reserveFunding;
  P.reserveFunding = function (id, ...args) {
    const r = this.s.fundingRequests.find((r) => r.id === id);
    if (r?.simpleWallet)
      throw Error("استخدم موافقة وتمويل لتنفيذ الطلب مباشرة");
    return reserve.call(this, id, ...args);
  };
  const component = {
    props: { walletService: { type: String, default: "voucher" } },
    data() {
      return {
        tab: "request",
        value: "",
        note: "",
        to: "",
        key: M.id("WF"),
        review: {},
        directConfirm: false,
        error: "",
        busy: false,
        filter: "all",
        requestClock: Date.now(),
        detailId: "",
      };
    },
    computed: {
      kind() {
        return this.services.some((s) => s.id === this.walletService)
          ? this.walletService
          : "";
      },
      pointRequester() {
        return this.s.pos.some((p) => p.id === this.me);
      },
      requestPolicy() {
        return this.e.fundingRequestPolicy();
      },
      requestsToday() {
        return this.e.fundingRequestsToday(
          this.me,
          new Date(this.requestClock),
        );
      },
      requestRemaining() {
        return Math.max(0, this.requestPolicy.dailyLimit - this.requestsToday);
      },
      vm() {
        return this.$root;
      },
      e() {
        return this.vm.engine;
      },
      s() {
        return this.vm.s;
      },
      me() {
        return this.e.walletIdentity();
      },
      admin() {
        return this.me === "@owner";
      },
      parent() {
        return this.e.walletParent();
      },
      services() {
        return [
          { id: "voucher", name: "رصيد البطاقات" },
          { id: "topup", name: "Topup" },
          ...this.s.providers
            .filter((p) => p.connection === "API")
            .map((p) => ({ id: "api:" + p.id, name: p.name })),
        ];
      },
      ownSummary() {
        return this.kind ? this.vm.walletOwnSummary(this.kind) : null;
      },
      children() {
        return this.e.walletChildren();
      },
      available() {
        return this.admin || !this.kind
          ? 0
          : this.e.serviceAvailable(this.me, this.kind);
      },
      requests() {
        return this.s.fundingRequests.filter((r) =>
          this.e.walletRequestVisible(r),
        );
      },
      fundingDetail() {
        return this.history.find((r) => r.id === this.detailId);
      },
      incoming() {
        return this.requests.filter(
          (r) =>
            r.from === this.me &&
            ["بانتظار التمويل", "معتمد ومحجوز"].includes(r.status),
        );
      },
      history() {
        const tx = new Set(
          this.requests.map((r) => r.transfer).filter(Boolean),
        );
        return [
          ...this.requests.map((r) => ({ ...r, recordType: "request" })),
          ...this.s.fundingTransfers
            .filter(
              (t) => (t.from === this.me || t.to === this.me) && !tx.has(t.id),
            )
            .map((t) => ({ ...t, recordType: "transfer" })),
        ]
          .filter(
            (r) => this.filter === "all" || this.status(r) === this.filter,
          )
          .sort((a, b) => b.time.localeCompare(a.time));
      },
      tabs() {
        return [
          ...(!this.admin && this.vm.can("wallets.request")
            ? [{ id: "request", name: "طلب تمويل" }]
            : []),
          ...(this.vm.can("wallets.approve")
            ? [{ id: "incoming", name: "طلبات واردة" }]
            : []),
          ...(!this.admin &&
          this.children.length &&
          this.vm.can("wallets.transfer")
            ? [{ id: "direct", name: "تمويل مباشر" }]
            : []),
          { id: "history", name: "سجل التمويل" },
        ];
      },
    },
    mounted() {
      this.tab = this.tabs[0]?.id || "history";
      this._requestClock = setInterval(() => {
        this.requestClock = Date.now();
      }, 1000);
    },
    beforeUnmount() {
      clearInterval(this._requestClock);
    },
    watch: {
      incoming: {
        immediate: true,
        handler(rows) {
          rows.forEach((r) => this.edit(r));
        },
      },
      "vm.currentUser"() {
        this.reset();
        this.tab = this.tabs[0]?.id || "history";
        this.review = {};
        this.incoming.forEach((r) => this.edit(r));
      },
      kind() {
        this.directConfirm = false;
        this.key = M.id("WF");
      },
      value() {
        this.directConfirm = false;
        this.key = M.id("WF");
      },
      to() {
        this.directConfirm = false;
        this.key = M.id("WF");
      },
    },
    methods: {
      name(id) {
        return id === "@owner"
          ? "إدارة النظام"
          : this.s.agents.find((a) => a.id === id)?.name ||
              this.s.pos.find((p) => p.id === id)?.name ||
              id;
      },
      requestUser(id, snapshot) {
        return snapshot || this.s.users.find((u) => u.id === id)?.name || "—";
      },
      service(id) {
        return id === "cash"
          ? "سجل نقدي سابق"
          : this.services.find((s) => s.id === id)?.name || id;
      },
      status(r) {
        return (
          {
            "بانتظار التمويل": "بانتظار الموافقة",
            "معتمد ومحجوز": "بانتظار التسليم",
            ملغى: "ملغي",
          }[r.status] || r.status
        );
      },
      reset() {
        this.value = "";
        this.note = "";
        this.to = "";
        this.key = M.id("WF");
        this.directConfirm = false;
        this.error = "";
      },
      act(fn, message) {
        if (this.busy) return;
        this.busy = true;
        this.error = "";
        try {
          fn();
          this.vm.persist();
          this.vm.notify(message);
        } catch (e) {
          this.error = e.message;
        } finally {
          this.busy = false;
        }
      },
      request() {
        this.act(() => {
          if (!this.kind) throw Error("اختر محفظة من كروت المحافظ أولًا");
          this.e.requestWalletFunding(
            this.value,
            this.kind,
            this.note,
            this.key,
          );
          this.reset();
          this.tab = "history";
        }, "أرسل طلب التمويل إلى الجهة الأعلى");
      },
      direct() {
        if (!this.directConfirm) {
          this.directConfirm = true;
          return;
        }
        this.act(() => {
          this.e.directWalletFunding(this.to, this.value, this.kind, this.key);
          this.reset();
          this.tab = "history";
        }, "تم التمويل وتحويل الرصيد");
      },
      decide(r, decision) {
        this.act(
          () => {
            this.e.reviewWalletFunding(r.id, decision, this.review[r.id] || {});
            delete this.review[r.id];
          },
          decision === "approve"
            ? "تم تنفيذ طلب التمويل"
            : "تم رفض طلب التمويل",
        );
      },
      cancel(r) {
        this.act(() => this.e.cancelWalletFunding(r.id), "تم إلغاء الطلب");
      },
      edit(r) {
        this.review[r.id] ??= { reference: "", batch: "", reason: "" };
      },
      loadOrder(r) {
        this.vm.walletOrderAgent = r.to;
        this.vm.go("import");
        this.vm.notify(
          "حمّل طلبية بقيمة " +
            this.vm.money(r.amount) +
            " ثم ارجع لربطها بطلب التمويل",
        );
      },
      batches(r) {
        return this.e.walletFundingBatches(r);
      },
    },
  };
  const requestSettings = {
    data() {
      return { draft: { amounts: [], dailyLimit: 1 }, error: "" };
    },
    computed: {
      vm() {
        return this.$root;
      },
      visible() {
        return (
          this.vm.page === "security" && this.vm.can("security.fundingRequests")
        );
      },
    },
    watch: {
      visible: {
        immediate: true,
        handler(v) {
          if (v) this.load();
        },
      },
      "vm.currentUser"() {
        this.load();
      },
    },
    methods: {
      load() {
        this.draft = M.clone(this.vm.engine.fundingRequestPolicy());
        this.error = "";
      },
      add() {
        this.draft.amounts.push("");
      },
      save() {
        this.error = "";
        try {
          this.vm.engine.saveFundingRequestPolicy(this.draft);
          this.vm.persist();
          this.load();
          this.vm.notify("تم حفظ إعدادات طلبات التمويل");
        } catch (e) {
          this.error = e.message;
        }
      },
    },
  };
  function install(o) {
    o.components["funding-request-settings"] = requestSettings;
    void 0;
    const data = o.data;
    o.data = function () {
      return { ...data.call(this), walletOrderAgent: "" };
    };
    const form = o.components["cash-order-form"],
      formData = form.data;
    form.data = function () {
      const d = formData.call(this);
      if (this.$root.walletOrderAgent) {
        d.draft.agent = this.$root.walletOrderAgent;
        this.$root.walletOrderAgent = "";
      }
      return d;
    };
    const panel = o.components["operations-panel"];
    panel.components ??= {};
    panel.components["simple-wallets"] = component;
    void 0;
    const marker = "</template>\n\n <div v-if=\"page==='exceptions'\"";
    void 0;
    void 0;
    panel.computed.walletFundingPending = function () {
      if (!this.vm.can("wallets.approve")) return 0;
      const me = this.e.walletIdentity();
      return this.s.fundingRequests.filter(
        (r) =>
          this.e.walletRequestVisible(r) &&
          r.from === me &&
          ["بانتظار التمويل", "معتمد ومحجوز"].includes(r.status),
      ).length;
    };
    void 0;
    void 0;
    void 0;
    void 0;
  }
  root.MasalSimpleWallets = { install };
})(globalThis);
