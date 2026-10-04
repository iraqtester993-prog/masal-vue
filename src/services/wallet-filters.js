(function (root) {
  "use strict";
  const empty = () => ({
    city: "",
    kind: "",
    network: "",
    account: "",
    active: "",
    query: "",
    reference: "",
    from: "",
    to: "",
  });
  function kind(vm, a) {
    if (vm.s.pos.some((p) => p.id === a.id)) return "pos";
    if (a.type === "رئيسي") return "main";
    return vm.s.agents.some((p) => p.id === a.parent && p.type === "فرعي")
      ? "subsub"
      : "sub";
  }
  const labels = {
    main: "وكيل رئيسي",
    sub: "وكيل فرعي",
    subsub: "فرعي تابع لفرعي",
    pos: "نقطة بيع",
  };
  function accounts(vm, rows, f, ignoreAccount = false) {
    const network = f.network
        ? new Set(vm.engine.descendants(f.network))
        : null,
      q = f.query.trim().toLocaleLowerCase();
    return rows.filter(
      (a) =>
        (!f.city || a.city === f.city) &&
        (!f.kind || kind(vm, a) === f.kind) &&
        (!network ||
          network.has(vm.s.pos.some((p) => p.id === a.id) ? a.agent : a.id)) &&
        (ignoreAccount || !f.account || a.id === f.account) &&
        (!f.active || !!a.active === (f.active === "active")) &&
        (!q ||
          [a.name, a.phone, a.city, a.owner].some((v) =>
            String(v || "")
              .toLocaleLowerCase()
              .includes(q),
          )),
    );
  }
  function record(vm, row, f) {
    const q = f.reference.trim().toLocaleLowerCase();
    if (
      q &&
      ![
        row.id,
        row.group,
        row.reference,
        row.batch,
        row.purpose,
        row.kind,
      ].some((v) =>
        String(v || "")
          .toLocaleLowerCase()
          .includes(q),
      )
    )
      return false;
    if (!f.from && !f.to) return true;
    const t = row.time;
    if (!t || Number.isNaN(Date.parse(t))) return false;
    const day = Masal.businessDay(t);
    return (!f.from || day >= f.from) && (!f.to || day <= f.to);
  }
  const dialog = {
    computed: {
      vm() {
        return this.$root;
      },
      draft() {
        return this.vm.modal.draft;
      },
      cities() {
        return this.vm.scopeFilterCities;
      },
      networks() {
        return this.vm.scopeFilterAgents;
      },
      kinds() {
        return this.vm.scopeFilterKinds;
      },
      choices() {
        const rows =
          this.vm.actor.role === "owner"
            ? [...this.vm.s.agents, ...this.vm.s.pos]
            : this.vm.accounts;
        return accounts(this.vm, rows, this.draft, true);
      },
    },
    methods: {
      changed(field) {
        if (["city", "kind"].includes(field)) this.draft.network = "";
        if (!this.choices.some((a) => a.id === this.draft.account))
          this.draft.account = "";
      },
    },
  };

  const accountTable = {
    props: ["rows", "service"],
    emits: ["movements"],
    data() {
      return { query: "", type: "", size: 10, page: 1 };
    },
    computed: {
      vm() {
        return this.$root;
      },
      filtered() {
        const q = this.query.trim().toLocaleLowerCase();
        return this.rows.filter(
          (a) =>
            (!this.type || kind(this.vm, a) === this.type) &&
            (!q ||
              [a.name, a.phone, a.id].some((x) =>
                String(x || "")
                  .toLocaleLowerCase()
                  .includes(q),
              )),
        );
      },
      pages() {
        return Math.max(1, Math.ceil(this.filtered.length / this.size));
      },
      shown() {
        return this.filtered.slice(
          (this.page - 1) * this.size,
          this.page * this.size,
        );
      },
    },
    watch: {
      query() {
        this.page = 1;
      },
      type() {
        this.page = 1;
      },
      size() {
        this.page = 1;
      },
      rows() {
        this.page = 1;
      },
      "vm.currentUser"() {
        this.query = "";
        this.type = "";
        this.page = 1;
      },
    },
    methods: {
      parent(a) {
        const id = this.vm.s.pos.some((p) => p.id === a.id)
          ? a.agent
          : a.parent;
        return this.vm.accounts.find((x) => x.id === id)?.name || "—";
      },
      details(a) {
        this.vm.modal = {
          kind: "walletAccountDetail",
          title: a.name,
          accountId: a.id,
          service: this.service,
        };
      },
    },
  };
  const accountDetail = {
    computed: {
      vm() {
        return this.$root;
      },
      account() {
        return this.vm.accounts.find((a) => a.id === this.vm.modal.accountId);
      },
      service() {
        return this.vm.modal.service;
      },
      parent() {
        if (!this.account) return "—";
        const a = this.account,
          id = this.vm.s.pos.some((p) => p.id === a.id) ? a.agent : a.parent;
        return this.vm.accounts.find((x) => x.id === id)?.name || "—";
      },
    },
    methods: {
      movements() {
        if (!this.account) return;
        const panel = this.vm.$refs.operations;
        panel.account = this.account.id;
        panel.tab = "balances";
        this.vm.closeModal();
        this.$nextTick(() =>
          document
            .querySelector(".wallet-movements")
            ?.scrollIntoView({ behavior: "smooth" }),
        );
      },
    },
  };

  function total(vm, id, service, available) {
    const ids =
      service === "all"
        ? [
            "voucher",
            "topup",
            "cash",
            ...vm.s.providers
              .filter((p) => p.connection === "API")
              .map((p) => "api:" + p.id),
          ]
        : [service];
    return ids.reduce(
      (n, s) =>
        n + vm.engine[available ? "serviceAvailable" : "serviceBalance"](id, s),
      0,
    );
  }
  function install(o) {
    o.methods.walletOwnSummary = function (service) {
      const id = this.engine.walletIdentity();
      if (!this.accounts.some((a) => a.id === id)) return null;
      const current = total(this, id, service, false),
        available = total(this, id, service, true);
      return {
        id,
        current,
        available,
        held: Math.round(Math.max(0, current - available) * 100) / 100,
      };
    };
    o.methods.walletTableAmount = function (id, service, available = true) {
      return total(this, id, service, available);
    };
    o.components["wallet-accounts-table"] = accountTable;
    o.components["wallet-account-detail"] = accountDetail;
    const data = o.data;
    o.data = function () {
      return { ...data.call(this), walletFilters: empty() };
    };
    o.components["wallet-filter-dialog"] = dialog;
    o.methods.emptyWalletFilters = empty;
    o.methods.walletAccountType = function (a) {
      return labels[kind(this, a)];
    };
    o.computed.walletFiltersActive = function () {
      return Object.values(this.walletFilters).some(Boolean);
    };
    o.methods.openWalletFilters = function () {
      this.modal = {
        kind: "walletFilters",
        title: "فلترة المحافظ والتحويلات",
        draft: Masal.clone(this.walletFilters),
        error: "",
      };
    };
    o.methods.applyWalletFilters = function () {
      const f = this.modal.draft;
      for (const k of ["from", "to"])
        if (
          f[k] &&
          (!/^\d{4}-\d{2}-\d{2}$/.test(f[k]) || Number.isNaN(Date.parse(f[k])))
        ) {
          this.modal.error = "اختر تاريخًا صحيحًا";
          return;
        }
      if (f.from && f.to && f.from > f.to) {
        this.modal.error = "تاريخ البداية يجب أن يسبق تاريخ النهاية";
        return;
      }
      if (
        (f.city && !this.scopeFilterCities.includes(f.city)) ||
        (f.kind && !this.scopeFilterKinds.some((k) => k.id === f.kind))
      ) {
        this.modal.error = "الاختيار خارج نطاق حسابك";
        return;
      }
      if (
        (f.network &&
          !this.scopeFilterAgents.some((a) => a.id === f.network)) ||
        (f.account &&
          !accounts(this, this.accounts, f, true).some(
            (a) => a.id === f.account,
          ))
      ) {
        this.modal.error = "اختر حسابًا ضمن النتائج المتاحة";
        return;
      }
      this.walletFilters = Masal.clone(f);
      this.dashboardFilter = null;
      const panel = this.$refs.operations;
      if (panel) {
        panel.account = "";
        panel.fundSelected = [];
      }
      this.closeModal();
    };
    o.methods.clearWalletFilters = function () {
      this.walletFilters = empty();
      this.dashboardFilter = null;
      const p = this.$refs.operations;
      if (p) {
        p.account = "";
        p.fundSelected = [];
      }
    };
    const change = o.methods.switchUser;
    o.methods.switchUser = function (...args) {
      this.walletFilters = empty();
      return change.apply(this, args);
    };
    o.computed.walletReconciliationAgents = function () {
      return accounts(
        this,
        this.visibleAgents.filter((a) => !a.parent),
        this.walletFilters,
      );
    };
    const panel = o.components["operations-panel"];
    panel.components = {
      ...panel.components,
      "wallet-accounts-table": accountTable,
    };
    panel.computed.walletAccounts = function () {
      return accounts(this.vm, this.accounts, this.vm.walletFilters);
    };
    panel.computed.walletOwnTotals = function () {
      return this.vm.walletOwnSummary(this.service);
    };
    panel.computed.walletDisplayTotals = function () {
      if (this.walletOwnTotals) return this.walletOwnTotals;
      const current = this.walletAccounts.reduce(
          (sum, a) =>
            sum + this.vm.walletTableAmount(a.id, this.service, false),
          0,
        ),
        available = this.walletAccounts.reduce(
          (sum, a) => sum + this.vm.walletTableAmount(a.id, this.service, true),
          0,
        );
      return {
        current,
        available,
        held: Math.round(Math.max(0, current - available) * 100) / 100,
      };
    };
    panel.computed.walletChildrenTotal = function () {
      const own = this.walletOwnTotals;
      if (!own || this.vm.actor.role === "pos") return null;
      const children = new Set(this.e.descendants(own.id)),
        accounts = this.walletAccounts.filter(
          (a) => a.id !== own.id && children.has(this.e.accountAgent(a.id)),
        );
      if (!accounts.length) return null;
      return accounts.reduce(
        (sum, a) => sum + this.vm.walletTableAmount(a.id, this.service, false),
        0,
      );
    };
    panel.computed.showStockMetrics = function () {
      return (
        ["owner", "main"].includes(this.vm.actor.role) &&
        this.can("wallets.view") &&
        ["voucher", "all"].includes(this.service)
      );
    };
    panel.computed.cardMetrics = function () {
      if (!this.showStockMetrics) return { count: 0, cost: 0, credit: 0 };
      const ids = new Set(
        (this.walletAccounts || []).map((a) =>
          this.e.main(this.e.accountAgent(a.id)),
        ),
      );
      const cards = this.s.cards.filter(
        (c) =>
          ids.has(c.agent) &&
          ["Available", "Reserved", "_Held"].includes(c.status),
      );
      return {
        count: cards.length,
        cost: cards.reduce((n, c) => n + Number(c.cost || 0), 0),
        credit: cards.reduce((n, c) => n + Number(c.credit ?? c.cost ?? 0), 0),
      };
    };
    panel.computed.walletAgents = function () {
      return this.walletAccounts.filter((a) =>
        this.s.agents.some((x) => x.id === a.id),
      );
    };
    const balance = panel.computed.balanceTotal;
    panel.computed.balanceTotal = function () {
      return this.page === "wallets"
        ? this.walletAccounts.reduce(
            (n, a) => n + this.e.serviceBalance(a.id, this.service),
            0,
          )
        : balance.call(this);
    };
    for (const key of ["ledger", "requests", "transfers", "invoices"]) {
      const base = panel.computed[key];
      panel.computed[key] = function () {
        const rows = base.call(this);
        if (this.page !== "wallets") return rows;
        const ids = new Set(this.walletAccounts.map((a) => a.id)),
          f = this.vm.walletFilters;
        return rows.filter(
          (r) =>
            (key === "ledger"
              ? ids.has(r.account)
              : key === "invoices"
                ? ids.has(r.agent)
                : ids.has(r.from) || ids.has(r.to)) &&
            (key === "invoices"
              ? this.service === "voucher"
              : !r.service || r.service === this.service) &&
            record(this.vm, r, f),
        );
      };
    }
    // Outstanding debt remains a current total, independent of the displayed date range.
    panel.computed.debt = function () {
      if (
        !this.account ||
        !this.vm.visibleAgents.some((a) => a.id === this.account)
      )
        return 0;
      return (
        this.s.batchInvoices
          .filter((i) => i.agent === this.account && i.status !== "معكوسة")
          .reduce((n, i) => n + i.amount, 0) -
        this.s.collections
          .filter((c) => c.account === this.account)
          .reduce((n, c) => n + c.amount, 0)
      );
    };
  }
  root.MasalWalletFilters = { install };
})(globalThis);
