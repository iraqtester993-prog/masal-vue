(function (root) {
  "use strict";
  const P = Masal.Engine.prototype;
  function validate(d = {}) {
    const n = Number(d.dailyCards ?? 0),
      mode = d.dailyMode ?? "account";
    if (
      d.dailyCards === "" ||
      !Number.isSafeInteger(n) ||
      n < 0 ||
      n > 1000000 ||
      !["account", "network"].includes(mode)
    )
      throw Error("أدخل حدًا يوميًا صحيحًا بين 0 و1000000 وحدد طريقة الاحتساب");
    const dailyProductMode = d.dailyProductMode ?? "all",
      dailyProducts =
        dailyProductMode === "selected"
          ? [...new Set(d.dailyProducts || [])]
          : [];
    if (
      !["all", "selected"].includes(dailyProductMode) ||
      (dailyProductMode === "selected" && !dailyProducts.length)
    )
      throw Error("اختر فئة واحدة على الأقل");
    return { dailyCards: n, dailyMode: mode, dailyProductMode, dailyProducts };
  }
  function printed(t) {
    return (
      t.firstPrintedAt ||
      (t.attempts || []).find((a) =>
        ["Printed", "Reprinted"].includes(a.status),
      )?.time ||
      (["Printed", "Reprinted"].includes(t.status)
        ? t.printStartedAt || t.time
        : null)
    );
  }
  const policy = P.printPolicy;
  P.printPolicy = function (id, product) {
    return {
      dailyCards: 0,
      dailyMode: "account",
      dailyProductMode: "all",
      dailyProducts: [],
      ...policy.call(this, id, product),
    };
  };
  P.dailyPrintContext = function (id, product) {
    const account = this.s.pos.find((x) => x.id === id)?.agent || id;
    let network = this.main(account),
      best = -1,
      selected = null;
    for (const r of this.s.printPolicyRules || []) {
      if (
        !r.active ||
        (r.policy.dailyProductMode === "selected" &&
          !r.policy.dailyProducts?.includes(product))
      )
        continue;
      for (const t of r.targets) {
        let rank = -1;
        if (t.kind === "pos" && t.id === id) rank = 10000;
        else if (t.kind === "agent" && t.id === id) rank = 9000;
        else if (t.kind === "tree") {
          let a = account,
            d = 0;
          const seen = new Set();
          while (a && !seen.has(a)) {
            if (a === t.id) {
              rank = 8000 - d;
              break;
            }
            seen.add(a);
            a = this.s.agents.find((x) => x.id === a)?.parent;
            d++;
          }
        }
        if (rank < 0) continue;
        rank += r.policy.dailyProductMode === "selected" ? 0.5 : 0;
        if (rank > best) {
          best = rank;
          selected = r;
          network = t.kind === "tree" ? t.id : this.main(account);
        }
      }
    }
    const direct = this.s.pos.find((x) => x.id === id)?.printPolicy,
      policy = {
        dailyCards: 0,
        dailyMode: "account",
        dailyProductMode: "all",
        ...this.s.settings.printPolicy,
        ...selected?.policy,
        ...direct,
      };
    if (direct) network = this.main(account);
    return {
      policy,
      network,
      custom: !!(selected || direct),
      product: policy.dailyProductMode === "selected" ? product : null,
    };
  };
  P.dailyPrintNetwork = function (id, product) {
    return this.dailyPrintContext(id, product).network;
  };
  P.dailyCategoryOverride = function (id, product) {
    const c = this.dailyPrintContext(id, product);
    return c.custom && c.policy.dailyCards > 0;
  };
  function belongs(e, t, id, c) {
    return (
      (!c.product || t.product === c.product) &&
      (c.policy.dailyMode === "network"
        ? e
            .descendants(c.network)
            .includes(e.s.pos.find((x) => x.id === t.pos)?.agent || t.pos)
        : t.pos === id)
    );
  }
  P.checkDailySale = function (id, product, q) {
    const c = this.dailyPrintContext(id, product);
    if (!this.dailyCategoryOverride(id, product)) return;
    const day = Masal.businessDay(new Date()),
      used = this.s.sales
        .filter(
          (t) =>
            belongs(this, t, id, c) &&
            Masal.businessDay(new Date(t.time)) === day,
        )
        .reduce((n, t) => n + Number(t.quantity || 0), 0);
    if (used + Number(q) > c.policy.dailyCards)
      throw Error(
        "تم بلوغ الحد اليومي المخصص؛ المتاح للبيع: " +
          Math.max(0, c.policy.dailyCards - used) +
          " بطاقة",
      );
  };
  const sell = P.sell;
  P.sell = function (id, product, q, key, ...args) {
    if (!this.s.sales.some((t) => t.key === key))
      this.checkDailySale(id, product, q);
    return sell.call(this, id, product, q, key, ...args);
  };
  P.dailyPrintUsage = function (id, now = Date.now(), product) {
    const c = this.dailyPrintContext(id, product),
      p = c.policy,
      day = Masal.businessDay(new Date(now));
    let used = 0,
      pending = 0;
    const cards = new Set();
    for (const t of this.s.sales) {
      if (!belongs(this, t, id, c)) continue;
      const first = printed(t);
      if (first && Masal.businessDay(new Date(first)) === day) {
        if (t.cards?.length) {
          for (const c of t.cards)
            if (!cards.has(c)) {
              cards.add(c);
              used++;
            }
        } else used += Number(t.quantity) || 0;
      } else if (!first && t.printPending) pending += Number(t.quantity) || 0;
    }
    return {
      limit: p.dailyCards,
      used,
      pending,
      remaining: p.dailyCards
        ? Math.max(0, p.dailyCards - used - pending)
        : null,
      day,
      mode: p.dailyMode,
    };
  };
  P.checkDailyPrint = function (t) {
    if (printed(t)) return;
    const u = this.dailyPrintUsage(t.pos, Date.now(), t.product),
      own = t.printPending ? Number(t.quantity) || 0 : 0;
    if (u.limit && u.used + u.pending - own + Number(t.quantity) > u.limit)
      throw Error(
        "تم بلوغ الحد اليومي للبطاقات المطبوعة؛ المتاح اليوم: " +
          Math.max(0, u.limit - u.used - u.pending + own) +
          " بطاقة",
      );
  };
  const begin = P.beginPrint;
  P.beginPrint = function (id) {
    const t = this.s.sales.find((t) => t.id === id);
    if (t) this.checkDailyPrint(t);
    return begin.call(this, id);
  };
  const result = P.printResult;
  P.printResult = function (id, success, ...args) {
    const t = this.s.sales.find((t) => t.id === id),
      first = t && printed(t);
    if (t && success) this.checkDailyPrint(t);
    const out = result.call(this, id, success, ...args);
    if (t && success && !first) t.firstPrintedAt = new Date().toISOString();
    return out;
  };
  function install(o) {
    o.components["print-policy-settings"].components = {
      ...o.components["print-policy-settings"].components,
      "agent-product-picker": o.components["agent-product-picker"],
    };
    const summary = o.components["print-policy-summary"];
    summary.computed.daily = function () {
      this.now;
      return this.vm.engine.dailyPrintUsage(
        this.id,
        this.now,
        this.tx?.product || this.vm.saleForm.product,
      );
    };
    void 0;
  }
  root.MasalDailyPrint = { validate, install };
})(globalThis);
