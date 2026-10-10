(function (root) {
  "use strict";
  const M = root.Masal,
    P = M.Engine.prototype,
    copy = M.clone,
    now = () => new Date().toISOString();
  const baseInitialize = root.MasalOperations.initialize;
  root.MasalOperations.initialize = function (s) {
    baseInitialize(s);
    for (const k of [
      "pricePolicies",
      "exportRequests",
      "deliveryRecords",
      "authChallenges",
      "authEvents",
      "notificationRules",
      "fundingHolds",
      "importTemplates",
    ])
      s[k] ??= [];
    return s;
  };
  P.operations = function () {
    return root.MasalOperations.initialize(this.s);
  };
  P.reconcile = function (agent) {
    this.operations();
    this.requirePermission("wallets.view");
    this.require(agent);
    const main = this.main(agent),
      accounts = [
        ...this.descendants(main),
        ...this.s.pos
          .filter((p) => this.descendants(main).includes(p.agent))
          .map((p) => p.id),
      ],
      cards = this.s.cards.filter(
        (c) => c.agent === main && ["Available", "Reserved"].includes(c.status),
      );
    const stock = cards.reduce((n, c) => n + (c.credit ?? c.cost), 0),
      distributed = accounts
        .filter((a) => a !== main)
        .reduce((n, a) => n + this.serviceBalance(a), 0),
      held = this.s.reservations
        .filter((r) => accounts.includes(r.pos) && r.status === "محجوز")
        .reduce((n, r) => n + r.credit, 0),
      mainBalance = this.serviceBalance(main);
    return {
      main,
      stock,
      mainBalance,
      distributed,
      held,
      total: mainBalance + distributed,
      difference: stock - mainBalance - distributed,
    };
  };
  P.savePricePolicy = function (draft) {
    this.operations();
    this.requirePermission("prices.policy");
    this.require(draft.agent);
    const product = this.s.products.find((p) => p.id === draft.product);
    if (!product) throw Error("الفئة غير موجودة");
    const price = Number(draft.price),
      rate = Number(draft.rate || 1);
    if (
      !Number.isFinite(price) ||
      !Number.isFinite(rate) ||
      rate <= 0 ||
      price <= 0 ||
      price * rate < product.min
    )
      throw Error("سعر البيع النهائي دون الحد الأدنى المسموح");
    if (!/^\d{4}-\d{2}-\d{2}$/.test(draft.effective || ""))
      throw Error("تاريخ النفاذ مطلوب");
    const policy = {
      id: M.id("POL"),
      agent: draft.agent,
      product: draft.product,
      city: draft.city || "الكل",
      effective: draft.effective,
      price,
      rate,
      finalPrice: Math.round(price * rate * 100) / 100,
      time: now(),
      user: this.user,
    };
    this.s.pricePolicies.push(policy);
    this.log("سياسة سعر منطقة وتاريخ", policy.id, null, policy);
    return policy;
  };
  P.policyPrice = function (agent, product, city) {
    this.operations();
    const policies = this.s.pricePolicies
      .filter(
        (p) =>
          p.agent === agent &&
          p.product === product &&
          p.effective <= M.day() &&
          (p.city === "الكل" || p.city === city),
      )
      .sort(
        (a, b) =>
          b.effective.localeCompare(a.effective) ||
          (b.city !== "الكل") - (a.city !== "الكل") ||
          b.time.localeCompare(a.time),
      );
    return (
      policies[0]?.finalPrice ??
      this.s.prices.find(
        (p) =>
          p.agent === agent && p.product === product && p.effective <= M.day(),
      )?.price ??
      0
    );
  };
  P.saveLimits = function (account, product, values) {
    this.operations();
    this.requirePermission("products.limits");
    this.accountCheck(account);
    const item =
      this.s.agents.find((a) => a.id === account) ||
      this.s.pos.find((p) => p.id === account);
    const v = {};
    for (const k of ["limit", "dailyQty", "dailyAmount"]) {
      v[k] = Number(values[k]);
      if (!Number.isFinite(v[k]) || v[k] <= 0)
        throw Error("حدود البيع يجب أن تكون موجبة");
    }
    item.saleLimits ??= {};
    item.saleLimits[product] = v;
    this.log("حدود بيع مخصصة", account, null, { product, ...v });
  };
  const baseSell = P.sell;
  P.sell = function (posID, product, qty, key, retailPrice) {
    this.operations();
    const pos = this.saleAccount(posID),
      p = this.s.products.find((p) => p.id === product);
    if (!pos || !p) return baseSell.call(this, posID, product, qty, key);
    const policyPrice = this.policyPrice(pos.agent, product, pos.city);
    if (!policyPrice) {
      this.alertOnce(
        "سعر غير صالح",
        "الفئة " + p.name + " غير قابلة للبيع بسعر صفر",
        pos.agent,
        "price-" + pos.agent + "-" + product,
      );
      throw Error("سعر الفئة غير صالح؛ تم تسجيل تنبيه");
    }
    const limits = this.s.agents.find((a) => a.id === pos.agent)?.saleLimits?.[
        product
      ],
      device = pos.saleLimits?.[product],
      old = {
        limit: p.limit,
        dailyQty: p.dailyQty,
        dailyAmount: p.dailyAmount,
      };
    for (const k of Object.keys(old))
      p[k] = Math.min(
        old[k] === null || old[k] === undefined ? Infinity : old[k],
        limits?.[k] ?? Infinity,
        device?.[k] ?? Infinity,
      );
    const price = this.s.prices.find(
        (x) => x.agent === pos.agent && x.product === product,
      ),
      before = price ? copy(price) : null;
    if (price) {
      price.price = policyPrice;
      price.effective = M.day();
    } else
      this.s.prices.push({
        id: M.id("TMP"),
        agent: pos.agent,
        product,
        price: policyPrice,
        effective: M.day(),
      });
    try {
      const t = baseSell.call(this, posID, product, qty, key);
      if (t.retailPrice === undefined) {
        t.retailPrice = Number(retailPrice) > 0 ? Number(retailPrice) : t.price;
        t.retailTotal = t.retailPrice * t.quantity;
        t.posProfit = t.retailTotal - t.total;
      }
      return t;
    } finally {
      Object.assign(p, old);
      if (price) Object.assign(price, before);
      else
        this.s.prices = this.s.prices.filter((x) => x.id.indexOf("TMP-") !== 0);
    }
  };
  P.alertOnce = function (title, body, target, key, severity = "متوسطة") {
    this.operations();
    if (this.s.notifications.some((n) => n.eventKey === key)) return;
    this.s.notifications.unshift({
      id: M.id("NT"),
      title,
      body,
      target,
      eventKey: key,
      severity,
      channel: "داخل النظام",
      time: now(),
      user: "SYSTEM",
    });
  };
  P.scanAlerts = function () {
    this.operations();
    this.requirePermission("notifications.send");
    for (const a of this.s.agents.filter(
      (a) => !a.parent && this.allowed(a.id),
    )) {
      for (const p of this.s.products) {
        const cards = this.s.cards.filter(
            (c) =>
              c.agent === a.id &&
              c.product === p.id &&
              c.status === "Available",
          ),
          threshold = Number(this.s.serviceSettings.stockThreshold ?? 10);
        if (cards.length <= threshold)
          this.alertOnce(
            "انخفاض المخزون",
            p.name + " • المتبقي " + cards.length,
            a.id,
            "stock-" + a.id + "-" + p.id + "-" + M.day(),
          );
        const exp = cards.filter(
          (c) =>
            Date.parse(c.expiry) - Date.now() <
            Number(this.s.settings.expiryDays) * 86400000,
        );
        if (exp.length)
          this.alertOnce(
            "بطاقات قريبة الانتهاء",
            p.name + " • " + exp.length + " بطاقة",
            a.id,
            "expiry-" + a.id + "-" + p.id + "-" + M.day(),
            "مرتفعة",
          );
      }
    }
    for (const t of this.s.sales.filter(
      (t) => t.status === "Print Failed" && this.allowed(t.agent),
    ))
      this.alertOnce(
        "فشل طباعة",
        "العملية " + t.id,
        t.pos,
        "print-" + t.id,
        "مرتفعة",
      );
    this.log("فحص التنبيهات المحلية", "notifications", null, {});
  };
  P.requestExport = function (batch, reason) {
    this.operations();
    this.requirePermission("exports.request");
    const b = this.s.batches.find((b) => b.id === batch);
    if (!b) throw Error("اختر دفعة");
    this.require(b.agent);
    if (!reason?.trim()) throw Error("سبب السحب مطلوب");
    const existing = this.s.exportRequests.find(
      (r) =>
        r.batch === batch && ["بانتظار الاعتماد", "معتمد"].includes(r.status),
    );
    if (existing) return existing;
    this.batchAction(batch, "quarantine");
    const r = {
      id: M.id("ER"),
      batch,
      agent: b.agent,
      reason,
      user: this.user,
      time: now(),
      status: "بانتظار الاعتماد",
    };
    this.s.exportRequests.push(r);
    this.log("طلب سحب وتصدير", r.id, null, r);
    return r;
  };
  P.approveExport = function (id, hours = 24) {
    this.operations();
    this.requirePermission("exports.approve");
    const r = this.s.exportRequests.find((r) => r.id === id);
    if (!r || r.status !== "بانتظار الاعتماد") throw Error("طلب غير متاح");
    this.require(r.agent);
    if (r.user === this.user && this.actor().role !== "owner")
      throw Error("يعتمد التصدير مستخدم آخر");
    hours = Number(hours);
    if (!Number.isFinite(hours) || hours <= 0 || hours > 72)
      throw Error("صلاحية التنزيل من ساعة إلى 72 ساعة");
    r.status = "معتمد";
    r.until = new Date(Date.now() + hours * 3600000).toISOString();
    r.approver = this.user;
    this.log("اعتماد تصدير مؤقت", id, null, { until: r.until });
  };
  P.rejectExport = function (id, reason) {
    this.operations();
    this.requirePermission("exports.approve");
    const r = this.s.exportRequests.find((r) => r.id === id);
    if (!r || r.status !== "بانتظار الاعتماد") throw Error("طلب غير متاح");
    this.require(r.agent);
    if (r.user === this.user && this.actor().role !== "owner")
      throw Error("يعالج الطلب مستخدم آخر");
    if (!reason?.trim()) throw Error("سبب رفض طلب التصدير مطلوب");
    r.status = "مرفوض";
    r.rejectReason = reason.trim();
    r.rejectedBy = this.user;
    r.rejectedAt = now();
    this.log("رفض طلب تصدير", id, null, { reason: r.rejectReason });
    return r;
  };
  P.markDelivered = function (tx, channel, reference) {
    this.operations();
    this.requirePermission("sell.deliver");
    this.requirePermission("data.pin");
    const t = this.s.sales.find((t) => t.id === tx);
    if (!t) throw Error("العملية غير موجودة");
    this.requireOwnSeller(t.pos);
    if (!["ملف مشفر", "استلام مباشر"].includes(channel) || !reference?.trim())
      throw Error("قناة ومرجع التسليم مطلوبان");
    if (t.status === "Delivered") return t;
    t.deliveryChannel = channel;
    t.deliveryReference = reference;
    t.status = "Delivered";
    this.s.cards
      .filter((c) => t.cards.includes(c.id))
      .forEach((c) => (c.status = "Delivered"));
    this.s.deliveryRecords.push({
      id: M.id("DEL"),
      tx,
      channel,
      reference,
      time: now(),
      user: this.user,
    });
    this.log("تسليم البطاقات", tx, null, { channel, reference });
    return t;
  };
  const originalValidate = P.validateImport;
  P.validateImport = function (meta, rows) {
    const checked = originalValidate.call(this, meta, rows),
      p = this.s.products.find((p) => p.id === meta.product);
    for (let i = 0; i < checked.length; i++) {
      const r = checked[i];
      if (p?.extraFields?.length) r.extra = {};
      for (const f of p?.extraFields || []) {
        r.extra[f.key] = String(rows[i][f.key] ?? "").trim();
        if (f.required && !r.extra[f.key] && !r.error)
          r.error = "حقل مطلوب: " + f.label;
      }
    }
    return checked;
  };
  root.MasalWorkflow = {};
  P.reserveFunding = function (id, amount, exception) {
    this.operations();
    this.requirePermission("wallets.transfer");
    const r = this.s.fundingRequests.find((r) => r.id === id);
    if (!r) throw Error("طلب غير موجود");
    this.accountCheck(r.from);
    this.accountCheck(r.to);
    const previous = this.s.fundingHolds.find(
      (h) => h.request === id && h.status !== "ملغى",
    );
    if (previous) return previous;
    amount = root.MasalOperations.positive(amount);
    const u = this.actor(),
      own = u.agent === r.from && ["main", "sub", "employee"].includes(u.role),
      ex = this.s.fundingExceptions.find(
        (x) =>
          x.id === exception &&
          x.user === this.user &&
          x.from === r.from &&
          !x.used,
      );
    if (!own && !ex) throw Error("التمويل يتطلب موظف الوكيل أو استثناء موثقًا");
    if (this.serviceAvailable(r.from, r.service) < amount)
      throw Error("الرصيد المتاح غير كافٍ للحجز");
    const hold = {
      id: M.id("HOLD"),
      request: id,
      from: r.from,
      to: r.to,
      service: r.service,
      amount,
      exception: ex?.id || "",
      user: this.user,
      status: "محجوز",
      time: now(),
    };
    this.s.fundingHolds.push(hold);
    r.status = "معتمد ومحجوز";
    r.approvedAmount = amount;
    this.log("حجز رصيد طلب تمويل", hold.id, null, {
      from: hold.from,
      to: hold.to,
      amount,
    });
    return hold;
  };
  P.completeFunding = function (id) {
    this.operations();
    this.requirePermission("wallets.transfer");
    const hold = this.s.fundingHolds.find((h) => h.id === id);
    if (!hold) throw Error("الحجز غير موجود");
    this.accountCheck(hold.from);
    if (hold.status === "منفذ")
      return this.s.fundingTransfers.find((t) => t.id === hold.transfer);
    if (hold.status !== "محجوز") throw Error("الحجز ملغى");
    hold.status = "قيد التنفيذ";
    try {
      const t = this.fund(
        hold.from,
        hold.to,
        hold.amount,
        hold.service,
        "HOLD-" + hold.id,
        hold.exception,
      );
      hold.status = "منفذ";
      hold.transfer = t.id;
      const r = this.s.fundingRequests.find((r) => r.id === hold.request);
      r.status = "منفذ";
      r.transfer = t.id;
      return t;
    } catch (e) {
      hold.status = "محجوز";
      throw e;
    }
  };
  P.cancelFundingHold = function (id, reason) {
    this.operations();
    this.requirePermission("wallets.reverse");
    const hold = this.s.fundingHolds.find((h) => h.id === id);
    if (!hold || hold.status !== "محجوز" || !reason?.trim())
      throw Error("حجز معلق وسبب إلغاء مطلوبان");
    this.accountCheck(hold.from);
    hold.status = "ملغى";
    this.s.fundingRequests.find((r) => r.id === hold.request).status =
      "بانتظار التمويل";
    this.log("تحرير حجز تمويل", id, null, { reason });
  };
})(globalThis);
