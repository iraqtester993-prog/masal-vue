(function (root) {
  "use strict";
  const M = root.Masal,
    P = M.Engine.prototype,
    copy = M.clone;
  const money = (n) => Math.round(Number(n) * 100) / 100;
  P.productAvailable = function (agent, product, city) {
    const p = this.s.products.find((p) => p.id === product);
    return (
      !!p?.active &&
      !!this.s.providers.find((v) => v.id === p.provider)?.active &&
      (!p.allowedAgents?.length ||
        p.allowedAgents.some((a) => this.descendants(a).includes(agent))) &&
      (!p.allowedCities?.length || p.allowedCities.includes(city))
    );
  };
  // A failed financial operation must not leave a partial valuation or ledger entry.
  function atomic(e, fn) {
    const before = copy(e.s);
    try {
      return fn();
    } catch (error) {
      for (const key of Object.keys(e.s)) if (!(key in before)) delete e.s[key];
      Object.assign(e.s, before);
      throw error;
    }
  }
  const basePolicy = P.policyPrice;
  P.policyPrice = function (agent, product) {
    const main = this.main(agent),
      a = this.s.agents.find((a) => a.id === main);
    return basePolicy.call(this, main, product, a?.city);
  };
  P.alignStock = function (main, product) {
    this.operations();
    const cards = this.s.cards.filter(
        (c) =>
          c.agent === main &&
          (!product || c.product === product) &&
          c.status === "Available" &&
          !c.creditHeld,
      ),
      changes = [];
    for (const c of cards) {
      const price = this.policyPrice(main, c.product);
      if (!price) continue;
      const delta = money(price - Number(c.credit ?? c.cost));
      if (delta) changes.push({ c, price, delta });
    }
    const delta = money(changes.reduce((n, x) => n + x.delta, 0));
    if (!changes.length) return;
    if (money(this.serviceAvailable(main) + delta) < 0)
      throw Error("استرجع الرصيد الموزع قبل تخفيض سعر المخزون");
    changes.forEach((x) => (x.c.credit = x.price));
    if (delta)
      this.serviceEntry(
        main,
        "voucher",
        delta,
        "تحديث قيمة المخزون بسعر البيع",
        M.id("VAL"),
      );
    this.log("تحديث قيمة المخزون", main, null, {
      product: product || "",
      count: changes.length,
      amount: delta,
    });
  };
  const imported = P.importBatch;
  P.importBatch = function (meta, rows) {
    this.requirePermission("import.approve");
    this.require(meta.agent);
    this.operations();
    const existing =
      meta.postingKey &&
      this.s.batchInvoices.find((i) => i.key === meta.postingKey);
    if (existing) {
      const batch = this.s.batches.find((b) => b.id === existing.batch);
      if (batch.agent !== meta.agent || batch.product !== meta.product)
        throw Error("معرف الطلبية مستخدم لبيانات مختلفة");
      return batch;
    }
    return atomic(this, () => {
      const price = this.policyPrice(meta.agent, meta.product);
      if (!(price > 0)) throw Error("حدد سعر بيع الفئة للوكيل أولًا");
      this.alignStock(meta.agent, meta.product);
      return imported.call(
        this,
        { ...meta, loadPrice: price, contract: "إعادة بيع", commission: 0 },
        rows,
      );
    });
  };
  const sell = P.sell;
  P.sell = function (pos, product, qty, key, retail) {
    this.requirePermission("sell.create");
    this.accountCheck(pos);
    return atomic(this, () => {
      const exists = this.s.sales.some((t) => t.key === key);
      if (!exists) this.alignStock(this.main(this.accountAgent(pos)), product);
      const t = sell.call(this, pos, product, qty, key, retail);
      if (!exists) {
        t.loadCost = money(
          t.cards.reduce((n, id) => {
            const c = this.s.cards.find((c) => c.id === id),
              b = this.s.batches.find((b) => b.id === c?.batch);
            return n + Number(b?.loadPrice ?? c?.credit ?? c?.cost ?? 0);
          }, 0),
        );
        t.agentMargin = money(t.credit - t.loadCost);
        t.pricingVersion = 2;
      }
      return t;
    });
  };
  const fund = P.fund;
  P.fund = function (from, to, amount, service, key, exception) {
    this.requirePermission("wallets.transfer");
    this.accountCheck(from);
    return atomic(this, () => {
      if (service === "voucher") this.alignStock(this.main(from));
      return fund.call(this, from, to, amount, service, key, exception);
    });
  };
  const reserve = P.reserve;
  P.reserve = function (pos, product, qty, key) {
    this.requirePermission("sell.create");
    const point = this.accountCheck(pos);
    if (
      !this.productAvailable(point.agent, product, point.city) ||
      !this.policyPrice(point.agent, product)
    )
      throw Error("الفئة غير متاحة لهذا الحساب");
    return atomic(this, () => {
      this.alignStock(this.main(this.accountAgent(pos)), product);
      return reserve.call(this, pos, product, qty, key);
    });
  };
  // A changed price requires a new reservation instead of silently changing an existing quote.
  const issue = P.issueReservation;
  P.issueReservation = function (id) {
    const r = this.s.reservations.find((r) => r.id === id);
    if (!r || r.status === "صادر") return issue.call(this, id);
    this.requirePermission("sell.create");
    this.accountCheck(r.pos);
    if (
      money(
        this.policyPrice(this.accountAgent(r.pos), r.product) * r.quantity,
      ) !== money(r.credit)
    )
      throw Error("تغير السعر؛ ألغِ الحجز وأعده بالسعر الجديد");
    return issue.call(this, id);
  };
  const savePrice = P.savePricePolicy;
  P.savePricePolicy = function (draft) {
    this.requirePermission("prices.policy");
    if (this.main(draft.agent) !== draft.agent)
      throw Error("التسعير للوكيل الرئيسي فقط");
    return atomic(this, () => {
      const a = this.s.agents.find((a) => a.id === draft.agent);
      const r = savePrice.call(this, {
        ...draft,
        rate: 1,
        city: a.city || "الكل",
      });
      this.alignStock(draft.agent, draft.product);
      return r;
    });
  };
  for (const key of ["proposePrices"]) {
    const base = P[key];
    P[key] = function (changes) {
      if (changes.some((c) => this.main(c.agent) !== c.agent))
        throw Error("التسعير للوكيل الرئيسي فقط");
      return atomic(this, () => base.call(this, changes));
    };
  }
  for (const key of ["approvePrices", "reversePrices"]) {
    const base = P[key];
    P[key] = function (id) {
      return atomic(this, () => {
        const r = this.s.priceRequests.find((r) => r.id === id);
        if (r?.changes.some((c) => this.main(c.agent) !== c.agent))
          throw Error("أعد الطلب بأسعار الوكيل الرئيسي");
        const result = base.call(this, id);
        for (const c of r.changes) {
          this.s.pricePolicies = this.s.pricePolicies.filter(
            (p) =>
              !(
                p.agent === c.agent &&
                p.product === c.product &&
                p.effective <= M.day()
              ),
          );
          this.alignStock(c.agent, c.product);
        }
        return result;
      });
    };
  }
  function alerts(s) {
    const today = M.day(),
      now = Date.parse(today + "T00:00:00Z"),
      days = Number(s.settings.expiryDays ?? 30);
    if (!Number.isFinite(days) || days < 0) return;
    for (const b of s.batches) {
      const cards = s.cards.filter(
          (c) => c.batch === b.id && c.status === "Available",
        ),
        near = cards.filter((c) => {
          const left = (Date.parse(c.expiry + "T00:00:00Z") - now) / 86400000;
          return left >= 0 && left <= days;
        });
      if (!near.length) continue;
      const product = s.products.find((p) => p.id === b.product),
        key = "batch-expiry:" + b.id + ":" + today,
        expiry = near.map((c) => c.expiry).sort()[0];
      if (s.notifications.some((n) => n.eventKey === key)) continue;
      const users = s.users
        .filter(
          (u) =>
            u.active &&
            ((u.role === "main" && u.agent === b.agent) ||
              (u.role === "employee" && u.staffAccount === b.agent)) &&
            root.MasalAccess.can(u, "notifications.view", s),
        )
        .map((u) => u.id);
      s.notifications.unshift({
        id: M.id("NT"),
        user: "SYSTEM",
        time: new Date().toISOString(),
        title: "قرب انتهاء البطاقات",
        body:
          (product?.name || "") +
          " · الطلبية " +
          b.id +
          " · " +
          near.length +
          " بطاقة · " +
          expiry,
        target: b.agent,
        recipientUsers: users,
        readBy: [],
        eventKey: key,
        batch: b.id,
        product: b.product,
        expiry,
        audience: "expiry",
      });
    }
  }
  P.expiryAlerts = function () {
    alerts(this.s);
  };
  P.saveCompany = function (draft) {
    this.requirePermission("company.edit");
    if (!(
      this.actor().role === "owner" ||
      (this.actor().role === "employee" &&
        this.actor().staffAccount === "@system")
    ))
      throw Error("التعديل لإدارة النظام فقط");
    const text = (k) => String(draft[k] || "").trim();
    const next = {
      name: text("name"),
      about: text("about"),
      phone: text("phone"),
      email: text("email"),
      address: text("address"),
      website: text("website"),
      slides: (draft.slides || []).map((x) => ({
        image: root.MasalFeatureUpdates.attachment(x.image),
        caption: String(x.caption || "").trim(),
      })),
    };
    if (!next.name) throw Error("اسم الشركة مطلوب");
    if (next.website && !/^https:\/\//i.test(next.website))
      throw Error("رابط الموقع يجب أن يبدأ بـ https://");
    this.s.companyProfile = next;
    this.log("تعديل صفحة الشركة", "company", null, {
      name: next.name,
      slides: next.slides.length,
    });
  };
  // Receipt layouts contain only known blocks; card codes can never be removed.
  const blocks = ["company", "category", "image", "codes", "amount", "footer"];
  P.saveCardDesign = function (type, id, draft) {
    this.requirePermission("branding.receipt");
    const list = type === "provider" ? this.s.providers : this.s.products,
      item = list.find((x) => x.id === id);
    if (!item) throw Error("اختر الشركة أو الفئة");
    this.requirePermission(
      type === "provider" ? "providers.edit" : "products.edit",
    );
    if (
      !/^#[0-9a-f]{6}$/i.test(draft.color) ||
      ![58, 80].includes(Number(draft.width))
    )
      throw Error("تصميم غير صالح");
    if (
      !Array.isArray(draft.order) ||
      draft.order.length !== blocks.length ||
      new Set(draft.order).size !== blocks.length ||
      draft.order.some((x) => !blocks.includes(x))
    )
      throw Error("ترتيب غير صالح");
    item.cardDesign = {
      color: draft.color,
      width: Number(draft.width),
      header: String(draft.header || ""),
      footer: String(draft.footer || ""),
      image: root.MasalFeatureUpdates.attachment(draft.image),
      order: [...draft.order],
    };
    this.log("تصميم البطاقة", id, null, {
      type,
      order: draft.order,
      color: draft.color,
    });
  };
  function route(e, t) {
    if (t.routeStack?.length) return t.routeStack;
    const stack = [t.origin],
      seen = new Set(stack);
    let id = t.origin;
    while (id && id !== t.recipient) {
      id = e.supportParent(id);
      if (!id || seen.has(id)) break;
      stack.push(id);
      seen.add(id);
    }
    t.routeStack = stack;
    return stack;
  }
  P.supportActivePair = function (t) {
    const stack = route(this, t);
    return stack.slice(-2);
  };
  P.supportReplyAllowed = function (t) {
    return (
      t.status !== "مغلقة" &&
      this.supportVisible(t) &&
      this.supportActivePair(t).includes(this.supportIdentity())
    );
  };
  P.visibleSupportReplies = function (t) {
    const own = this.supportIdentity();
    return t.replies.filter((r) => !r.audience || r.audience.includes(own));
  };
  P.replySupport = function (id, body) {
    const t = this.supportCheck(id, "support.reply"),
      own = this.supportIdentity(),
      stack = route(this, t);
    if (!this.supportReplyAllowed(t))
      throw Error("الرسالة لدى المستوى الأعلى؛ انتظر الرد");
    body = String(body || "").trim();
    if (!body) throw Error("اكتب الرد");
    const audience = this.supportActivePair(t);
    t.replies.push({
      body,
      user: this.user,
      time: new Date().toISOString(),
      audience,
    });
    if (own === t.recipient && stack.length > 2) {
      const from = stack.pop();
      t.recipient = stack.at(-1);
      t.status = "تم الرد";
      t.history.push({
        from,
        to: t.recipient,
        status: "إعادة للمسؤول",
        user: this.user,
        time: new Date().toISOString(),
      });
    }
    this.supportNotice(
      t,
      "رد جديد على رسالة الدعم",
      audience.filter((x) => x !== own),
    );
    this.log("رد على رسالة دعم", id, null, {
      from: own,
      to: audience.filter((x) => x !== own),
    });
  };
  P.changeSupport = function (id, status) {
    const t = this.supportCheck(
      id,
      status === "مغلقة" ? "support.close" : "support.escalate",
    );
    if (!this.supportCanManage(t)) throw Error("الإجراء للمستلم الحالي فقط");
    const stack = route(this, t),
      old = t.recipient;
    if (status === "مصعّدة") {
      const parent = this.supportParent(old);
      if (!parent) throw Error("وصلت الرسالة لإدارة النظام");
      stack.push(parent);
      t.recipient = parent;
      if (!t.participants.includes(parent)) t.participants.push(parent);
    } else if (status === "مغلقة") {
      if (stack.length > 2)
        throw Error("أرسل الرد للمسؤول المباشر قبل إغلاق الرسالة");
    } else throw Error("حالة غير صالحة");
    t.status = status;
    t.history.push({
      from: old,
      to: t.recipient,
      status,
      user: this.user,
      time: new Date().toISOString(),
    });
    this.supportNotice(
      t,
      status === "مغلقة" ? "تم إغلاق رسالة الدعم" : "رسالة دعم مصعّدة",
      status === "مغلقة" ? stack : [t.recipient],
    );
    this.log(
      "تحديث مسار رسالة دعم",
      id,
      { recipient: old },
      { recipient: t.recipient, status },
    );
  };
  P.setOperationStops = function (type, id, values) {
    this.requirePermission("security.policies");
    const list = type === "pos" ? this.s.pos : this.s.agents,
      item = list.find((x) => x.id === id);
    if (!item) throw Error("اختر الحساب");
    this.require(type === "pos" ? item.agent : id);
    const next = {};
    for (const k of ["login", "sales", "printing", "import"])
      next[k] = !!values[k];
    const old = copy(item.operationStops || {});
    item.operationStops = next;
    this.log("تغيير تشغيل حساب", id, old, next);
  };
  P.checkOperation = function (account, key) {
    const pos = this.s.pos.find((p) => p.id === account);
    let node = pos || this.s.agents.find((a) => a.id === account);
    const seen = new Set();
    while (node && !seen.has(node.id)) {
      seen.add(node.id);
      if (node.operationStops?.[key])
        throw Error(
          ({
            login: "الدخول",
            sales: "البيع",
            printing: "الطباعة",
            import: "رفع الطلبيات",
          }[key] || key) + " موقوف لهذه الجهة",
        );
      node = this.s.agents.find((a) => a.id === (node.agent || node.parent));
    }
  };
  const requireAccount = P.require;
  P.require = function (...args) {
    requireAccount.apply(this, args);
    const u = this.actor();
    if (u.role !== "owner" && u.staffAccount !== "@system")
      this.checkOperation(u.pos || u.agent, "login");
  };
  for (const [method, key, index] of [
    ["sell", "sales", 0],
    ["reserve", "sales", 0],
    ["importBatch", "import", 0],
  ]) {
    const base = P[method];
    P[method] = function (...args) {
      this.checkOperation(
        method === "importBatch" ? args[0].agent : args[index],
        key,
      );
      return base.apply(this, args);
    };
  }
  const print = P.assertPrintReady;
  P.assertPrintReady = function (id) {
    const t = this.s.sales.find((t) => t.id === id);
    if (t) this.checkOperation(t.pos, "printing");
    return print.call(this, id);
  };
  const saleBeforePrint = P.sell;
  P.sell = function (...args) {
    this.checkOperation(args[0], "printing");
    return saleBeforePrint.apply(this, args);
  };
  root.MasalMeetingRules = { atomic, alerts, blocks };
})(globalThis);
