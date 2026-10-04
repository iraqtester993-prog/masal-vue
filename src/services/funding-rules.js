(function (root) {
  "use strict";
  const M = root.Masal,
    P = M.Engine.prototype,
    copy = M.clone;
  const baseBalance = P.serviceBalance,
    baseAvailable = P.serviceAvailable,
    baseRequest = P.requestFunding,
    baseReserve = P.reserveFunding;
  function serviceIds(engine) {
    return [
      "voucher",
      "topup",
      "cash",
      ...engine.s.providers
        .filter((p) => p.connection === "API")
        .map((p) => "api:" + p.id),
    ];
  }
  function assertService(engine, service) {
    if (service === "all")
      throw Error("اختر خدمة محددة للتمويل؛ الإجمالي للعرض فقط");
  }
  function rollback(state, before) {
    for (const key of Object.keys(state))
      if (!(key in before)) delete state[key];
    Object.assign(state, before);
  }
  function atomic(engine, fn) {
    const before = copy(engine.s);
    try {
      return fn();
    } catch (error) {
      rollback(engine.s, before);
      throw error;
    }
  }
  function controller(engine) {
    const user = engine.actor();
    return user?.staffAccount && user.staffAccount !== "@system"
      ? user.staffAccount
      : user?.agent || "";
  }
  function assertOwner(engine, from) {
    if (controller(engine) !== from)
      throw Error("التمويل ينفذه مستخدم مخوّل من حساب الوكيل المموّل فقط");
  }
  P.serviceBalance = function (account, service = "voucher") {
    return service === "all"
      ? serviceIds(this).reduce(
          (sum, id) => sum + baseBalance.call(this, account, id),
          0,
        )
      : baseBalance.call(this, account, service);
  };
  P.serviceAvailable = function (account, service = "voucher") {
    return service === "all"
      ? serviceIds(this).reduce(
          (sum, id) => sum + baseAvailable.call(this, account, id),
          0,
        )
      : baseAvailable.call(this, account, service);
  };
  const baseFund = P.fund;
  P.fund = function (from, to, amount, service, key) {
    assertService(this, service);
    if (!this.canFundFrom(from))
      throw Error("ليس لديك صلاحية التمويل من الحساب المحدد");
    return baseFund.call(this, from, to, amount, service, key, "");
  };
  P.requestFunding = function (to, from, amount, service, purpose, key) {
    assertService(this, service);
    return baseRequest.call(this, to, from, amount, service, purpose, key);
  };
  P.authorizeFunding = function () {
    throw Error("تم إلغاء التمويل الاستثنائي؛ التمويل ينفذه حساب الوكيل فقط");
  };
  P.reserveFunding = function (id, amount) {
    const request = this.s.fundingRequests.find((r) => r.id === id);
    if (!request) throw Error("الطلب غير موجود");
    assertService(this, request.service);
    assertOwner(this, request.from);
    return baseReserve.call(this, id, amount, "");
  };
  P.bulkFunding = function (rows, service, key) {
    this.operations();
    this.requirePermission("wallets.bulk");
    this.requirePermission("wallets.transfer");
    assertService(this, service);
    if (!key) throw Error("معرف المجموعة مطلوب");
    if (!Array.isArray(rows) || !rows.length)
      throw Error("أدخل مستفيدًا ومبلغًا لكل سطر");
    const from = rows[0]?.from;
    assertOwner(this, from);
    if (rows.some((r) => r.from !== from))
      throw Error("كل مستفيدي المجموعة يجب أن يكونوا من الوكيل نفسه");
    if (new Set(rows.map((r) => r.to)).size !== rows.length)
      throw Error("المستفيد مكرر في المجموعة");
    const total = rows.reduce((sum, r) => sum + Number(r.amount), 0);
    if (
      !Number.isFinite(total) ||
      rows.some(
        (r) => !Number.isFinite(Number(r.amount)) || Number(r.amount) <= 0,
      )
    )
      throw Error("تحقق من مبالغ المجموعة");
    if (this.serviceAvailable(from, service) < total)
      throw Error("رصيد الوكيل لا يكفي لكامل المجموعة؛ لم ينفذ أي تمويل");
    const previous = this.s.fundingTransfers.filter((t) => t.groupKey === key);
    if (previous.length === rows.length)
      return rows.map((r) => ({
        to: r.to,
        status: "منفذ",
        transfer: previous.find((t) => t.to === r.to)?.id,
      }));
    if (previous.length)
      throw Error("هذه المجموعة غير مكتملة؛ استخدم مفتاح مجموعة جديد");
    return atomic(this, () =>
      rows.map((r, i) => {
        const t = this.fund(from, r.to, r.amount, service, key + "-" + i);
        t.groupKey = key;
        return { to: r.to, status: "منفذ", transfer: t.id };
      }),
    );
  };
  P.processFundingBatch = function (ids, amounts = {}) {
    this.operations();
    this.requirePermission("wallets.approve");
    const requests = ids.map((id) =>
      this.s.fundingRequests.find((r) => r.id === id),
    );
    if (
      !requests.length ||
      requests.some((r) => !r || r.status !== "بانتظار التمويل")
    )
      throw Error("حدد طلبات تمويل بانتظار المعالجة فقط");
    const from = requests[0].from,
      service = requests[0].service;
    assertService(this, service);
    assertOwner(this, from);
    if (requests.some((r) => r.from !== from || r.service !== service))
      throw Error("اعتمد معًا طلبات الوكيل والخدمة نفسيهما فقط");
    const values = requests.map((r) => Number(amounts[r.id] ?? r.amount));
    if (values.some((v) => !Number.isFinite(v) || v <= 0))
      throw Error("تحقق من مبالغ الطلبات");
    if (
      this.serviceAvailable(from, service) < values.reduce((n, v) => n + v, 0)
    )
      throw Error("رصيد الوكيل لا يكفي لكل الطلبات؛ لم ينفذ أي طلب");
    return atomic(this, () =>
      requests.map((r, index) => this.processFunding(r.id, values[index])),
    );
  };
  function install(o) {
    const panel = o.components["operations-panel"],
      baseServices = panel.computed.services,
      baseLedger = panel.computed.ledger,
      baseServiceName = panel.methods.serviceName;
    panel.computed.services = function () {
      return [
        { id: "all", name: "إجمالي كل الأرصدة" },
        ...baseServices.call(this),
      ];
    };
    panel.computed.ledger = function () {
      if (this.service !== "all") return baseLedger.call(this);
      return this.s.serviceLedger
        .filter(
          (l) =>
            this.accounts.some((a) => a.id === l.account) &&
            (!this.account || l.account === this.account),
        )
        .slice()
        .reverse();
    };
    panel.computed.balanceTotal = function () {
      const accounts = this.walletAccounts || this.accounts;
      if (this.service !== "all")
        return accounts.reduce(
          (n, a) => n + this.e.serviceBalance(a.id, this.service),
          0,
        );
      const ids = this.services.filter((s) => s.id !== "all").map((s) => s.id);
      return accounts.reduce(
        (sum, a) =>
          sum + ids.reduce((n, id) => n + this.e.serviceBalance(a.id, id), 0),
        0,
      );
    };
    panel.methods.serviceName = function (id) {
      return id === "all"
        ? "إجمالي كل الأرصدة"
        : baseServiceName.call(this, id);
    };
    panel.methods.approveRequests = function () {
      this.act(() => {
        const result = this.e.processFundingBatch(
          this.fundSelected,
          this.fundAmounts,
        );
        this.fundSelected = [];
        this.fundAmounts = {};
        return result;
      }, "تم تنفيذ الطلبات المحددة دفعة واحدة");
    };
  }
  const cents = (value) => Math.round(Number(value) * 100);
  function recoveryHours(s) {
    const n = Number(s.settings.fundingRecoveryHours ?? 24);
    return Number.isFinite(n) && n > 0 ? n : 24;
  }
  P.stampFundingRecovery = function (t) {
    if (Object.hasOwn(t, "recoveryDeadline")) return t;
    const hours = recoveryHours(this.s),
      time = Date.parse(t.time),
      end = time + hours * 3600000;
    t.recoveryHours = hours;
    t.recoveryDeadline =
      Number.isFinite(time) &&
      Number.isFinite(end) &&
      Math.abs(end) <= 8640000000000000
        ? new Date(end).toISOString()
        : null;
    return t;
  };
  const recoveryOperations = P.operations;
  P.operations = function (...args) {
    const result = recoveryOperations.apply(this, args);
    this.s.settings.fundingRecoveryHours ??= 24;
    for (const t of this.s.fundingTransfers) this.stampFundingRecovery(t);
    return result;
  };
  P.setFundingRecoveryHours = function (value) {
    this.operations();
    this.requirePermission("security.fundingRecovery");
    const hours = Number(value);
    if (
      !Number.isFinite(hours) ||
      hours <= 0 ||
      !Number.isFinite(new Date(Date.now() + hours * 3600000).getTime())
    )
      throw Error("أدخل عدد ساعات موجبًا وصالحًا");
    const before = this.s.settings.fundingRecoveryHours;
    this.s.settings.fundingRecoveryHours = hours;
    this.log(
      "تعديل مهلة استرجاع الرصيد",
      "fundingRecoveryHours",
      before,
      hours,
    );
    return hours;
  };
  P.fundingRecoveryOpen = function (t, now = Date.now()) {
    return (
      !!t &&
      t.status !== "معكوس" &&
      Number.isFinite(Date.parse(t.time)) &&
      Date.parse(t.time) <= now &&
      Number.isFinite(Date.parse(t.recoveryDeadline)) &&
      now < Date.parse(t.recoveryDeadline)
    );
  };
  P.recoverableFunding = function (from, to, service, transferId = "") {
    this.operations();
    const remaining = this.s.fundingTransfers
      .filter(
        (t) =>
          t.from === from &&
          t.to === to &&
          t.service === service &&
          (!transferId || t.id === transferId) &&
          this.fundingRecoveryOpen(t),
      )
      .reduce(
        (sum, t) =>
          sum + Math.max(0, cents(t.amount) - cents(t.recoveredAmount || 0)),
        0,
      );
    return (
      Math.max(
        0,
        Math.min(remaining, cents(this.serviceAvailable(to, service))),
      ) / 100
    );
  };
  P.recoverFunding = function (
    from,
    to,
    amount,
    service,
    reason,
    key,
    transferId = "",
  ) {
    this.operations();
    this.requirePermission("wallets.reverse");
    assertOwner(this, from);
    assertService(this, service);
    this.accountCheck(from);
    this.accountCheck(to);
    if (from === to || !this.descendants(from).includes(this.accountAgent(to)))
      throw Error("الاسترجاع من الحسابات التابعة للوكيل فقط");
    amount = Number(amount);
    reason = String(reason || "").trim();
    if (
      !Number.isFinite(amount) ||
      amount <= 0 ||
      Math.abs(amount * 100 - cents(amount)) > 0.00001
    )
      throw Error("أدخل مبلغًا موجبًا بمنزلتين عشريتين كحد أقصى");
    if (!reason || !key) throw Error("سبب الاسترجاع مطلوب");
    const old = (this.s.fundingRecoveries || []).find((r) => r.key === key);
    if (old) {
      if (
        old.from !== from ||
        old.to !== to ||
        old.service !== service ||
        old.amount !== amount ||
        old.reason !== reason ||
        (old.transferId || "") !== transferId
      )
        throw Error("معرف الاسترجاع مستخدم لبيانات مختلفة");
      return old;
    }
    if (transferId) {
      const t = this.s.fundingTransfers.find(
        (t) =>
          t.id === transferId &&
          t.from === from &&
          t.to === to &&
          t.service === service,
      );
      if (!t) throw Error("عملية التمويل غير مطابقة للحساب");
      if (!this.fundingRecoveryOpen(t))
        throw Error("انتهت مهلة الاسترجاع أو لا يتوفر تاريخ صالح للعملية");
    }
    if (
      cents(amount) >
      cents(this.recoverableFunding(from, to, service, transferId))
    )
      throw Error(
        "المبلغ أكبر من الرصيد المتاح للاسترجاع؛ الرصيد المستخدم أو المحجوز أو الموزع لا يمكن سحبه",
      );
    return atomic(this, () => {
      let remaining = cents(amount);
      const allocations = [];
      for (const t of this.s.fundingTransfers.filter(
        (t) =>
          t.from === from &&
          t.to === to &&
          t.service === service &&
          (!transferId || t.id === transferId) &&
          this.fundingRecoveryOpen(t),
      )) {
        const take = Math.min(
          remaining,
          Math.max(0, cents(t.amount) - cents(t.recoveredAmount || 0)),
        );
        if (!take) continue;
        t.recoveredAmount = (cents(t.recoveredAmount || 0) + take) / 100;
        allocations.push({ transfer: t.id, amount: take / 100 });
        remaining -= take;
        if (!remaining) break;
      }
      if (remaining)
        throw Error("تغيّر المتاح أو انتهت المهلة؛ أعد مراجعة العملية");
      const r = {
        id: M.id("RECOVERY"),
        key,
        from,
        to,
        amount,
        service,
        reason,
        transferId,
        allocations,
        user: this.user,
        time: new Date().toISOString(),
      };
      this.s.fundingRecoveries ??= [];
      this.s.fundingRecoveries.unshift(r);
      this.serviceEntry(
        to,
        service,
        -amount,
        "استرجاع رصيد إلى الممول",
        r.id,
        from,
      );
      this.serviceEntry(from, service, amount, "استرداد رصيد موزع", r.id, to);
      this.log("استرجاع رصيد موزع", r.id, null, r);
      this.s.notifications.unshift({
        id: M.id("NT"),
        target: to,
        title: "استرجاع رصيد",
        body: amount + " د.ع • " + reason,
        time: r.time,
        user: this.user,
      });
      return r;
    });
  };
  const previousReverse = P.reverseFunding;
  P.reverseFunding = function (id, reason) {
    this.operations();
    const t = this.s.fundingTransfers.find((t) => t.id === id);
    if (t && t.status !== "معكوس" && !this.fundingRecoveryOpen(t))
      throw Error("انتهت مهلة استرجاع هذا التمويل");
    if (t?.recoveredAmount)
      throw Error(
        "تم استرجاع جزء من هذا التمويل؛ استخدم استرجاع الرصيد للمبلغ المتبقي",
      );
    return previousReverse.call(this, id, reason);
  };
  function installRecovery(o) {
    const panel = o.components["operations-panel"],
      data = panel.data;
    panel.data = function () {
      return {
        ...data.call(this),
        recovery: {
          to: "",
          transferId: "",
          amount: "",
          reason: "",
          key: M.id("RECOVERY"),
        },
        recoveryError: "",
        recoveryClock: Date.now(),
        recoveryHoursDraft: recoveryHours(this.$root.s),
      };
    };
    const mounted = panel.mounted,
      unmounted = panel.beforeUnmount;
    panel.mounted = function (...args) {
      mounted?.apply(this, args);
      this._recoveryTimer = setInterval(() => {
        this.recoveryClock = Date.now();
      }, 1000);
    };
    panel.beforeUnmount = function (...args) {
      clearInterval(this._recoveryTimer);
      unmounted?.apply(this, args);
    };
    panel.computed.recoveryFrom = function () {
      return controller(this.e);
    };
    panel.computed.recoveryAccounts = function () {
      return this.accounts.filter(
        (a) =>
          a.id !== this.recoveryFrom &&
          this.s.fundingTransfers.some(
            (t) =>
              t.from === this.recoveryFrom &&
              t.to === a.id &&
              t.service === this.service,
          ),
      );
    };
    panel.computed.recoveryTransfers = function () {
      this.e.operations();
      return this.s.fundingTransfers.filter(
        (t) =>
          t.from === this.recoveryFrom &&
          t.to === this.recovery.to &&
          t.service === this.service,
      );
    };
    panel.computed.selectedRecoveryTransfer = function () {
      return this.recoveryTransfers.find(
        (t) => t.id === this.recovery.transferId,
      );
    };
    panel.computed.recoveryLimit = function () {
      this.recoveryClock;
      return this.recovery.transferId
        ? this.e.recoverableFunding(
            this.recoveryFrom,
            this.recovery.to,
            this.service,
            this.recovery.transferId,
          )
        : 0;
    };
    panel.computed.recoveryRows = function () {
      const ids = new Set(
        (this.walletAccounts || this.accounts).map((a) => a.id),
      );
      return (this.s.fundingRecoveries || []).filter(
        (r) =>
          (ids.has(r.from) || ids.has(r.to)) &&
          (this.service === "all" || r.service === this.service),
      );
    };
    panel.methods.resetRecovery = function () {
      this.recovery = {
        to: "",
        transferId: "",
        amount: "",
        reason: "",
        key: M.id("RECOVERY"),
      };
      this.recoveryError = "";
    };
    panel.methods.submitRecovery = function () {
      this.recoveryError = "";
      try {
        if (!this.recovery.transferId) throw Error("اختر عملية التمويل");
        this.e.recoverFunding(
          this.recoveryFrom,
          this.recovery.to,
          this.recovery.amount,
          this.service,
          this.recovery.reason,
          this.recovery.key,
          this.recovery.transferId,
        );
        this.resetRecovery();
        this.vm.notify("تم استرجاع الرصيد إلى محفظة الوكيل وتسجيل الحركة");
      } catch (error) {
        this.recoveryError = error.message;
      }
    };
    panel.methods.recoveryTimeLabel = function (t) {
      if (t.status === "معكوس") return "تم عكس التمويل";
      if (!t.recoveryDeadline) return "تاريخ العملية غير متوفر";
      if (!this.e.fundingRecoveryOpen(t, this.recoveryClock))
        return "انتهت مهلة الاسترجاع";
      const minutes = Math.ceil(
        (Date.parse(t.recoveryDeadline) - this.recoveryClock) / 60000,
      );
      return (
        "متبقي " +
        Math.floor(minutes / 60) +
        " ساعة و" +
        (minutes % 60) +
        " دقيقة"
      );
    };
    panel.methods.saveRecoveryHours = function () {
      this.act(
        () => this.e.setFundingRecoveryHours(this.recoveryHoursDraft),
        "تم حفظ مهلة استرجاع الرصيد",
      );
    };
    const reset = panel.methods.reset;
    panel.methods.reset = function (...args) {
      this.resetRecovery();
      return reset.apply(this, args);
    };
    const watch = panel.watch?.service;
    panel.watch ??= {};
    panel.watch.service = function (...args) {
      this.resetRecovery();
      if (typeof watch === "function") watch.apply(this, args);
    };
  }
  root.MasalFundingRules = {
    install(o) {
      install(o);
      installRecovery(o);
    },
  };
})(globalThis);
