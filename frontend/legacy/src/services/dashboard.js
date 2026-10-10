(function (root) {
  "use strict";
  function install(o) {
    const baseData = o.data;
    o.data = function () {
      return { ...baseData.call(this), dashboardFilter: null };
    };
    const originalGo = o.methods.go;
    o.methods.go = function (...args) {
      this.dashboardFilter = null;
      return originalGo.apply(this, args);
    };
    const switchUser = o.methods.switchUser;
    o.methods.switchUser = function (...args) {
      this.dashboardFilter = null;
      return switchUser.apply(this, args);
    };
    const transactionRows = o.computed.transactionRows;
    o.computed.transactionRows = function () {
      const rows = transactionRows.call(this),
        f = this.dashboardFilter;
      if (!f || f.page !== this.page) return rows;
      if (["sales", "quantity", "profit"].includes(f.key))
        return rows.filter(
          (t) => t.time && Masal.businessDay(t.time) === f.day,
        );
      if (f.key === "failed" || f.key === "reprints")
        return rows.filter(
          (t) =>
            t.status ===
            (f.key === "failed" ? "Print Failed" : "Reprint Requested"),
        );
      return rows;
    };
    const batchRows = o.computed.batchRows;
    o.computed.batchRows = function () {
      const rows = batchRows.call(this),
        f = this.dashboardFilter;
      if (f?.key !== "inventory" || this.page !== "inventory") return rows;
      const ids = new Set(
        this.availableCards
          .filter((c) => !f.account || c.agent === f.account)
          .map((c) => c.batch),
      );
      return rows.filter((b) => ids.has(b.id));
    };
    const tickets = o.computed.visibleTickets;
    o.computed.visibleTickets = function () {
      const rows = tickets.call(this);
      return this.dashboardFilter?.key === "support" && this.page === "support"
        ? rows.filter((t) => t.status !== "مغلقة")
        : rows;
    };
    const panel = o.components["operations-panel"];
    const accounts = panel.computed.accounts;
    panel.computed.accounts = function () {
      const rows = accounts.call(this),
        f = this.vm.dashboardFilter;
      return f?.key === "balance" && this.page === "wallets" && f.account
        ? rows.filter((a) => a.id === f.account)
        : rows;
    };
    const printRequests = panel.computed.printRequests;
    panel.computed.printRequests = function () {
      const rows = printRequests.call(this),
        f = this.vm.dashboardFilter;
      if (
        this.page !== "exceptions" ||
        !["failed", "reprints"].includes(f?.key)
      )
        return rows;
      const ids = new Set(this.vm.transactionRows.map((t) => t.id));
      return rows.filter(
        (r) => ids.has(r.tx) && !["مستخدم", "مرفوض"].includes(r.status),
      );
    };
    const requests = panel.computed.requests;
    panel.computed.requests = function () {
      const rows = requests.call(this),
        f = this.vm.dashboardFilter;
      if (f?.key !== "funding" || this.page !== "wallets") return rows;
      return rows.filter(
        (r) =>
          r.status === "بانتظار التمويل" &&
          (f.point ? r.to === f.account : f.main ? r.from === f.account : true),
      );
    };
    o.methods.clearDashboardFilter = function () {
      this.dashboardFilter = null;
      this.statusFilter = "";
      this.search = "";
      if (this.$refs.operations) this.$refs.operations.account = "";
    };
    o.methods.openDashboardPage = async function () {
      const card = this.dashboardDetail;
      if (!card) return;
      const key = card.key,
        page =
          {
            balance: "wallets",
            funding: "wallets",
            inventory: "inventory",
            support: "support",
            reprints: "exceptions",
            failed: "exceptions",
          }[key] || "sales";
      if (!this.can(page + ".view")) return;
      const point = this.actor.role === "pos",
        main = this.managementRole === "main",
        account = point
          ? this.actor.pos
          : this.actor.staffAccount && this.actor.staffAccount !== "@system"
            ? this.actor.staffAccount
            : this.actor.agent;
      this.closeModal();
      this.go(page);
      if (this.page !== page) return;
      this.search = "";
      this.statusFilter =
        key === "failed"
          ? "Print Failed"
          : key === "reprints"
            ? "Reprint Requested"
            : "";
      this.dashboardFilter = {
        key,
        page,
        title: card.title,
        day: Masal.day(),
        account: main || point || key === "balance" ? account : "",
        point,
        main,
      };
      await this.$nextTick();
      if (page === "wallets") {
        const p = this.$refs.operations;
        if (p) {
          p.tab = key === "funding" ? "fund" : "balances";
          p.service = "voucher";
          p.account = key === "balance" ? account || "" : "";
        }
      }
    };
    o.computed.dashboardCards = function () {
      const role = this.managementRole || this.actor.role,
        point = this.actor.role === "pos",
        account = point
          ? this.actor.pos
          : this.actor.staffAccount && this.actor.staffAccount !== "@system"
            ? this.actor.staffAccount
            : this.actor.agent,
        today = Masal.day();
      const sales = this.visibleSales.filter(
          (t) => t.time && Masal.businessDay(t.time) === today,
        ),
        sum = (k) => sales.reduce((n, t) => n + (Number(t[k]) || 0), 0),
        total = sum("total");
      const allowedAccount = (id) =>
        point ? id === account : this.accounts.some((a) => a.id === id);
      const requests = (this.s.fundingRequests || []).filter(
        (r) =>
          r.status === "بانتظار التمويل" &&
          (point
            ? r.to === account
            : role === "main"
              ? r.from === account
              : allowedAccount(r.from) || allowedAccount(r.to)),
      );
      const reprints = this.visibleSales.filter(
        (t) => t.status === "Reprint Requested",
      );
      const cards = [],
        add = (key, title, value, unit, note, icon, permissions) => {
          if (permissions.every((k) => this.can(k)))
            cards.push({ key, title, value, unit, note, icon });
        };
      const revenue = () =>
        add(
          "sales",
          point
            ? "مبيعات اليوم"
            : ["main", "sub"].includes(role)
              ? "مبيعات الشبكة اليوم"
              : "مبيعات اليوم",
          total,
          "د.ع",
          "اليوم",
          "sales",
          ["sales.view"],
        );
      const wallet = () =>
        add(
          "balance",
          "الرصيد التشغيلي المتاح",
          account
            ? this.engine.serviceAvailable(account, "voucher")
            : this.accounts.reduce(
                (n, a) => n + this.engine.serviceAvailable(a.id, "voucher"),
                0,
              ),
          "د.ع",
          "البطاقات · الآن",
          "wallets",
          ["wallets.view"],
        );
      const profit = () => {
        const network = ["main", "sub"].includes(role),
          known = sales.every(
            (t) =>
              Number.isFinite(
                Number(
                  t.pricingVersion === 2 ? t.cost : network ? t.credit : t.cost,
                ),
              ) &&
              (t.pricingVersion === 2 ? t.cost : network ? t.credit : t.cost) !=
                null,
          ),
          value = known
            ? sales.reduce(
                (n, t) =>
                  n +
                  t.total -
                  (t.pricingVersion === 2
                    ? t.cost
                    : network
                      ? t.credit
                      : t.cost),
                0,
              )
            : null;
        add(
          "profit",
          network ? "نسبة ربح مبيعات الشبكة" : "الربح الإجمالي اليوم",
          network
            ? total && value !== null
              ? (100 * value) / total
              : null
            : value,
          network ? "%" : "د.ع",
          network ? "اليوم · قبل المصاريف" : "اليوم · قبل المصاريف",
          "reports",
          ["sales.view", "data.profit"],
        );
      };
      const inventory = () =>
        add(
          "inventory",
          "البطاقات المتاحة",
          this.availableCards.filter(
            (c) => role !== "main" || c.agent === account,
          ).length,
          "بطاقة",
          "المخزون الحالي",
          "inventory",
          ["inventory.view"],
        );
      const funding = () =>
        add(
          "funding",
          "طلبات التمويل المعلقة",
          requests.length,
          "طلب",
          "بانتظار التمويل",
          "wallets",
          ["wallets.view"],
        );
      const prints = () =>
        add(
          "reprints",
          "طلبات إعادة الطباعة",
          reprints.length,
          "طلب",
          "بانتظار الإكمال",
          "exceptions",
          ["exceptions.view"],
        );
      const support = () =>
        add(
          "support",
          "رسائل الدعم المفتوحة",
          this.visibleTickets.filter((t) => t.status !== "مغلقة").length,
          "رسالة",
          "تحتاج متابعة",
          "support",
          ["support.view"],
        );
      if (point) {
        wallet();
        revenue();
        add(
          "quantity",
          "البطاقات المباعة اليوم",
          sum("quantity"),
          "بطاقة",
          "اليوم",
          "sell",
          ["sales.view"],
        );
        add(
          "failed",
          "عمليات الطباعة الفاشلة",
          this.visibleSales.filter((t) => t.status === "Print Failed").length,
          "عملية",
          "تحتاج متابعة",
          "exceptions",
          ["exceptions.view"],
        );
        prints();
        funding();
      } else if (role === "main") {
        wallet();
        revenue();
        profit();
        inventory();
        funding();
        prints();
      } else if (role === "sub") {
        wallet();
        revenue();
        profit();
        funding();
        prints();
        support();
      } else {
        revenue();
        profit();
        inventory();
        funding();
        prints();
        support();
      }
      const name = (type, id) =>
        this.s[type].find((x) => x.id === id)?.name || "—";
      const saleRows = (rows) =>
        rows.map((t) => ({
          name: name("pos", t.pos) + " · " + name("products", t.product),
          value: Number(t.total) || 0,
          unit: "د.ع",
          note: this.status(t.status),
        }));
      for (const card of cards) {
        if (card.key === "sales") card.rows = saleRows(sales);
        if (card.key === "quantity")
          card.rows = sales.map((t) => ({
            name: name("products", t.product),
            value: Number(t.quantity) || 0,
            unit: "بطاقة",
            note: name("pos", t.pos),
          }));
        if (card.key === "failed" || card.key === "reprints")
          card.rows = saleRows(
            this.visibleSales.filter(
              (t) =>
                t.status ===
                (card.key === "failed" ? "Print Failed" : "Reprint Requested"),
            ),
          );
        if (card.key === "inventory") {
          const stock = this.availableCards.filter(
            (c) => role !== "main" || c.agent === account,
          );
          card.rows = this.s.products
            .map((p) => ({
              name: p.name,
              value: stock.filter((c) => c.product === p.id).length,
              unit: "بطاقة",
            }))
            .filter((r) => r.value);
        }
        if (card.key === "funding")
          card.rows = requests.map((r) => ({
            name:
              name("agents", r.from) +
              " ← " +
              ([...this.s.agents, ...this.s.pos].find((a) => a.id === r.to)
                ?.name || "—"),
            value: r.amount,
            unit: "د.ع",
            note: r.status,
          }));
        if (card.key === "balance")
          card.rows = this.accounts
            .filter((a) => !account || a.id === account)
            .map((a) => ({
              name: a.name,
              value: this.engine.serviceAvailable(a.id, "voucher"),
              unit: "د.ع",
            }));
        if (card.key === "support")
          card.rows = this.visibleTickets
            .filter((t) => t.status !== "مغلقة")
            .map((t) => ({
              name: t.title,
              value: null,
              unit: "",
              note: t.status,
            }));
        if (card.key === "profit") {
          const network = ["main", "sub"].includes(role);
          card.rows = sales.map((t) => ({
            name: name("pos", t.pos) + " · " + name("products", t.product),
            value:
              (t.pricingVersion === 2 ? t.cost : network ? t.credit : t.cost) ==
              null
                ? null
                : t.total -
                  (t.pricingVersion === 2
                    ? t.cost
                    : network
                      ? t.credit
                      : t.cost),
            unit: "د.ع",
            note: "ربح العملية",
          }));
          card.explanation = network
            ? "نسبة الربح = مجموع أرباح المبيعات ÷ مجموع المبيعات × 100. قبل المصاريف."
            : "مجموع المبيعات ناقص تكلفة البطاقات المباعة، قبل المصاريف.";
        }
      }
      return cards.slice(0, 6);
    };
    o.computed.dashboardDetail = function () {
      return this.modal?.kind === "dashboardDetail"
        ? this.dashboardCards.find((c) => c.key === this.modal.key)
        : null;
    };
    o.methods.showDashboardDetails = function (item) {
      if (!this.can("dashboard.view")) return;
      const card = this.dashboardCards.find((c) => c.key === item.key);
      if (card)
        this.modal = {
          kind: "dashboardDetail",
          key: card.key,
          title: card.title,
        };
    };
  }
  root.MasalDashboard = { install };
})(globalThis);
