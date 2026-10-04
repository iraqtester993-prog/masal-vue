(function (root) {
  "use strict";
  const M = root.Masal || null,
    P = M.Engine.prototype;
  const now = () => new Date().toISOString(),
    copy = M.clone;
  const positive = (v) => {
    v = Number(v);
    if (!Number.isFinite(v) || v <= 0) throw Error("المبلغ يجب أن يكون موجبًا");
    return Math.round(v * 100) / 100;
  };
  function initialize(s) {
    for (const k of [
      "serviceLedger",
      "fundingRequests",
      "fundingTransfers",
      "fundingExceptions",
      "batchInvoices",
      "printOverrides",
      "reservations",
      "serviceOrders",
      "collections",
      "deviceRequests",
    ])
      s[k] ??= [];
    // Existing unfinished attempts must also wait for approval after upgrading.
    for (const t of s.sales || []) {
      if (t.status === "Reprint Requested" && !t.reprintApproval) {
        const attempt = [...(t.attempts || [])]
          .reverse()
          .find((a) => a.status === "Reprint Requested");
        const r = {
          id: M.id("RP"),
          tx: t.id,
          agent: t.agent,
          pos: t.pos,
          reason: attempt?.reason || "مراجعة طلب إعادة الطباعة السابق",
          status: "قيد المراجعة",
          user: attempt?.user || t.user,
          time: now(),
          previous: "Print Failed",
          counted: true,
        };
        s.printOverrides.unshift(r);
        t.reprintApproval = r.id;
      }
    }
    s.serviceKeys ??= {};
    s.serviceSettings ??= {};
    s.publicContent ??= {
      about: "",
      services: "",
      contact: "",
      android: "",
      ios: "",
      privacy: "",
      terms: "",
    };
    if (!s.operationsVersion) {
      // Historic cash is retained verbatim; only identifiable remaining stock becomes opening voucher credit.
      for (const a of s.agents.filter((a) => !a.parent)) {
        const cards = s.cards.filter(
          (c) => c.agent === a.id && c.status === "Available",
        );
        const amount = cards.reduce((n, c) => n + Number(c.cost || 0), 0);
        if (amount)
          s.serviceLedger.push({
            id: M.id("SL"),
            group: "OPEN-" + a.id,
            account: a.id,
            service: "voucher",
            amount,
            kind: "رصيد افتتاحي من تكلفة المخزون المتبقي",
            time: now(),
          });
      }
      s.operationsVersion = 1;
    }
    return s;
  }
  P.operations = function () {
    return initialize(this.s);
  };
  P.serviceBalance = function (account, service = "voucher") {
    this.operations();
    return this.s.serviceLedger
      .filter((l) => l.account === account && l.service === service)
      .reduce((n, l) => n + l.amount, 0);
  };
  P.serviceAvailable = function (account, service = "voucher") {
    this.operations();
    const held = (this.s.fundingHolds || [])
      .filter(
        (h) =>
          h.from === account && h.service === service && h.status === "محجوز",
      )
      .reduce((n, h) => n + h.amount, 0);
    return (
      this.serviceBalance(account, service) -
      held -
      this.s.reservations
        .filter(
          (r) =>
            r.pos === account && r.status === "محجوز" && service === "voucher",
        )
        .reduce((n, r) => n + r.credit, 0)
    );
  };
  P.serviceEntry = function (
    account,
    service,
    amount,
    kind,
    group,
    counterparty,
  ) {
    this.s.serviceLedger.push({
      id: M.id("SL"),
      account,
      service,
      amount,
      kind,
      group,
      counterparty,
      time: now(),
    });
  };
  P.accountCheck = function (account) {
    const agent = this.s.agents.find((a) => a.id === account),
      pos = this.s.pos.find((p) => p.id === account);
    if (!agent && !pos) throw Error("الحساب غير موجود");
    this.require(pos?.agent || agent.id);
    if (this.actor().role === "pos" && account !== this.actor().pos)
      throw Error("الحساب خارج نطاق نقطة البيع");
    return agent || pos;
  };
  P.serviceCredit = function (account, service, amount, reference, key) {
    this.operations();
    this.requirePermission("wallets.deposit");
    this.accountCheck(account);
    if (service === "voucher")
      throw Error("الرصيد التشغيلي ينشأ من تحميل البطاقات فقط");
    if (
      !["topup", "api", "cash"].includes(service) &&
      !this.s.providers.some((p) => service === "api:" + p.id)
    )
      throw Error("خدمة غير صالحة");
    amount = positive(amount);
    if (!reference?.trim() || !key) throw Error("مرجع الإيداع مطلوب");
    const old = this.s.serviceKeys[key];
    if (old) return old;
    this.serviceEntry(account, service, amount, "إيداع خدمة", key, reference);
    this.s.serviceKeys[key] = key;
    this.log("إيداع رصيد خدمة", account, null, { service, amount, reference });
    return key;
  };
  P.authorizeFunding = function (from, reason) {
    this.operations();
    this.requirePermission("wallets.exception");
    this.accountCheck(from);
    if (!reason?.trim()) throw Error("وثق سبب الاستثناء");
    const r = {
      id: M.id("FX"),
      from,
      reason,
      user: this.user,
      time: now(),
      used: false,
    };
    this.s.fundingExceptions.unshift(r);
    this.log("استثناء تمويل موثق", r.id, null, r);
    return r;
  };
  // A selected payer remains the debited account; administration never impersonates its user.
  P.canFundFrom = function (from) {
    const u = this.actor(),
      account = this.s.agents.find((a) => a.id === from);
    if (
      !account?.active ||
      !this.can("wallets.transfer") ||
      !this.allowed(from)
    )
      return false;
    return (
      u.role === "owner" ||
      (u.role === "employee" &&
        u.staffAccount === "@system" &&
        this.can("wallets.bulk")) ||
      (["main", "sub", "employee"].includes(u.role) &&
        (u.staffAccount && u.staffAccount !== "@system"
          ? u.staffAccount
          : u.agent) === from)
    );
  };
  P.fund = function (from, to, amount, service, key, exception = "") {
    this.operations();
    this.requirePermission("wallets.transfer");
    this.accountCheck(from);
    this.accountCheck(to);
    if (!key) throw Error("معرف العملية مطلوب");
    const previous = this.s.fundingTransfers.find((t) => t.key === key);
    if (previous) {
      if (
        previous.from !== from ||
        previous.to !== to ||
        previous.service !== service ||
        previous.amount !== Number(amount)
      )
        throw Error("معرف العملية مستخدم لطلب مختلف");
      return previous;
    }
    if (
      this.accountCheck(from).active === false ||
      this.accountCheck(to).active === false
    )
      throw Error("الحساب الممول أو المستفيد موقوف");
    amount = positive(amount);
    if (
      from === to ||
      !this.s.agents.some((a) => a.id === from) ||
      !this.descendants(from).includes(this.accountAgent(to))
    )
      throw Error("التمويل للوكلاء والنقاط التابعة فقط");
    const u = this.actor(),
      own = this.canFundFrom(from);
    const ex = this.s.fundingExceptions.find(
      (e) =>
        e.id === exception && e.from === from && e.user === u.id && !e.used,
    );
    if (!own && !ex)
      throw Error(
        "التمويل من حساب الوكيل يتطلب موظفًا تابعًا له أو استثناء تمويل موثقًا",
      );
    if (this.serviceAvailable(from, service) < amount)
      throw Error("الرصيد المتاح للخدمة غير كافٍ");
    const t = {
      id: M.id("FT"),
      key,
      from,
      to,
      amount,
      service,
      user: this.user,
      exception: ex?.id || "",
      status: "منفذ",
      time: now(),
    };
    this.stampFundingRecovery?.(t);
    this.serviceEntry(from, service, -amount, "تمويل صادر", t.id, to);
    this.serviceEntry(to, service, amount, "تمويل وارد", t.id, from);
    if (ex) ex.used = true;
    this.s.fundingTransfers.unshift(t);
    this.s.notifications.unshift({
      id: M.id("NT"),
      target: to,
      title: "تمويل محفظة",
      body: "تم استلام " + amount + " د.ع لخدمة " + service,
      time: now(),
      user: this.user,
    });
    this.log("تمويل خدمة", t.id, null, t);
    return t;
  };
  // Keep the old transfer entry point subject to the same financial rules.
  P.transfer = function (from, to, amount, key, exception) {
    return this.fund(
      from,
      to,
      amount,
      "voucher",
      key || M.id("FUND"),
      exception,
    ).id;
  };
  P.reverseFunding = function (id, reason) {
    this.operations();
    this.requirePermission("wallets.reverse");
    const t = this.s.fundingTransfers.find((t) => t.id === id);
    if (!t) throw Error("التحويل غير موجود");
    this.accountCheck(t.from);
    this.accountCheck(t.to);
    if (t.status === "معكوس") return t;
    if (!reason?.trim()) throw Error("سبب العكس مطلوب");
    if (this.serviceAvailable(t.to, t.service) < t.amount)
      throw Error("الرصيد مستخدم؛ لا يمكن عكس التحويل");
    this.serviceEntry(t.to, t.service, -t.amount, "عكس تمويل", t.id, t.from);
    this.serviceEntry(t.from, t.service, t.amount, "استرداد تمويل", t.id, t.to);
    t.status = "معكوس";
    t.reversalReason = reason;
    this.log("عكس تمويل", t.id, null, { reason });
    return t;
  };
  P.requestFunding = function (to, from, amount, service, purpose, key) {
    this.operations();
    this.requirePermission("wallets.request");
    this.accountCheck(to);
    if (!this.s.agents.some((a) => a.id === from))
      throw Error("الوكيل الممول غير موجود");
    amount = positive(amount);
    if (!purpose?.trim() || !key) throw Error("الغرض من التمويل مطلوب");
    if (to === from || !this.descendants(from).includes(this.accountAgent(to)))
      throw Error("اختر الوكيل الأعلى الصحيح");
    const old = this.s.fundingRequests.find((r) => r.key === key);
    if (old) return old;
    const r = {
      id: M.id("FR"),
      key,
      from,
      to,
      amount,
      service,
      purpose,
      status: "بانتظار التمويل",
      time: now(),
      user: this.user,
    };
    this.s.fundingRequests.unshift(r);
    this.s.notifications.unshift({
      id: M.id("NT"),
      target: from,
      title: "طلب تمويل جديد",
      body:
        (this.s.agents.find((a) => a.id === to)?.name ||
          this.s.pos.find((p) => p.id === to)?.name ||
          to) +
        " • " +
        amount +
        " د.ع • " +
        purpose,
      time: r.time,
      user: this.user,
      reference: r.id,
    });
    this.log("طلب تمويل", r.id, null, r);
    return r;
  };
  P.processFunding = function (id, amount, exception) {
    this.operations();
    this.requirePermission("wallets.approve");
    const r = this.s.fundingRequests.find((r) => r.id === id);
    if (!r) throw Error("الطلب غير موجود");
    this.accountCheck(r.from);
    if (r.status === "منفذ") return r;
    const t = this.fund(
      r.from,
      r.to,
      amount,
      r.service,
      "REQUEST-" + r.id,
      exception,
    );
    r.status = "منفذ";
    r.approvedAmount = t.amount;
    r.transfer = t.id;
    r.approver =
      this.s.users.find((u) => u.id === this.user)?.name || this.user;
    r.approverId = this.user;
    r.approvedAt = now();
    return r;
  };
  P.bulkFunding = function (rows, service, key) {
    this.operations();
    this.requirePermission("wallets.bulk");
    this.requirePermission("wallets.transfer");
    if (!key) throw Error("معرف المجموعة مطلوب");
    return rows.map((r, i) => {
      try {
        return {
          to: r.to,
          status: "منفذ",
          transfer: this.fund(
            r.from,
            r.to,
            r.amount,
            service,
            key + "-" + i,
            r.exception,
          ).id,
        };
      } catch (e) {
        return { to: r.to, status: "لم ينفذ", reason: e.message };
      }
    });
  };
  const originalImport = P.importBatch;
  P.importBatch = function (meta, rows) {
    this.operations();
    const loadPrice = positive(meta.loadPrice ?? meta.cost),
      contract = meta.contract || "إعادة بيع",
      commission = Number(meta.commission || 0);
    if (
      !["إعادة بيع", "عمولة"].includes(contract) ||
      !Number.isFinite(commission) ||
      commission < 0
    )
      throw Error("بيانات العقد غير صالحة");
    if (meta.postingKey) {
      const existing = this.s.batchInvoices.find(
        (i) => i.key === meta.postingKey,
      );
      if (existing) {
        this.require(existing.agent);
        return this.s.batches.find((b) => b.id === existing.batch);
      }
    }
    const debtor = this.s.agents.find((a) => a.id === meta.agent);
    if (meta.paymentMethod === "كاش") this.requirePermission("wallets.collect");
    if (meta.paymentMethod !== "كاش" && debtor?.creditLimit !== undefined) {
      const debt =
        this.s.batchInvoices
          .filter((i) => i.agent === meta.agent && i.status !== "معكوسة")
          .reduce((n, i) => n + i.amount, 0) -
        this.s.collections
          .filter((c) => c.account === meta.agent)
          .reduce((n, c) => n + c.amount, 0);
      const quantity = this.validateImport(meta, rows).filter(
        (r) => !r.error,
      ).length;
      if (debt + quantity * loadPrice > debtor.creditLimit)
        throw Error("قيمة الطلبية تتجاوز سقف مديونية الوكيل");
    }
    const b = originalImport.call(this, meta, rows);
    b.loadPrice = loadPrice;
    b.contract = contract;
    const cost = b.quantity * b.cost + b.expenses,
      amount = b.quantity * loadPrice;
    for (const c of this.s.cards.filter((c) => c.batch === b.id))
      c.credit = loadPrice;
    const inv = {
      id: M.id("INV"),
      batch: b.id,
      key: meta.postingKey || b.id,
      agent: b.agent,
      quantity: b.quantity,
      loadPrice,
      amount,
      cost,
      contract,
      commission,
      profit: contract === "عمولة" ? b.quantity * commission : amount - cost,
      time: now(),
      status: "مرحلة",
    };
    this.s.batchInvoices.unshift(inv);
    this.serviceEntry(b.agent, "voucher", amount, "تحميل بطاقات", b.id);
    this.log("فاتورة تحميل دفعة", inv.id, null, inv);
    return b;
  };
  const originalBatch = P.batchAction;
  P.batchAction = function (batch, action) {
    this.operations();
    const b = this.s.batches.find((b) => b.id === batch);
    if (!b) throw Error("الدفعة غير موجودة");
    this.require(b.agent);
    if (action === "cancel" && b.status === "Cancelled by Reversal") return;
    if (action === "cancel" && this.s.claims.some((c) => c.batch === batch))
      throw Error("سوّ المطالبة بدل إلغاء دفعتها");
    const cards = this.s.cards.filter(
        (c) =>
          c.batch === batch && ["Available", "Quarantined"].includes(c.status),
      ),
      credit = cards
        .filter((c) => !c.creditHeld)
        .reduce((n, c) => n + (c.credit ?? c.cost), 0);
    if (this.serviceAvailable(b.agent) < credit)
      throw Error("استرجع الرصيد الموزع قبل سحب هذه البطاقات");
    originalBatch.call(this, batch, action);
    if (credit)
      this.serviceEntry(
        b.agent,
        "voucher",
        -credit,
        action === "cancel" ? "عكس تحميل دفعة" : "حجر رصيد البطاقات",
        batch,
      );
    cards.forEach((c) => (c.creditHeld = true));
    if (action === "cancel") {
      const inv = this.s.batchInvoices.find((i) => i.batch === batch);
      if (inv) inv.status = "معكوسة";
    }
  };
  P.reprintLimit = function (posID) {
    const pos = this.saleAccount(posID);
    let a = this.s.agents.find((a) => a.id === pos?.agent);
    while (a && a.reprint === undefined)
      a = this.s.agents.find((x) => x.id === a.parent);
    const parent = Number(a?.reprint ?? this.s.settings.reprint);
    return pos?.reprint === undefined
      ? parent
      : Math.min(parent, Number(pos.reprint));
  };
  // Reprinting always requires a separate superior's approval, including within the limit.
  P.canApproveReprint = function (r) {
    const actor = {
        ...this.actor(),
        role: MasalAccess.managementRole(this.s, this.actor()),
      },
      source = this.s.users.find((u) => u.id === r.user),
      requester = source && {
        ...source,
        role: MasalAccess.managementRole(this.s, source),
      };
    if (!requester || r.user === this.user || actor.role === "pos")
      return false;
    if (actor.role === "owner") return true;
    if (requester.role === "owner") return false;
    if (actor.role === "supervisor")
      return !["supervisor"].includes(requester.role) && this.allowed(r.agent);
    if (!["main", "sub"].includes(actor.role)) return false;
    let id =
      requester.role === "pos"
        ? this.s.pos.find((p) => p.id === r.pos)?.agent
        : this.s.agents.find((a) => a.id === requester.agent)?.parent;
    const seen = new Set();
    while (id && !seen.has(id)) {
      if (id === actor.agent) return true;
      seen.add(id);
      id = this.s.agents.find((a) => a.id === id)?.parent;
    }
    return false;
  };
  P.requestReprint = function (tx, reason) {
    this.operations();
    this.requirePermission("sell.reprint");
    const t = this.s.sales.find((t) => t.id === tx);
    if (!t) throw Error("العملية غير موجودة");
    this.accountCheck(t.pos);
    if (!reason?.trim()) throw Error("سبب إعادة الطباعة مطلوب");
    const old = this.s.printOverrides.find(
      (r) =>
        r.id === t.reprintApproval &&
        ["قيد المراجعة", "معتمد"].includes(r.status),
    );
    if (old) return old;
    if (!["Printed", "Print Failed", "Reprinted"].includes(t.status))
      throw Error("حالة العملية لا تسمح بطلب إعادة الطباعة");
    const r = {
      id: M.id("RP"),
      tx,
      agent: t.agent,
      pos: t.pos,
      reason: reason.trim(),
      status: "قيد المراجعة",
      user: this.user,
      time: now(),
      previous: t.status,
    };
    this.s.printOverrides.unshift(r);
    t.reprintApproval = r.id;
    t.status = "Reprint Requested";
    this.log("طلب إذن إعادة الطباعة", r.id, null, r);
    return r;
  };
  P.reprint = function (tx, reason) {
    this.requestReprint(tx, reason);
    return this.s.sales.find((t) => t.id === tx);
  };
  P.approveReprint = function (id, approve) {
    this.operations();
    this.requirePermission("exceptions.approve");
    const r = this.s.printOverrides.find((r) => r.id === id);
    if (!r || r.status !== "قيد المراجعة") throw Error("طلب غير متاح");
    this.accountCheck(r.pos);
    if (!this.canApproveReprint(r))
      throw Error("الموافقة من مستخدم أعلى من مقدم الطلب ضمن الشبكة فقط");
    const t = this.s.sales.find((t) => t.id === r.tx);
    if (!t || t.reprintApproval !== id || t.status !== "Reprint Requested")
      throw Error("طلب غير مرتبط بالمحاولة الحالية");
    r.status = approve ? "معتمد" : "مرفوض";
    r.approver = this.user;
    r.decidedAt = now();
    if (approve) {
      if (!r.counted) t.reprints = (t.reprints || 0) + 1;
      r.counted = true;
      t.attempts.push({
        time: now(),
        reason: r.reason,
        user: r.user,
        approver: this.user,
        device: t.pos,
        status: "Reprint Requested",
      });
    } else {
      t.status = r.previous || "Print Failed";
      delete t.reprintApproval;
    }
    this.log("قرار إذن إعادة الطباعة", id, null, {
      status: r.status,
      tx: r.tx,
    });
  };
  P.assertPrintReady = function (tx) {
    this.operations();
    const t = this.s.sales.find((t) => t.id === tx);
    if (!t) throw Error("العملية غير موجودة");
    this.accountCheck(t.pos);
    if (
      !this.s.settings.printing ||
      !this.saleAccount(t.pos)?.active ||
      !this.saleAccount(t.pos)?.online
    )
      throw Error("الطباعة موقوفة أو الجهاز غير متصل");
    this.require(t.agent);
    if (t.status === "Print Requested") return t;
    if (t.status !== "Reprint Requested")
      throw Error("يلزم طلب إذن جديد لإعادة الطباعة");
    const r = this.s.printOverrides.find(
      (r) => r.id === t.reprintApproval && r.tx === tx && r.status === "معتمد",
    );
    if (!r) throw Error("بانتظار موافقة الأعلى على إعادة الطباعة");
    return t;
  };
  const originalPrintResult = P.printResult;
  P.printResult = function (tx, success) {
    const t = this.assertPrintReady(tx),
      request =
        t.status === "Reprint Requested"
          ? this.s.printOverrides.find((r) => r.id === t.reprintApproval)
          : null;
    originalPrintResult.call(this, tx, success);
    if (request) {
      request.status = "مستخدم";
      request.usedAt = now();
    }
  };

  const originalSell = P.sell;
  P.sell = function (pos, product, quantity, key) {
    this.operations();
    this.checkDevice(pos);
    const existing = this.s.sales.find((t) => t.key === key);
    if (existing) {
      if (
        existing.pos !== pos ||
        existing.product !== product ||
        existing.quantity !== Number(quantity)
      )
        throw Error("معرف البيع مستخدم لطلب مختلف");
      return originalSell.call(this, pos, product, quantity, key);
    }
    const cards = this.s.cards
      .filter(
        (c) =>
          c.agent === this.main(this.accountAgent(pos)) &&
          c.product === product &&
          c.status === "Available" &&
          c.expiry > M.day(),
      )
      .sort(
        (a, b) =>
          a.expiry.localeCompare(b.expiry) ||
          a.created.localeCompare(b.created) ||
          a.id.localeCompare(b.id),
      )
      .slice(0, Number(quantity));
    const credit = cards.reduce((n, c) => n + (c.credit ?? c.cost), 0);
    if (this.serviceAvailable(pos, "voucher") < credit)
      throw Error(
        "رصيد البطاقات التشغيلي غير كافٍ؛ موّل النقطة من محفظة البطاقات",
      );
    // The existing issuance validator checks retail limits. Actual consumption is posted to voucher credit once.
    const balance = this.balance;
    this.balance = () => Number.MAX_SAFE_INTEGER;
    let t;
    try {
      t = originalSell.call(this, pos, product, quantity, key);
    } finally {
      this.balance = balance;
    }
    this.s.ledger = this.s.ledger.filter((l) => l.group !== t.id);
    t.credit = credit;
    t.agentMargin = t.total - credit;
    this.serviceEntry(pos, "voucher", -credit, "إصدار بطاقات", t.id);
    return t;
  };
  P.reserve = function (pos, product, quantity, key) {
    this.operations();
    this.requirePermission("sell.create");
    this.accountCheck(pos);
    this.checkDevice(pos);
    const old = this.s.reservations.find((r) => r.key === key);
    if (old) {
      this.accountCheck(old.pos);
      return old;
    }
    const p = this.s.products.find((p) => p.id === product),
      q = Number(quantity);
    if (!p?.active || !Number.isInteger(q) || q < 1)
      throw Error("فئة أو كمية غير صالحة");
    const cards = this.s.cards
      .filter(
        (c) =>
          c.agent === this.main(this.accountAgent(pos)) &&
          c.product === product &&
          c.status === "Available" &&
          c.expiry > M.day(),
      )
      .sort(
        (a, b) =>
          a.expiry.localeCompare(b.expiry) ||
          a.created.localeCompare(b.created) ||
          a.id.localeCompare(b.id),
      )
      .slice(0, q);
    if (cards.length !== q) throw Error("المخزون المتاح غير كافٍ");
    const credit = cards.reduce((n, c) => n + (c.credit ?? c.cost), 0);
    if (this.serviceAvailable(pos) < credit)
      throw Error("الرصيد التشغيلي غير كافٍ");
    const r = {
      id: M.id("RS"),
      key,
      pos,
      product,
      quantity: q,
      credit,
      cards: cards.map((c) => c.id),
      status: "محجوز",
      time: now(),
      user: this.user,
    };
    cards.forEach((c) => {
      c.status = "Reserved";
      c.reservation = r.id;
    });
    this.s.reservations.push(r);
    this.log("حجز قبل كشف الرموز", r.id, null, { pos, product, quantity: q });
    return r;
  };
  P.cancelReservation = function (id) {
    this.operations();
    this.requirePermission("sell.create");
    const r = this.s.reservations.find((r) => r.id === id);
    if (!r) throw Error("الحجز غير موجود");
    this.accountCheck(r.pos);
    if (r.status !== "محجوز") throw Error("لا يمكن فك حجز صادر أو ملغى");
    this.s.cards
      .filter((c) => r.cards.includes(c.id) && c.status === "Reserved")
      .forEach((c) => {
        c.status = "Available";
        delete c.reservation;
      });
    r.status = "ملغى";
    this.log("فك حجز دون كشف الرموز", id, null, {});
  };
  P.issueReservation = function (id) {
    this.operations();
    this.requirePermission("sell.create");
    this.requirePermission("data.pin");
    const r = this.s.reservations.find((r) => r.id === id);
    if (!r) throw Error("الحجز غير موجود");
    this.accountCheck(r.pos);
    if (r.status === "صادر") return this.s.sales.find((t) => t.id === r.sale);
    if (r.status !== "محجوز") throw Error("الحجز غير متاح");
    const selected = this.s.cards.filter((c) => r.cards.includes(c.id));
    if (selected.some((c) => c.status !== "Reserved" || c.expiry <= M.day()))
      throw Error("الحجز غير صالح؛ ألغِه وأعد المحاولة");
    const other = this.s.cards.filter(
      (c) =>
        !r.cards.includes(c.id) &&
        c.product === r.product &&
        c.agent === this.main(this.accountAgent(r.pos)) &&
        c.status === "Available",
    );
    other.forEach((c) => (c.status = "_Held"));
    selected.forEach((c) => (c.status = "Available"));
    r.status = "قيد الإصدار";
    try {
      const t = this.sell(r.pos, r.product, r.quantity, "RES-" + r.id);
      r.status = "صادر";
      r.sale = t.id;
      return t;
    } catch (e) {
      r.status = "محجوز";
      selected.forEach((c) => (c.status = "Reserved"));
      throw e;
    } finally {
      other.forEach((c) => (c.status = "Available"));
    }
  };
  P.checkDevice = function (posID) {
    const p = this.s.pos.find((p) => p.id === posID);
    if (!p) return;
    const compare = (a, b) => {
      const x = String(a || "0")
          .split(".")
          .map(Number),
        y = String(b || "0")
          .split(".")
          .map(Number);
      for (let i = 0; i < Math.max(x.length, y.length); i++) {
        const d = (x[i] || 0) - (y[i] || 0);
        if (d) return d;
      }
      return 0;
    };
    if (compare(p.version, this.s.settings.minVersion) < 0)
      throw Error("إصدار التطبيق أقل من الحد المطلوب");
    if (
      this.s.settings.minOS &&
      compare(p.osVersion, this.s.settings.minOS) < 0
    )
      throw Error("إصدار نظام الجهاز أقل من الحد المطلوب");
    if (p.bindingRequired && !p.boundSerial)
      throw Error("يجب اعتماد ربط الجهاز");
    if (p.bindingRequired && p.boundSerial !== p.serial)
      throw Error("الجهاز لا يطابق الربط المعتمد");
    if (p.geoPolicy === "تنبيه" && p.allowedCity && p.city !== p.allowedCity)
      this.alertOnce?.(
        "الجهاز خارج المنطقة",
        p.name,
        p.agent,
        "geo-" + p.id + "-" + M.day(),
        "مرتفعة",
      );
    if (
      p.geoPolicy === "حظر" &&
      p.allowedCity &&
      p.city !== p.allowedCity &&
      (!p.geoExceptionUntil || p.geoExceptionUntil < now())
    )
      throw Error("الجهاز خارج المنطقة المسموحة");
  };
  P.configureDevice = function (posID, values) {
    this.operations();
    this.requirePermission("pos.device");
    this.requirePermission("pos.location");
    this.accountCheck(posID);
    const p = this.s.pos.find((p) => p.id === posID);
    if (!p) throw Error("النقطة غير موجودة");
    const before = copy(p);
    for (const k of [
      "bindingRequired",
      "boundSerial",
      "osVersion",
      "geoPolicy",
      "allowedCity",
      "geoExceptionUntil",
      "installationId",
      "certificateHint",
    ])
      if (k in values) p[k] = values[k];
    this.log("سياسة جهاز وموقع", posID, before, p);
  };
  const originalClaim = P.claim,
    originalSettle = P.settle;
  P.claim = function (batch, reason) {
    this.operations();
    const c = originalClaim.call(this, batch, reason);
    c.credit = this.s.cards
      .filter((x) => x.batch === batch && x.status === "Quarantined")
      .reduce((n, x) => n + (x.credit ?? x.cost), 0);
    return c;
  };
  P.settle = function (id, outcome, details = {}) {
    this.operations();
    const c = this.s.claims.find((c) => c.id === id);
    if (!c) throw Error("المطالبة غير موجودة");
    this.requirePermission("claims.settle");
    this.require(c.agent);
    if (["خسارة", "رفض"].includes(outcome))
      this.requirePermission("claims.loss");
    if (outcome === "استبدال") {
      const b = this.s.batches.find(
        (b) =>
          b.id === details.replacement &&
          b.agent === c.agent &&
          b.id !== c.batch &&
          b.status === "Loaded",
      );
      if (!b || !this.s.batchInvoices.some((i) => i.batch === b.id))
        throw Error("اختر دفعة بديلة محملة ومعتمدة؛ رصيدها يسجل عند تحميلها");
      if (this.s.claims.some((x) => x.replacement === b.id))
        throw Error("الدفعة البديلة مرتبطة بمطالبة أخرى");
    }
    if (outcome === "تعويض") positive(details.amount);
    originalSettle.call(this, id, outcome);
    if (outcome === "إعادة تفعيل" && c.credit) {
      this.serviceEntry(
        c.agent,
        "voucher",
        c.credit,
        "استعادة رصيد مطالبة",
        c.id,
      );
      this.s.cards
        .filter((x) => x.batch === c.batch)
        .forEach((x) => delete x.creditHeld);
    }
    if (outcome === "تعويض") {
      c.compensation = Number(details.amount);
      this.serviceEntry(c.agent, "cash", c.compensation, "تعويض مزود", c.id);
    }
    if (outcome === "استبدال") c.replacement = details.replacement;
    this.log("الأثر المالي للتسوية", id, null, {
      outcome,
      credit: c.credit,
      ...details,
    });
  };
  P.collect = function (account, amount, method, reference, key) {
    this.operations();
    this.requirePermission("wallets.collect");
    this.accountCheck(account);
    amount = positive(amount);
    if (!["مندوب", "QiCard", "تحويل مصرفي"].includes(method))
      throw Error("اختر طريقة التحصيل");
    reference = String(reference || "").trim();
    if (!reference || !key) throw Error("مرجع التحصيل مطلوب");
    const old = this.s.collections.find((r) => r.key === key);
    if (old) {
      if (
        old.account !== account ||
        old.amount !== amount ||
        old.method !== method ||
        old.reference !== reference
      )
        throw Error("معرف التحصيل مستخدم لبيانات مختلفة");
      return old;
    }
    const r = {
      id: M.id("COL"),
      key,
      account,
      amount,
      method,
      reference,
      time: now(),
      user: this.user,
    };
    this.s.collections.push(r);
    this.log("تحصيل ذمم", r.id, null, r);
    return r;
  };
  P.startServiceOrder = function (
    agent,
    provider,
    service,
    recipient,
    cost,
    price,
    key,
  ) {
    this.operations();
    this.requirePermission("integrations.transact");
    this.accountCheck(agent);
    cost = positive(cost);
    price = positive(price);
    if (!recipient?.trim() || !key) throw Error("رقم المستفيد مطلوب");
    if (
      !this.s.integrations.some(
        (i) =>
          i.agent === agent &&
          i.provider === provider &&
          i.active &&
          i.tested &&
          (!i.expiry || i.expiry >= M.day()),
      )
    )
      throw Error("فعّل واختبر إعداد التكامل أولًا");
    const old = this.s.serviceOrders.find((o) => o.key === key);
    if (old) {
      this.accountCheck(old.agent);
      if (
        old.agent !== agent ||
        old.provider !== provider ||
        old.service !== service ||
        old.recipient !== recipient ||
        old.cost !== cost ||
        old.price !== price
      )
        throw Error("معرف الطلب مستخدم لبيانات مختلفة");
      return old;
    }
    if (!["topup", "api:" + provider].includes(service))
      throw Error("محفظة الخدمة غير مطابقة");
    if (this.serviceAvailable(agent, service) < cost)
      throw Error("رصيد الخدمة غير كافٍ");
    const o = {
      id: M.id("API"),
      key,
      agent,
      provider,
      service,
      recipient,
      cost,
      price,
      profit: price - cost,
      status: "قيد المعالجة",
      time: now(),
      attempts: 1,
    };
    this.serviceEntry(agent, service, -cost, "حجز طلب خدمة", o.id);
    this.s.serviceOrders.unshift(o);
    this.log("طلب خدمة محلي", o.id, null, { service, cost, price });
    return o;
  };
  P.resolveServiceOrder = function (id, status) {
    this.operations();
    this.requirePermission("integrations.transact");
    const o = this.s.serviceOrders.find((o) => o.id === id);
    if (!o) throw Error("الطلب غير موجود");
    this.accountCheck(o.agent);
    if (["ناجح", "فاشل"].includes(o.status)) return o;
    if (!["ناجح", "فاشل", "غير معروف"].includes(status))
      throw Error("حالة غير صالحة");
    o.status = status;
    if (status === "فاشل")
      this.serviceEntry(o.agent, o.service, o.cost, "تحرير حجز طلب فاشل", o.id);
    this.log("نتيجة محاكاة المزود", id, null, { status });
    return o;
  };
  root.MasalOperations = { initialize, positive };
})(globalThis);
