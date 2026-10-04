(function (root) {
  "use strict";
  const P = Masal.Engine.prototype,
    defaults = { failedRetries: 2, maxCards: 10, intervalSeconds: 5 };
  function owner(e) {
    e.requirePermission("security.policies");
    if (e.actor().role !== "owner")
      throw Error("تعديل ضوابط الطباعة لمدير النظام فقط");
  }
  P.printPolicy = function (id) {
    return {
      ...defaults,
      ...this.s.settings.printPolicy,
      ...this.s.pos.find((p) => p.id === id)?.printPolicy,
    };
  };
  P.savePrintPolicy = function (id, draft) {
    owner(this);
    const point = id && this.s.pos.find((p) => p.id === id);
    if (id && !point) throw Error("نقطة البيع غير موجودة");
    const target = point || this.s.settings,
      before = Masal.clone(target.printPolicy || null);
    if (draft === null) {
      if (!point) throw Error("لا يمكن حذف الإعداد العام");
      delete target.printPolicy;
    } else {
      const next = {};
      for (const key of Object.keys(defaults)) {
        const v = draft[key],
          n = Number(v);
        if (
          v === "" ||
          v == null ||
          !Number.isSafeInteger(n) ||
          n < (key === "maxCards" ? 1 : 0) ||
          n > 100000
        )
          throw Error("أدخل أعدادًا صحيحة؛ عدد البطاقات لا يقل عن 1");
        next[key] = n;
      }
      Object.assign(next, MasalDailyPrint.validate(draft));
      target.printPolicy = next;
    }
    this.log(
      "تعديل ضوابط الطباعة",
      id || "settings",
      before,
      target.printPolicy || null,
    );
  };
  P.printWait = function (id, now = Date.now(), product) {
    const last = Math.max(
      0,
      ...this.s.sales
        .filter((t) => t.pos === id)
        .map((t) => Date.parse(t.printStartedAt || "") || 0),
    );
    return Math.max(
      0,
      Math.ceil(
        (last + this.printPolicy(id, product).intervalSeconds * 1000 - now) /
          1000,
      ),
    );
  };
  P.failureRetriesLeft = function (t) {
    return Math.max(
      0,
      this.printPolicy(t.pos, t.product).failedRetries -
        (t.failedRetryCount || 0),
    );
  };
  P.checkPrintStart = function (t) {
    if (this.s.sales.some((x) => x.pos === t.pos && x.printPending))
      throw Error(
        "سجل نتيجة الطباعة المعلقة أولًا؛ النتيجة غير المعروفة ليست فشلًا",
      );
    const wait = this.printWait(t.pos, Date.now(), t.product);
    if (wait) throw Error("انتظر " + wait + " ثانية قبل الطباعة التالية");
  };
  P.beginPrint = function (id) {
    this.requirePermission("sell.print");
    const t = this.assertPrintReady(id);
    this.checkPrintStart(t);
    if (t.failureRetry && this.failureRetriesLeft(t) <= 0)
      throw Error("تم بلوغ حد المحاولات؛ اطلب موافقة إعادة الطباعة");
    if (t.failureRetry) {
      t.failedRetryCount = (t.failedRetryCount || 0) + 1;
      delete t.failureRetry;
    }
    t.printPending = true;
    t.printStartedAt = new Date().toISOString();
    this.log("بدء محاولة طباعة", t.id, null, {
      failedRetries: t.failedRetryCount || 0,
      time: t.printStartedAt,
    });
    return t;
  };
  P.retryFailedPrint = function (id) {
    this.requirePermission("sell.reprint");
    this.requirePermission("sell.print");
    const t = this.s.sales.find((x) => x.id === id);
    if (!t || this.sellerID() !== t.pos)
      throw Error("إعادة المحاولة لصاحب البيع فقط");
    this.accountCheck(t.pos);
    this.checkOperation(t.pos, "printing");
    if (t.status !== "Print Failed")
      throw Error("إعادة المحاولة للطباعة الفاشلة فقط");
    if (this.failureRetriesLeft(t) <= 0)
      throw Error("تم بلوغ الحد؛ اطلب موافقة إعادة الطباعة");
    this.checkPrintStart(t);
    t.status = "Print Requested";
    t.failureRetry = true;
    this.log("تجهيز إعادة محاولة فاشلة", id, null, {
      remaining: this.failureRetriesLeft(t),
    });
    return t;
  };
  const result = P.printResult;
  P.printResult = function (id, ...args) {
    const t = this.s.sales.find((x) => x.id === id);
    if (!t?.printPending) throw Error("ابدأ محاولة الطباعة قبل تسجيل النتيجة");
    const out = result.call(this, id, ...args);
    delete this.s.sales.find((x) => x.id === id).printPending;
    return out;
  };
  const request = P.requestReprint;
  P.requestReprint = function (id, ...args) {
    if (this.s.sales.find((t) => t.id === id)?.printPending)
      throw Error("سجل نتيجة الطباعة المعلقة أولًا");
    return request.call(this, id, ...args);
  };
  function quantity(e, id, q, product) {
    if (Number(q) > e.printPolicy(id, product).maxCards)
      throw Error(
        "الحد الأقصى لعدد البطاقات في الطلب الواحد: " +
          e.printPolicy(id).maxCards,
      );
  }
  const sell = P.sell;
  P.sell = function (id, product, q, key, ...args) {
    if (!this.s.sales.some((t) => t.key === key))
      quantity(this, id, q, product);
    return sell.call(this, id, product, q, key, ...args);
  };
  const reserve = P.reserve;
  P.reserve = function (id, product, q, ...args) {
    quantity(this, id, q);
    return reserve.call(this, id, product, q, ...args);
  };
  const issue = P.issueReservation;
  P.issueReservation = function (id, ...args) {
    const r = this.s.reservations.find((x) => x.id === id);
    if (r && r.status !== "صادر") quantity(this, r.pos, r.quantity, r.product);
    return issue.call(this, id, ...args);
  };
  const labels = {
    failedRetries: "عدد محاولات إعادة الطباعة بعد الفشل",
    maxCards: "الحد الأقصى لعدد البطاقات في الطلب الواحد",
    intervalSeconds: "مدة الانتظار بين عمليتي طباعة — بالثواني",
  };
  const panel = {
    data() {
      return { target: "", draft: {}, labels };
    },
    computed: {
      vm() {
        return this.$root;
      },
      allowed() {
        return this.vm.actor.role === "owner";
      },
      policy() {
        return this.vm.engine.printPolicy(this.target);
      },
    },
    mounted() {
      this.load();
    },
    watch: {
      target() {
        this.load();
      },
      "vm.currentUser"() {
        this.target = "";
        this.load();
      },
    },
    methods: {
      load() {
        this.draft = { ...this.policy };
      },
      save() {
        this.vm.run(() => {
          this.vm.engine.savePrintPolicy(this.target, this.draft);
          this.vm.persist();
        }, "تم حفظ ضوابط الطباعة");
      },
      reset() {
        this.vm.run(() => {
          this.vm.engine.savePrintPolicy(this.target, null);
          this.load();
          this.vm.persist();
        }, "تم تطبيق الإعدادات العامة على النقطة");
      },
    },
  };
  const summary = {
    props: ["tx"],
    data() {
      return { now: Date.now() };
    },
    computed: {
      vm() {
        return this.$root;
      },
      id() {
        return this.tx?.pos || this.vm.engine.sellerID();
      },
      policy() {
        return this.vm.engine.printPolicy(
          this.id,
          this.tx?.product || this.vm.saleForm.product,
        );
      },
      wait() {
        return this.vm.engine.printWait(
          this.id,
          this.now,
          this.tx?.product || this.vm.saleForm.product,
        );
      },
      remaining() {
        return this.tx
          ? this.vm.engine.failureRetriesLeft(this.tx)
          : this.policy.failedRetries;
      },
    },
    mounted() {
      this.timer = setInterval(() => (this.now = Date.now()), 500);
    },
    beforeUnmount() {
      clearInterval(this.timer);
    },
  };
  function install(o) {
    NAV.find((g) => g.title === "إدارة النظام").items.push({
      id: "printPolicies",
      label: "ضوابط الطباعة",
      icon: "▣",
      subtitle: "حدود الطلب ومحاولات الفشل والفاصل بين الطباعات",
    });
    const can = o.methods.can;
    o.methods.can = function (key) {
      if (key.startsWith("printPolicies."))
        return (
          this.actor.role === "owner" && this.engine.can("security.policies")
        );
      return can.call(this, key);
    };
    o.components["print-policy-settings"] = panel;
    o.components["print-policy-summary"] = summary;
    const data = o.data;
    o.data = function () {
      return { ...data.call(this), printClock: Date.now() };
    };
    const mounted = o.mounted;
    o.mounted = function () {
      mounted?.call(this);
      this.printClockTimer = setInterval(
        () => (this.printClock = Date.now()),
        500,
      );
    };
    const unmount = o.beforeUnmount;
    o.beforeUnmount = function () {
      clearInterval(this.printClockTimer);
      unmount?.call(this);
    };
    o.methods.printStartDisabled = function (t) {
      return (
        !t ||
        t.printPending ||
        this.engine.printWait(t.pos, this.printClock, t.product) > 0 ||
        this.s.sales.some((x) => x.pos === t.pos && x.printPending)
      );
    };
    o.methods.retryFailedPrint = function (t) {
      this.run(() => {
        const tx = this.engine.retryFailedPrint(t.id);
        this.viewReceipt(tx);
        this.persist();
      });
    };
    const print = o.methods.printReceipt;
    o.methods.printReceipt = function () {
      try {
        this.engine.beginPrint(this.modal.tx.id);
        this.persist();
      } catch (e) {
        this.notify(e.message, true);
        return;
      }
      return print.call(this);
    };
  }
  root.MasalPrintPolicies = { install };
})(globalThis);
