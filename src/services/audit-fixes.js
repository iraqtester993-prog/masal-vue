(function (root) {
  "use strict";
  const M = Masal,
    P = M.Engine.prototype,
    copy = M.clone,
    now = () => new Date().toISOString(),
    round = (n) => Math.round(Number(n) * 100) / 100;
  const atomic = (e, fn) => MasalMeetingRules.atomic(e, fn);
  function init(s) {
    for (const k of ["fundingBatches", "pendingOrders", "claimLedger"])
      s[k] ??= [];
  }
  function controlling(e) {
    const u = e.actor();
    return u.staffAccount && u.staffAccount !== "@system"
      ? u.staffAccount
      : u.agent || "";
  }
  function admin(e) {
    return e.actor().role === "owner" || e.actor().staffAccount === "@system";
  }
  function serviceName(s, id) {
    return (
      {
        voucher: "البطاقات — رصيد تشغيلي",
        topup: "التعبئة المباشرة",
        cash: "النقد والتعويضات",
      }[id] ||
      s.providers.find((p) => "api:" + p.id === id)?.name ||
      id
    );
  }
  function loadCost(s, t) {
    if (Number.isFinite(t.loadCost)) return t.loadCost;
    let known = true;
    const value = (t.cards || []).reduce((n, id) => {
      const c = s.cards.find((c) => c.id === id),
        b = s.batches.find((b) => b.id === c?.batch),
        invoice = s.batchInvoices?.find((i) => i.batch === b?.id);
      const price = invoice?.loadPrice ?? b?.loadPrice;
      if (price === undefined) known = false;
      return n + Number(price || 0);
    }, 0);
    return known ? round(value) : null;
  }
  const sell = P.sell;
  P.sell = function (id, product, qty, key, retail) {
    this.requireOwnSeller(id);
    if (
      retail !== undefined &&
      retail !== null &&
      retail !== "" &&
      (!Number.isFinite(+retail) || +retail <= 0 || round(retail) !== +retail)
    )
      throw Error("أدخل سعر بيع للزبون موجبًا بمنزلتين عشريتين كحد أقصى");
    try {
      return sell.call(this, id, product, qty, key, retail);
    } catch (error) {
      if (!this.policyPrice(this.accountAgent(id), product)) {
        const targets = [
          ...new Set([
            id,
            ...this.s.agents
              .filter((a) =>
                this.descendants(a.id).includes(this.accountAgent(id)),
              )
              .map((a) => a.id),
          ]),
        ];
        for (const target of targets)
          this.alertOnce(
            "سعر غير صالح",
            "حدد سعر الفئة " +
              (this.s.products.find((p) => p.id === product)?.name || product),
            target,
            "price-" + target + "-" + product + "-" + M.day(),
          );
      }
      throw error;
    }
  };
  const reverse = P.reverseFunding;
  P.reverseFunding = function (id, reason) {
    const t = this.s.fundingTransfers.find((t) => t.id === id);
    if (!t || controlling(this) !== t.from)
      throw Error("عكس التمويل من حساب الوكيل الممول فقط");
    return reverse.call(this, id, reason);
  };

  // Canonical batch payload is checked before balances, including successful retries.
  function normalizeBatch(e, rows, service) {
    if (!Array.isArray(rows) || !rows.length)
      throw Error("أضف مستفيدًا واحدًا على الأقل");
    return rows.map((r) => ({
      from: String(r.from || ""),
      to: String(r.to || ""),
      amount: Number(r.amount),
      service: String(r.service || service || ""),
      reference: String(r.reference || "").trim(),
    }));
  }
  P.previewFundingBatch = function (rows, service) {
    this.operations();
    this.requirePermission("wallets.bulk");
    this.requirePermission("wallets.transfer");
    init(this.s);
    const clean = normalizeBatch(this, rows, service),
      seen = new Set(),
      totals = {};
    const checked = clean.map((r) => {
      let error = "";
      try {
        if (!this.canFundFrom(r.from))
          throw Error("ليس لديك صلاحية التمويل من الحساب المحدد");
        if (r.from !== clean[0].from)
          throw Error("اختر ممولًا واحدًا لكل مجموعة");
        if (
          this.accountCheck(r.from).active === false ||
          this.accountCheck(r.to).active === false
        )
          throw Error("الحساب الممول أو المستفيد موقوف");
        if (
          r.from === r.to ||
          !this.descendants(r.from).includes(this.accountAgent(r.to))
        )
          throw Error("المستفيد خارج الجهات التابعة");
        if (
          ![
            "voucher",
            "topup",
            "cash",
            ...this.s.providers
              .filter((p) => p.connection === "API")
              .map((p) => "api:" + p.id),
          ].includes(r.service)
        )
          throw Error("نوع المحفظة غير صالح");
        if (
          !Number.isFinite(r.amount) ||
          r.amount <= 0 ||
          round(r.amount) !== r.amount
        )
          throw Error("المبلغ غير صالح");
        const k = r.to + "|" + r.service;
        if (seen.has(k)) throw Error("المستفيد والمحفظة مكرران");
        seen.add(k);
        if (
          r.reference &&
          this.s.fundingTransfers.some(
            (t) =>
              t.from === r.from &&
              t.reference === r.reference &&
              t.status !== "معكوس",
          )
        )
          throw Error("مرجع الطلب مستخدم سابقًا");
      } catch (e) {
        error = e.message;
      }
      totals[r.service] =
        (totals[r.service] || 0) + (Number.isFinite(r.amount) ? r.amount : 0);
      return { ...r, error };
    });
    const balances = Object.entries(totals).map(([service, total]) => ({
      service,
      total: round(total),
      available: this.serviceAvailable(clean[0].from, service),
      remaining: round(this.serviceAvailable(clean[0].from, service) - total),
    }));
    return {
      rows: checked,
      balances,
      valid:
        checked.every((r) => !r.error) &&
        balances.every((b) => b.remaining >= 0),
    };
  };
  P.bulkFunding = function (rows, service, key) {
    this.operations();
    this.requirePermission("wallets.bulk");
    this.requirePermission("wallets.transfer");
    init(this.s);
    if (!key) throw Error("معرف المجموعة مطلوب");
    const clean = normalizeBatch(this, rows, service),
      payload = JSON.stringify(clean);
    if (clean.some((r) => !this.canFundFrom(r.from)))
      throw Error("ليس لديك صلاحية التمويل من الحساب المحدد");
    if (clean.some((r) => r.from !== clean[0].from))
      throw Error("اختر ممولًا واحدًا لكل مجموعة");
    const previous = this.s.fundingBatches.find((b) => b.key === key);
    if (previous) {
      if (previous.payload !== payload)
        throw Error("معرف المجموعة مستخدم لبيانات مختلفة");
      return copy(previous.results);
    }
    const legacy = this.s.fundingTransfers.filter((t) => t.groupKey === key);
    if (legacy.length) {
      if (
        legacy.length !== clean.length ||
        clean.some(
          (r) =>
            !legacy.some(
              (t) =>
                t.from === r.from &&
                t.to === r.to &&
                t.amount === r.amount &&
                t.service === r.service,
            ),
        )
      )
        throw Error("معرف المجموعة مستخدم لبيانات مختلفة");
      return clean.map((r) => ({
        to: r.to,
        amount: r.amount,
        service: r.service,
        status: "منفذ",
        transfer: legacy.find((t) => t.to === r.to && t.service === r.service)
          .id,
      }));
    }
    const preview = this.previewFundingBatch(clean, service);
    if (!preview.valid)
      throw Error(
        preview.rows.find((r) => r.error)?.error ||
          "الرصيد لا يكفي لكامل المجموعة؛ لم ينفذ أي تحويل",
      );
    return atomic(this, () => {
      const batch = {
        id: M.id("FUND-BATCH"),
        key,
        payload,
        from: clean[0].from,
        time: now(),
        user: this.user,
        totals: preview.balances,
        status: "منفذ",
        results: [],
      };
      batch.results = clean.map((r, i) => {
        const t = this.fund(r.from, r.to, r.amount, r.service, key + "-" + i);
        t.groupKey = key;
        t.batchId = batch.id;
        t.reference = r.reference;
        return { ...r, status: "منفذ", transfer: t.id };
      });
      this.s.fundingBatches.unshift(batch);
      this.log("اعتماد مجموعة تمويل", batch.id, null, {
        count: clean.length,
        totals: batch.totals,
      });
      return copy(batch.results);
    });
  };
  P.processFundingBatch = function (ids, amounts = {}) {
    this.requirePermission("wallets.approve");
    if (new Set(ids).size !== ids.length) throw Error("طلب مكرر");
    const rs = ids.map((id) => this.s.fundingRequests.find((r) => r.id === id));
    if (!rs.length || rs.some((r) => !r || r.status !== "بانتظار التمويل"))
      throw Error("اختر طلبات بانتظار التمويل");
    return atomic(this, () => {
      const result = this.bulkFunding(
        rs.map((r) => ({
          from: r.from,
          to: r.to,
          amount: amounts[r.id] ?? r.amount,
          service: r.service,
          reference: r.id,
        })),
        null,
        "REQUESTS:" + ids.slice().sort().join("|"),
      );
      rs.forEach((r, i) =>
        Object.assign(r, {
          status: "منفذ",
          approvedAmount: result[i].amount,
          transfer: result[i].transfer,
        }),
      );
      return result;
    });
  };

  function claimCards(s, c) {
    return s.cards.filter((x) =>
      c.cardIds
        ? c.cardIds.includes(x.id)
        : x.batch === c.batch &&
          !x.sale &&
          ["Quarantined", "Exported", "Awaiting Replacement"].includes(
            x.status,
          ),
    );
  }
  function registerClaim(e, b, reason) {
    init(e.s);
    const existing = e.s.claims.find(
      (c) => c.batch === b.id && c.status === "معلقة",
    );
    if (existing) return existing;
    const cards = e.s.cards.filter(
      (c) =>
        c.batch === b.id &&
        !c.sale &&
        ["Quarantined", "Exported"].includes(c.status),
    );
    if (!cards.length) throw Error("لا توجد بطاقات مسحوبة قابلة للمطالبة");
    const c = {
      id: M.id("CL"),
      batch: b.id,
      agent: b.agent,
      product: b.product,
      reason,
      status: "معلقة",
      quantity: cards.length,
      cardIds: cards.map((x) => x.id),
      value: round(cards.reduce((n, x) => n + x.cost, 0)),
      credit: round(cards.reduce((n, x) => n + (x.credit ?? x.cost), 0)),
      time: now(),
      soldBefore: e.s.cards.filter((x) => x.batch === b.id && x.sale).length,
    };
    e.s.claims.unshift(c);
    e.s.claimLedger.push({
      id: M.id("CLL"),
      claim: c.id,
      agent: c.agent,
      kind: "تعليق قيمة بطاقات",
      amount: c.value,
      time: now(),
    });
    e.log("فتح مطالبة مرتبطة بالدفعة", c.id, null, {
      batch: b.id,
      quantity: c.quantity,
      value: c.value,
    });
    return c;
  }
  function migrateClaims(s) {
    init(s);
    const e = new M.Engine(s, s.users.find((u) => u.role === "owner")?.id);
    for (const r of s.exportRequests || []) {
      const b = s.batches.find((b) => b.id === r.batch);
      if (!b) continue;
      let c = s.claims.find((c) => c.batch === b.id);
      if (
        !c &&
        s.cards.some(
          (x) =>
            x.batch === b.id &&
            !x.sale &&
            ["Quarantined", "Exported"].includes(x.status),
        )
      )
        c = registerClaim(e, b, r.reason || "طلب تصدير سابق");
      if (c) {
        r.claim = c.id;
        c.exportRequest ??= r.id;
      }
    }
    for (const c of s.claims) {
      const b = s.batches.find((b) => b.id === c.batch);
      if (!b) continue;
      c.soldBefore ??= s.cards.filter((x) => x.batch === b.id && x.sale).length;
      if (!s.claimLedger.some((l) => l.claim === c.id)) {
        s.claimLedger.push({
          id: M.id("CLL"),
          claim: c.id,
          agent: c.agent,
          kind: "ترحيل مطالبة سابقة",
          amount: c.value,
          time: c.time,
        });
        if (c.status !== "معلقة")
          s.claimLedger.push({
            id: M.id("CLL"),
            claim: c.id,
            agent: c.agent,
            kind: c.status,
            amount: -c.value,
            time: c.settled || c.time,
          });
      }
      if (c.status === "تعويض" || (c.status === "استبدال" && c.replacement)) {
        const status = c.status === "تعويض" ? "Compensated" : "Replaced";
        s.cards
          .filter(
            (x) =>
              x.batch === c.batch &&
              !x.sale &&
              x.status === "Awaiting Replacement",
          )
          .forEach((x) => (x.status = status));
        if (["Quarantined", "Exported"].includes(b.status)) b.status = status;
        c.remainingValue = 0;
        c.replacedQuantity ??=
          c.status === "استبدال"
            ? s.batches.find((x) => x.id === c.replacement)?.quantity || 0
            : 0;
      }
    }
  }
  const requestExport = P.requestExport;
  P.requestExport = function (batch, reason) {
    return atomic(this, () => {
      const r = requestExport.call(this, batch, reason);
      const b = this.s.batches.find((b) => b.id === batch);
      const c = registerClaim(this, b, reason);
      r.claim = c.id;
      c.exportRequest = r.id;
      return r;
    });
  };
  const claim = P.claim;
  P.claim = function (batch, reason) {
    this.requirePermission("claims.create");
    const b = this.s.batches.find((b) => b.id === batch);
    if (!b) throw Error("اختر دفعة");
    this.require(b.agent);
    if (!reason?.trim()) throw Error("سبب المطالبة مطلوب");
    return atomic(this, () => {
      if (!["Quarantined", "Exported"].includes(b.status))
        this.batchAction(batch, "quarantine");
      return registerClaim(this, b, reason);
    });
  };
  P.claimRefundAmount = function (id) {
    const c = this.s.claims.find((c) => c.id === id);
    if (!c) throw Error("المطالبة غير موجودة");
    this.require(c.agent);
    const b = this.s.batches.find((b) => b.id === c.batch),
      invoice = this.s.batchInvoices.find(
        (i) => i.batch === c.batch && !i.inventoryAdjustment,
      );
    const price = invoice?.loadPrice ?? b?.loadPrice;
    const amount =
      price !== undefined
        ? round(Number(price) * c.quantity)
        : Number(c.credit);
    if (!Number.isFinite(amount) || amount <= 0)
      throw Error("سعر الشراء الأصلي غير موثق؛ راجع الفاتورة");
    return amount;
  };
  P.previewClaimReplacement = function (id, files) {
    this.requirePermission("claims.settle");
    this.requirePermission("import.approve");
    const c = this.s.claims.find((c) => c.id === id && c.status === "معلقة");
    if (!c) throw Error("المطالبة غير متاحة");
    this.require(c.agent);
    const b = this.s.batches.find((b) => b.id === c.batch),
      product = this.s.products.find((p) => p.id === b.product);
    if (!files?.length) throw Error("ارفع ملف البطاقات البديلة");
    for (const f of files)
      if (
        f.categoryCode &&
        !this.resolveOrderProduct(product.provider, f.categoryCode).some(
          (p) => p.id === b.product,
        )
      )
        throw Error("فئة الملف لا تطابق فئة البطاقات التالفة");
    const rows = files.flatMap((f) => f.rows);
    if (rows.length !== c.quantity)
      throw Error("عدد البدائل يجب أن يساوي " + c.quantity + " بطاقة");
    const meta = {
      agent: c.agent,
      product: b.product,
      provider: product.provider,
      cost: b.cost,
      expenses: 0,
      supplier: b.supplier || "بديل تالف",
      city: b.city,
      loadPrice: this.claimRefundAmount(id) / c.quantity,
    };
    const checked = this.validateImport(meta, rows).map((r, i) => ({
      ...r,
      error: rows[i].parseError || r.error,
    }));
    return { meta, rows: copy(rows), checked };
  };
  P.replaceClaimFiles = function (id, files) {
    const p = this.previewClaimReplacement(id, files);
    if (p.checked.some((r) => r.error))
      throw Error("صحح البطاقات المرفوضة في الملف البديل");
    return this.approveReplacement(id, p.meta, p.rows);
  };
  P.settle = function (id, outcome, details = {}) {
    this.requirePermission("claims.settle");
    init(this.s);
    const c = this.s.claims.find((c) => c.id === id);
    if (!c || c.status !== "معلقة") throw Error("المطالبة غير متاحة للتسوية");
    this.require(c.agent);
    const cards = claimCards(this.s, c),
      batch = this.s.batches.find((b) => b.id === c.batch);
    if (!["استبدال", "تعويض", "رفض", "خسارة", "إعادة تفعيل"].includes(outcome))
      throw Error("اختر نتيجة التسوية");
    if (["رفض", "خسارة"].includes(outcome))
      this.requirePermission("claims.loss");
    if (
      outcome === "إعادة تفعيل" &&
      (cards.some((x) => x.status === "Exported") ||
        this.s.exports.some((x) => x.batch === c.batch))
    )
      throw Error("الرموز المصدرة لا تعاد للبيع");
    let replacement;
    if (outcome === "استبدال") {
      replacement = this.s.batches.find((b) => b.id === details.replacement);
      if (
        !replacement ||
        replacement.replacementFor !== c.id ||
        replacement.agent !== c.agent ||
        replacement.product !== batch.product ||
        replacement.quantity !== c.quantity ||
        round(replacement.replacementCredit) !== round(c.credit) ||
        this.s.claims.some(
          (x) => x.id !== id && x.replacement === replacement.id,
        )
      )
        throw Error(
          "ارفع دفعة بديلة مرتبطة بهذه المطالبة وبالفئة والكمية والقيمة نفسها",
        );
    }
    if (
      cards.length !== c.quantity ||
      cards.some(
        (x) =>
          x.sale ||
          !["Quarantined", "Exported", "Awaiting Replacement"].includes(
            x.status,
          ),
      )
    )
      throw Error("تغيرت حالة البطاقات أو سبق تعويضها");
    if (outcome === "تعويض") {
      if (this.s.batches.some((b) => b.replacementFor === c.id))
        throw Error("المطالبة مرتبطة ببدائل ولا تقبل تعويضًا ماليًا");
      details = { ...details, amount: this.claimRefundAmount(id) };
    }
    return atomic(this, () => {
      if (outcome === "إعادة تفعيل") {
        cards.forEach((x) => {
          x.status = "Available";
          delete x.creditHeld;
        });
        this.serviceEntry(
          c.agent,
          "voucher",
          c.credit,
          "استعادة رصيد مطالبة",
          c.id,
        );
        batch.status = this.s.cards.some((x) => x.batch === batch.id && x.sale)
          ? "Partially Used"
          : "Loaded";
      } else {
        const status =
          outcome === "استبدال"
            ? "Replaced"
            : outcome === "تعويض"
              ? "Compensated"
              : "Written Off";
        cards.forEach((x) => (x.status = status));
        batch.status = status;
        if (replacement) c.replacement = replacement.id;
        if (outcome === "تعويض") {
          c.compensation = Number(details.amount);
          c.compensationService = "voucher";
          c.compensationAccount = c.agent;
          c.compensationBasis = "سعر الشراء الأصلي";
          this.serviceEntry(
            c.agent,
            "voucher",
            c.compensation,
            "تعويض بطاقات تالفة",
            c.id,
          );
        }
      }
      c.status = outcome;
      c.settled = now();
      c.settledBy = this.user;
      c.remainingValue = 0;
      c.replacedQuantity = replacement?.quantity || 0;
      c.rejectedQuantity = ["رفض", "خسارة"].includes(outcome) ? c.quantity : 0;
      this.s.claimLedger.push({
        id: M.id("CLL"),
        claim: c.id,
        agent: c.agent,
        kind: outcome,
        amount: -c.value,
        time: now(),
        compensation: c.compensation || 0,
        replacement: c.replacement || "",
      });
      this.log(
        "تسوية المطالبة",
        id,
        { status: "معلقة" },
        { status: outcome, quantity: c.quantity, ...details },
      );
      return c;
    });
  };
  P.submitCashOrder = function (meta, rows) {
    this.requirePermission("import.preview");
    this.require(meta.agent);
    init(this.s);
    const checked = this.validateImport(meta, rows);
    if (!checked.length || checked.some((r) => r.error))
      throw Error("صحح بيانات الملف أولًا");
    if (!String(meta.supplier || "").trim()) throw Error("المجهز مطلوب");
    const old = this.s.pendingOrders.find((r) => r.key === meta.postingKey);
    const payload = JSON.stringify({ meta, rows });
    if (old) {
      if (old.payload !== payload)
        throw Error("معرف الطلبية مستخدم لبيانات مختلفة");
      return old;
    }
    const r = {
      id: M.id("ORDER"),
      key: meta.postingKey,
      meta: copy(meta),
      rows: copy(rows),
      payload,
      agent: meta.agent,
      quantity: rows.length,
      status: "بانتظار الاعتماد",
      user: this.user,
      time: now(),
      submitted: true,
    };
    this.s.pendingOrders.unshift(r);
    this.log("إرسال طلبية للاعتماد", r.id, null, {
      agent: r.agent,
      quantity: r.quantity,
    });
    return r;
  };
  P.reviewCashOrder = function (id, accepted, reason) {
    this.requirePermission("import.approve");
    if (!admin(this)) throw Error("اعتماد الكاش لإدارة النظام");
    const r = this.s.pendingOrders.find((r) => r.id === id);
    if (!r || r.status !== "بانتظار الاعتماد") throw Error("الطلبية غير متاحة");
    this.require(r.agent);
    if (!accepted) {
      if (!reason?.trim()) throw Error("سبب الرفض مطلوب");
      r.status = "مرفوضة";
      r.reason = reason;
      this.log("رفض طلبية", r.id, null, { reason });
      return r;
    }
    return atomic(this, () => {
      const b = this.approveCashOrder(
        { ...r.meta, cashConfirmed: true },
        r.rows,
      );
      r.status = "معتمدة";
      r.batch = b.id;
      r.reviewed = now();
      return b;
    });
  };
  P.approveReplacement = function (claimId, meta, rows) {
    this.requirePermission("claims.settle");
    this.requirePermission("import.approve");
    const c = this.s.claims.find(
      (c) => c.id === claimId && c.status === "معلقة",
    );
    if (!c) throw Error("المطالبة غير متاحة");
    this.require(c.agent);
    const old = this.s.batches.find((b) => b.replacementFor === c.id);
    if (old) throw Error("توجد دفعة بديلة لهذه المطالبة");
    const original = this.s.batches.find((b) => b.id === c.batch);
    if (
      meta.agent !== c.agent ||
      meta.product !== original.product ||
      rows.length !== c.quantity
    )
      throw Error("البديل يجب أن يطابق الوكيل والفئة والكمية المسحوبة");
    const checked = this.validateImport(meta, rows);
    if (checked.some((r) => r.error)) throw Error("صحح ملف البطاقات البديلة");
    return atomic(this, () => {
      const b = this.importBatch(
          { ...meta, postingKey: "REPLACE:" + c.id },
          rows,
        ),
        invoice = this.s.batchInvoices.find((i) => i.batch === b.id),
        current = invoice.amount;
      this.serviceEntry(
        c.agent,
        "voucher",
        round(c.credit - current),
        "مطابقة رصيد الدفعة البديلة",
        c.id,
      );
      this.s.cards
        .filter((x) => x.batch === b.id)
        .forEach((x) => (x.credit = c.credit / c.quantity));
      b.replacementFor = c.id;
      b.replacementCredit = c.credit;
      b.loadPrice = original.loadPrice ?? c.credit / c.quantity;
      Object.assign(invoice, {
        amount: 0,
        profit: 0,
        status: "بديلة — دون تحصيل",
        replacementFor: c.id,
        loadPrice: b.loadPrice,
      });
      this.settle(c.id, "استبدال", { replacement: b.id });
      return b;
    });
  };

  function install(o) {
    o.methods.claimStatusTone = function (status) {
      return ["تعويض", "Compensated", "معوضة", "إعادة تفعيل"].includes(status)
        ? "settled-green"
        : ["استبدال", "Replaced", "مستبدلة"].includes(status)
          ? "settled-blue"
          : ["خسارة", "Written Off", "مشطوبة"].includes(status)
            ? "settled-red"
            : status === "رفض"
              ? "settled-purple"
              : ["معلقة", "Quarantined", "Awaiting Replacement"].includes(
                    status,
                  )
                ? "settled-amber"
                : "settled-neutral";
    };
    const data = o.data;
    o.data = function () {
      const d = data.call(this);
      migrateClaims(d.s);
      return { ...d, replacementClaim: "" };
    };
    const receipt = o.components["meeting-receipt"];
    receipt.methods ??= {};
    receipt.methods.label = function (key) {
      const lang =
        this.product.receiptLanguage ||
        this.vm.s.settings.receiptLanguage ||
        "العربية";
      const map = {
        serial: ["سيريال المزود", "Provider serial", "ژمارەی دابینکەر"],
        internal: ["المعرف الداخلي", "Internal ID", "ناسنامەی ناوخۆیی"],
        expiry: ["الانتهاء", "Expiry", "بەرواری بەسەرچوون"],
        reference: ["المرجع", "Reference", "سەرچاوە"],
        reprint: ["إعادة طباعة", "Reprint", "چاپکردنەوە"],
      };
      return (
        map[key]?.[lang === "English" ? 1 : lang === "کوردی" ? 2 : 0] || key
      );
    };
    const go = o.methods.go;
    o.methods.go = function (page, ...args) {
      if (page !== "import") this.replacementClaim = "";
      return go.call(this, page, ...args);
    };
    const status = o.methods.status;
    o.methods.status = function (v) {
      return (
        { Replaced: "مستبدلة", Compensated: "معوضة" }[v] || status.call(this, v)
      );
    };
    // Historical records are displayed with their recorded acquisition price, never today's price.
    o.methods.saleAgentProfit = function (t) {
      const cost = loadCost(this.s, t);
      return cost === null
        ? "—"
        : this.money(round((t.credit ?? t.total) - cost));
    };
    const dashboard = o.computed.dashboardCards;
    o.computed.dashboardCards = function () {
      const cards = dashboard.call(this),
        role = this.managementRole,
        day = M.day(),
        sales = this.visibleSales.filter((t) => M.businessDay(t.time) === day);
      return cards.map((c) => {
        if (c.key !== "profit") return c;
        if (role === "owner") {
          const invoices = (this.s.batchInvoices || []).filter(
            (i) => M.businessDay(i.time) === day && i.status !== "معكوسة",
          );
          return {
            ...c,
            explanation: "",
            title: "ربح ماسال من الطلبيات اليوم",
            unit: "د.ع",
            rows: invoices.map((i) => ({
              name: i.id,
              value: i.profit,
              unit: "د.ع",
              note: i.status,
            })),
            value: round(invoices.reduce((n, i) => n + i.profit, 0)),
          };
        }
        const known = sales.every(
          (t) => role === "pos" || loadCost(this.s, t) !== null,
        );
        return {
          ...c,
          explanation: "",
          title: role === "pos" ? "ربح نقطة البيع اليوم" : "ربح الوكيل اليوم",
          unit: "د.ع",
          rows: sales.map((t) => ({
            name: t.id,
            value:
              role === "pos"
                ? t.posProfit
                : loadCost(this.s, t) === null
                  ? null
                  : round((t.credit ?? t.total) - loadCost(this.s, t)),
            unit: "د.ع",
            note: this.status(t.status),
          })),
          value: known
            ? round(
                sales.reduce(
                  (n, t) =>
                    n +
                    (role === "pos"
                      ? t.posProfit || 0
                      : (t.credit ?? t.total) - loadCost(this.s, t)),
                  0,
                ),
              )
            : null,
          note: known ? c.note : "تكلفة تحميل بعض العمليات القديمة غير موثقة",
        };
      });
    };
    const panel = o.components["operations-panel"];
    installFunding(panel);
    installClaims(panel);
    installOrders(o);
    const navigate = o.methods.openDashboardPage;
    o.methods.openDashboardPage = function () {
      if (
        this.dashboardDetail?.key === "profit" &&
        this.managementRole === "owner"
      ) {
        this.closeModal();
        this.go("wallets");
        this.$nextTick(() => {
          this.$refs.operations.tab = "invoice";
          this.$refs.operations.service = "voucher";
        });
        return;
      }
      return navigate.call(this);
    };
  }

  function installFunding(panel) {
    const data = panel.data;
    panel.data = function () {
      return {
        ...data.call(this),
        fundingPreview: null,
        fundingPreviewKey: "",
        fundingBusy: false,
        fundingError: "",
        fundingSource: "",
        fundingHistory: null,
        bulkSelected: [],
        bulkAmounts: {},
        bulkSearch: "",
      };
    };
    panel.computed.bulkFunders = function () {
      return this.agents.filter((a) => this.e.canFundFrom(a.id));
    };
    panel.computed.bulkRecipients = function () {
      return this.accounts.filter(
        (a) =>
          a.id !== this.from &&
          a.active !== false &&
          this.e.descendants(this.from).includes(this.e.accountAgent(a.id)),
      );
    };
    panel.computed.bulkVisibleRecipients = function () {
      const q = this.bulkSearch.trim().toLowerCase();
      return this.bulkRecipients.filter((a) =>
        (a.name + " " + a.id + " " + (a.city || "")).toLowerCase().includes(q),
      );
    };
    panel.watch.fundingPreview = function (value) {
      if (value)
        this.$nextTick(() => {
          const d = this.$refs.fundingDialog;
          if (d && !d.open) {
            this._fundingFocus = document.activeElement;
            d.showModal();
            d.focus();
          }
        });
      else
        this.$nextTick(
          () => this._fundingFocus?.isConnected && this._fundingFocus.focus(),
        );
    };
    panel.methods.closeFundingPreview = function () {
      if (!this.fundingBusy) this.fundingPreview = null;
    };
    panel.methods.clearBulkSelection = function () {
      this.bulkSelected = [];
      this.bulkAmounts = {};
      this.bulkSearch = "";
      this.bulkText = "";
      this.fundingPreview = null;
      this.bulkResult = [];
      this.newKey();
    };
    const oldFromWatcher = panel.watch.from;
    panel.watch.from = function (...args) {
      this.clearBulkSelection();
      if (typeof oldFromWatcher === "function")
        oldFromWatcher.apply(this, args);
    };
    panel.watch.bulkSelected = {
      deep: true,
      handler() {
        this.fundingPreview = null;
      },
    };
    panel.watch.bulkAmounts = {
      deep: true,
      handler() {
        this.fundingPreview = null;
      },
    };
    panel.watch.bulkText = function () {
      this.fundingPreview = null;
    };
    const oldServiceWatcher = panel.watch.service;
    panel.watch.service = function (...args) {
      this.fundingPreview = null;
      if (typeof oldServiceWatcher === "function")
        oldServiceWatcher.apply(this, args);
    };
    panel.methods.readBulk = async function (event) {
      try {
        this.e.requirePermission("wallets.import");
        const sheets = await MasalImportReader.read(event.target.files[0]);
        let rows = sheets.flatMap((s) => s.rows);
        if (rows[0] && !Number.isFinite(Number(rows[0][1]))) rows.shift();
        this.bulkSelected = [];
        this.bulkAmounts = {};
        this.bulkText = rows.map((r) => r.slice(0, 4).join(",")).join("\n");
        this.newKey();
        this.fundingPreview = null;
        this.vm.notify("تمت قراءة الملف");
      } catch (e) {
        this.vm.notify(e.message, true);
      }
      event.target.value = "";
    };
    panel.methods.prepareFunding = function (source) {
      this.fundingError = "";
      try {
        const rows =
          source === "requests"
            ? this.fundSelected.map((id) => {
                const r = this.s.fundingRequests.find((r) => r.id === id);
                if (!r || r.status !== "بانتظار التمويل")
                  throw Error("اختر طلبات بانتظار التمويل");
                return {
                  from: r.from,
                  to: r.to,
                  amount: this.fundAmounts[id] ?? r.amount,
                  service: r.service,
                  reference: r.id,
                };
              })
            : this.bulkSelected.length
              ? this.bulkSelected.map((to) => {
                  if (!this.bulkRecipients.some((a) => a.id === to))
                    throw Error("المستفيد لم يعد ضمن الجهات المسموح تمويلها");
                  return {
                    from: this.from,
                    to,
                    amount: Number(this.bulkAmounts[to]),
                    service: this.service,
                    reference: "",
                  };
                })
              : MasalImportReader.delimited(this.bulkText).map((r) => ({
                  from: this.from,
                  to: r[0],
                  amount: Number(r[1]),
                  service: r[2] || this.service,
                  reference: r[3] || "",
                }));
        this.fundingPreview = this.e.previewFundingBatch(rows, this.service);
        this.fundingSource = source;
        this.fundingPreviewKey = M.id("BATCH");
      } catch (e) {
        this.vm.notify(e.message, true);
      }
    };
    panel.methods.bulk = function () {
      this.prepareFunding("bulk");
    };
    panel.methods.approveRequests = function () {
      this.prepareFunding("requests");
    };
    panel.methods.confirmFunding = function () {
      if (this.fundingBusy || !this.fundingPreview?.valid) return;
      this.fundingBusy = true;
      this.fundingError = "";
      try {
        if (this.fundingSource === "requests")
          this.e.requirePermission("wallets.approve");
        const rows = this.fundingPreview.rows.map(({ error, ...r }) => r);
        this.bulkResult = atomic(this.e, () => {
          if (
            this.fundingSource === "requests" &&
            rows.some(
              (r) =>
                this.s.fundingRequests.find((q) => q.id === r.reference)
                  ?.status !== "بانتظار التمويل",
            )
          )
            throw Error("تغيرت حالة أحد الطلبات؛ أعد المعاينة");
          const out = this.e.bulkFunding(rows, null, this.fundingPreviewKey);
          if (this.fundingSource === "requests")
            rows.forEach((r, i) =>
              Object.assign(
                this.s.fundingRequests.find((q) => q.id === r.reference),
                {
                  status: "منفذ",
                  approvedAmount: out[i].amount,
                  transfer: out[i].transfer,
                },
              ),
            );
          return out;
        });
        this.fundSelected = [];
        this.fundingPreview = null;
        this.bulkText = "";
        this.bulkSelected = [];
        this.bulkAmounts = {};
        this.newKey();
        this.vm.notify("تم تنفيذ مجموعة التمويل");
      } catch (e) {
        this.fundingError = e.message;
        this.vm.notify(e.message, true);
      } finally {
        this.fundingBusy = false;
      }
    };
    panel.methods.downloadFunding = function (batch) {
      const rows =
        batch?.results || this.fundingPreview?.rows || this.bulkResult;
      download(
        "funding-results.csv",
        csv(
          rows.map((r) => ({
            المستفيد: this.name(r.to),
            المحفظة: this.serviceName(r.service),
            المبلغ: r.amount,
            "مرجع الطلب": r.reference || "",
            النتيجة: r.error ? "مرفوض" : r.status || "صالح",
            السبب: r.error || r.reason || "",
            العملية: r.transfer || "",
          })),
        ),
        "text/csv;charset=utf-8",
      );
    };
    panel.computed.fundingGroups = function () {
      return (this.s.fundingBatches || []).filter((b) =>
        this.e.allowed(b.from),
      );
    };
    const reset = panel.methods.reset;
    panel.methods.reset = function (...args) {
      this.clearBulkSelection();
      const result = reset.apply(this, args);
      if (!this.bulkFunders.some((a) => a.id === this.from))
        this.from = this.bulkFunders[0]?.id || "";
      return result;
    };
  }
  function installClaims(panel) {
    panel.computed.claimPreview = function () {
      return this.vm.visibleClaims.find((c) => c.id === this.claimPreviewId);
    };
    panel.methods.openClaimPreview = function (c) {
      this.e.require(c.agent);
      this.claimPreviewId = c.id;
      this.$nextTick(() => this.$refs.claimPreviewDialog?.showModal());
    };
    panel.methods.closeClaimPreview = function () {
      if (this.replacementBusy) return;
      this.$refs.claimPreviewDialog?.close();
      this.claimPreviewId = "";
    };
    const previousData = panel.data;
    panel.data = function () {
      return {
        ...previousData.call(this),
        claimPreviewId: "",
        replacementFiles: {},
        replacementPreviews: {},
        replacementErrors: {},
        replacementBusy: false,
      };
    };
    panel.methods.refundAmount = function (c) {
      try {
        return this.e.claimRefundAmount(c.id);
      } catch {
        return null;
      }
    };
    panel.methods.readReplacement = async function (c, event) {
      this.replacementBusy = true;
      delete this.replacementPreviews[c.id];
      delete this.replacementFiles[c.id];
      this.replacementErrors[c.id] = "";
      try {
        const file = event.target.files[0];
        if (!file) return;
        const files = await MasalOrderParser.read(file);
        this.replacementPreviews[c.id] = this.e.previewClaimReplacement(
          c.id,
          files,
        );
        this.replacementFiles[c.id] = files;
      } catch (e) {
        this.replacementErrors[c.id] = e.message;
      } finally {
        this.replacementBusy = false;
        event.target.value = "";
      }
    };
    panel.methods.confirmClaim = function (c) {
      if (this.replacementBusy) return;
      this.replacementBusy = true;
      try {
        this.act(() => {
          if (this.vm.settlements[c.id] === "استبدال")
            return this.e.replaceClaimFiles(c.id, this.replacementFiles[c.id]);
          if (this.vm.settlements[c.id] === "تعويض")
            return this.e.settle(c.id, "تعويض");
          throw Error("اختر نوع التعويض");
        });
      } finally {
        this.replacementBusy = false;
      }
    };
    panel.methods.claimDetail = function (c) {
      return this.claimDetails[c.id] || (this.claimDetails[c.id] = {});
    };
    panel.methods.startReplacement = function (c) {
      this.vm.replacementClaim = c.id;
      this.vm.go("import");
    };
  }
  function installOrders(o) {
    const form = o.components["cash-order-form"],
      data = form.data;
    form.data = function () {
      const d = data.call(this),
        c = this.$root.s.claims.find(
          (c) => c.id === this.$root.replacementClaim && c.status === "معلقة",
        );
      if (c) {
        const b = this.$root.s.batches.find((b) => b.id === c.batch);
        Object.assign(d.draft, {
          agent: c.agent,
          product: b.product,
          cost: b.cost,
          expenses: 0,
          supplier: b.supplier || "",
        });
      }
      return d;
    };
    form.computed.isAdmin = function () {
      return admin(this.vm.engine);
    };
    form.computed.replacement = function () {
      return this.vm.s.claims.find(
        (c) => c.id === this.vm.replacementClaim && c.status === "معلقة",
      );
    };
    const price = form.computed.price;
    form.computed.price = function () {
      return this.replacement
        ? this.replacement.credit / this.replacement.quantity
        : price.call(this);
    };
    const approve = form.methods.approve;
    form.methods.approve = function () {
      if (this.replacement) {
        this.busy = true;
        try {
          this.done = this.vm.engine.approveReplacement(
            this.replacement.id,
            this.snapshot,
            this.rows(),
          );
          this.vm.replacementClaim = "";
        } catch (e) {
          this.error = e.message;
        } finally {
          this.busy = false;
        }
        return;
      }
      if (this.isAdmin) return approve.call(this);
      this.busy = true;
      try {
        this.done = this.vm.engine.submitCashOrder(this.snapshot, this.rows());
        this.vm.notify("أرسلت الطلبية للإدارة");
      } catch (e) {
        this.error = e.message;
      } finally {
        this.busy = false;
      }
    };
    // The base cash-order template is rewritten once by cash-orders.js before this
    // installer runs. Normalize its final submit guard so agents can send orders
    // without the admin-only cash confirmation/approval permissions.
    o.components["pending-order-review"] = {
      data() {
        return {
          selected: "",
          reason: "",
          busy: false,
          expanded: true,
          query: "",
          status: "",
        };
      },
      computed: {
        vm() {
          return this.$root;
        },
        rows() {
          return (this.vm.s.pendingOrders || [])
            .filter((r) => this.vm.engine.allowed(r.agent))
            .slice()
            .sort((a, b) => Date.parse(b.time) - Date.parse(a.time));
        },
        filtered() {
          const q = this.query.trim().toLowerCase();
          return this.rows.filter(
            (r) =>
              (!this.status || r.status === this.status) &&
              (!q ||
                [
                  r.id,
                  this.vm.nameOf("agents", r.agent),
                  this.vm.nameOf("products", r.meta.product),
                  r.meta.supplier,
                ]
                  .join(" ")
                  .toLowerCase()
                  .includes(q)),
          );
        },
        current() {
          return this.rows.find((r) => r.id === this.selected);
        },
        admin() {
          return admin(this.vm.engine);
        },
      },
      watch: {
        "vm.currentUser"() {
          this.selected = "";
          this.reason = "";
          this.query = "";
          this.status = "";
        },
        selected() {
          this.reason = "";
        },
      },
      methods: {
        approve(ok) {
          if (this.busy) return;
          this.busy = true;
          try {
            this.vm.engine.reviewCashOrder(this.selected, ok, this.reason);
            this.selected = "";
            this.reason = "";
            this.vm.notify("تمت مراجعة الطلبية");
          } catch (e) {
            this.vm.notify(e.message, true);
          } finally {
            this.busy = false;
          }
        },
        toggle() {
          this.expanded = !this.expanded;
          if (!this.expanded) this.selected = "";
        },
      },
    };
  }
  root.MasalAuditFixes = { install, loadCost, serviceName, migrateClaims };
})(globalThis);
