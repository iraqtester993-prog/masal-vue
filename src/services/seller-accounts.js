(function (root) {
  "use strict";
  const P = Masal.Engine.prototype;
  P.sellerID = function () {
    const u = this.actor(),
      role = MasalAccess.managementRole(this.s, u);
    return role === "pos"
      ? u.pos
      : role === "sub"
        ? u.staffAccount || u.agent
        : "";
  };
  P.saleAccount = function (id) {
    const pos = this.s.pos.find((p) => p.id === id);
    if (pos) return pos;
    const a = this.s.agents.find((a) => a.id === id && a.type === "فرعي");
    return a ? { ...a, agent: a.id, online: true, sellerType: "sub" } : null;
  };
  P.requireOwnSeller = function (id) {
    this.requirePermission("sell.create");
    if (!this.sellerID() || this.sellerID() !== id)
      throw Error("البيع من حسابك ومحفظتك فقط");
    if (!this.saleAccount(id)) throw Error("حساب البيع غير موجود");
  };
  const sell = P.sell;
  P.sell = function (id, ...args) {
    this.requireOwnSeller(id);
    const tx = sell.call(this, id, ...args);
    tx.sellerAccount = id;
    tx.sellerType = this.s.pos.some((p) => p.id === id) ? "pos" : "sub";
    tx.user = tx.user || this.user;
    return tx;
  };
  const reserve = P.reserve;
  P.reserve = function (id, ...args) {
    this.requireOwnSeller(id);
    return reserve.call(this, id, ...args);
  };
  const issue = P.issueReservation;
  P.issueReservation = function (id) {
    const r = this.s.reservations.find((r) => r.id === id);
    this.requireOwnSeller(r?.pos);
    return issue.call(this, id);
  };
  for (const method of ["assertPrintReady", "requestReprint"]) {
    const original = P[method];
    P[method] = function (id, ...args) {
      const t = this.s.sales.find((t) => t.id === id);
      if (!this.sellerID() || t?.pos !== this.sellerID())
        throw Error("الطباعة من حساب صاحب البيع فقط");
      return original.call(this, id, ...args);
    };
  }
  function install(o) {
    o.methods.ownsSale = function (t) {
      return (
        !!t && !!this.engine.sellerID() && t.pos === this.engine.sellerID()
      );
    };
    o.methods.canViewSaleCards = function (t) {
      return (
        this.ownsSale(t) && this.can("sell.receipt") && this.can("data.pin")
      );
    };
    for (const method of ["viewReceipt", "openReprint"]) {
      const original = o.methods[method];
      o.methods[method] = function (t) {
        if (!this.canViewSaleCards(t)) {
          this.notify(
            "يمكنك متابعة الحالة فقط؛ البطاقة والوصل لصاحب البيع",
            true,
          );
          return;
        }
        return original.call(this, t);
      };
    }
    const receiptCards = o.computed.receiptCards;
    o.computed.receiptCards = function () {
      return this.canViewSaleCards(this.modal?.tx)
        ? receiptCards.call(this)
        : [];
    };
    const receipt = o.components["meeting-receipt"];
    const cards = receipt.computed.cards;
    receipt.computed.cards = function () {
      return this.tx && !this.vm.canViewSaleCards(this.tx)
        ? []
        : cards.call(this);
    };
    o.computed.ownReprintSales = function () {
      return this.visibleSales.filter(
        (t) =>
          this.ownsSale(t) &&
          ["Printed", "Reprinted", "Print Failed"].includes(t.status),
      );
    };

    const panel = o.components["operations-panel"];
    panel.computed.printRequests = function () {
      const ids = new Set(this.vm.visibleSales.map((t) => t.id));
      return this.s.printOverrides.filter((r) => ids.has(r.tx));
    };
    o.computed.sellingAccount = function () {
      return this.engine.saleAccount(this.engine.sellerID());
    };
    o.computed.selectedPOS = function () {
      return this.sellingAccount;
    };
    const name = o.methods.nameOf;
    o.methods.nameOf = function (list, id) {
      if (list === "pos") {
        const a = this.s.agents.find((a) => a.id === id);
        if (a) return a.name;
      }
      return name.call(this, list, id);
    };
    const sale = o.methods.sell;
    o.methods.sell = function () {
      this.saleForm.pos = this.engine.sellerID();
      return sale.call(this);
    };
    const mounted = o.mounted;
    o.mounted = function () {
      mounted?.call(this);
      this.$watch(
        () => this.engine.sellerID(),
        (id) => {
          this.saleForm.pos = id;
          this.saleKey = Masal.id("SALE");
          if (this.page === "sell" && !this.can("sell.view"))
            this.go("dashboard");
        },
        { immediate: true },
      );
    };
  }
  root.MasalSellerAccounts = { install };
})(globalThis);
